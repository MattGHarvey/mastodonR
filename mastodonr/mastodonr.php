<?php
/**
 * Plugin Name: MastodonR
 * Description: Sends WordPress posts to Mastodon using the first post image, post text, EXIF metadata, and hashtags.
 * Version: 0.1.1
 * Author: MastodonR
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class MastodonR_To_Mastodon {
    const OPTION_KEY = 'mastodonr_settings';
    const OAUTH_SCOPES = 'read write:statuses write:media';
    const NONCE_ACTION_SEND = 'mastodonr_send_to_mastodon';
    const META_LAST_STATUS_ID = '_mastodonr_last_status_id';
    const META_LAST_STATUS_TIME = '_mastodonr_last_status_gmt';
    const META_LAST_STATUS_URL = '_mastodonr_last_status_url';
    const AUTO_SEND_HOOK = 'mastodonr_process_auto_send';
    const AUTO_SEND_DELAY_SECONDS = 90;

    public function __construct() {
        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_mastodon_metabox'));
        add_action('admin_post_mastodonr_send_to_mastodon', array($this, 'handle_manual_send'));
        add_action('admin_notices', array($this, 'render_admin_notice'));

        add_action('transition_post_status', array($this, 'handle_publish_post'), 10, 3);
        add_action(self::AUTO_SEND_HOOK, array($this, 'process_auto_send'), 10, 1);

        add_action('admin_post_mastodonr_connect', array($this, 'handle_connect'));
        add_action('admin_post_mastodonr_disconnect', array($this, 'handle_disconnect'));
        add_action('admin_post_mastodonr_callback', array($this, 'handle_callback'));
        add_action('admin_post_nopriv_mastodonr_callback', array($this, 'handle_callback'));
    }

    public function register_settings_page() {
        add_options_page('MastodonR', 'MastodonR', 'manage_options', 'mastodonr', array($this, 'render_settings_page'));
    }

    public function register_settings() {
        register_setting('mastodonr_settings_group', self::OPTION_KEY, array($this, 'sanitize_settings'));

        add_settings_section(
            'mastodonr_instance_section',
            'Mastodon Connection',
            function () {
                echo '<p>Enter your Mastodon instance URL, save it, then connect your account.</p>';
            },
            'mastodonr'
        );

        $fields = array(
            'instance_url' => 'Instance URL',
            'default_tags' => 'Default Hashtags (space-separated)',
        );

        foreach ($fields as $key => $label) {
            add_settings_field(
                'mastodonr_' . $key,
                $label,
                array($this, 'render_text_field'),
                'mastodonr',
                'mastodonr_instance_section',
                array('key' => $key)
            );
        }

        add_settings_field(
            'mastodonr_visibility',
            'Visibility',
            array($this, 'render_visibility_field'),
            'mastodonr',
            'mastodonr_instance_section'
        );
    }

    public function sanitize_settings($input) {
        $existing = $this->get_settings();
        $instance_url = isset($input['instance_url']) ? esc_url_raw(trim($input['instance_url'])) : $existing['instance_url'];
        $instance_url = rtrim($instance_url, '/');

        return array(
            'instance_url' => $instance_url,
            'default_tags' => isset($input['default_tags']) ? sanitize_text_field($input['default_tags']) : '',
            'visibility' => isset($input['visibility']) ? $this->sanitize_visibility($input['visibility']) : $existing['visibility'],
            'client_id' => isset($input['client_id']) ? sanitize_text_field($input['client_id']) : $existing['client_id'],
            'client_secret' => isset($input['client_secret']) ? sanitize_text_field($input['client_secret']) : $existing['client_secret'],
            'access_token' => isset($input['access_token']) ? sanitize_text_field($input['access_token']) : $existing['access_token'],
            'registered_scopes' => isset($input['registered_scopes']) ? sanitize_text_field($input['registered_scopes']) : $existing['registered_scopes'],
            'account' => isset($input['account']) && is_array($input['account']) ? $input['account'] : $existing['account'],
        );
    }

    public function render_text_field($args) {
        $settings = $this->get_settings();
        $key = $args['key'];
        printf(
            '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_attr(isset($settings[$key]) ? $settings[$key] : '')
        );
        if ($key === 'instance_url') {
            echo '<p class="description">For example: https://mastodon.social</p>';
        }
    }

    public function render_visibility_field() {
        $settings = $this->get_settings();
        $options = array(
            'public' => 'Public',
            'unlisted' => 'Unlisted',
            'private' => 'Followers only',
            'direct' => 'Mentioned users only',
        );

        echo '<select name="' . esc_attr(self::OPTION_KEY) . '[visibility]">';
        foreach ($options as $value => $label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($settings['visibility'], $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $connected = !empty($settings['access_token']);
        $account_name = !empty($settings['account']['acct']) ? $settings['account']['acct'] : '';
        ?>
        <div class="wrap">
            <h1>MastodonR</h1>
            <form action="options.php" method="post">
                <?php settings_fields('mastodonr_settings_group'); ?>
                <?php do_settings_sections('mastodonr'); ?>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr />
            <h2>Mastodon Account Connection</h2>
            <?php if ($connected) : ?>
                <p>Connected as <strong><?php echo esc_html($account_name ? '@' . $account_name : 'Mastodon account'); ?></strong></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mastodonr_disconnect" />
                    <?php wp_nonce_field('mastodonr_disconnect'); ?>
                    <?php submit_button('Disconnect Mastodon Account', 'delete'); ?>
                </form>
            <?php else : ?>
                <p>Connect your Mastodon account to allow uploads. The plugin registers itself with the instance automatically.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mastodonr_connect" />
                    <?php wp_nonce_field('mastodonr_connect'); ?>
                    <?php submit_button('Connect Mastodon Account', 'primary'); ?>
                </form>
            <?php endif; ?>

            <p>After connecting, posts are sent when they transition from non-published to published. A post can also be sent manually from its editor.</p>
        </div>
        <?php
    }

    public function add_mastodon_metabox() {
        add_meta_box('mastodonr_box', 'Mastodon', array($this, 'render_mastodon_metabox'), 'post', 'side', 'default');
    }

    public function render_mastodon_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $query_type = isset($_GET['mastodonr_notice']) ? sanitize_key(wp_unslash($_GET['mastodonr_notice'])) : '';
        $notice = get_transient('mastodonr_notice_' . get_current_user_id());
        if ($query_type === 'success' || $query_type === 'error') {
            $notice_type = is_array($notice) && !empty($notice['type']) ? $notice['type'] : $query_type;
            $notice_message = is_array($notice) && !empty($notice['message'])
                ? $notice['message']
                : ($query_type === 'success'
                    ? 'MastodonR completed the send request.'
                    : 'MastodonR could not complete the send request.');
            $notice_class = $notice_type === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($notice_class) . ' inline"><p>' . esc_html($notice_message) . '</p></div>';
            delete_transient('mastodonr_notice_' . get_current_user_id());
        }

        $settings = $this->get_settings();
        $status_id = get_post_meta($post->ID, self::META_LAST_STATUS_ID, true);
        $status_url = get_post_meta($post->ID, self::META_LAST_STATUS_URL, true);
        $status_time = get_post_meta($post->ID, self::META_LAST_STATUS_TIME, true);
        $missing = $this->get_missing_config($settings);

        echo '<p>Manually send this post to Mastodon for testing or one-off posting.</p>';
        if (!empty($missing)) {
            echo '<p><strong>Setup required:</strong> Complete MastodonR settings before sending.</p>';
            echo '<p><a href="' . esc_url(admin_url('options-general.php?page=mastodonr')) . '">Open MastodonR settings</a></p>';
        }
        if ($status_id) {
            echo '<p><strong>Last Mastodon Status ID:</strong> ' . esc_html($status_id) . '</p>';
        }
        if ($status_url) {
            echo '<p><a href="' . esc_url($status_url) . '" target="_blank" rel="noopener">View last Mastodon status</a></p>';
        }
        if ($status_time) {
            echo '<p><strong>Last Post (GMT):</strong> ' . esc_html($status_time) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mastodonr_send_to_mastodon" />';
        echo '<input type="hidden" name="post_id" value="' . (int) $post->ID . '" />';
        wp_nonce_field(self::NONCE_ACTION_SEND . '_' . (int) $post->ID);
        echo '<p><button type="submit" class="button button-primary">Send to Mastodon</button></p></form>';
    }

    public function handle_manual_send() {
        $post_id = isset($_REQUEST['post_id']) ? absint(wp_unslash($_REQUEST['post_id'])) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer(self::NONCE_ACTION_SEND . '_' . $post_id);

        $result = $this->send_post_to_mastodon($post_id, true);
        if (is_wp_error($result)) {
            $this->set_admin_notice('error', $result->get_error_message());
            $notice_type = 'error';
        } else {
            $this->set_admin_notice('success', 'Sent to Mastodon successfully.');
            $notice_type = 'success';
        }

        $edit_link = admin_url('post.php?post=' . $post_id . '&action=edit');
        $edit_link = add_query_arg('mastodonr_notice', $notice_type, $edit_link);
        wp_safe_redirect($edit_link);
        exit;
    }

    public function handle_publish_post($new_status, $old_status, $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'post' || wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if (!wp_next_scheduled(self::AUTO_SEND_HOOK, array((int) $post->ID))) {
            wp_schedule_single_event(time() + self::AUTO_SEND_DELAY_SECONDS, self::AUTO_SEND_HOOK, array((int) $post->ID));
        }
    }

    public function process_auto_send($post_id) {
        $post = get_post((int) $post_id);
        if (!$post || !($post instanceof WP_Post) || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return;
        }
        $publish_ts = get_post_time('U', true, $post);
        if ($publish_ts && $publish_ts > time()) {
            return;
        }
        $this->send_post_to_mastodon($post->ID, false);
    }

    public function handle_connect() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer('mastodonr_connect');

        $settings = $this->get_settings();
        if (empty($settings['instance_url'])) {
            $this->set_admin_notice('error', 'Save an Instance URL before connecting Mastodon.');
            wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
            exit;
        }

        $redirect_uri = admin_url('admin-post.php?action=mastodonr_callback');
        if ($settings['registered_scopes'] !== self::OAUTH_SCOPES) {
            $settings['client_id'] = '';
            $settings['client_secret'] = '';
            $settings['access_token'] = '';
            $settings['account'] = array();
            $settings['registered_scopes'] = self::OAUTH_SCOPES;
            update_option(self::OPTION_KEY, $settings);
        }

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            $app = $this->register_app($settings['instance_url'], $redirect_uri);
            if (is_wp_error($app)) {
                $this->set_admin_notice('error', 'Mastodon app registration failed: ' . $app->get_error_message());
                wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
                exit;
            }
            $settings['client_id'] = sanitize_text_field($app['client_id']);
            $settings['client_secret'] = sanitize_text_field($app['client_secret']);
            $settings['registered_scopes'] = self::OAUTH_SCOPES;
            update_option(self::OPTION_KEY, $settings);
        }

        $state = wp_generate_password(32, false, false);
        set_transient('mastodonr_oauth_state_' . $state, array('user_id' => get_current_user_id(), 'instance_url' => $settings['instance_url']), 15 * MINUTE_IN_SECONDS);
        $authorize_url = add_query_arg(array(
            'client_id' => $settings['client_id'],
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPES,
            'state' => $state,
        ), $settings['instance_url'] . '/oauth/authorize');

        wp_redirect(esc_url_raw($authorize_url));
        exit;
    }

    public function handle_callback() {
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $oauth_error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $state_data = $state ? get_transient('mastodonr_oauth_state_' . $state) : false;
        if (!$state_data || !is_array($state_data)) {
            $this->set_admin_notice('error', 'Invalid or expired Mastodon authorization state.');
            wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
            exit;
        }
        delete_transient('mastodonr_oauth_state_' . $state);

        if ($oauth_error) {
            $this->set_admin_notice('error', 'Mastodon authorization failed: ' . $oauth_error);
            wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
            exit;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $settings = $this->get_settings();
        $token = $this->exchange_code($settings, $code, admin_url('admin-post.php?action=mastodonr_callback'));
        if (is_wp_error($token)) {
            $this->set_admin_notice('error', 'Mastodon token exchange failed: ' . $token->get_error_message());
            wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
            exit;
        }

        $settings['access_token'] = sanitize_text_field($token['access_token']);
        $account = $this->api_request($settings, '/api/v1/accounts/verify_credentials', 'GET');
        $settings['account'] = is_wp_error($account) ? array() : array(
            'acct' => isset($account['acct']) ? sanitize_text_field($account['acct']) : '',
            'display_name' => isset($account['display_name']) ? sanitize_text_field($account['display_name']) : '',
        );
        update_option(self::OPTION_KEY, $settings);

        $this->set_admin_notice('success', 'Mastodon account connected.');
        wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
        exit;
    }

    public function handle_disconnect() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer('mastodonr_disconnect');
        $settings = $this->get_settings();
        $settings['access_token'] = '';
        $settings['account'] = array();
        update_option(self::OPTION_KEY, $settings);
        $this->set_admin_notice('success', 'Mastodon account disconnected.');
        wp_safe_redirect(admin_url('options-general.php?page=mastodonr'));
        exit;
    }

    private function register_app($instance_url, $redirect_uri) {
        $response = wp_remote_post($instance_url . '/api/v1/apps', array(
            'timeout' => 30,
            'body' => array(
                'client_name' => 'MastodonR WordPress Plugin',
                'redirect_uris' => $redirect_uri,
                'scopes' => self::OAUTH_SCOPES,
                'website' => home_url('/'),
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['client_id']) || empty($data['client_secret'])) {
            return new WP_Error('app_registration_failed', 'The instance did not return valid application credentials.');
        }
        return $data;
    }

    private function exchange_code($settings, $code, $redirect_uri) {
        if (empty($code)) {
            return new WP_Error('missing_code', 'Mastodon did not return an authorization code.');
        }
        $response = wp_remote_post($settings['instance_url'] . '/oauth/token', array(
            'timeout' => 30,
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri' => $redirect_uri,
                'scope' => self::OAUTH_SCOPES,
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ((int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300 || empty($data['access_token'])) {
            return new WP_Error('token_exchange_failed', 'The instance did not return an access token.');
        }
        return $data;
    }

    private function send_post_to_mastodon($post_id, $is_manual) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('invalid_post', 'Only standard posts can be sent to Mastodon.');
        }

        $settings = $this->get_settings();
        $missing = $this->get_missing_config($settings);
        if (!empty($missing)) {
            $labels = array('instance_url' => 'Instance URL', 'client_id' => 'Mastodon app', 'client_secret' => 'Mastodon app secret', 'access_token' => 'Connected Mastodon account');
            $names = array();
            foreach ($missing as $key) {
                $names[] = isset($labels[$key]) ? $labels[$key] : $key;
            }
            return new WP_Error('missing_credentials', 'MastodonR is not fully configured. Missing: ' . implode(', ', $names) . '.');
        }

        $image_data = $this->resolve_first_image($post);
        if (is_wp_error($image_data)) {
            return $image_data;
        }

        $caption = $this->build_caption($post);
        $tags = array();
        if (!empty($settings['default_tags'])) {
            $tags = array_merge($tags, preg_split('/\s+/', trim($settings['default_tags'])));
        }
        $tags = $this->normalize_tags(array_merge($tags, $this->extract_exif_tags($image_data['path'])));
        $hashtags = '';
        foreach ($tags as $tag) {
            $hashtags .= ' #' . preg_replace('/[^a-zA-Z0-9_]/', '', $tag);
        }
        $status = trim($post->post_title . "\n\n" . $caption . $hashtags);
        $status = mb_substr($status, 0, 500);

        $media = $this->upload_media($settings, $image_data['path'], mb_substr($caption, 0, 1500));
        if (!empty($image_data['temp']) && file_exists($image_data['path'])) {
            @unlink($image_data['path']);
        }
        if (is_wp_error($media)) {
            return $media;
        }

        $result = $this->api_request($settings, '/api/v1/statuses', 'POST', array(
            'status' => $status,
            'media_ids[]' => $media['id'],
            'visibility' => $settings['visibility'],
        ));
        if (is_wp_error($result)) {
            if (!$is_manual) {
                error_log('MastodonR status failed for post ' . $post_id . ': ' . $result->get_error_message());
            }
            return $result;
        }

        update_post_meta($post_id, self::META_LAST_STATUS_ID, sanitize_text_field($result['id']));
        update_post_meta($post_id, self::META_LAST_STATUS_TIME, gmdate('Y-m-d H:i:s'));
        if (!empty($result['url'])) {
            update_post_meta($post_id, self::META_LAST_STATUS_URL, esc_url_raw($result['url']));
        }
        return $result['id'];
    }

    private function upload_media($settings, $path, $description) {
        if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
            return new WP_Error('curl_missing', 'PHP cURL is required for Mastodon media uploads.');
        }

        if (!file_exists($path)) {
            return new WP_Error('file_missing', 'Image file does not exist at upload time.');
        }
        if (filesize($path) === 0) {
            return new WP_Error('file_empty', 'Image file is empty at upload time.');
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'application/octet-stream';

        $handle = curl_init($settings['instance_url'] . '/api/v2/media');
        curl_setopt_array($handle, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                'file' => curl_file_create($path, $mime, basename($path)),
                'description' => $description,
            ),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $settings['access_token'],
                'Accept: application/json',
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ));

        $response_body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($handle);
        curl_close($handle);

        if ($response_body === false) {
            return new WP_Error('media_upload_transport', 'Mastodon media upload transport failed: ' . $curl_error);
        }

        $data = json_decode($response_body, true);
        if ($code < 200 || $code >= 300 || empty($data['id'])) {
            return new WP_Error('media_upload_failed', 'Mastodon media upload failed: ' . $this->format_api_error($code, $data, $response_body));
        }
        return $data;
    }

    private function api_request($settings, $path, $method = 'GET', $body = array()) {
        $args = array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . $settings['access_token'], 'Accept' => 'application/json'));
        if ($method === 'POST') {
            $args['body'] = $body;
            $response = wp_remote_post($settings['instance_url'] . $path, $args);
        } else {
            $response = wp_remote_get($settings['instance_url'] . $path, $args);
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        if ($code < 200 || $code >= 300) {
            $message = 'Mastodon API request failed: ' . $this->format_api_error($code, $data, $response_body);
            return new WP_Error('api_request_failed', $message);
        }
        return is_array($data) ? $data : array();
    }

    private function format_api_error($code, $data, $response_body) {
        if (is_array($data)) {
            if (!empty($data['error_description'])) {
                return sanitize_text_field($data['error_description']) . ' (HTTP ' . $code . ')';
            }
            if (!empty($data['error'])) {
                return sanitize_text_field($data['error']) . ' (HTTP ' . $code . ')';
            }
            if (!empty($data['details']) && is_array($data['details'])) {
                $details = array();
                foreach ($data['details'] as $field => $messages) {
                    $details[] = $field . ': ' . implode(', ', array_map('sanitize_text_field', (array) $messages));
                }
                return implode('; ', $details) . ' (HTTP ' . $code . ')';
            }
        }

        $body = trim(wp_strip_all_tags((string) $response_body));
        return ($body !== '' ? mb_substr($body, 0, 300) . ' ' : '') . '(HTTP ' . $code . ')';
    }

    private function resolve_first_image($post) {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $match)) {
            $resolved = $this->resolve_image_src_to_path($match[1]);
            if (!is_wp_error($resolved)) {
                return $resolved;
            }
        }
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $path = get_attached_file($thumb_id);
            if ($path && file_exists($path)) {
                return array('path' => $path, 'temp' => false);
            }
        }
        return new WP_Error('no_image', 'No usable image was found. Add an image to the post content or set a featured image.');
    }

    private function resolve_image_src_to_path($src) {
        $attachment_id = attachment_url_to_postid($src);
        if ($attachment_id) {
            $path = get_attached_file($attachment_id);
            if ($path && file_exists($path)) {
                return array('path' => $path, 'temp' => false);
            }
        }
        if (filter_var($src, FILTER_VALIDATE_URL)) {
            $tmp = download_url($src);
            if (!is_wp_error($tmp) && file_exists($tmp)) {
                return array('path' => $tmp, 'temp' => true);
            }
        }
        return new WP_Error('image_not_resolved', 'Unable to resolve first image source to a local file.');
    }

    private function build_caption($post) {
        $content = wp_strip_all_tags($post->post_content, true);
        return mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 5000);
    }

    private function extract_exif_tags($image_path) {
        $tags = array();
        if (!function_exists('exif_read_data')) {
            return $tags;
        }
        $exif = @exif_read_data($image_path, null, true);
        if (!$exif || !is_array($exif)) {
            return $tags;
        }
        $wanted = array('IFD0' => array('Make', 'Model', 'ImageDescription'), 'EXIF' => array('LensModel', 'DateTimeOriginal', 'FNumber', 'ExposureTime', 'FocalLength', 'ISOSpeedRatings'));
        foreach ($wanted as $section => $keys) {
            if (empty($exif[$section]) || !is_array($exif[$section])) {
                continue;
            }
            foreach ($keys as $key) {
                if (!isset($exif[$section][$key])) {
                    continue;
                }
                $value = is_array($exif[$section][$key]) ? implode('_', array_map('strval', $exif[$section][$key])) : (string) $exif[$section][$key];
                foreach (preg_split('/[\s,;:\/\\]+/', trim($value)) as $part) {
                    $part = sanitize_title($part);
                    if ($part !== '') {
                        $tags[] = $part;
                    }
                }
            }
        }
        return $tags;
    }

    private function normalize_tags($tags) {
        $clean = array();
        foreach ($tags as $tag) {
            $tag = strtolower(trim(sanitize_text_field((string) $tag)));
            $tag = preg_replace('/[^a-z0-9_\-]/', '', preg_replace('/\s+/', '_', $tag));
            if ($tag !== '') {
                $clean[] = $tag;
            }
        }
        return array_slice(array_values(array_unique($clean)), 0, 20);
    }

    private function get_settings() {
        $defaults = array('instance_url' => '', 'default_tags' => 'wordpress mastodon', 'visibility' => 'public', 'client_id' => '', 'client_secret' => '', 'access_token' => '', 'registered_scopes' => '', 'account' => array());
        $saved = get_option(self::OPTION_KEY, array());
        return array_merge($defaults, is_array($saved) ? $saved : array());
    }

    private function get_missing_config($settings) {
        $missing = array();
        foreach (array('instance_url', 'client_id', 'client_secret', 'access_token') as $key) {
            if (empty($settings[$key])) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function sanitize_visibility($visibility) {
        $allowed = array('public', 'unlisted', 'private', 'direct');
        return in_array($visibility, $allowed, true) ? $visibility : 'public';
    }

    private function set_admin_notice($type, $message) {
        if (is_user_logged_in()) {
            set_transient('mastodonr_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
        }
    }

    public function render_admin_notice() {
        if (!is_user_logged_in()) {
            return;
        }
        $key = 'mastodonr_notice_' . get_current_user_id();
        $notice = get_transient($key);
        $query_type = isset($_GET['mastodonr_notice']) ? sanitize_key(wp_unslash($_GET['mastodonr_notice'])) : '';

        if (!$notice && in_array($query_type, array('success', 'error'), true)) {
            $notice = array(
                'type' => $query_type,
                'message' => $query_type === 'success'
                    ? 'MastodonR completed the send request.'
                    : 'MastodonR could not complete the send request. Check the WordPress error log for details.',
            );
        }

        if (!$notice || !is_array($notice)) {
            return;
        }
        if ($query_type === '') {
            delete_transient($key);
        }
        $class = 'notice notice-info';
        if (!empty($notice['type']) && $notice['type'] === 'error') {
            $class = 'notice notice-error';
        } elseif (!empty($notice['type']) && $notice['type'] === 'success') {
            $class = 'notice notice-success';
        }
        echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        if ($query_type !== '') {
            echo '<script>(function(){var url=new URL(window.location.href);url.searchParams.delete("mastodonr_notice");window.history.replaceState({},document.title,url.toString());}());</script>';
        }
    }
}

new MastodonR_To_Mastodon();