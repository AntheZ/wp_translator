<?php
/*
Plugin Name: Simple Google Cloud Translation Plugin
Description: A simple plugin to translate posts using Google Cloud Translation API
Version: 0.6
Author: Anton Zanizdra
*/

// Add a new submenu under Settings
function mt_add_pages() {
    add_options_page(__('Simple GC Translator','menu-test'), __('Simple GC Translator','menu-test'), 'manage_options', 'translationhandle', 'mt_settings_page');
}
add_action('admin_menu', 'mt_add_pages');

function mt_settings_page() {
    echo "<h2>" . __( 'SGC Translation Settings', 'menu-test' ) . "</h2>";
    echo '<form action="options.php" method="post">';
    settings_fields('mt_options');
    do_settings_sections('translationhandle');
    submit_button();
    // place your settings form here 
    echo '</form>';
}

// Register and define the settings
add_action('admin_init', 'mt_admin_init');
function mt_admin_init(){
    register_setting( 'mt_options', 'mt_options', 'mt_validate_options' );
    add_settings_section('mt_main', 'Main Settings', 'mt_section_text', 'translationhandle');
    add_settings_field('mt_text_string', 'Language Code', 'mt_setting_string', 'translationhandle', 'mt_main');
    add_settings_field('mt_api_key', 'API Key', 'mt_setting_api_key', 'translationhandle', 'mt_main');
}

function mt_plugin_action_links($links) {
    $settings_link = '<a href="options-general.php?page=translationhandle">' . __( 'Settings' ) . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__); 
add_filter("plugin_action_links_$plugin", 'mt_plugin_action_links' );

// Draw the section header
function mt_section_text() {
    echo '<p>Enter your settings here.</p>';
}

// Display and fill the form field
function mt_setting_string() {
    // get option 'text_string' value from the database
    $options = get_option( 'mt_options' );
    $value = $options['text_string'];
    // echo the field
    echo "<input id='mt_text_string' name='mt_options[text_string]' type='text' value='$value' />";
}

function mt_setting_api_key() {
    // get option 'api_key' value from the database
    $options = get_option( 'mt_options' );
    $value = $options['api_key'];
    // echo the field
    echo "<input id='mt_api_key' name='mt_options[api_key]' type='text' value='$value' />";
}

// List of supported languages by Google Cloud Translation API
$supported_languages = array("af", "sq", "am", "ar", "hy", "as", "ay", "az", "bm", "eu", "be", "bn", "bho", "bs", "bg", "ca", "ceb", "zh-CN", "zh-TW", "co", "hr", "cs", "da", "dv", "doi", "nl", "en", "eo", "et", "ee", "fil", "fi", "fr", "fy", "gl", "ka", "de", "el", "gn", "gu", "ht", "ha", "haw", "he", "hi", "hmn", "hu", "is", "ig", "ilo", "id", "ga", "it", "ja", "jv", "kn", "kk", "km", "rw", "gom", "ko", "kri", "ku", "ckb", "ky", "lo", "la", "lv", "ln", "lt", "lg", "lb", "mk", "mai", "mg", "ms", "ml", "mt", "mi", "mr", "mni-Mtei", "lus", "mn", "my", "ne", "no", "ny", "or", "om", "ps", "fa", "pl", "pt", "pa", "qu", "ro", "ru", "sm", "sa", "gd", "nso", "sr", "st", "sn", "sd", "si", "sk", "sl", "so", "es", "su", "sw", "sv", "tl", "tg", "ta", "tt", "te", "th", "ti", "ts", "tr", "tk", "ak", "uk", "ur", "ug", "uz", "vi", "cy", "xh", "yi", "yo", "zu");

// Validate user input
function mt_validate_options($input) {
    global $supported_languages;
    $valid = array();
    $language_code = sanitize_text_field($input['text_string']);

    // Check if the language code is in the list of supported languages
    if (in_array($language_code, $supported_languages)) {
        $valid['text_string'] = $language_code;
    } else {
        add_settings_error(
            'mt_options',           // Setting title
            'mt_texterror',            // Error ID
            'Please enter a valid language code.',   // Error message
            'error'                        // Type of message
        );
    }

    $api_key = $input['api_key'];
    $url = "https://translation.googleapis.com/language/translate/v2?key=$api_key&q=hello&source=en&target=es";
    $response = wp_remote_get($url);
    if ( is_wp_error( $response ) ) {
        add_settings_error('mt_options', 'mt_connection_error', 'Could not connect to the API. Please check your API key.');
    }
    return $input;
}

// Connect to Google Cloud Translation API and translate the posts
function translate_posts() {
    // Get the language code from the settings
    $options = get_option( 'mt_options' );
    $language_code = $options['text_string'];

    // Get all posts
    $args = array(
        'posts_per_page'   => -1,
        'post_type'        => 'post',
    );
    $posts_array = get_posts( $args );

    foreach ( $posts_array as $post ) {
        // Connect to Google Cloud Translation API and translate the post
        // Make sure to handle the rate limit of 6000000 characters per minute
        // Log the translation process
    }

    // Update the progress bar in the admin interface
}

// Use the translated posts
function use_translated_posts() {
    // Backup the original posts and postmeta tables
    // Replace the original posts and postmeta tables with the translated ones
    // Log the process
}

?>