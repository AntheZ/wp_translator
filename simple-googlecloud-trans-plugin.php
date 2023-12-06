<?php
/*
Plugin Name: Simple Google Cloud Translation Plugin
Description: A simple plugin to translate posts using Google Cloud Translation API
Version: 0.14
Author: AntheZ
*/

register_activation_hook(__FILE__, 'mt_activate');
function mt_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$wpdb->prefix}trans_posts LIKE {$wpdb->prefix}posts;
            CREATE TABLE {$wpdb->prefix}trans_postmeta LIKE {$wpdb->prefix}postmeta;
            CREATE TABLE {$wpdb->prefix}bak_posts LIKE {$wpdb->prefix}posts;
            CREATE TABLE {$wpdb->prefix}bak_postmeta LIKE {$wpdb->prefix}postmeta;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

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
    submit_button('Запустити перекладач');
    submit_button('Використати переклад');
    echo '</form>';
    echo '<p>Шлях до логу плагіну: ' . plugin_dir_path(__FILE__) . 'sgclog.txt</p>';
    echo '<a href="' . plugin_dir_url(__FILE__) . 'sgclog.txt" download>Скачати лог</a>';
}

// Register and define the settings
add_action('admin_init', 'mt_admin_init');
function mt_admin_init(){
    register_setting( 'mt_options', 'mt_options', 'mt_validate_options' );
    add_settings_section('mt_main', 'Main Settings', 'mt_section_text', 'translationhandle');
    add_settings_field('mt_website_language_code', 'Website Language Code', 'mt_setting_website_language_code', 'translationhandle', 'mt_main');
    add_settings_field('mt_translation_language_code', 'Translation Language Code', 'mt_setting_translation_language_code', 'translationhandle', 'mt_main');
    add_settings_field('mt_api_key', 'API Key', 'mt_setting_api_key', 'translationhandle', 'mt_main');
    add_settings_section('mt_main_analyse', 'Additional Settings', 'mt_section_text', 'translationhandle');
    add_settings_field('mt_analyse_button', 'Analyse Posts', 'pluginSettingsPage', 'translationhandle', 'mt_main_analyse');
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

function pluginSettingsPage() {
    // Перевіряємо, чи була натиснута кнопка
    if (isset($_POST['analyse_posts'])) {
        // Виконуємо функцію analysePosts
        analysePosts();
        echo '<div class="updated"><p>Аналіз завершено</p></div>';
    }

    // Виводимо форму
    echo '<div class="wrap">';
    echo '<h1>Налаштування плагіну</h1>';
    echo '<form id="analysePostsForm" method="post">';
    echo '<input type="button" id="analysePostsButton" name="analyse_posts" class="button button-primary" value="Проаналізувати кількість статей" />';
    echo '</form>';
    echo '</div>';

    // Додаємо JavaScript для обробки натискання кнопки
    echo '
    <script type="text/javascript">
    document.getElementById("analysePostsButton").addEventListener("click", function(e) {
        e.preventDefault();
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "' . admin_url('admin-ajax.php') . '", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function() {
            if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                alert("Аналіз завершено");
            }
        }
        xhr.send("action=analysePosts");
    });
    </script>
    ';
}

add_action('wp_ajax_analysePosts', 'analysePosts');

// Display and fill the website language form field
function mt_setting_website_language_code() {
    // get option 'website_language_code' value from the database
    $options = get_option( 'mt_options' );
    $value = $options['website_language_code'];
    // echo the field
    echo "<input id='mt_website_language_code' name='mt_options[website_language_code]' type='text' value='$value' />";
}

// Display and fill the translation language form field
function mt_setting_translation_language_code() {
    // get option 'translation_language_code' value from the database
    $options = get_option( 'mt_options' );
    $value = $options['translation_language_code'];
    // echo the field
    echo "<input id='mt_translation_language_code' name='mt_options[translation_language_code]' type='text' value='$value' />";
}

function mt_setting_api_key() {
    // get option 'api_key' value from the database
    $options = get_option( 'mt_options' );
    $value = $options['api_key'];
    // echo the field
    echo "<input id='mt_api_key' name='mt_options[api_key]' type='text' value='$value' />";
}

// Validate user input
function mt_validate_options($input) {
// List of supported languages by Google Cloud Translation API
$valid_language_codes = array("af", "sq", "am", "ar", "hy", "as", "ay", "az", "bm", "eu", "be", "bn", "bho", "bs", "bg", "ca", "ceb", "zh-CN", "zh-TW", "co", "hr", "cs", "da", "dv", "doi", "nl", "en", "eo", "et", "ee", "fil", "fi", "fr", "fy", "gl", "ka", "de", "el", "gn", "gu", "ht", "ha", "haw", "he", "hi", "hmn", "hu", "is", "ig", "ilo", "id", "ga", "it", "ja", "jv", "kn", "kk", "km", "rw", "gom", "ko", "kri", "ku", "ckb", "ky", "lo", "la", "lv", "ln", "lt", "lg", "lb", "mk", "mai", "mg", "ms", "ml", "mt", "mi", "mr", "mni-Mtei", "lus", "mn", "my", "ne", "no", "ny", "or", "om", "ps", "fa", "pl", "pt", "pa", "qu", "ro", "ru", "sm", "sa", "gd", "nso", "sr", "st", "sn", "sd", "si", "sk", "sl", "so", "es", "su", "sw", "sv", "tl", "tg", "ta", "tt", "te", "th", "ti", "ts", "tr", "tk", "ak", "uk", "ur", "ug", "uz", "vi", "cy", "xh", "yi", "yo", "zu");

    // Check if the language code is in the list of supported languages
    if (!in_array($input['website_language_code'], $valid_language_codes)) {
        add_settings_error('mt_options', 'mt_invalid_website_language_code', 'Invalid website language code.');
    }
    if (!in_array($input['translation_language_code'], $valid_language_codes)) {
        add_settings_error('mt_options', 'mt_invalid_translation_language_code', 'Invalid translation language code.');
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

// Detect language code uk or ru on first 50 words of a post
function detectLanguage($text) {
    // Обмежуємо текст до перших 50 слів
    $words = explode(' ', $text);
    $text = implode(' ', array_slice($words, 0, 50));

    // Видаляємо HTML з тексту
    $text = wp_strip_all_tags($text);

    // Набір унікальних слів для кожної мови
    $ukrainianWords = array('і', 'ї', 'є', 'ґ');
    $russianWords = array('ы', 'э', 'ё', 'й');
    $ukCount = 0;
    $ruCount = 0;

    // Перевіряємо кількість унікальних слів для кожної мови в тексті
    foreach ($ukrainianWords as $word) {
        $ukCount += substr_count($text, $word);
    }

    foreach ($russianWords as $word) {
        $ruCount += substr_count($text, $word);
    }

    // Повертаємо код мови в залежності від того, яка мова має більшу кількість унікальних слів
    if ($ukCount > $ruCount) {
        return 'uk';
    } else {
        return 'ru';
    }
}

// lets analyze how many posts we have and their language
Зрозуміло, ви хочете видалити логування в файл і замість цього виводити повідомлення через AJAX. Ось оновлений код:

PHP
Код, створений штучним інтелектом. Перевіряйте та використовуйте обережно. Додаткові відомості про запитання й відповіді.

// lets analyze how many posts we have and their language
function analysePosts() {
    global $wpdb;

    // Створюємо нову таблицю
    $table_name = $wpdb->prefix . 'sgct_analysed_posts';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        post_id mediumint(9) NOT NULL,
        meta_key varchar(255) DEFAULT '' NOT NULL,
        language_code varchar(2) DEFAULT '' NOT NULL,
        PRIMARY KEY  (post_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Отримуємо перші 100 статей
    $posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'post' LIMIT 100");

    $start_time = microtime(true);

    foreach ($posts as $post) {
        // Визначаємо мову статті
        $language_code = detectLanguage($post->post_content);

        // Отримуємо meta_key для цієї статті
        $meta_keys = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->prefix}postmeta WHERE post_id = %d", $post->ID));

        foreach ($meta_keys as $meta_key) {
            // Додаємо дані до нової таблиці
            $wpdb->insert(
                $table_name,
                array(
                    'post_id' => $post->ID,
                    'meta_key' => $meta_key,
                    'language_code' => $language_code
                )
            );
        }
    }

    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;

    // Повертаємо результати через AJAX
    echo "Аналіз завершено. Оброблено " . count($posts) . " статей. Витрачено часу " . $execution_time . " секунд.";
    wp_die(); // це потрібно, щоб уникнути повернення 0 в кінці відповіді AJAX
}

add_action('wp_ajax_analysePosts', 'analysePosts');

// Додаємо JavaScript для обробки натискання кнопки
echo '
<script type="text/javascript">
document.getElementById("analysePostsButton").addEventListener("click", function(e) {
    e.preventDefault();
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "' . admin_url('admin-ajax.php') . '", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
            alert(this.responseText);
        }
    }
    xhr.send("action=analysePosts");
});
</script>
';

?>