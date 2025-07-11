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
        $base_url = 'tools.php?page=' . $_REQUEST['page'];

        switch ($item['translation_status']) {
            case 'Untranslated':
                $actions['translate'] = sprintf('<a href="%s&action=translate&post=%s">Translate</a>', wp_nonce_url($base_url, 'translate_' . $item['ID']), $item['ID']);
                break;
            case 'Pending Review':
                 $actions['review'] = sprintf('<a href="%s&action=review&post=%s">Review</a>', $base_url, $item['ID']);
                break;
            case 'Completed':
                $actions['restore'] = sprintf('<a href="%s&action=restore&post=%s" style="color:#a00;">Restore Original</a>', wp_nonce_url($base_url, 'restore_' . $item['ID']), $item['ID']);
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

        // First, get all counts for the tabs
        $this->status_counts = [
            'all' => $wpdb->get_var("SELECT COUNT(*) FROM $table_posts WHERE post_type = 'post' AND post_status = 'publish'"),
            'pending_review' => $wpdb->get_var("SELECT COUNT(*) FROM $table_translations WHERE status = 'pending_review'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM $table_translations WHERE status = 'completed'")
        ];
        $this->status_counts['untranslated'] = $this->status_counts['all'] - $this->status_counts['pending_review'] - $this->status_counts['completed'];


        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;
        
        $orderby = ( ! empty( $_REQUEST['orderby'] ) ) ? $_REQUEST['orderby'] : 'date';
        $order = ( ! empty( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'desc';

        $query = "
            SELECT p.ID, p.post_title, p.post_author, p.post_date,
                   COALESCE(t.status, 'Untranslated') as translation_status
            FROM {$table_posts} p
            LEFT JOIN {$table_translations} t ON p.ID = t.post_id
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
        ";
        
        // Add status filtering
        if ($current_status === 'untranslated') {
            $query .= " AND t.status IS NULL";
        } elseif ($current_status !== 'all') {
            $query .= $wpdb->prepare(" AND t.status = %s", $current_status);
        }

        // Get total items for pagination
        $total_items = $wpdb->get_var( str_replace('SELECT p.ID, p.post_title, p.post_author, p.post_date, COALESCE(t.status, \'Untranslated\') as translation_status', 'SELECT COUNT(*)', $query) );
        
        $query .= " ORDER BY {$orderby} {$order}";
        $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

        $results = $wpdb->get_results($query, ARRAY_A);
        
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ] );
        
        $items = [];
        foreach( $results as $row ) {
            $items[] = [
                'ID' => $row['ID'],
                'title' => $row['post_title'],
                'translation_status' => ucfirst(str_replace('_', ' ', $row['translation_status'])),
                'author' => get_the_author_meta('display_name', $row['post_author']),
                'date' => $row['post_date']
            ];
        }

        $this->items = $items;
    }
} 