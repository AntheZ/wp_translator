<?php

class Gemini_Translator {

    protected $plugin_name;
    protected $version;
    protected $plugin_admin;

    public function __construct() {
        $this->version = '0.1';
        $this->plugin_name = 'gemini-post-translator';
        
        $this->load_dependencies();
        $this->define_admin_hooks();
    }

    public static function on_activate() {
        global $wpdb;

        $table_originals_name = $wpdb->prefix . 'gemini_originals';
        $table_translations_name = $wpdb->prefix . 'gemini_translations';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_originals = "CREATE TABLE $table_originals_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            original_title text NOT NULL,
            original_content longtext NOT NULL,
            saved_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id)
        ) $charset_collate;";

        $sql_translations = "CREATE TABLE $table_translations_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            translated_title text NOT NULL,
            translated_content longtext NOT NULL,
            seo_title text,
            meta_description text,
            meta_keywords text,
            status varchar(20) DEFAULT 'pending_review' NOT NULL,
            translated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_originals );
        dbDelta( $sql_translations );
    }

    private function load_dependencies() {
        // Load the admin-specific functionality
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-gemini-translator-admin.php';
    }

    private function define_admin_hooks() {
        $this->plugin_admin = new Gemini_Translator_Admin( $this->get_plugin_name(), $this->get_version() );

        // Enqueue admin scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        
        // Initialize admin settings
        add_action( 'admin_init', array( $this->plugin_admin, 'register_settings' ) );
    }

    public function enqueue_admin_scripts( $hook ) {
        // Only load on post edit pages and plugin settings pages
        if ( $hook === 'post.php' || $hook === 'post-new.php' || 
             strpos( $hook, $this->plugin_name ) !== false ) {
            
            // Enqueue jQuery if not already loaded
            wp_enqueue_script( 'jquery' );
            
            // Ensure AJAX URL is available for our scripts
            wp_localize_script( 'jquery', 'gemini_translator_ajax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'gemini_translate_post' ),
                'save_nonce' => wp_create_nonce( 'gemini_save_post_nonce' )
            ));
        }
    }

    public function run() {
        // Plugin is now properly initialized
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }
} 