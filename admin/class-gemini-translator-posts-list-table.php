<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Gemini_Translator_Posts_List_Table extends WP_List_Table {

    private $status_counts;

    public function __construct() {
        parent::__construct( [
            'singular' => 'Post',
            'plural'   => 'Posts',
            'ajax'     => false
        ] );
    }

    public function get_status_count($status) {
        return $this->status_counts[$status] ?? 0;
    }

    public function get_columns() {
        return [
            'cb'       => '<input type="checkbox" />',
            'title'    => 'Title',
            'translation_status' => 'Translation Status',
            'author'   => 'Author',
            'categories' => 'Categories',
            'date'     => 'Date'
        ];
    }

    protected function get_sortable_columns() {
        return [
            'title'  => [ 'title', false ],
            'author' => [ 'author', false ],
            'date'   => [ 'date', true ]
        ];
    }

    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'author':
            case 'date':
                return $item[ $column_name ];
            default:
                return print_r( $item, true ); // For debugging
        }
    }

    protected function column_categories( $item ) {
        $categories = get_the_category($item['ID']);
        if ( ! empty( $categories ) ) {
            $out = [];
            foreach ( $categories as $category ) {
                $out[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( admin_url( 'admin.php?page=gemini-translator&cat=' . $category->term_id ) ),
                    esc_html( $category->name )
                );
            }
            return implode( ', ', $out );
        }
        return '—';
    }

    protected function column_cb( $item ) {
        // The checkbox should only be available for translatable items
        if ($item['translation_status'] === 'Untranslated' || $item['translation_status'] === 'Completed') {
             return sprintf(
                '<input type="checkbox" name="post[]" value="%s" />', $item['ID']
            );
        }
        return '';
    }
    
    protected function column_title( $item ) {
        $actions = [];
        
        switch ($item['translation_status']) {
            case 'Untranslated':
                $actions['translate'] = sprintf(
                    '<a href="#" class="row-action" data-action="translate" data-post-id="%s">Translate</a>',
                    esc_attr($item['ID'])
                );
                break;
            case 'Pending Review':
                 $actions['review'] = sprintf(
                    '<a href="#" class="row-action" data-action="review" data-post-id="%s">Review</a>',
                    esc_attr($item['ID'])
                );
                $actions['re-translate'] = sprintf(
                    '<a href="#" class="row-action" data-action="re-translate" data-post-id="%s" style="color:#a00;">Send to Re-translate</a>',
                    esc_attr($item['ID'])
                );
                break;
            case 'Completed':
                $actions['restore'] = sprintf(
                    '<a href="#" class="row-action" data-action="restore" data-post-id="%s" style="color:#a00;">Restore Original</a>',
                    esc_attr($item['ID'])
                );
                break;
        }

        $actions['edit'] = sprintf( '<a href="%s">Edit</a>', get_edit_post_link( $item['ID'] ) );
        $actions['view'] = sprintf( '<a href="%s">View</a>', get_permalink( $item['ID'] ) );

        return sprintf( '%1$s %2$s', $item['title'], $this->row_actions( $actions ) );
    }

    protected function column_translation_status( $item ) {
        $status = $item['translation_status'];
        $color = 'black';
        switch ($status) {
            case 'Untranslated':
                $color = '#999';
                break;
            case 'Pending Review':
                $color = 'orange';
                break;
            case 'Completed':
                $color = 'green';
                break;
        }
        return sprintf('<strong style="color:%s;">%s</strong>', $color, esc_html($status));
    }


    public function prepare_items( $current_status = 'all' ) {
        global $wpdb;

        $table_posts = $wpdb->prefix . 'posts';
        $table_translations = $wpdb->prefix . 'gemini_translations';
        $category_filter = isset($_REQUEST['cat']) ? (int)$_REQUEST['cat'] : 0;

        // --- Build Base Query Parts (with category filter if applied) ---
        $query_from = "FROM {$table_posts} p LEFT JOIN {$table_translations} t ON p.ID = t.post_id";
        $base_query_where = "WHERE p.post_type = 'post' AND p.post_status = 'publish'";

        if ($category_filter > 0) {
            // Need to join term tables to filter by category
            $query_from .= " INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
            $query_from .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $base_query_where .= $wpdb->prepare(" AND tt.taxonomy = 'category' AND tt.term_id = %d", $category_filter);
        }

        // --- Calculate Status Counts for Tabs using the base query ---
        $this->status_counts = [
            'all' => $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) {$query_from} {$base_query_where}"),
            'pending_review' => $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) {$query_from} {$base_query_where} AND t.status = 'pending_review'"),
            'completed' => $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) {$query_from} {$base_query_where} AND t.status = 'completed'"),
            'untranslated' => $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) {$query_from} {$base_query_where} AND t.status IS NULL")
        ];

        // --- Create the Main Query's WHERE clause by adding the status filter ---
        $main_query_where = $base_query_where;
        if ($current_status === 'untranslated') {
            $main_query_where .= " AND t.status IS NULL";
        } elseif ($current_status !== 'all') {
            $main_query_where .= $wpdb->prepare(" AND t.status = %s", $current_status);
        }

        // --- Pagination ---
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // The total items for pagination is the count of the currently viewed status tab
        $total_items = $this->status_counts[$current_status];

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        // --- Sorting ---
        $orderby = ( ! empty( $_REQUEST['orderby'] ) ) ? $_REQUEST['orderby'] : 'date';
        $order = ( ! empty( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'desc';
        // Whitelist orderby to prevent SQL injection
        $allowed_orderby = ['title', 'author', 'date'];
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'date';
        }

        // --- Final Query & Results ---
        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

        $query_select = "SELECT DISTINCT p.ID, p.post_title, p.post_author, p.post_date, COALESCE(t.status, 'Untranslated') as translation_status";
        
        $query = $query_select . " " . $query_from . " " . $main_query_where
               . " ORDER BY p.post_{$orderby} {$order}"
               . $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

        $results = $wpdb->get_results($query, ARRAY_A);
        
        $items = [];
        if ($results) {
            foreach( $results as $row ) {
                $items[] = [
                    'ID' => $row['ID'],
                    'title' => $row['post_title'],
                    'translation_status' => ucwords(str_replace('_', ' ', $row['translation_status'])),
                    'author' => get_the_author_meta('display_name', $row['post_author']),
                    'categories' => get_the_category_list(', ', '', $row['ID']),
                    'date' => $row['post_date']
                ];
            }
        }

        $this->items = $items;
    }
} 