<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Gemini_Translator_Posts_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'Post',
            'plural'   => 'Posts',
            'ajax'     => false
        ] );
    }

    public function get_columns() {
        return [
            'cb'       => '<input type="checkbox" />',
            'title'    => 'Title',
            'author'   => 'Author',
            'categories' => 'Categories',
            'tags'     => 'Tags',
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
        return $item[ $column_name ];
    }

    protected function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="post[]" value="%s" />', $item['ID']
        );
    }
    
    protected function column_title( $item ) {
        $actions = [
            'edit' => sprintf( '<a href="%s">Edit</a>', get_edit_post_link( $item['ID'] ) ),
            'view' => sprintf( '<a href="%s">View</a>', get_permalink( $item['ID'] ) )
        ];
        return sprintf( '%1$s %2$s', $item['title'], $this->row_actions( $actions ) );
    }


    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        $args = [
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_type'      => 'post',
            'post_status'    => 'publish'
        ];

        // Handle sorting
        if ( ! empty( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], array_keys( $this->get_sortable_columns() ) ) ) {
            $args['orderby'] = $_REQUEST['orderby'];
        }
        if ( ! empty( $_REQUEST['order'] ) && in_array( $_REQUEST['order'], ['asc', 'desc'] ) ) {
            $args['order'] = $_REQUEST['order'];
        }

        $query = new WP_Query( $args );
        
        $this->set_pagination_args( [
            'total_items' => $query->found_posts,
            'per_page'    => $per_page
        ] );
        
        $items = [];
        foreach( $query->posts as $post ) {
            $categories = get_the_category($post->ID);
            $cat_names = !empty($categories) ? implode(', ', wp_list_pluck($categories, 'name')) : '';
            
            $tags = get_the_tags($post->ID);
            $tag_names = !empty($tags) ? implode(', ', wp_list_pluck($tags, 'name')) : '';

            $items[] = [
                'ID' => $post->ID,
                'title' => $post->post_title,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'categories' => $cat_names,
                'tags' => $tag_names,
                'date' => $post->post_date
            ];
        }

        $this->items = $items;
    }
} 