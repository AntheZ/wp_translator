<?php

class Gemini_Translator_Admin {

    private $plugin_name;
    private $version;
    private $options;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_translate_meta_box' ) );
        add_action( 'wp_ajax_gemini_translate_post', array( $this, 'handle_translation_request' ) );
        add_action( 'wp_ajax_gemini_save_translated_post', array( $this, 'handle_save_translated_post' ) );
    }

    public function add_plugin_admin_menu() {
        // Main settings page
        add_options_page(
            'Gemini Post Translator Settings',
            'Gemini Translator',
            'manage_options',
            $this->plugin_name,
            array( $this, 'render_settings_page' )
        );

        // Batch processing page under "Tools"
        add_management_page(
            'Batch Translator',
            'Batch Translator',
            'manage_options',
            $this->plugin_name . '-batch',
            array( $this, 'render_batch_page' )
        );
    }

    public function render_batch_page() {
        require_once plugin_dir_path( __FILE__ ) . 'class-gemini-translator-posts-list-table.php';
        $list_table = new Gemini_Translator_Posts_List_Table();
        $list_table->prepare_items();
        ?>
        <div class="wrap">
            <h2>Batch Translator</h2>
            <p>Select posts from the list below and click the button to start the translation process.</p>
            
            <form id="posts-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']) ?>" />
                <?php
                $list_table->display();
                ?>
            </form>
            
            <div id="batch-controls" style="margin-top: 20px;">
                <button class="button button-primary" id="start-batch-translation">Translate Selected</button>
                <div id="batch-progress" style="margin-top: 10px; display: none;">
                    <div id="batch-progress-bar" style="width: 0%; height: 20px; background-color: #4CAF50;"></div>
                    <p id="batch-progress-status"></p>
                </div>
                <div id="batch-log" style="margin-top: 10px; height: 200px; overflow-y: scroll; border: 1px solid #ccc; padding: 5px; background: #f7f7f7;"></div>
            </div>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#start-batch-translation').on('click', function() {
                var postIds = [];
                $('input[name="post[]"]:checked').each(function() {
                    postIds.push($(this).val());
                });

                if (postIds.length === 0) {
                    alert('Please select at least one post to translate.');
                    return;
                }

                var button = $(this);
                button.prop('disabled', true);
                $('#batch-progress').show();
                $('#batch-log').html('');

                var totalPosts = postIds.length;
                var processedCount = 0;

                function processNextPost() {
                    if (postIds.length === 0) {
                        button.prop('disabled', false);
                        $('#batch-progress-status').text('Batch processing complete!');
                        return;
                    }

                    var postId = postIds.shift();
                    processedCount++;
                    
                    var progress = (processedCount / totalPosts) * 100;
                    $('#batch-progress-bar').css('width', progress + '%');
                    $('#batch-progress-status').text('Processing ' + processedCount + ' of ' + totalPosts + '... (Post ID: ' + postId + ')');
                    
                    var row = $('input[value="' + postId + '"]').closest('tr');
                    row.css('background-color', '#f3f3f3');


                    // We can re-use the same AJAX action
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gemini_translate_post', // The one that returns data
                            post_id: postId,
                            nonce: $('#gemini_translator_nonce').val() // We need to add this nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                if (response.data.status === 'already_in_target_language') {
                                    logMessage('Post ' + postId + ': Skipped (already in target language).', 'blue');
                                    saveOrSkip(postId, null, processNextPost);
                                } else {
                                    logMessage('Post ' + postId + ': Translated successfully. Saving...', 'green');
                                    saveOrSkip(postId, response.data, processNextPost);
                                }
                            } else {
                                logMessage('Post ' + postId + ': Translation failed. ' + response.data.message, 'red');
                                processNextPost(); // Move to the next one
                            }
                        },
                        error: function() {
                            logMessage('Post ' + postId + ': An unknown error occurred during translation.', 'red');
                            processNextPost();
                        }
                    });
                }
                
                function saveOrSkip(postId, translationData, callback) {
                    if (translationData === null) {
                        // Just a formality to keep the loop going
                        callback();
                        return;
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gemini_save_translated_post',
                            post_id: postId,
                            nonce: $('#gemini_save_post_nonce').val(), // And this one
                            title: translationData.translated_title,
                            content: translationData.translated_content,
                            meta_description: translationData.meta_description,
                            meta_keywords: translationData.meta_keywords
                        },
                        success: function(saveResponse) {
                            if (saveResponse.success) {
                                logMessage('Post ' + postId + ': Saved successfully.', 'green');
                            } else {
                                logMessage('Post ' + postId + ': Save failed. ' + saveResponse.data.message, 'red');
                            }
                            callback();
                        },
                        error: function() {
                            logMessage('Post ' + postId + ': An unknown error occurred during saving.', 'red');
                            callback();
                        }
                    });
                }

                function logMessage(message, color) {
                    $('#batch-log').append('<p style="color:' + color + ';">' + message + '</p>').scrollTop($('#batch-log')[0].scrollHeight);
                }

                processNextPost();
            });
        });
        </script>
        <?php
        // We need to output the nonces for the JavaScript to use
        wp_nonce_field( 'gemini_translate_post', 'gemini_translator_nonce', false );
        wp_nonce_field( 'gemini_save_post_nonce', 'gemini_save_post_nonce', false );
    }

    public function render_settings_page() {
        // Handle clear log action
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'clear_log' ) {
            if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_gemini_log_nonce' ) ) {
                $this->clear_log_file();
                // Redirect to avoid re-triggering on refresh
                wp_safe_redirect( admin_url( 'options-general.php?page=' . $this->plugin_name . '&tab=logs&log_cleared=true' ) );
                exit;
            }
        }
        if ( isset( $_GET['log_cleared'] ) && $_GET['log_cleared'] === 'true' ) {
             add_settings_error('gemini-translator-notices', 'log-cleared', 'Log file has been cleared.', 'updated');
        }


        $this->options = get_option( 'gemini_translator_options' );
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
        ?>
        <div class="wrap">
            <h2>Gemini Post Translator</h2>
            <?php settings_errors('gemini-translator-notices'); ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr($this->plugin_name); ?>&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=<?php echo esc_attr($this->plugin_name); ?>&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
            </h2>

            <?php if ( $active_tab == 'settings' ) : ?>
                <form method="post" action="options.php">
                    <?php
                        settings_fields( 'gemini_translator_option_group' );
                        do_settings_sections( $this->plugin_name );
                        submit_button();
                    ?>
                </form>
            <?php else : ?>
                <div class="card">
                    <h3>Debug Log</h3>
                    <p>This log shows the requests sent to the Gemini API and the responses received. Enable logging in the Settings tab.</p>
                    <?php $this->render_log_viewer(); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_log_viewer() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/gemini-translator-logs/debug.log';
        $log_content = 'Log file not found. Make sure logging is enabled and a translation has been attempted.';

        if ( file_exists( $log_file ) ) {
            $log_content = file_get_contents( $log_file );
            if ( empty( $log_content ) ) {
                $log_content = 'Log file is empty.';
            }
        }
        
        echo '<textarea readonly style="width: 100%; height: 500px; background: #fff; white-space: pre; font-family: monospace;">' . esc_textarea( $log_content ) . '</textarea>';
        
        $clear_log_url = wp_nonce_url( admin_url( 'options-general.php?page=' . $this->plugin_name . '&action=clear_log' ), 'clear_gemini_log_nonce' );
        echo '<p><a href="' . esc_url( $clear_log_url ) . '" class="button button-danger">Clear Log</a></p>';
    }

    public function register_settings() {
        register_setting(
            'gemini_translator_option_group', // Option group
            'gemini_translator_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'setting_section_id', // ID
            'API Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            $this->plugin_name // Page
        );

        add_settings_field(
            'api_key', // ID
            'Gemini API Key', // Title
            array( $this, 'api_key_callback' ), // Callback
            $this->plugin_name, // Page
            'setting_section_id' // Section
        );

        add_settings_field(
            'target_language', // ID
            'Target Language', // Title
            array( $this, 'target_language_callback' ), // Callback
            $this->plugin_name, // Page
            'setting_section_id' // Section
        );

        add_settings_field(
            'enable_logging',
            'Enable Logging',
            array( $this, 'enable_logging_callback' ),
            $this->plugin_name,
            'setting_section_id'
        );
    }

    public function sanitize( $input ) {
        $new_input = array();
        if( isset( $input['api_key'] ) ) {
            $new_input['api_key'] = sanitize_text_field( $input['api_key'] );
        }
        
        if( isset( $input['target_language'] ) ) {
            $new_input['target_language'] = sanitize_text_field( $input['target_language'] );
        }
        
        if ( isset( $input['enable_logging'] ) ) {
            $new_input['enable_logging'] = absint( $input['enable_logging'] );
        } else {
            $new_input['enable_logging'] = 0;
        }

        return $new_input;
    }

    public function print_section_info() {
        print 'Enter your Gemini API settings below:';
    }

    public function api_key_callback() {
        printf(
            '<input type="text" id="api_key" name="gemini_translator_options[api_key]" value="%s" size="50" />',
            isset( $this->options['api_key'] ) ? esc_attr( $this->options['api_key']) : ''
        );
    }

    public function target_language_callback() {
        $value = isset( $this->options['target_language'] ) ? esc_attr( $this->options['target_language']) : 'Ukrainian';
        printf(
            '<input type="text" id="target_language" name="gemini_translator_options[target_language]" value="%s" size="50" />',
            $value
        );
        echo '<p class="description">The language to translate the posts into. Default is Ukrainian.</p>';
    }

    public function enable_logging_callback() {
        $this->options = get_option('gemini_translator_options');
        $value = isset( $this->options['enable_logging'] ) ? $this->options['enable_logging'] : 0;
        echo '<input type="checkbox" id="enable_logging" name="gemini_translator_options[enable_logging]" value="1" ' . checked( 1, $value, false ) . ' />';
        echo '<label for="enable_logging">Log API requests and responses for debugging purposes.</label>';
        echo '<p class="description">Logs will be saved in <code>wp-content/uploads/gemini-translator-logs/debug.log</code> and can be viewed in the "Logs" tab.</p>';
    }

    private function clear_log_file() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/gemini-translator-logs/debug.log';
        if ( file_exists( $log_file ) ) {
            file_put_contents( $log_file, '' );
        }
    }

    private function log_message( $message ) {
        $options = get_option('gemini_translator_options');
        if ( ! isset( $options['enable_logging'] ) || ! $options['enable_logging'] ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/gemini-translator-logs';

        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $log_file = $log_dir . '/debug.log';
        $formatted_message = '[' . date('Y-m-d H:i:s') . '] - ' . ( is_array( $message ) || is_object( $message ) ? json_encode( $message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : $message ) . "\n\n";

        file_put_contents( $log_file, $formatted_message, FILE_APPEND );
    }

    public function handle_translation_request() {
        check_ajax_referer('gemini_translate_post', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        $this->log_message("--- New Translation Request for Post ID: {$post_id} ---");

        if (empty($post_id)) {
            $this->log_message("Error: Post ID is missing.");
            wp_send_json_error(['message' => 'Post ID is missing.']);
        }

        $options = get_option('gemini_translator_options');
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        $target_language = isset($options['target_language']) ? $options['target_language'] : 'Ukrainian';

        if (empty($api_key)) {
            $this->log_message("Error: Gemini API Key is not set.");
            wp_send_json_error(['message' => 'Gemini API Key is not set.']);
        }

        $post = get_post($post_id);
        if (!$post) {
            $this->log_message("Error: Could not retrieve post with ID: {$post_id}");
            wp_send_json_error(['message' => 'Could not retrieve post.']);
        }
        
        $title_to_translate = $post->post_title;
        $content_to_translate = $post->post_content;

        // A single, comprehensive prompt for the entire process
        $prompt = "You are an expert content localizer and SEO specialist. Your task is to process the following blog post.\n\n";
        $prompt .= "The target language is: {$target_language}\n\n";
        $prompt .= "Perform the following steps:\n";
        $prompt .= "1.  First, accurately detect the primary language of the provided text (title and content).\n";
        $prompt .= "2.  If the detected language is already the same as the target language ('{$target_language}'), you MUST stop and respond with only the following JSON object: {\"status\": \"already_in_target_language\"}.\n";
        $prompt .= "3.  If the language is different, proceed with a full translation and enhancement.\n";
        $prompt .= "4.  Translate the title and content to {$target_language}. Correct any grammatical or stylistic errors. Improve the overall readability and flow, while preserving the original meaning and any HTML formatting.\n";
        $prompt .= "5.  Based on the final translated text, generate an SEO-optimized meta description (around 155-160 characters) and a comma-separated list of 5-7 relevant meta keywords.\n\n";
        $prompt .= "Your final output MUST be a single, valid JSON object. If translating, it must contain the keys 'translated_title', 'translated_content', 'meta_description', and 'meta_keywords'. Do not add any extra text or explanations outside of the JSON structure.\n\n";
        $prompt .= "--- TEXT TO PROCESS ---\n";
        $prompt .= "Title: " . $title_to_translate . "\n\n";
        $prompt .= "Content:\n" . $content_to_translate;

        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
        
        $request_body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['response_mime_type' => 'application/json']
        ];
        
        $this->log_message("Request Body Sent to API:");
        $this->log_message($request_body);
        
        $response = wp_remote_post($api_url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode($request_body),
            'timeout'   => 180, // Increased timeout for large articles
        ]);

        $this->log_message("Raw Response from API:");
        $this->log_message(wp_remote_retrieve_body($response));

        if (is_wp_error($response)) {
            $error_message = 'API request failed: ' . $response->get_error_message();
            $this->log_message("Error: {$error_message}");
            wp_send_json_error(['message' => $error_message]);
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        $translated_text_part = $response_data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (empty($translated_text_part)) {
            $error_message = 'Failed to get content from API response.';
            $this->log_message("Error: {$error_message}");
            $this->log_message($response_data);
            wp_send_json_error(['message' => $error_message, 'response' => $response_data]);
        }

        $translated_data = json_decode($translated_text_part, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Failed to decode JSON from API content. Error: ' . json_last_error_msg();
            $this->log_message("Error: {$error_message}");
            wp_send_json_error(['message' => $error_message, 'response' => $translated_text_part]);
        }

        // Instead of updating the post, we send the data back to the browser.
        wp_send_json_success($translated_data);
    }

    public function handle_save_translated_post() {
        check_ajax_referer('gemini_save_post_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $new_title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $new_content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';
        $meta_description = isset($_POST['meta_description']) ? sanitize_text_field($_POST['meta_description']) : '';
        $meta_keywords = isset($_POST['meta_keywords']) ? sanitize_text_field($_POST['meta_keywords']) : '';

        if (empty($post_id) || empty($new_title) || empty($new_content)) {
            wp_send_json_error(['message' => 'Missing required data to save.']);
        }

        $this->log_message("--- Saving confirmed translation for Post ID: {$post_id} ---");

        $updated_post_id = wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title,
            'post_content' => $new_content,
        ], true);

        if (is_wp_error($updated_post_id)) {
            $error_message = 'Failed to update post: ' . $updated_post_id->get_error_message();
            $this->log_message("Error: {$error_message}");
            wp_send_json_error(['message' => $error_message]);
        }
        
        if (!empty($meta_description)) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            update_post_meta($post_id, '_aioseo_description', $meta_description);
        }
        if (!empty($meta_keywords)) {
            update_post_meta($post_id, '_aioseo_keywords', $meta_keywords);
        }

        $this->log_message("Successfully saved post {$post_id}.");
        wp_send_json_success(['message' => 'Post updated successfully!']);
    }

    public function add_translate_meta_box() {
        add_meta_box(
            'gemini_translator_meta_box',
            'Gemini Translator',
            array( $this, 'render_translate_meta_box' ),
            'post', // Add to posts
            'side', // Position
            'high' // Priority
        );

        // Add a placeholder for the modal window
        add_action( 'admin_footer', array( $this, 'render_preview_modal' ) );
    }

    public function render_preview_modal() {
        // This will be populated by JavaScript
        ?>
        <div id="gemini-preview-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; width: 80%; max-width: 1200px; height: 90%; overflow-y: scroll;">
                <h2>Translation Preview</h2>
                <div style="display: flex; gap: 20px;">
                    <div style="width: 50%;">
                        <h3>Original</h3>
                        <div id="gemini-original-content" style="border: 1px solid #ccc; padding: 10px; height: 500px; overflow-y: auto;"></div>
                    </div>
                    <div style="width: 50%;">
                        <h3>Translated</h3>
                        <div id="gemini-translated-content" style="border: 1px solid #ccc; padding: 10px; height: 500px; overflow-y: auto;"></div>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <button type="button" id="gemini-save-changes" class="button button-primary">Confirm & Save</button>
                    <button type="button" id="gemini-cancel-preview" class="button">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_translate_meta_box( $post ) {
        // Add a nonce field so we can check for it later.
        wp_nonce_field( 'gemini_translate_post', 'gemini_translator_nonce' );
        wp_nonce_field( 'gemini_save_post_nonce', 'gemini_save_post_nonce' );
        
        echo '<p>Click the button below to translate this post with Gemini.</p>';
        echo '<button type="button" id="gemini-translate-button" class="button button-primary">Translate with Gemini</button>';
        echo '<span id="gemini-spinner" class="spinner" style="float:none; margin-left: 5px;"></span>';
        echo '<div id="gemini-translation-status" style="margin-top:10px;"></div>';

        // Add JavaScript to handle the click event
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var currentPostId = <?php echo $post->ID; ?>;
            var originalTitle = '';
            var originalContent = '';

            // Handle Translate button click
            $('#gemini-translate-button').on('click', function() {
                var button = $(this);
                var spinner = $('#gemini-spinner');
                var status = $('#gemini-translation-status');
                
                originalTitle = $('#title').val(); 
                originalContent = (typeof tinymce !== 'undefined' && tinymce.get('content')) ? tinymce.get('content').getContent() : $('#content').val();

                button.prop('disabled', true);
                spinner.addClass('is-active');
                status.text('Translating...').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gemini_translate_post',
                        post_id: currentPostId,
                        nonce: $('#gemini_translator_nonce').val()
                    },
                    success: function(response) {
                        if(response.success) {
                            if (response.data.status === 'already_in_target_language') {
                                status.text('Post is already in the target language.');
                                button.prop('disabled', false);
                            } else {
                                // Populate and show the modal
                                $('#gemini-original-content').html('<h4>' + originalTitle + '</h4>' + originalContent);
                                
                                var translatedHtml = '<h4>' + response.data.translated_title + '</h4>' + response.data.translated_content;
                                $('#gemini-translated-content').html(translatedHtml);

                                // Store data for saving
                                $('#gemini-save-changes').data('translation', response.data);

                                status.hide();
                                $('#gemini-preview-modal').show();
                            }
                        } else {
                            status.text('Error: ' + response.data.message);
                            button.prop('disabled', false);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        status.text('AJAX error: ' + textStatus);
                        button.prop('disabled', false);
                    },
                    complete: function() {
                        spinner.removeClass('is-active');
                    }
                });
            });

            // Handle Save Changes button in modal
            $('#gemini-save-changes').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Saving...');
                var translationData = button.data('translation');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gemini_save_translated_post',
                        post_id: currentPostId,
                        nonce: $('#gemini_save_post_nonce').val(),
                        title: translationData.translated_title,
                        content: translationData.translated_content,
                        meta_description: translationData.meta_description,
                        meta_keywords: translationData.meta_keywords
                    },
                    success: function(response) {
                        if(response.success) {
                            // Reload the page to see the saved changes
                            location.reload();
                        } else {
                            alert('Error saving post: ' + response.data.message);
                            button.prop('disabled', false).text('Confirm & Save');
                        }
                    },
                    error: function() {
                        alert('An unexpected error occurred while saving.');
                        button.prop('disabled', false).text('Confirm & Save');
                    }
                });
            });

            // Handle Cancel button in modal
            $('#gemini-cancel-preview').on('click', function() {
                $('#gemini-preview-modal').hide();
                $('#gemini-translate-button').prop('disabled', false);
            });
        });
        </script>
        <?php
    }
} 