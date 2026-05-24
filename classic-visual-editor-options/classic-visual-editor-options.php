<?php
/**
 * Plugin Name: Classic Visual Editor Options
 * Description: Restores the option to disable the visual editor in user profiles – for old-school WordPress users who still believe that "Code is Poetry."
 * Version: 1.0.4
 * Author: DVeb (Dejan S. Višekruna)
 * Author URI: https://profiles.wordpress.org/supracorona
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: classic-visual-editor-options
 */

if (!defined('ABSPATH')) exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if (is_admin() && !isset($_GET['page']) && isset($_SERVER['PHP_SELF']) && !empty($_SERVER['PHP_SELF'])) {
    $cveo_php_self = sanitize_text_field(wp_unslash($_SERVER['PHP_SELF']));
    if (strpos($cveo_php_self, 'profile.php') !== false) {
        ob_start('cveo_strip_duplicate_rich_editing');
    }
}

function cveo_strip_duplicate_rich_editing($content) {
    preg_match_all('/<tr class="user-rich-editing-wrap">.*?<\/tr>/s', $content, $matches);
    if (!empty($matches[0]) && count($matches[0]) > 1) {
        foreach (array_slice($matches[0], 1) as $redundant) {
            $content = str_replace($redundant, '', $content);
        }
    }
    return $content;
}

// Add checkbox
add_action('personal_options', 'cveo_personal_options_field', 0);
function cveo_personal_options_field($user) {
    if (!user_can($user, 'edit_posts')) return;
    $rich_editing = get_user_meta($user->ID, 'rich_editing', true);
    if ($rich_editing === '') $rich_editing = 'true';
    ?>
    <tr class="user-rich-editing-wrap">
        <th scope="row"><?php esc_html_e('Visual Editor', 'classic-visual-editor-options'); ?></th>
        <td>
            <label for="rich_editing">
                <input name="rich_editing" type="checkbox" id="rich_editing" value="false" <?php checked($rich_editing, 'false'); ?> />
                <?php esc_html_e('Disable the visual editor when writing', 'classic-visual-editor-options'); ?>
            </label>
        </td>
    </tr>
    <?php
}

// Save value
add_action('personal_options_update', 'cveo_save_profile_field');
add_action('edit_user_profile_update', 'cveo_save_profile_field');
function cveo_save_profile_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;

    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'update-user_' . $user_id)) return;

    $input_raw = isset($_POST['rich_editing']) ? sanitize_text_field(wp_unslash($_POST['rich_editing'])) : 'true';
    $input = $input_raw === 'false' ? 'false' : 'true';
    update_user_meta($user_id, 'rich_editing', $input);
}

// Reset all users on deactivation
register_deactivation_hook(__FILE__, 'cveo_force_visual_editor_on_deactivation');
function cveo_force_visual_editor_on_deactivation() {
    $users = get_users(['fields' => ['ID']]);
    foreach ($users as $user) {
        update_user_meta($user->ID, 'rich_editing', 'true');
    }
}

// Show admin notice with dismiss button
add_action('admin_notices', 'cveo_missing_plugin_notice');
function cveo_missing_plugin_notice() {
    if (!current_user_can('edit_posts')) return;
    $rich_editing = get_user_meta(get_current_user_id(), 'rich_editing', true);
    $dismissed = get_user_meta(get_current_user_id(), 'cveo_dismiss_notice', true);
    if ($rich_editing === 'false' && !$dismissed) {
        $profile_url = get_edit_profile_url();
        ?>
        <div class="notice notice-warning cveo-visual-editor-warning">
            <p>
                <strong><?php esc_html_e('Your visual editor is currently disabled.', 'classic-visual-editor-options'); ?></strong><br>
                <?php esc_html_e('If this was caused by a deactivated or removed plugin, you can re-enable it manually from your profile.', 'classic-visual-editor-options'); ?>
                <a href="<?php echo esc_url($profile_url); ?>"><?php esc_html_e('Go to your profile', 'classic-visual-editor-options'); ?></a>.
                <button type="button" class="button button-secondary cveo-dismiss-button"
                    style="float: right; margin-top: -10px; margin-left: 10px;">
                    ✕ <?php esc_html_e('Dismiss', 'classic-visual-editor-options'); ?>
                </button>
            </p>
        </div>
        <?php
    }
}

// Handle dismiss via AJAX
add_action('wp_ajax_cveo_dismiss_notice', 'cveo_dismiss_notice_callback');
function cveo_dismiss_notice_callback() {
    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), 'cveo_dismiss_notice', '1');
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

// JS enhancements: move row and handle dismiss
add_action('admin_enqueue_scripts', 'cveo_enqueue_inline_js');
function cveo_enqueue_inline_js($hook) {
    if (!in_array($hook, ['profile.php', 'user-edit.php', 'index.php', 'edit.php', 'post.php', 'post-new.php'], true)) return;

    wp_register_script('cveo-inline-script', '', [], '1.0.4', true);
    $inline_js  = '';
    $inline_js .= 'document.addEventListener("DOMContentLoaded", function () {';
    $inline_js .= 'const row = document.querySelector("tr.user-rich-editing-wrap");';
    $inline_js .= 'const table = document.querySelector(".form-table");';
    $inline_js .= 'if (row && table) table.insertBefore(row, table.firstChild);';
    $inline_js .= 'const notice = document.querySelector(".cveo-visual-editor-warning");';
    $inline_js .= 'if (notice) {';
    $inline_js .= '  const button = notice.querySelector(".cveo-dismiss-button");';
    $inline_js .= '  if (button) {';
    $inline_js .= '    button.addEventListener("click", function () {';
    $inline_js .= '      fetch(ajaxurl, {';
    $inline_js .= '        method: "POST",';
    $inline_js .= '        credentials: "same-origin",';
    $inline_js .= '        headers: { "Content-Type": "application/x-www-form-urlencoded" },';
    $inline_js .= '        body: "action=cveo_dismiss_notice"';
    $inline_js .= '      }).then(() => {';
    $inline_js .= '        notice.remove();';
    $inline_js .= '      });';
    $inline_js .= '    });';
    $inline_js .= '  }';
    $inline_js .= '}';
    $inline_js .= '});';
    wp_add_inline_script('cveo-inline-script', $inline_js);
    wp_enqueue_script('cveo-inline-script');
}
