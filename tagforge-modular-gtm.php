<?php
/**
 * Plugin Name: TagForge Modular GTM
 * Description: Modular GTM builder for WooCommerce with dynamic modules, stylized product UI, secure timed downloads, email injection, admin test & settings.
 * Version: 5.3.1
 * Author: Amit Wadhwa
 * Author URI: https://xava.ie
 * License: GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Tested up to: 6.7
 * Update URI: github.com/your-org/tagforge-modular-gtm
 */
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'TAGFORGE_VERSION', '5.3.1' );
define( 'TAGFORGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAGFORGE_URL', plugin_dir_url( __FILE__ ) );
define( 'TAGFORGE_UPLOAD_SUBDIR', 'tagforge' );
if ( ! defined( 'TAGFORGE_DEBUG' ) ) { $opts = (array) get_option('tagforge_options', []); define( 'TAGFORGE_DEBUG', ! empty( $opts['debug'] ) ); }

// Optional GitHub updater (PUC) - only if library exists.
if ( file_exists( TAGFORGE_DIR . 'includes/plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once TAGFORGE_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        $tagforgeUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/your-org/tagforge-modular-gtm/',
            __FILE__,
            'tagforge-modular-gtm'
        );
        if ( method_exists( $tagforgeUpdateChecker->getVcsApi(), 'enableReleaseAssets' ) ) {
            $tagforgeUpdateChecker->getVcsApi()->enableReleaseAssets();
        }
        // For private repos: $tagforgeUpdateChecker->setAuthentication('ghp_xxx');
    }
}

require_once TAGFORGE_DIR . 'includes/helpers.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-factory.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-order.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-admin.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-product-ui.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-resend.php';

add_action( 'init', [ '\\TagForge\\Resend', 'register' ] );

add_action('admin_menu', ['\\TagForge\\Admin', 'menus']);
add_action('admin_init', ['\\TagForge\\Admin', 'register_settings']);
add_action('add_meta_boxes', ['\\TagForge\\Admin', 'register_order_meta_box']);
add_action('admin_post_nopriv_tagforge_download', 'tagforge_download_handler');
add_action('admin_post_tagforge_download', 'tagforge_download_handler');

function tagforge_download_handler() {
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    if ( ! $token ) wp_die('Missing token');

    $path = get_transient( "tagforge_download_{$token}" );
    if ( ! $path ) wp_die('Invalid or expired link');

    $uploads = wp_get_upload_dir();

    // FIX: guard realpath() against returning false (e.g. directory not yet created).
    // Previously, strpos( $real, false ) would silently pass or behave unexpectedly.
    $base = realpath( $uploads['basedir'] );
    $real = realpath( $path );
    if ( ! $real || ! $base || strpos( $real, $base ) !== 0 ) {
        wp_die('Invalid path');
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . basename($real) . '"');
    header('Content-Length: ' . filesize($real));
    readfile($real);
    exit;
}

add_action('woocommerce_order_status_changed', function($order_id, $from, $to){
    if (! in_array($to, ['processing','completed'], true)) return;
    try { \TagForge\Order::handle_order($order_id); } catch (\Throwable $e) { error_log('[TagForge] Error: '.$e->getMessage()); }
}, 1, 3);

add_action('woocommerce_order_status_completed', function($order_id){
    try { \TagForge\Order::ensure_artifact($order_id); } catch (\Throwable $e) { error_log('[TagForge] Error: '.$e->getMessage()); }
});

add_action('woocommerce_payment_complete', function($order_id){
    try { \TagForge\Order::handle_order($order_id); } catch (\Throwable $e) { error_log('[TagForge] Error: '.$e->getMessage()); }
});

// Inject download link into WooCommerce completed order email
add_action('woocommerce_email_after_order_table', function($order, $sent_to_admin, $plain_text, $email){
    // Guard against WooCommerce email preview which passes false as the order object
    if ( ! is_a( $order, 'WC_Order' ) || ! $order->get_id() ) return;
    if ($sent_to_admin) return;
    $allowed = ['customer_completed_order'];
    if (is_object($email) && method_exists($email,'get_id') && !in_array($email->get_id(), $allowed, true)) return;

    // Ensure artifact exists
    if (! $order->get_meta('_tagforge_download_url')) {
        try { \TagForge\Order::ensure_artifact($order->get_id()); } catch (\Throwable $e) {}
    }

    // Re-fetch from DB - in-memory object may not reflect writes from this request
    $order   = wc_get_order( $order->get_id() );
    $url     = $order->get_meta('_tagforge_download_url');
    $expires = (int)$order->get_meta('_tagforge_download_expires');
    if (!$url || !$expires) return;

    $when = date_i18n(get_option('date_format').' '.get_option('time_format'), $expires);
    if ($plain_text) {
        echo "\n== Your GTM Container ==\nDownload your pre-filled container (expires {$when}):\n{$url}\n\nImport it into GTM: Admin > Import Container > Choose file > Merge > Confirm.\n";
    } else {
        echo '<div style="margin:24px 0;padding:20px 24px;background:#f8f8f8;border-left:4px solid #E70028;font-family:Arial,sans-serif">';
        echo '<h2 style="margin:0 0 8px;font-size:18px;color:#1E2130">Your GTM container is forged and ready</h2>';
        echo '<p style="margin:0 0 16px;font-size:14px;color:#555">Your container has been forged and is ready to import. Link expires ' . esc_html($when) . '.</p>';
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#8C167B,#E70028,#F3882A);color:#fff;text-decoration:none;border-radius:8px;font-size:15px;font-weight:bold">Download your container &rarr;</a>';
        echo '<p style="margin:16px 0 0;font-size:12px;color:#999">In GTM: Admin &rsaquo; Import Container &rsaquo; Choose file &rsaquo; Merge &rsaquo; Confirm &amp; Publish</p>';
        echo '</div>';
    }
}, 10, 4);

add_action('admin_post_tagforge_regen', function(){
    $order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
    if ( ! current_user_can( 'edit_shop_order', $order_id ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'tagforge_regen_' . $order_id );

    $order = wc_get_order( $order_id );
    if ( $order ) {
        // Clear existing artifact - forces a full fresh rebuild
        $order->delete_meta_data( '_tagforge_download_url' );
        $order->delete_meta_data( '_tagforge_download_expires' );
        $order->delete_meta_data( '_tagforge_building' );
        $order->save();
        $order->add_order_note( 'TagForge: Container re-forged by admin.' );

        // Rebuild container
        try { \TagForge\Order::handle_order( $order_id ); } catch ( \Throwable $e ) {
            error_log( '[TagForge] Regen error: ' . $e->getMessage() );
        }

        // Re-send delivery email with new link
        $order = wc_get_order( $order_id ); // fresh fetch after rebuild
        $url = $order->get_meta( '_tagforge_download_url' );
        if ( $url ) {
            $mailer = WC()->mailer()->get_emails();
            if ( isset( $mailer['WC_Email_Customer_Completed_Order'] ) ) {
                $mailer['WC_Email_Customer_Completed_Order']->trigger( $order_id, $order );
                $order->add_order_note( 'TagForge: Delivery email resent to ' . $order->get_billing_email() . '.' );
            }
        }
    }

    wp_safe_redirect( get_edit_post_link( $order_id, '' ) );
    exit;
});

add_action('woocommerce_order_status_completed', function($order_id){
    // Only run ensure_artifact if status_changed hook did not already handle this order.
    // The lock transient will still exist if status_changed ran within the last 30 seconds.
    $lock_key = 'tagforge_lock_' . $order_id;
    if ( get_transient( $lock_key ) ) return; // status_changed already handled it
    try { \TagForge\Order::ensure_artifact($order_id); } catch (\Throwable $e) { error_log('[TagForge] Error late build: '.$e->getMessage()); }
});

// ── v4.0 AI Builder ───────────────────────────────────────────────────────────
// Additive only. All v3.x hooks and classes above are untouched.

require_once TAGFORGE_DIR . 'includes/class-tagforge-ai-db.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-ai-admin.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-ai-builder.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-ai-pricing.php';
require_once TAGFORGE_DIR . 'includes/class-tagforge-zoho.php';

// Activation: create sessions table
register_activation_hook( __FILE__, [ '\\TagForge\\AI_DB', 'on_activate' ] );

// Deactivation: optionally drop table (only if admin ticked the option)
register_deactivation_hook( __FILE__, [ '\\TagForge\\AI_DB', 'on_deactivate' ] );

// Maybe upgrade table schema on each load
add_action( 'init', [ '\\TagForge\\AI_DB', 'maybe_upgrade' ] );

// Register AI Builder admin submenus + AJAX handlers
add_action( 'init', [ '\\TagForge\\AI_Admin', 'register' ] );

// Register AI Builder REST endpoints + front-end shortcodes
add_action( 'init', [ '\\TagForge\\AI_Builder', 'register' ] );

// Register dynamic pricing WooCommerce hooks
add_action( 'init', [ '\\TagForge\\AI_Pricing', 'register' ] );
