<?php

class Gemini_Translator {

    protected $plugin_name;
    protected $version;

    public function __construct() {
        $this->version = '0.1';
        $this->plugin_name = 'gemini-post-translator';
        
        $this->define_admin_hooks();
    }

    private function define_admin_hooks() {
        // We need an admin class to handle the settings page
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-gemini-translator-admin.php';
        $plugin_admin = new Gemini_Translator_Admin( $this->get_plugin_name(), $this->get_version() );

        // Add settings page
        add_action( 'admin_menu', array( $plugin_admin, 'add_options_page' ) );
        add_action( 'admin_init', array( $plugin_admin, 'register_settings' ) );
    }

    public function run() {
        // The run method is now empty as hooks are added directly in the constructor
        // or in the admin class. We will expand this later.
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }
} 