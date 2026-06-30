<?php
namespace TagForge;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TagForge\Resend
 *
 * Provides the [tagforge_resend_download] shortcode.
 * Displays a form asking for order number + billing email.
 * Validates the pair against WooCommerce orders, regenerates
 * a fresh download token and emails the new link to the
 * billing email on the order — never displays the URL on screen.
 *
 * Security model:
 *   - Both order number AND billing email must match
 *   - Fresh link is sent to the email on the order (not user input)
 *   - Rate limited: 3 attempts per IP per 10 minutes via transient
 *   - No account required
 */
class Resend {

    const RATE_LIMIT_MAX      = 3;
    const RATE_LIMIT_WINDOW   = 600; // 10 minutes in seconds

    public static function register() : void {
        add_shortcode( 'tagforge_resend_download', [ __CLASS__, 'render' ] );
    }

    /**
     * Shortcode handler — renders form or processes submission.
     */
    public static function render( array $atts = [] ) : string {
        // Process form submission
        if ( isset( $_POST['tagforge_resend_nonce'] ) ) {
            return self::handle_submission();
        }
        return self::render_form();
    }

    // ── Form HTML ────────────────────────────────────────────────────────────

    private static function render_form( string $message = '', string $type = '' ) : string {
        $msg_html = '';
        if ( $message ) {
            $colour = $type === 'success' ? '#0F6E56' : '#E70028';
            $bg     = $type === 'success' ? '#E1F5EE' : '#FCEBEB';
            $msg_html = sprintf(
                '<div style="margin:0 0 20px;padding:14px 16px;background:%s;border-left:3px solid %s;border-radius:0 8px 8px 0;font-family:Arial,sans-serif;font-size:14px;color:%s;line-height:1.6;">%s</div>',
                esc_attr( $bg ),
                esc_attr( $colour ),
                esc_attr( $colour ),
                wp_kses_post( $message )
            );
        }

        ob_start();
        ?>
        <div class="tfresend-wrap" style="max-width:520px;margin:0 auto;font-family:Arial,sans-serif;">
            <?php echo $msg_html; ?>
            <form method="post" class="tfresend-form" style="background:#f9f7fa;border:1px solid #e8e0ee;border-radius:12px;padding:28px 32px;">
                <?php wp_nonce_field( 'tagforge_resend_download', 'tagforge_resend_nonce' ); ?>

                <div style="margin-bottom:20px;">
                    <label for="tfresend_order" style="display:block;font-size:13px;font-weight:600;color:#1E1E2E;margin-bottom:6px;letter-spacing:0.02em;">
                        Order number
                    </label>
                    <input
                        type="text"
                        id="tfresend_order"
                        name="tfresend_order"
                        placeholder="e.g. XAV-TF-001 or just 1"
                        required
                        style="width:100%;padding:10px 14px;border:1px solid #d0c8da;border-radius:8px;font-size:14px;color:#1E1E2E;background:#fff;box-sizing:border-box;"
                        value="<?php echo esc_attr( $_POST['tfresend_order'] ?? '' ); ?>"
                    >
                    <p style="margin:6px 0 0;font-size:12px;color:#888899;">You'll find this in your original order confirmation email.</p>
                </div>

                <div style="margin-bottom:24px;">
                    <label for="tfresend_email" style="display:block;font-size:13px;font-weight:600;color:#1E1E2E;margin-bottom:6px;letter-spacing:0.02em;">
                        Email address used at checkout
                    </label>
                    <input
                        type="email"
                        id="tfresend_email"
                        name="tfresend_email"
                        placeholder="you@example.com"
                        required
                        style="width:100%;padding:10px 14px;border:1px solid #d0c8da;border-radius:8px;font-size:14px;color:#1E1E2E;background:#fff;box-sizing:border-box;"
                        value="<?php echo esc_attr( $_POST['tfresend_email'] ?? '' ); ?>"
                    >
                </div>

                <button
                    type="submit"
                    style="display:inline-block;background:linear-gradient(135deg,#8C167B,#E70028,#F3882A);color:#fff;border:none;border-radius:24px;padding:12px 28px;font-size:14px;font-weight:600;cursor:pointer;font-family:Arial,sans-serif;width:100%;"
                >
                    Send me a fresh download link &rarr;
                </button>

                <p style="margin:16px 0 0;font-size:12px;color:#aaaaaa;text-align:center;">
                    We'll send the link to the email address on your order &mdash; not to any email you type here.
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Form submission handler ───────────────────────────────────────────────

    private static function handle_submission() : string {

        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['tagforge_resend_nonce'] ?? '', 'tagforge_resend_download' ) ) {
            return self::render_form( 'Security check failed. Please refresh the page and try again.', 'error' );
        }

        // Rate limiting
        $ip      = self::get_ip();
        $ip_key  = 'tfresend_' . md5( $ip );
        $history = (array) get_transient( $ip_key );
        $now     = time();
        $history = array_filter( $history, fn( $t ) => ( $now - $t ) < self::RATE_LIMIT_WINDOW );

        if ( count( $history ) >= self::RATE_LIMIT_MAX ) {
            return self::render_form( 'Too many attempts. Please wait 10 minutes and try again.', 'error' );
        }

        // Log this attempt
        $history[] = $now;
        set_transient( $ip_key, array_values( $history ), self::RATE_LIMIT_WINDOW + 5 );

        // Sanitise inputs
        $order_input = sanitize_text_field( $_POST['tfresend_order'] ?? '' );
        $email_input = sanitize_email( $_POST['tfresend_email'] ?? '' );

        if ( ! $order_input || ! $email_input ) {
            return self::render_form( 'Please fill in both fields.', 'error' );
        }

        // Normalise input — accept XAV-TF-001, XAV-TF-1, 001, 1, #5 etc.
        $order_input_clean = strtoupper( trim( ltrim( $order_input, '#' ) ) );

        // If customer typed just a number, pad and prefix it
        if ( is_numeric( $order_input_clean ) ) {
            $order_input_clean = 'XAV-TF-' . str_pad( (int) $order_input_clean, 3, '0', STR_PAD_LEFT );
        } elseif ( preg_match( '/^XAV-TF-(\d+)$/', $order_input_clean, $m ) ) {
            // Already in correct format — normalise padding
            $order_input_clean = 'XAV-TF-' . str_pad( (int) $m[1], 3, '0', STR_PAD_LEFT );
        } else {
            return self::render_form( 'Please enter a valid order number (e.g. XAV-TF-001 or just 1).', 'error' );
        }

        // Look up order by sequential number meta — HPOS compatible
        $order    = null;
        $order_id = 0;

        // Method 1: wc_get_orders with meta_query (works with both HPOS and legacy)
        $orders = wc_get_orders( [
            'meta_query' => [ [
                'key'     => '_tagforge_seq_number',
                'value'   => $order_input_clean,
                'compare' => '=',
            ] ],
            'limit'  => 1,
            'return' => 'objects',
            'status' => 'any',
        ] );

        if ( ! empty( $orders ) ) {
            $order    = $orders[0];
            $order_id = $order->get_id();
        }

        // Method 2: fallback — direct DB query on order meta table
        if ( ! $order ) {
            global $wpdb;

            // Try HPOS orders meta table first
            $hpos_table = $wpdb->prefix . 'wc_orders_meta';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_table}'" ) === $hpos_table ) {
                $found_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT order_id FROM {$hpos_table} WHERE meta_key = '_tagforge_seq_number' AND meta_value = %s LIMIT 1",
                    $order_input_clean
                ) );
                if ( $found_id ) {
                    $order    = wc_get_order( (int) $found_id );
                    $order_id = (int) $found_id;
                }
            }

            // Try legacy postmeta table
            if ( ! $order ) {
                $found_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tagforge_seq_number' AND meta_value = %s LIMIT 1",
                    $order_input_clean
                ) );
                if ( $found_id ) {
                    $order    = wc_get_order( (int) $found_id );
                    $order_id = (int) $found_id;
                }
            }
        }

        if ( ! $order ) {
            error_log( '[TagForge Resend] No order found for seq number: ' . $order_input_clean );
            return self::render_form( self::not_found_message(), 'error' );
        }

        // Validate email matches billing email on order
        // Use hash comparison to avoid timing attacks
        $billing_email = strtolower( trim( $order->get_billing_email() ) );
        $input_email   = strtolower( trim( $email_input ) );

        if ( ! hash_equals( md5( $billing_email ), md5( $input_email ) ) ) {
            return self::render_form( self::not_found_message(), 'error' );
        }

        // Check if this is a WooCommerce downloadable product order
        // If so, direct customer to their account downloads instead
        $all_downloadable = true;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && ! $product->is_downloadable() ) {
                $all_downloadable = false;
                break;
            }
        }
        if ( $all_downloadable ) {
            return self::render_form(
                'Your order contains a standard downloadable product. You can access your download any time from <a href="' . esc_url( wc_get_account_endpoint_url( 'downloads' ) ) . '">My Account → Downloads</a>.',
                'success'
            );
        }
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && strpos( strtolower( $product->get_sku() ), 'tf-' ) === 0 ) {
                $has_tagforge = true;
                break;
            }
        }
        // Also check if order meta exists as fallback
        if ( ! $has_tagforge && $order->get_meta( '_tagforge_download_url' ) ) {
            $has_tagforge = true;
        }

        if ( ! $has_tagforge ) {
            return self::render_form( self::not_found_message(), 'error' );
        }

        // Regenerate the download token — reuse existing JSON file, just issue a fresh token
        try {
            // Clear any stale mutex flag from previous failed attempts
            $order->delete_meta_data( '_tagforge_building' );
            $order->save();
            // Find the existing JSON file path from the current (possibly expired) download URL
            // The file itself never expires — only the transient token does
            $existing_url = $order->get_meta( '_tagforge_download_url' );
            $existing_path = null;

            if ( $existing_url ) {
                // Extract old token and look up the file path from transient (may still exist)
                preg_match( '/token=([a-f0-9]+)/i', $existing_url, $m );
                if ( ! empty( $m[1] ) ) {
                    $existing_path = get_transient( 'tagforge_download_' . $m[1] );
                }
            }

            // If transient expired, find the file via upload dir pattern
            if ( ! $existing_path ) {
                $uploads  = wp_get_upload_dir();
                $pattern  = $uploads['basedir'] . 'tagforge/tagforge-order-' . $order_id . '-*.json';
                $files    = glob( $pattern );
                if ( ! empty( $files ) ) {
                    // Use most recently created file
                    usort( $files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
                    $existing_path = $files[0];
                }
            }

            if ( ! $existing_path || ! file_exists( $existing_path ) ) {
                // No existing file — need a full rebuild
                // This happens on very old orders where the file was cleaned up
                Order::handle_order( $order_id );
                $order = wc_get_order( $order_id );
                $url   = $order->get_meta( '_tagforge_download_url' );
            } else {
                // File exists — just issue a fresh token
                $opts        = \TagForge\Helpers::get_options();
                $expiry_days = max( 1, (int) ( $opts['expiry_days'] ?? 7 ) );
                $expires_ts  = time() + ( $expiry_days * DAY_IN_SECONDS );
                $token       = bin2hex( random_bytes( 16 ) );

                set_transient( 'tagforge_download_' . $token, $existing_path, $expiry_days * DAY_IN_SECONDS );

                $url = add_query_arg( [
                    'action' => 'tagforge_download',
                    'token'  => $token,
                ], admin_url( 'admin-post.php' ) );

                // Update order meta with fresh URL and expiry
                $order->update_meta_data( '_tagforge_download_url',     $url );
                $order->update_meta_data( '_tagforge_download_expires', $expires_ts );
                $order->save();
            }

            // Fetch fresh order
            $order = wc_get_order( $order_id );
            $url   = $order->get_meta( '_tagforge_download_url' );

            if ( ! $url ) {
                return self::render_form( 'We had trouble generating your link. Please <a href="mailto:amit@tagforge.io">contact support</a> with your order number.', 'error' );
            }

            // Send the fresh link to the billing email on the order
            self::send_resend_email( $order, $url );

            // Add order note
            $order->add_order_note( 'TagForge: Customer requested resend via tagforge.io/resend-download/. Fresh link generated and sent.' );

        } catch ( \Throwable $e ) {
            error_log( '[TagForge] Resend error for order ' . $order_id . ': ' . $e->getMessage() );
            return self::render_form( 'Something went wrong. Please <a href="mailto:amit@tagforge.io">contact support</a> with your order number.', 'error' );
        }

        // Success — do not reveal any URL on screen
        return self::render_form(
            'Done — we\'ve sent a fresh download link to the email address on your order. Check your inbox (and spam folder if you don\'t see it within a few minutes).',
            'success'
        );
    }

    // ── Email ────────────────────────────────────────────────────────────────

    private static function send_resend_email( \WC_Order $order, string $url ) : void {
        $to      = $order->get_billing_email();
        $name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: 'there';
        $expires = (int) $order->get_meta( '_tagforge_download_expires' );
        $when    = $expires ? date_i18n( get_option( 'date_format' ), $expires ) : 'soon';

        $subject = 'Your TagForge download link — fresh copy';

        $body = "Hi {$name},\n\n"
            . "Here's your fresh TagForge container download link. It expires on {$when}.\n\n"
            . "{$url}\n\n"
            . "To import it into GTM: Admin → Import Container → Choose file → Merge → Confirm.\n\n"
            . "If you need help with the import, reply to this email and I'll help you out.\n\n"
            . "Amit Wadhwa\n"
            . "TagForge · A XAVA Division · tagforge.io\n";

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        wp_mail( $to, $subject, $body, $headers );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Deliberately vague error message — don't reveal whether the order
     * exists or not, to prevent enumeration.
     */
    private static function not_found_message() : string {
        return 'We couldn\'t find a TagForge order matching those details. Please check your order number and the email address you used at checkout, then try again. If you\'re still stuck, <a href="mailto:amit@tagforge.io">get in touch</a> with your order confirmation email.';
    }

    private static function get_ip() : string {
        $ip = sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );
        return explode( ',', $ip )[0];
    }
}
