<?php
/*
Plugin Name: Simple Google Cloud Translation Plugin
Description: A simple plugin to translate posts using Google Cloud Translation API
Version: 0.22
Author: AntheZ
*/

register_activation_hook(__FILE__, 'mt_activate');
function mt_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "DROP TABLE IF EXISTS {$wpdb->prefix}sgct_trans_posts;
            DROP TABLE IF EXISTS {$wpdb->prefix}sgct_bak_posts;
            DROP TABLE IF EXISTS {$wpdb->prefix}sgct_analysed_posts;
            CREATE TABLE {$wpdb->prefix}sgct_trans_posts LIKE {$wpdb->prefix}posts;
            CREATE TABLE {$wpdb->prefix}sgct_bak_posts LIKE {$wpdb->prefix}posts;
            CREATE TABLE {$wpdb->prefix}sgct_analysed_posts (
                post_id mediumint(9) NOT NULL,
                post_title text NOT NULL,
                language_code varchar(2) DEFAULT '' NOT NULL,
                PRIMARY KEY  (post_id)
            ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Add a new submenu under Settings
add_action('admin_menu', 'mt_add_pages');
function mt_add_pages() {
    add_options_page(__('Simple GC Translator','menu-test'), __('Simple GC Translator','menu-test'), 'manage_options', 'translationhandle', 'mt_settings_page');
}

function mt_settings_page() {
    echo "<h2>" . __( 'SGC Translation Settings', 'menu-test' ) . "</h2>";
    echo '<form action="options.php" method="post">';
    settings_fields('mt_options');
    do_settings_sections('translationhandle');
    echo '</form>';
}

// Register and define the settings
add_action('admin_init', 'mt_admin_init');
function mt_admin_init(){
    register_setting( 'mt_options', 'mt_options', 'mt_validate_options', 'mt_batch_size' );
    add_settings_section('mt_main', 'Main Settings', 'mt_section_text', 'translationhandle');
    add_settings_field('mt_api_key', 'API Key', 'mt_setting_api_key', 'translationhandle', 'mt_main');
    add_settings_section('mt_main_analyse', 'Additional Settings', 'mt_section_text', 'translationhandle');
    add_settings_field('mt_batch_size', 'Batch Size', 'mt_batch_size_input', 'translationhandle', 'mt_main_analyse');
    add_settings_field('mt_analyse_button', 'Analyse Posts', 'pluginSettingsPage', 'translationhandle', 'mt_main_analyse');
    add_settings_section('mt_main_translate', 'Translation Settings', 'mt_section_text', 'translationhandle');
    add_settings_field('mt_website_language_code', 'Website Language Code', 'mt_setting_website_language_code', 'translationhandle', 'mt_main_translate');
    add_settings_field('mt_translation_language_code', 'Translation Language Code', 'mt_setting_translation_language_code', 'translationhandle', 'mt_main_translate');
    add_settings_field('mt_translate_button', 'Translate Posts', 'mt_setting_translate_button', 'translationhandle', 'mt_main_translate');
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
    echo '<p>Тут колись буде опис</p>';
}

// Функція для відображення поля вводу для налаштування 'mt_batch_size'
function mt_batch_size_input() {
    // Отримання поточного значення налаштування 'mt_batch_size'
    $batch_size = get_option('mt_batch_size', 100); // 100 - значення за замовчуванням

    // Виведення поля вводу
    echo "<input id='mt_batch_size' name='mt_batch_size' type='number' value='" . esc_attr($batch_size) . "' />";
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
                alert(this.responseText);
            }
        }
        xhr.send("action=analysePosts");
    });
    </script>
    ';
}

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
add_action('wp_ajax_translatePosts', 'translate_posts');
function translate_posts() {
    global $wpdb;
    // Отримуємо мову перекладу та мову веб-сайту з налаштувань
    $options = get_option( 'mt_options' );
    $translation_language_code = $options['translation_language_code'];
    $website_language_code = $options['website_language_code'];
    $api_key = $options['api_key'];

    // Отримуємо всі статті з таблиці sgct_analysed_posts, які мають language_code, що відповідає мові веб-сайту
    $posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sgct_analysed_posts WHERE language_code = '$website_language_code'");

    foreach ($posts as $post) {
        // Отримуємо оригінальну статтю з таблиці wp_posts
        $original_post = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}posts WHERE ID = {$post->post_id}");

        // Перекладаємо заголовок статті за допомогою Google Cloud Translation API
        $translated_title = translate_text($original_post->post_title, $translation_language_code, $website_language_code, $api_key);

        // Копіюємо всі поля (крім post_title) в таблицю sgct_trans_posts
        $wpdb->insert(
            "{$wpdb->prefix}sgct_trans_posts",
            array(
                'ID' => $original_post->ID,
                'post_author' => $original_post->post_author,
                'post_date' => $original_post->post_date,
                'post_date_gmt' => $original_post->post_date_gmt,
                'post_content' => $original_post->post_content,
                'post_title' => $translated_title, // використовуємо перекладений заголовок
                'post_excerpt' => $original_post->post_excerpt,
                'post_status' => $original_post->post_status,
                'comment_status' => $original_post->comment_status,
                'ping_status' => $original_post->ping_status,
                'post_password' => $original_post->post_password,
                'post_name' => $original_post->post_name,
                'to_ping' => $original_post->to_ping,
                'pinged' => $original_post->pinged,
                'post_modified' => $original_post->post_modified,
                'post_modified_gmt' => $original_post->post_modified_gmt,
                'post_content_filtered' => $original_post->post_content_filtered,
                'post_parent' => $original_post->post_parent,
                'guid' => $original_post->guid,
                'menu_order' => $original_post->menu_order,
                'post_type' => $original_post->post_type,
                'post_mime_type' => $original_post->post_mime_type,
                'comment_count' => $original_post->comment_count
            )
        );
    }
}

function translate_text($text, $target_language, $website_language, $api_key) {
    $url = "https://translation.googleapis.com/language/translate/v2?key=$api_key&q=" . urlencode($text) . "&source=$website_language&target=$target_language";
    $response = wp_remote_get($url);
    if ( is_wp_error( $response ) ) {
        return $text; // якщо є помилка, повертаємо оригінальний текст
    }
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    return $response_body['data']['translations'][0]['translatedText'];
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

// Давайте проаналізуємо скільки саме у нас статей, та їх мову. Але зробимо це безпечно пачками по Х штук
add_action('wp_ajax_analysePosts', 'analysePosts');
function analysePosts() {
    global $wpdb;
    // Встановлюємо кількість статей для обробки за один раз. 
    $batch_size = get_option('mt_batch_size', 100); // 100 - значення за замовчуванням
    // Отримуємо кількість всіх опублікованих статей
    $total_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'post' AND post_status = 'publish'");
    // Розраховуємо кількість партій
    $batches = ceil($total_posts / $batch_size);
    $start_time = microtime(true);

    for ($i = 0; $i < $batches; $i++) {
        // Обчислюємо зсув для поточної партії
        $offset = $i * $batch_size;

        // Отримуємо статті для поточної партії
        $posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'post' AND post_status = 'publish' LIMIT $offset, $batch_size");

        foreach ($posts as $post) {
            // Визначаємо мову статті
            $language_code = detectLanguage($post->post_content);

            // Додаємо дані до нової таблиці
            $wpdb->insert(
                "{$wpdb->prefix}sgct_analysed_posts",
                array(
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'language_code' => $language_code
                )
            );
        }
    }

    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;

    // Повертаємо результати через AJAX
    echo "Аналіз завершено. Оброблено " . $total_posts . " статей. Витрачено часу " . $execution_time . " секунд.";
    wp_die(); // це потрібно, щоб уникнути повернення 0 в кінці відповіді AJAX
}

function mt_setting_translate_button() {
    // Перевіряємо, чи була натиснута кнопка
    if (isset($_POST['translate_posts'])) {
        // Виконуємо функцію translate_posts
        $start_time = microtime(true);
        translate_posts();
        $end_time = microtime(true);
        $execution_time = $end_time - $start_time;
        echo '<div class="updated"><p>Було перекладено ' . count($posts) . ' заголовків статей. Витрачено часу ' . $execution_time . ' секунд.</p></div>';
    }

    // Виводимо форму
    echo '<div class="wrap">';
    echo '<form id="translatePostsForm" method="post">';
    echo '<input type="button" id="translatePostsButton" name="translate_posts" class="button button-primary" value="Запустити перекладач" />';
    echo '</form>';
    echo '</div>';

    // Додаємо JavaScript для обробки натискання кнопки
    echo '
    <script type="text/javascript">
    document.getElementById("translatePostsButton").addEventListener("click", function(e) {
        e.preventDefault();
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "' . admin_url('admin-ajax.php') . '", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function() {
            if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                alert(this.responseText);
            }
        }
        xhr.send("action=translatePosts");
    });
    </script>
    ';
}

?>