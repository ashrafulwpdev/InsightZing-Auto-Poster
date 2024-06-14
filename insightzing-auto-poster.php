<?php
/**
 * Plugin Name: InsightZing Auto Poster
 * Description: Automatically posts new or updated posts to a Telegram channel with enhanced features.
 * Version: 1.0.2
 * Author: InsightZing
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Hook into the post publish event.
add_action('save_post', 'insightzing_send_telegram_post_notification', 10, 2);

function insightzing_send_telegram_post_notification($post_id, $post) {
    // Avoid auto-saves and revisions.
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || $post->post_type != 'post') {
        return;
    }

    // Check if this is an AMP request and return early if so.
    if (function_exists('is_amp_endpoint') && is_amp_endpoint()) {
        return;
    }

    // Check if posting to Telegram is enabled via admin settings.
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    if ($enable_telegram_post !== 'yes') {
        return; // Stop execution if Telegram posting is disabled.
    }

    // Get the last modified time of the post.
    $last_modified = get_post_modified_time('U', true, $post_id);

    // Get the timestamp when the last notification was sent (if any).
    $last_sent_time = get_post_meta($post_id, '_telegram_last_sent_time', true);

    // Determine if this is a new post or an update.
    $is_new_post = !$last_sent_time;

    // Compare if the post was modified after the last notification was sent.
    if (!$is_new_post && $last_modified <= $last_sent_time) {
        return; // Stop execution if the post hasn't been modified since the last notification.
    }

    // Get Telegram settings from options.
    $telegram_bot_token = get_option('wp_telegram_bot_token');
    $telegram_chat_id = get_option('wp_telegram_chat_id');
    $telegram_channel_username = get_option('wp_telegram_channel_username');

    if (empty($telegram_bot_token) || (empty($telegram_chat_id) && empty($telegram_channel_username))) {
        error_log('Telegram bot token, chat ID, or channel username is not set.');
        return;
    }

    // Determine the chat ID based on the settings.
    if (!empty($telegram_channel_username)) {
        $chat_id = '@' . $telegram_channel_username;
    } elseif (!empty($telegram_chat_id)) {
        $chat_id = $telegram_chat_id;
    } else {
        error_log('Telegram chat ID or channel username is not set.');
        return;
    }

    // Prepare post content.
    $title = get_the_title($post_id);
    $link = get_permalink($post_id);
    $summary = wp_trim_words($post->post_content, 40, '...');

    // Get the featured image.
    $featured_image = get_the_post_thumbnail_url($post_id, 'full');
    if (!$featured_image) {
        $featured_image = 'default_image_url'; // Optional: Set a default image URL if no featured image is found.
    }

    // Create the message content with indicators and emojis.
    if ($is_new_post) {
        $caption = "🆕 **New Post!**\n\n[" . $title . "](" . $link . ")\n\n" . $summary;
    } else {
        $caption = "🔄 **Updated Post!**\n\n[" . $title . "](" . $link . ")\n\n" . $summary;
    }

    // Prepare data for Telegram API.
    $data = array(
        'chat_id' => $chat_id,
        'photo' => $featured_image,
        'caption' => $caption,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(array(
            'inline_keyboard' => array(
                array(
                    array(
                        'text' => 'Read More ➡️',
                        'url' => $link,
                        'callback_data' => 'read_more'
                    )
                )
            )
        ))
    );

    // Telegram API endpoint URL.
    $url = 'https://api.telegram.org/bot' . $telegram_bot_token . '/sendPhoto';

    // Send the message to Telegram.
    $response = wp_remote_post($url, array(
        'body' => $data
    ));

    if (is_wp_error($response)) {
        error_log('Error sending message to Telegram: ' . $response->get_error_message());
    } else {
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (!$response_data['ok']) {
            error_log('Telegram API error: ' . $response_data['description']);
        } else {
            // Update the last sent time meta to the current time.
            update_post_meta($post_id, '_telegram_last_sent_time', current_time('timestamp'));
            error_log('Message successfully sent to Telegram.');
        }
    }
}

// Add submenu page under Settings for InsightZing Auto Poster.
add_action('admin_menu', 'insightzing_auto_poster_add_settings_page');

function insightzing_auto_poster_add_settings_page() {
    add_options_page(
        'InsightZing Auto Poster Settings',
        'InsightZing Auto Poster',
        'manage_options',
        'insightzing-auto-poster-settings',
        'insightzing_auto_poster_settings_page'
    );
}

function insightzing_auto_poster_settings_page() {
    ?>
    <div class="wrap">
        <h1>InsightZing Auto Poster Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('insightzing_auto_poster_settings');
            do_settings_sections('insightzing_auto_poster_settings');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

// Register settings and fields for the InsightZing Auto Poster Settings page.
add_action('admin_init', 'insightzing_auto_poster_settings_init');

function insightzing_auto_poster_settings_init() {
    // Register settings.
    register_setting('insightzing_auto_poster_settings', 'enable_telegram_post');
    register_setting('insightzing_auto_poster_settings', 'wp_telegram_bot_token');
    register_setting('insightzing_auto_poster_settings', 'wp_telegram_chat_id');
    register_setting('insightzing_auto_poster_settings', 'wp_telegram_channel_username');

    // Add settings section.
    add_settings_section(
        'insightzing_auto_poster_general_settings_section',
        'General Settings',
        'insightzing_auto_poster_general_settings_section_callback',
        'insightzing_auto_poster_settings'
    );

    // Add fields.
    add_settings_field(
        'enable_telegram_post',
        'Enable Telegram Auto Post',
        'enable_telegram_post_field_callback',
        'insightzing_auto_poster_settings',
        'insightzing_auto_poster_general_settings_section'
    );

    add_settings_field(
        'wp_telegram_bot_token',
        'Telegram Bot Token',
        'wp_telegram_bot_token_field_callback',
        'insightzing_auto_poster_settings',
        'insightzing_auto_poster_general_settings_section'
    );

    add_settings_field(
        'wp_telegram_chat_id',
        'Telegram Chat ID',
        'wp_telegram_chat_id_field_callback',
        'insightzing_auto_poster_settings',
        'insightzing_auto_poster_general_settings_section'
    );

    add_settings_field(
        'wp_telegram_channel_username',
        'Telegram Channel Username',
        'wp_telegram_channel_username_field_callback',
        'insightzing_auto_poster_settings',
        'insightzing_auto_poster_general_settings_section'
    );
}

// Callback functions for settings fields.
function enable_telegram_post_field_callback() {
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    echo '<input type="checkbox" id="enable_telegram_post" name="enable_telegram_post" value="yes" ' . checked('yes', $enable_telegram_post, false) . ' />';
    echo '<label for="enable_telegram_post">Enable auto posting to Telegram</label>';
}

function wp_telegram_bot_token_field_callback() {
    $token = get_option('wp_telegram_bot_token');
    echo '<input type="text" id="wp_telegram_bot_token" name="wp_telegram_bot_token" value="' . esc_attr($token) . '" />';
    echo '<p class="description">Enter your Telegram bot token here.</p>';
}

function wp_telegram_chat_id_field_callback() {
    $chat_id = get_option('wp_telegram_chat_id');
    echo '<input type="text" id="wp_telegram_chat_id" name="wp_telegram_chat_id" value="' . esc_attr($chat_id) . '" />';
    echo '<p class="description">Enter your Telegram chat ID here.</p>';
}

function wp_telegram_channel_username_field_callback() {
    $channel_username = get_option('wp_telegram_channel_username');
    echo '<input type="text" id="wp_telegram_channel_username" name="wp_telegram_channel_username" value="' . esc_attr($channel_username) . '" />';
    echo '<p class="description">Enter your Telegram channel username (without @) here.</p>';
}

function insightzing_auto_poster_general_settings_section_callback() {
    echo '<p>Configure settings for the InsightZing Auto Poster plugin.</p>';
}
?>
