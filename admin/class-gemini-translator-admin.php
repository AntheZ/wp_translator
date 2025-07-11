<?php

class Gemini_Translator_Admin {

    private $plugin_name;
    private $version;
    private $options;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        
        // Register AJAX hooks
        add_action( 'wp_ajax_gemini_translate_post', array( $this, 'handle_translation_request' ) );
        add_action( 'wp_ajax_gemini_approve_translation', array( $this, 'handle_approve_translation' ) );
        add_action( 'wp_ajax_gemini_restore_original', array( $this, 'handle_restore_original' ) );
        add_action( 'wp_ajax_gemini_get_review_data', array( $this, 'handle_get_review_data' ) );

        // Disable the old meta box for now as it's not compatible with the new workflow
        // add_action( 'add_meta_boxes', array( $this, 'add_translate_meta_box' ) );
    }

    public function add_plugin_admin_menu() {
        // Add top-level menu
        add_menu_page(
            'Gemini Translator', // Page title
            'Gemini Translator', // Menu title
            'manage_options',    // Capability
            'gemini-translator', // Menu slug
            array( $this, 'render_batch_page' ), // This function will render the dashboard
            'dashicons-translation', // Icon
            25 // Position
        );

        // Add Dashboard submenu page
        add_submenu_page(
            'gemini-translator', // Parent slug
            'Translation Dashboard', // Page title
            'Translation Dashboard',// Menu title
            'manage_options',    // Capability
            'gemini-translator', // Use parent slug to make it the default page
            array( $this, 'render_batch_page' ) // Function
        );

        // Add Settings submenu page
        add_submenu_page(
            'gemini-translator', // Parent slug
            'Gemini Translator Settings', // Page title
            'Settings',          // Menu title
            'manage_options',    // Capability
            'gemini-translator-settings', // Menu slug
            array( $this, 'render_settings_page' ) // Function
        );
    }

    public function render_batch_page() {
        require_once plugin_dir_path( __FILE__ ) . 'class-gemini-translator-posts-list-table.php';

        $current_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';

        $list_table = new Gemini_Translator_Posts_List_Table();
        $list_table->prepare_items($current_status);
        ?>
        <div class="wrap">
            <h2>Translation Dashboard</h2>

            <?php
            // Display a notice if logging is disabled
            $this->options = get_option('gemini_translator_options');
            if ( ! isset( $this->options['enable_logging'] ) || ! $this->options['enable_logging'] ) {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo '<b>Note:</b> Debug logging is currently disabled. The "Full Log" will be empty. To enable it, go to ';
                echo '<a href="' . esc_url(admin_url('admin.php?page=gemini-translator-settings')) . '">Settings</a> and check "Enable debug logging".';
                echo '</p></div>';
            }
            ?>
            
            <ul class="subsubsub">
                <li><a href="admin.php?page=gemini-translator&status=all" class="<?php echo $current_status === 'all' ? 'current' : ''; ?>">All <span class="count">(<?php echo $list_table->get_status_count('all'); ?>)</span></a> |</li>
                <li><a href="admin.php?page=gemini-translator&status=untranslated" class="<?php echo $current_status === 'untranslated' ? 'current' : ''; ?>">Untranslated <span class="count">(<?php echo $list_table->get_status_count('untranslated'); ?>)</span></a> |</li>
                <li><a href="admin.php?page=gemini-translator&status=pending_review" class="<?php echo $current_status === 'pending_review' ? 'current' : ''; ?>">Pending Review <span class="count">(<?php echo $list_table->get_status_count('pending_review'); ?>)</span></a> |</li>
                <li><a href="admin.php?page=gemini-translator&status=completed" class="<?php echo $current_status === 'completed' ? 'current' : ''; ?>">Completed <span class="count">(<?php echo $list_table->get_status_count('completed'); ?>)</span></a></li>
            </ul>

            <form id="posts-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']) ?>" />
                <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>" />
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <?php
                        wp_dropdown_categories([
                            'show_option_all' => 'All Categories',
                            'taxonomy'        => 'category',
                            'name'            => 'cat',
                            'orderby'         => 'name',
                            'selected'        => isset($_REQUEST['cat']) ? (int)$_REQUEST['cat'] : 0,
                            'hierarchical'    => true,
                            'show_count'      => true,
                            'hide_empty'      => true,
                        ]);
                        submit_button('Filter', 'button', 'filter_action', false);
                        ?>
                    </div>
                </div>

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
            </div>
            
            <div id="translation-log-container" style="margin-top: 20px; display: flex; gap: 20px;">
                <div id="live-log-container" style="flex: 1;">
                    <h3>Live Log</h3>
                    <div id="batch-log" style="height: 300px; overflow-y: scroll; border: 1px solid #ccc; padding: 10px; background: #f7f7f7; white-space: pre-wrap;"></div>
                </div>
                <div id="file-log-container" style="flex: 1;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3>Full Log (from debug.log)</h3>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=gemini-translator&action=clear_log'), 'clear_gemini_log_nonce')); ?>" class="button button-secondary">Clear Full Log</a>
                    </div>
                    <?php $this->render_log_viewer(); ?>
                </div>
            </div>
        </div>

        <!-- Review Modal Structure -->
        <div id="gemini-review-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 90%; max-width: 1200px; background: #fff; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
                <h2 style="margin-top:0;">Review Translation</h2>
                <div style="display: flex; gap: 20px;">
                    <div style="flex: 1;">
                        <h3>Original</h3>
                        <h4 id="review-original-title"></h4>
                        <div id="review-original-content" style="height: 50vh; overflow-y: scroll; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;"></div>
                    </div>
                    <div style="flex: 1;">
                        <h3>Translated</h3>
                        <h4 id="review-translated-title"></h4>
                        <div id="review-translated-content" style="height: 50vh; overflow-y: scroll; border: 1px solid #ddd; padding: 10px;"></div>
                    </div>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button class="button" id="review-cancel">Cancel</button>
                    <button class="button button-primary" id="review-approve">Approve & Save</button>
                    <input type="hidden" id="review-post-id" value="">
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            
            // --- Batch Translation Logic ---
            $('#start-batch-translation').on('click', function(e) {
                e.preventDefault();
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
                var batch_delay = <?php echo (int) ( get_option('gemini_translator_options')['batch_delay'] ?? 6 ); ?> * 1000;

                function processNextPost() {
                    if (postIds.length === 0) {
                        logMessage('--------------------------------------------------', 'blue');
                        logMessage('Batch processing complete! Please refresh the page to see status updates.', 'blue');
                        logMessage('--------------------------------------------------', 'blue');
                        button.prop('disabled', false);
                        $('#batch-progress-status').text('Batch processing complete!');
                        return;
                    }

                    var postId = postIds.shift();
                    processedCount++;
                    
                    var progress = (processedCount / totalPosts) * 100;
                    $('#batch-progress-bar').css('width', progress + '%');
                    $('#batch-progress-status').text('Processing ' + processedCount + ' of ' + totalPosts + '... (Post ID: ' + postId + ')');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gemini_translate_post',
                            post_id: postId,
                            gemini_translator_nonce: $('#gemini_translator_nonce').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                if (response.data.status === 'already_in_target_language') {
                                    logMessage('Post ' + postId + ': Skipped (already in target language).', 'blue');
                                } else {
                                    logMessage('Post ' + postId + ': Translation submitted for review.', 'green');
                                }
                            } else {
                                logMessage('Post ' + postId + ': Translation failed. ' + response.data.message, 'red');
                            }
                            setTimeout(processNextPost, batch_delay);
                        },
                        error: function() {
                            logMessage('Post ' + postId + ': An unknown AJAX error occurred.', 'red');
                            setTimeout(processNextPost, batch_delay);
                        }
                    });
                }
                
                function logMessage(message, color) {
                    $('#batch-log').append('<p style="color:' + color + ';">' + message + '</p>').scrollTop($('#batch-log')[0].scrollHeight);
                }

                processNextPost();
            });

            // --- Row Action Logic (Review/Restore/Translate) ---
            $('tr').on('click', 'a.row-action', function(e) {
                e.preventDefault();
                
                var link = $(this);
                var url;
                try {
                    url = new URL(link.attr('href'));
                } catch (error) {
                    console.error("Invalid URL:", link.attr('href'));
                    return;
                }
                
                var action = url.searchParams.get('action');
                var postId = url.searchParams.get('post');
                var nonceKey = '_wpnonce';
                var nonce = url.searchParams.get(nonceKey);
                var ajaxAction = '';

                if (action === 'restore') {
                    if (!confirm('Are you sure you want to restore this post to its original version? This will delete the current translation.')) {
                        return;
                    }
                    ajaxAction = 'gemini_restore_original';
                    nonce = $('#gemini_restore_nonce').val();

                } else if (action === 'review') {
                    $('#review-post-id').val(postId);
                    // Fetch data for the modal
                     $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gemini_get_review_data',
                            post_id: postId,
                            gemini_get_review_nonce: $('#gemini_get_review_nonce').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#review-original-title').text(response.data.original.title);
                                $('#review-original-content').html(response.data.original.content);
                                $('#review-translated-title').text(response.data.translated.title);
                                $('#review-translated-content').html(response.data.translated.content);
                                $('#gemini-review-modal').show();
                            } else {
                                alert('Error: ' + response.data.message);
                            }
                        },
                        error: function() {
                            alert('An unknown AJAX error occurred while fetching review data.');
                        }
                    });
                    return; // Stop further execution for review action

                } else if (action === 'translate') {
                     if (!confirm('This will submit the post for translation. Are you sure?')) {
                        return;
                    }
                    ajaxAction = 'gemini_translate_post';
                    nonce = url.searchParams.get('_wpnonce');
                
                } else {
                    return; // Unknown action
                }
                
                 $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: ajaxAction,
                        post_id: postId,
                        _wpnonce: nonce // This needs to be flexible for different nonce keys
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An unknown AJAX error occurred.');
                    }
                });
            });

            // --- Modal Button Logic ---
            $('#review-cancel').on('click', function() {
                $('#gemini-review-modal').hide();
            });

            $('#review-approve').on('click', function() {
                var postId = $('#review-post-id').val();
                if (!postId) {
                    alert('Error: Post ID not found in modal.');
                    return;
                }
                 $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gemini_approve_translation',
                        post_id: postId,
                        gemini_approve_nonce: $('#gemini_approve_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#gemini-review-modal').hide();
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An unknown AJAX error occurred while approving.');
                    }
                });
            });

        });
        </script>
        <?php
        // We need to output the nonces for the JavaScript to use
        wp_nonce_field( 'gemini_translate_post', 'gemini_translator_nonce', false );
        wp_nonce_field( 'gemini_approve_translation', 'gemini_approve_nonce', false );
        wp_nonce_field( 'gemini_restore_original', 'gemini_restore_nonce', false );
        wp_nonce_field( 'gemini_get_review_data', 'gemini_get_review_nonce', false );
    }

    public function render_settings_page() {
        // Clear log action moved to the dashboard, but we keep the redirect handler
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'clear_log' ) {
            if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_gemini_log_nonce' ) ) {
                $this->clear_log_file();
                // Redirect to avoid re-triggering on refresh
                wp_safe_redirect( admin_url( 'admin.php?page=gemini-translator&log_cleared=true' ) );
                exit;
            }
        }
        if ( isset( $_GET['log_cleared'] ) && $_GET['log_cleared'] === 'true' ) {
             add_settings_error('gemini-translator-notices', 'log-cleared', 'Log file has been cleared.', 'updated');
        }


        $this->options = get_option( 'gemini_translator_options' );
        ?>
        <div class="wrap">
            <h2>Gemini Post Translator Settings</h2>
            <?php settings_errors('gemini-translator-notices'); ?>

            <form method="post" action="options.php">
                <?php
                    settings_fields( 'gemini_translator_option_group' );
                    do_settings_sections( $this->plugin_name );
                    submit_button();
                ?>
            </form>
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
        
        echo '<textarea readonly style="width: 100%; height: 300px; background: #fff; white-space: pre; font-family: monospace;">' . esc_textarea( $log_content ) . '</textarea>';
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

        add_settings_field(
            'batch_delay',
            'Batch Delay (seconds)',
            array( $this, 'batch_delay_callback' ),
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

        if ( isset( $input['batch_delay'] ) ) {
            $new_input['batch_delay'] = absint( $input['batch_delay'] );
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

    public function batch_delay_callback() {
        $this->options = get_option('gemini_translator_options');
        $value = isset( $this->options['batch_delay'] ) ? $this->options['batch_delay'] : 6;
        echo '<input type="number" id="batch_delay" name="gemini_translator_options[batch_delay]" value="' . esc_attr($value) . '" min="0" step="1" />';
        echo '<p class="description">The delay in seconds between each request in a batch process. Default is 6 seconds to stay within the free tier limit (10 RPM).</p>';
    }

    private function clear_log_file() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/gemini-translator-logs/debug.log';
        if ( file_exists( $log_file ) ) {
            file_put_contents( $log_file, '' );
        }
    }

    private function log_message( $message ) {
        // Ensure options are loaded, especially for AJAX calls
        if ( empty( $this->options ) ) {
            $this->options = get_option( 'gemini_translator_options' );
        }

        if ( ! isset( $this->options['enable_logging'] ) || ! $this->options['enable_logging'] ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/gemini-translator-logs';

        if ( ! file_exists( $log_dir ) ) {
            if ( ! wp_mkdir_p( $log_dir ) ) {
                error_log('Gemini Translator: Could not create log directory: ' . $log_dir);
                return;
            }
        }

        // Check if directory is writable
        if ( ! is_writable( $log_dir ) ) {
            error_log('Gemini Translator: Log directory is not writable: ' . $log_dir);
            return;
        }

        $log_file = $log_dir . '/debug.log';
        $timestamp = current_time( 'mysql' );
        
        // Add separator for new requests
        if (strpos($message, '---') !== false) {
            $log_entry = "\n{$message}\n";
        } else {
            $log_entry = "[{$timestamp}] {$message}\n";
        }

        // Append to the log file
        if (file_put_contents( $log_file, $log_entry, FILE_APPEND ) === false) {
            error_log('Gemini Translator: Failed to write to log file: ' . $log_file);
        }
    }

    /**
     * Optimize content before sending to API by removing excessive inline styles while preserving structure
     */
    private function optimize_content_for_api( $content ) {
        // Remove excessive inline styles from table cells but keep basic structure
        $content = preg_replace('/style="[^"]*(?:width|height|border)[^"]*"/i', '', $content);
        
        // Remove width attributes from table elements as they're usually redundant
        $content = preg_replace('/width="[^"]*"/i', '', $content);
        
        // Remove excessive whitespace and empty paragraphs that add to content size
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
        
        // Clean up any double spaces created by our regex
        $content = str_replace('  ', ' ', $content);
        
        return trim($content);
    }

    /**
     * Split large content into manageable chunks considering Gemini output limits
     * Gemini 2.5 Flash: Input ~1M tokens, Output ~65K tokens
     * We need to ensure each chunk produces output within 65K token limit
     */
    private function split_content_for_translation($content, $max_input_chars = 150000) {
        // Conservative limit considering:
        // - Input content will be translated (often expands)
        // - Additional HTML structure modernization
        // - Meta descriptions and keywords generation
        // - Safety margin for API response formatting
        
        // If content is small enough, return as single chunk
        if (strlen($content) <= $max_input_chars) {
            return [$content];
        }
        
        $this->log_message("Content size (" . strlen($content) . " chars) exceeds safe limit. Splitting into chunks.");
        
        $chunks = [];
        $current_chunk = '';
        
        // First, try to split by major HTML structures that shouldn't be broken
        // Priority: tables, then headings, then paragraphs
        
        // Step 1: Extract tables separately as they're complex and shouldn't be split
        $tables = [];
        $content_without_tables = preg_replace_callback(
            '/<table[^>]*>.*?<\/table>/s',
            function($matches) use (&$tables) {
                $table_id = '___TABLE_PLACEHOLDER_' . count($tables) . '___';
                $tables[$table_id] = $matches[0];
                return $table_id;
            },
            $content
        );
        
        // Step 2: Split content by major sections (h2, h3 headings)
        $sections = preg_split('/(<h[2-3][^>]*>.*?<\/h[2-3]>)/s', $content_without_tables, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        foreach ($sections as $section) {
            if (empty(trim($section))) continue;
            
            // Restore any table placeholders in this section
            foreach ($tables as $placeholder => $table_html) {
                if (strpos($section, $placeholder) !== false) {
                    $section = str_replace($placeholder, $table_html, $section);
                }
            }
            
            // Check if this section alone exceeds our limit
            if (strlen($section) > $max_input_chars) {
                // Save current chunk if not empty
                if (!empty(trim($current_chunk))) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = '';
                }
                
                // Split this large section by paragraphs
                $this->split_large_section($section, $max_input_chars, $chunks);
            } else {
                // Check if adding this section would exceed the limit
                if (strlen($current_chunk . $section) > $max_input_chars && !empty(trim($current_chunk))) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = $section;
                } else {
                    $current_chunk .= $section;
                }
            }
        }
        
        // Add the last chunk if it's not empty
        if (!empty(trim($current_chunk))) {
            $chunks[] = trim($current_chunk);
        }
        
        // Clean up and validate chunks
        $final_chunks = array_filter($chunks, function($chunk) {
            return !empty(trim($chunk));
        });
        
        $this->log_message("Content split into " . count($final_chunks) . " chunks");
        
        return $final_chunks;
    }
    
    /**
     * Split a large section that exceeds limits
     */
    private function split_large_section($section, $max_size, &$chunks) {
        // Split by paragraphs
        $paragraphs = preg_split('/(<\/p>\s*<p[^>]*>)/s', $section, -1, PREG_SPLIT_DELIM_CAPTURE);
        $current_chunk = '';
        
        foreach ($paragraphs as $paragraph) {
            if (empty(trim($paragraph))) continue;
            
            // If this single paragraph is too large, we need to split it further
            if (strlen($paragraph) > $max_size) {
                // Save current chunk if not empty
                if (!empty(trim($current_chunk))) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = '';
                }
                
                // Split by sentences for very long paragraphs
                $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
                $sentence_chunk = '';
                
                foreach ($sentences as $sentence) {
                    if (strlen($sentence_chunk . $sentence) > $max_size && !empty($sentence_chunk)) {
                        $chunks[] = trim($sentence_chunk);
                        $sentence_chunk = $sentence;
                    } else {
                        $sentence_chunk .= ' ' . $sentence;
                    }
                }
                
                if (!empty(trim($sentence_chunk))) {
                    $current_chunk = trim($sentence_chunk);
                }
            } else {
                // Normal paragraph processing
                if (strlen($current_chunk . $paragraph) > $max_size && !empty(trim($current_chunk))) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = $paragraph;
                } else {
                    $current_chunk .= $paragraph;
                }
            }
        }
        
        // Add remaining content to current chunk
        if (!empty(trim($current_chunk))) {
            $chunks[] = trim($current_chunk);
        }
    }
    
    /**
     * Translate content in chunks with careful output size management
     */
    private function translate_chunked_content($title, $content, $target_language, $api_key) {
        $chunks = $this->split_content_for_translation($content);
        $total_chunks = count($chunks);
        
        $this->log_message("Content split into {$total_chunks} chunks for translation");
        
        if ($total_chunks === 1) {
            // Single chunk - use existing method
            return $this->translate_single_chunk($title, $chunks[0], $target_language, $api_key, true);
        }
        
        // Multiple chunks - translate each separately
        $translated_chunks = [];
        $final_meta_description = '';
        $final_meta_keywords = '';
        $translated_title = '';
        
        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_number = $i + 1;
            $this->log_message("Translating chunk {$chunk_number}/{$total_chunks} (size: " . strlen($chunks[$i]) . " chars)");
            
            // For first chunk, include title and request meta data
            $include_title = ($i === 0);
            $chunk_result = $this->translate_single_chunk(
                $include_title ? $title : '', 
                $chunks[$i], 
                $target_language, 
                $api_key, 
                $include_title
            );
            
            if (!$chunk_result || !$chunk_result['success']) {
                $this->log_message("Failed to translate chunk {$chunk_number}: " . ($chunk_result['message'] ?? 'Unknown error'));
                return ['success' => false, 'message' => "Failed to translate content chunk {$chunk_number}"];
            }
            
            $chunk_data = $chunk_result['data'];
            
            // Store title and meta from first chunk
            if ($i === 0 && !empty($chunk_data['translated_title'])) {
                $translated_title = $chunk_data['translated_title'];
                $final_meta_description = $chunk_data['meta_description'] ?? '';
                $final_meta_keywords = $chunk_data['meta_keywords'] ?? '';
            }
            
            $translated_chunks[] = $chunk_data['translated_content'];
            
            $this->log_message("Successfully translated chunk {$chunk_number}");
            
            // Delay between chunks to respect rate limits
            if ($i < $total_chunks - 1) {
                sleep(3); // Increased delay for stability
            }
        }
        
        // Combine all translated chunks
        $combined_content = implode("\n\n", $translated_chunks);
        
        $this->log_message("All chunks translated successfully. Combined content size: " . strlen($combined_content) . " chars");
        
        return [
            'success' => true,
            'data' => [
                'translated_title' => $translated_title ?: $title,
                'translated_content' => $combined_content,
                'meta_description' => $final_meta_description,
                'meta_keywords' => $final_meta_keywords
            ],
            'chunks_count' => $total_chunks // Pass the total chunks count
        ];
    }
    
    /**
     * Translate a single chunk with simplified prompt for chunks
     */
    private function translate_single_chunk($title, $content, $target_language, $api_key, $include_meta = true) {
        // Optimize content
        $content = $this->optimize_content_for_api($content);
        
        // Build simplified prompt for better output size control
        if ($include_meta && !empty($title)) {
            // Full prompt for first chunk with title and meta
            $prompt = "Translate and modernize this blog post to {$target_language}. Modernize HTML for Gutenberg (remove inline styles, preserve tables). Generate meta description (155 chars) and 5 keywords.\n\n";
            $prompt .= "Output as JSON: {\"translated_title\": \"...\", \"translated_content\": \"...\", \"meta_description\": \"...\", \"meta_keywords\": \"...\"}\n\n";
            $prompt .= "Title: {$title}\n\nContent:\n{$content}";
        } else {
            // Simplified prompt for content chunks
            $prompt = "Translate this content chunk to {$target_language}. Modernize HTML for Gutenberg (remove inline styles, preserve tables exactly).\n\n";
            $prompt .= "Output as JSON: {\"translated_content\": \"...\"}\n\n";
            $prompt .= "Content:\n{$content}";
        }
        
        return $this->make_api_request($prompt, $api_key, 3);
    }

    /**
     * Makes the actual API request to Google Gemini.
     */
    private function make_api_request($prompt, $api_key, $max_retries = 3) {
        // Corrected model name based on user-provided official documentation.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$api_key}";

        $this->log_message("--- Sending API Request ---");
        $this->log_message("URL: {$url}");
        $this->log_message("Prompt: " . json_encode($prompt, JSON_PRETTY_PRINT));

        $request_body = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'topP' => 0.8,
                'topK' => 40,
                'maxOutputTokens' => 65536,
                'responseMimeType' => 'text/plain'
            ]
        ];

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $this->log_message("API Request attempt {$attempt}/{$max_retries}");
            
            $response = wp_remote_post($url, [
                'body' => json_encode($request_body),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 300, // 5 minutes
            ]);

            if (is_wp_error($response)) {
                $this->log_message("API Error (WP_Error): " . $response->get_error_message());
                continue; // Try next attempt
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            $this->log_message("API Response Code: {$response_code}");
            $this->log_message("API Response Body: " . $response_body);

            if ($response_code === 200) {
                return $response_body; // Success
            }

            if ($response_code === 429) {
                // Rate limit hit
                $this->log_message("Rate limit hit. Waiting before retry...");
                if ($attempt < $max_retries) {
                    sleep(10 * $attempt);
                    continue;
                }
                return ['success' => false, 'message' => 'Rate limit exceeded. Please try again later.'];
            }

            if ($response_code !== 200) {
                $error_message = "HTTP Error {$response_code}: " . substr($response_body, 0, 500);
                $this->log_message($error_message);
                
                if ($attempt < $max_retries) {
                    sleep(2 * $attempt);
                    continue;
                }
                
                return ['success' => false, 'message' => $error_message];
            }

            // Parse the response
            $decoded_response = json_decode($response_body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !$decoded_response) {
                $error_message = "Failed to decode API response as JSON. Raw response: " . substr($response_body, 0, 1000);
                $this->log_message($error_message);
                
                if ($attempt < $max_retries) {
                    sleep(2 * $attempt);
                    continue;
                }
                
                return ['success' => false, 'message' => 'Invalid JSON response from API'];
            }

            // Extract content from Gemini response structure
            if (!isset($decoded_response['candidates'][0]['content']['parts'][0]['text'])) {
                $error_message = "Unexpected response structure from API";
                $this->log_message($error_message);
                $this->log_message("Full response structure: " . json_encode($decoded_response, JSON_PRETTY_PRINT));
                
                if ($attempt < $max_retries) {
                    sleep(2 * $attempt);
                    continue;
                }
                
                return ['success' => false, 'message' => $error_message];
            }

            $content = $decoded_response['candidates'][0]['content']['parts'][0]['text'];
            $this->log_message("Extracted content: " . substr($content, 0, 1000) . (strlen($content) > 1000 ? '...' : ''));

            // Try to extract JSON from the response
            $json_data = $this->extract_json_from_response($content);
            
            if (!$json_data) {
                $error_message = "Failed to extract valid JSON from API response. Content: " . substr($content, 0, 1000);
                $this->log_message($error_message);
                
                if ($attempt < $max_retries) {
                    sleep(2 * $attempt);
                    continue;
                }
                
                return ['success' => false, 'message' => 'API returned invalid JSON format'];
            }

            $this->log_message("Successfully parsed JSON response");
            return ['success' => true, 'data' => $json_data];
        }

        $this->log_message("API request failed after {$max_retries} attempts.");
        return ['success' => false, 'message' => "All {$max_retries} attempts failed"];
    }

    /**
     * Extract JSON from API response with better error handling
     */
    private function extract_json_from_response($content) {
        // First, try to decode the content directly
        $json_data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && $json_data) {
            return $json_data;
        }

        // Try to find JSON block in the response
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $content, $matches)) {
            $json_data = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && $json_data) {
                return $json_data;
            }
        }

        // Try to extract content between ```json and ``` markers
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json_data = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && $json_data) {
                return $json_data;
            }
        }

        // Try to find any valid JSON structure
        if (preg_match('/(\{(?:[^{}]|{(?:[^{}]|{[^{}]*})*})*\})/', $content, $matches)) {
            $json_data = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && $json_data) {
                return $json_data;
            }
        }

        // Clean up the content and try again
        $cleaned_content = preg_replace('/^[^{]*/', '', $content);
        $cleaned_content = preg_replace('/[^}]*$/', '', $cleaned_content);
        
        if (!empty($cleaned_content)) {
            $json_data = json_decode($cleaned_content, true);
            if (json_last_error() === JSON_ERROR_NONE && $json_data) {
                return $json_data;
            }
        }

        return null;
    }

    public function handle_translation_request() {
        // Verify nonce
        check_ajax_referer( 'gemini_translate_post', 'gemini_translator_nonce' );

        if ( ! isset( $_POST['post_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Error: Post ID not provided.' ) );
        }

        $post_id = intval( $_POST['post_id'] );
        $post = get_post( $post_id );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => 'Error: Post not found.' ) );
        }

        // Get API Key and target language from settings
        $options = get_option( 'gemini_translator_options' );
        $api_key = $options['api_key'] ?? '';
        $target_language = $options['target_language'] ?? 'Ukrainian';

        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => 'Error: API key is not set.' ) );
        }
        
        global $wpdb;
        $table_originals = $wpdb->prefix . 'gemini_originals';
        $table_translations = $wpdb->prefix . 'gemini_translations';

        // 1. Create a backup if it doesn't exist
        $backup_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_originals WHERE post_id = %d", $post_id ) );

        if ( ! $backup_exists ) {
            $wpdb->insert(
                $table_originals,
                [
                    'post_id' => $post_id,
                    'original_title' => $post->post_title,
                    'original_content' => $post->post_content,
                    'created_at' => current_time( 'mysql' ),
                ],
                ['%d', '%s', '%s', '%s']
            );
        }

        // 2. Perform the translation
        try {
            $this->log_message( "Starting translation for post ID: {$post_id}" );
            $translation_result = $this->translate_single_chunk($post->post_title, $post->post_content, $target_language, $api_key);

            if (isset($translation_result['error'])) {
                 throw new Exception($translation_result['error']);
            }
            
            if ($translation_result['status'] === 'already_in_target_language') {
                wp_send_json_success( ['status' => 'already_in_target_language'] );
            }

            // 3. Save translation to our custom table
            $wpdb->replace(
                $table_translations,
                [
                    'post_id' => $post_id,
                    'translated_title' => $translation_result['translated_title'],
                    'translated_content' => $translation_result['translated_content'],
                    'meta_description' => $translation_result['meta_description'],
                    'meta_keywords' => $translation_result['meta_keywords'],
                    'status' => 'pending_review',
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            $this->log_message( "Successfully saved translation for post ID: {$post_id} for review." );
            wp_send_json_success( [ 'status' => 'pending_review', 'message' => 'Translation complete. Ready for review.' ] );

        } catch ( Exception $e ) {
            $this->log_message( "Error translating post ID {$post_id}: " . $e->getMessage() );
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * Handles fetching data for the review modal.
     */
    public function handle_get_review_data() {
        check_ajax_referer('gemini_get_review_data', 'gemini_get_review_nonce');
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (empty($post_id)) {
            wp_send_json_error(['message' => 'Post ID is missing.']);
        }
        
        global $wpdb;
        $table_originals = $wpdb->prefix . 'gemini_originals';
        $table_translations = $wpdb->prefix . 'gemini_translations';

        $original = $wpdb->get_row($wpdb->prepare("SELECT original_title, original_content FROM $table_originals WHERE post_id = %d", $post_id));
        $translated = $wpdb->get_row($wpdb->prepare("SELECT translated_title, translated_content FROM $table_translations WHERE post_id = %d", $post_id));

        if (!$original || !$translated) {
            wp_send_json_error(['message' => 'Could not find original and translated versions for this post.']);
        }

        wp_send_json_success([
            'original' => [
                'title' => $original->original_title,
                'content' => $original->original_content,
            ],
            'translated' => [
                'title' => $translated->translated_title,
                'content' => $translated->translated_content,
            ]
        ]);
    }

    /**
     * Handles approving a translation and updating the original post.
     */
    public function handle_approve_translation() {
        check_ajax_referer('gemini_approve_translation', 'gemini_approve_nonce');
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (empty($post_id)) {
            wp_send_json_error(['message' => 'Post ID is missing.']);
        }

        $this->log_message("Attempting to approve translation for Post ID: {$post_id}");
        
        global $wpdb;
        $table_translations = $wpdb->prefix . 'gemini_translations';

        $translation = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_translations WHERE post_id = %d", $post_id));

        if (!$translation) {
            wp_send_json_error(['message' => 'Translation not found.']);
        }

        $post_data = [
            'ID' => $post_id,
            'post_title' => $translation->translated_title,
            'post_content' => $translation->translated_content,
        ];

        // Update the post
        wp_update_post($post_data);

        // Update our translation status to 'completed'
        $wpdb->update(
            $table_translations,
            ['status' => 'completed', 'updated_at' => current_time('mysql')],
            ['post_id' => $post_id],
            ['%s', '%s'],
            ['%d']
        );

        $this->log_message("Successfully approved and updated Post ID: {$post_id}");
        wp_send_json_success(['message' => 'Post updated with translation successfully.']);
    }

    /**
     * Handles restoring the original post from backup.
     */
    public function handle_restore_original() {
        check_ajax_referer('gemini_restore_original', 'gemini_restore_nonce');
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (empty($post_id)) {
            wp_send_json_error(['message' => 'Post ID is missing.']);
        }

        $this->log_message("Attempting to restore original for Post ID: {$post_id}");

        global $wpdb;
        $table_originals = $wpdb->prefix . 'gemini_originals';
        $table_translations = $wpdb->prefix . 'gemini_translations';

        $original = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_originals WHERE post_id = %d", $post_id));

        if (!$original) {
            wp_send_json_error(['message' => 'Original backup not found.']);
        }

        // Restore the post
        wp_update_post([
            'ID' => $post_id,
            'post_title' => $original->original_title,
            'post_content' => $original->original_content,
        ]);

        // Delete the translation record for this post
        $wpdb->delete($table_translations, ['post_id' => $post_id], ['%d']);

        $this->log_message("Successfully restored original for Post ID: {$post_id}");
        wp_send_json_success(['message' => 'Post restored to its original version.']);
    }

    /*
     * The old save function is no longer needed with the new workflow.
     * It is kept here for reference but is not hooked.
    public function handle_save_translated_post() {
        check_ajax_referer( 'gemini_save_post_nonce', 'nonce' );

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if ( empty($post_id) ) {
            wp_send_json_error( [ 'message' => 'Error: Post ID not provided.' ] );
        }

        $update_data = [
            'ID' => $post_id,
            'post_title' => sanitize_text_field( $_POST['title'] ),
            'post_content' => wp_kses_post( $_POST['content'] ),
        ];

        $result = wp_update_post( $update_data, true );

        if ( is_wp_error($result) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            // Also update meta fields if they exist
            if ( isset( $_POST['meta_description'] ) ) {
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $_POST['meta_description'] ) );
            }
            if ( isset( $_POST['meta_keywords'] ) ) {
                update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $_POST['meta_keywords'] ) );
            }
            wp_send_json_success( [ 'message' => 'Post saved successfully.' ] );
        }
    }
    */

    public function add_translate_meta_box() {
        // Add metabox for both classic and block editor
        add_meta_box(
            'gemini_translator_meta_box',
            'Gemini Translator',
            array( $this, 'render_translate_meta_box' ),
            'post', // Add to posts
            'side', // Position
            'high' // Priority
        );

        // Also add support for pages
        add_meta_box(
            'gemini_translator_meta_box_page',
            'Gemini Translator',
            array( $this, 'render_translate_meta_box' ),
            'page', // Add to pages
            'side', // Position
            'high' // Priority
        );

        // Add a placeholder for the modal window
        add_action( 'admin_footer', array( $this, 'render_preview_modal' ) );
        
        // Ensure our metabox works in both Classic and Block editors
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
    }

    public function enqueue_block_editor_assets() {
        // For Block Editor, we need to ensure our scripts work
        wp_enqueue_script( 'jquery' );
        
        // Add inline script to make sure our metabox is visible and functional
        wp_add_inline_script( 'jquery', '
            jQuery(document).ready(function($) {
                // Ensure metabox is visible in Block Editor
                if (typeof wp !== "undefined" && wp.data) {
                    console.log("Gemini Translator: Block Editor detected");
                }
            });
        ' );
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
            console.log('Gemini Translator: JavaScript loaded for post ID <?php echo $post->ID; ?>');
            console.log('Gemini Translator: WordPress version detected, checking editors...');
            
            // Check what editors are available
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                console.log('Gemini Translator: Block Editor (Gutenberg) available');
            }
            if (typeof tinymce !== 'undefined') {
                console.log('Gemini Translator: TinyMCE available');
            }
            if ($('#content').length) {
                console.log('Gemini Translator: Content textarea available');
            }
            if ($('#title').length) {
                console.log('Gemini Translator: Title field available');
            }
            if ($('.editor-post-title__input').length) {
                console.log('Gemini Translator: Block Editor title field available');
            }
            
            var currentPostId = <?php echo $post->ID; ?>;
            var originalTitle = '';
            var originalContent = '';

            // Check if our button exists
            if ($('#gemini-translate-button').length === 0) {
                console.error('Gemini Translator: Button not found! Metabox may not be loaded.');
                return;
            } else {
                console.log('Gemini Translator: Button found, attaching click handler');
            }

            // Handle Translate button click
            $('#gemini-translate-button').on('click', function() {
                console.log('Gemini Translator: Button clicked');
                
                var button = $(this);
                var spinner = $('#gemini-spinner');
                var status = $('#gemini-translation-status');
                
                // Get title - with safety check
                originalTitle = $('#title').val() || '';
                if (!originalTitle && $('.editor-post-title__input').length) {
                    // Block Editor title
                    originalTitle = $('.editor-post-title__input').val() || '';
                }
                
                // Get content - with safety checks for different editors
                originalContent = '';
                
                // Try Block Editor (Gutenberg) first
                if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                    try {
                        originalContent = wp.data.select('core/editor').getEditedPostContent() || '';
                        console.log('Gemini Translator: Content from Block Editor');
                    } catch (e) {
                        console.log('Gemini Translator: Block Editor not available, trying TinyMCE');
                    }
                }
                
                // Fallback to TinyMCE if Block Editor didn't work
                if (!originalContent && typeof tinymce !== 'undefined' && tinymce.get('content')) {
                    try {
                        originalContent = tinymce.get('content').getContent() || '';
                        console.log('Gemini Translator: Content from TinyMCE');
                    } catch (e) {
                        console.log('Gemini Translator: TinyMCE error:', e);
                    }
                }
                
                // Final fallback to textarea
                if (!originalContent) {
                    originalContent = $('#content').val() || '';
                    console.log('Gemini Translator: Content from textarea fallback');
                }

                console.log('Gemini Translator: Title length:', originalTitle.length);
                console.log('Gemini Translator: Content length:', originalContent.length);

                // Safety check before calculating total length
                if (typeof originalTitle !== 'string') originalTitle = '';
                if (typeof originalContent !== 'string') originalContent = '';

                var totalLength = originalTitle.length + originalContent.length;
                console.log('Gemini Translator: Total content length:', totalLength);
                
                if (totalLength === 0) {
                    status.html('<span style="color:red;">Error: No content found to translate. Please make sure you have a title or content.</span>');
                    return;
                }

                // Check content size before sending
                if (totalLength > 900000) {
                    status.html('<span style="color:red;">Error: Content is too large (' + totalLength + ' characters). Please split into smaller articles.</span>');
                    return;
                }

                button.prop('disabled', true);
                spinner.addClass('is-active');
                
                // Show progress and estimated time
                var startTime = Date.now();
                status.html('Translating... This may take 2-4 minutes for large articles.<br><span id="timer">0s</span>').show();
                
                // Update timer every second
                var timerInterval = setInterval(function() {
                    var elapsed = Math.round((Date.now() - startTime) / 1000);
                    $('#timer').text(elapsed + 's');
                }, 1000);

                console.log('Gemini Translator: Sending AJAX request');

                $.ajax({
                    url: typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'gemini_translate_post',
                        post_id: currentPostId,
                        gemini_translator_nonce: $('#gemini_translator_nonce').val()
                    },
                    timeout: 600000, // 10 minutes for chunked content processing
                    success: function(response) {
                        console.log('Gemini Translator: AJAX success', response);
                        clearInterval(timerInterval);
                        
                        if(response.success) {
                            if (response.data.status === 'already_in_target_language') {
                                status.html('<span style="color:blue;">Post is already in the target language.</span>');
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
                            status.html('<span style="color:red;">Error: ' + response.data.message + '</span>');
                            button.prop('disabled', false);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.log('Gemini Translator: AJAX error', jqXHR, textStatus, errorThrown);
                        clearInterval(timerInterval);
                        
                        var errorMessage = 'AJAX error: ' + textStatus;
                        if (errorThrown) {
                            errorMessage += ' - ' + errorThrown;
                        }
                        
                        if (jqXHR.status === 0) {
                            errorMessage += '<br/><br/><strong>Network Error:</strong> The request was cancelled or network connection failed. This often happens with very large content or slow server response.';
                        } else if (jqXHR.status >= 500) {
                            errorMessage += '<br/><br/><strong>Server Error (HTTP ' + jqXHR.status + '):</strong> The server encountered an internal error. Please check server logs.';
                        } else if (jqXHR.responseText) {
                           errorMessage += '<br/><br/><strong>Server Response:</strong><br/>' + jqXHR.responseText.substring(0, 1000);
                           if (jqXHR.responseText.length > 1000) {
                               errorMessage += '...<br/><em>(Response truncated - check logs for full details)</em>';
                           }
                        }
                        
                        status.html('<span style="color:red;">' + errorMessage + '</span>');
                        button.prop('disabled', false);
                    },
                    complete: function() {
                        clearInterval(timerInterval);
                        spinner.removeClass('is-active');
                    }
                });
            });

            // Handle Save Changes button in modal
            $('#gemini-save-changes').on('click', function() {
                console.log('Gemini Translator: Save button clicked');
                
                var button = $(this);
                button.prop('disabled', true).text('Saving...');
                var translationData = button.data('translation');
                
                $.ajax({
                    url: typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php',
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
                        console.log('Gemini Translator: Save success', response);
                        if(response.success) {
                            // Reload the page to see the saved changes
                            location.reload();
                        } else {
                            alert('Error saving post: ' + response.data.message);
                            button.prop('disabled', false).text('Confirm & Save');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.log('Gemini Translator: Save error', jqXHR, textStatus, errorThrown);
                        var errorMessage = 'An unexpected error occurred while saving. Status: ' + textStatus;
                         if (errorThrown) {
                            errorMessage += ' - ' + errorThrown;
                        }
                        if (jqXHR.responseText) {
                           errorMessage += '\\n\\nServer Response:\\n' + jqXHR.responseText.substring(0, 500);
                        }
                        alert(errorMessage);
                        button.prop('disabled', false).text('Confirm & Save');
                    }
                });
            });

            // Handle Cancel button in modal
            $('#gemini-cancel-preview').on('click', function() {
                console.log('Gemini Translator: Cancel button clicked');
                $('#gemini-preview-modal').hide();
                $('#gemini-translate-button').prop('disabled', false);
            });
        });
        </script>
        <?php
    }
} 