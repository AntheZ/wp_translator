<?php
/*
Plugin Name: Gemini Post Translator
Description: A plugin to translate and improve WordPress posts using the Google Gemini API.
Version: 0.1
Author: AntheZ
Author URI: https://github.com/AntheZ
License: GPLv2 or later
Text Domain: gemini-post-translator
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-gemini-translator.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    0.1
 */
function run_gemini_translator() {

    $plugin = new Gemini_Translator();
    $plugin->run();

}
run_gemini_translator(); 