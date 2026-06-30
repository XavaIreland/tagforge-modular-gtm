<?php
/**
 * TagForge\AI_Pricing
 *
 * Dynamic pricing for the AI Builder custom container product.
 * Price is calculated from the module count stored in the builder session
 * and enforced server-side via WooCommerce cart hooks.
 *
 * The session_id is passed as cart item data. The price override
 * reads the module count from the DB — client-side price display
 * is for UX only; this class is the source of truth.
 *
 * ADDITIVE ONLY — no existing files modified.
 *
 * @package TagForge
 * @since   4.0.0
 */

namespace TagForge;

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Pricing {

    // Option key that stores the builder product ID
    const PRODUCT_ID_OPTION = 'tagforge_builder_product_id';

    // ── Bootstrap ──────────────────────────────────────────────────────

    public static function register() : void {
        // Pass session_id through cart
        add_filter( 'woocommerce_add_cart_item_data',       [ __CLASS__, 'inject_session_into_cart'   ], 10, 3 );

        // Override price in cart from session module count
        add_action( 'woocommerce_before_calculate_totals',  [ __CLASS__, 'apply_session_price'        ], 20    );

        // Persist session_id to order meta
        add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'save_session_to_order' ], 10, 4 );

        // Store custom name in order meta
        add_action( 'woocommerce_checkout_create_order',    [ __CLASS__, 'save_custom_name_to_order'  ], 10, 2 );

        // Pass session to Factory via order meta
        add_filter( 'tagforge_order_modules',               [ __CLASS__, 'inject_session_modules'     ], 10, 2 );

        // Mark session purchased after order placed
        add_action( 'woocommerce_checkout_order_created',   [ __CLASS__, 'mark_session_purchased'     ], 10, 1 );

        // Display custom name above product title in cart/checkout
        add_filter( 'woocommerce_cart_item_name',           [ __CLASS__, 'display_custom_name_in_cart'], 10, 3 );
    }

    // ── Inject session_id into cart item data ──────────────────────────

    public static function inject_session_into_cart( array $cart_item_data, int $product_id, int $variation_id ) : array {
        $builder_product_id = (int) get_option( self::PRODUCT_ID_OPTION, 0 );

        // Only apply to the builder product
        if ( $builder_product_id && $product_id !== $builder_product_id ) {
            return $cart_item_data;
        }

        $session_id  = sanitize_text_field( $_POST['tf_session_id'] ?? '' );
        $custom_name = sanitize_text_field( $_POST['tf_custom_name'] ?? '' );

        if ( $session_id ) {
            $cart_item_data['tf_session_id']  = $session_id;
            $cart_item_data['tf_custom_name'] = $custom_name;
            // Unique key ensures WooCommerce treats each session as a distinct cart item
            $cart_item_data['tf_unique'] = md5( $session_id . time() );
        }

        return $cart_item_data;
    }

    // ── Apply price from session module count ──────────────────────────

    public static function apply_session_price( \WC_Cart $cart ) : void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) return;

        $builder_product_id = (int) get_option( self::PRODUCT_ID_OPTION, 0 );

        foreach ( $cart->get_cart() as $key => $item ) {
            if ( $builder_product_id && (int) $item['product_id'] !== $builder_product_id ) continue;

            $session_id = $item['tf_session_id'] ?? '';
            if ( ! $session_id ) continue;

            // Get module count from DB session
            global $wpdb;
            $table   = AI_DB::table_name();
            $modules = $wpdb->get_var(
                $wpdb->prepare( "SELECT modules FROM {$table} WHERE session_id = %s", $session_id )
            );

            $module_list = json_decode( $modules ?? '[]', true ) ?: [];
            $count       = count( $module_list );

            if ( $count > 0 ) {
                $price = AI_Admin::price_for_count( $count );
                $item['data']->set_price( $price );
            }
        }
    }

    // ── Save session_id to order line item meta ────────────────────────

    public static function save_session_to_order( \WC_Order_Item_Product $item, string $cart_item_key, array $cart_item, \WC_Order $order ) : void {
        if ( ! empty( $cart_item['tf_session_id'] ) ) {
            $item->add_meta_data( '_tf_session_id',  $cart_item['tf_session_id'],  true );
            $item->add_meta_data( '_tf_custom_name', $cart_item['tf_custom_name'] ?? '', true );
        }
    }

    // ── Save custom name to order meta ─────────────────────────────────

    public static function save_custom_name_to_order( \WC_Order $order, array $data ) : void {
        foreach ( $order->get_items() as $item ) {
            $session_id  = $item->get_meta( '_tf_session_id' );
            $custom_name = $item->get_meta( '_tf_custom_name' );
            if ( $session_id ) {
                $order->update_meta_data( '_tf_session_id',  $session_id  );
                $order->update_meta_data( '_tf_custom_name', $custom_name );
                break;
            }
        }
    }

    // ── Inject session modules into Factory via filter ─────────────────
    // class-tagforge-order.php fires tagforge_order_modules filter
    // (needs adding to Order class — see note below)

    public static function inject_session_modules( array $modules, \WC_Order $order ) : array {
        $session_id = $order->get_meta( '_tf_session_id' );
        if ( ! $session_id ) return $modules;

        global $wpdb;
        $table        = AI_DB::table_name();
        $session_mods = $wpdb->get_var(
            $wpdb->prepare( "SELECT modules FROM {$table} WHERE session_id = %s", $session_id )
        );

        $from_session = json_decode( $session_mods ?? '[]', true ) ?: [];
        return ! empty( $from_session ) ? $from_session : $modules;
    }

    // ── Mark session as purchased ──────────────────────────────────────

    public static function mark_session_purchased( \WC_Order $order ) : void {
        $session_id = $order->get_meta( '_tf_session_id' );
        if ( ! $session_id ) return;

        global $wpdb;
        $wpdb->update(
            AI_DB::table_name(),
            [
                'status'     => 'purchased',
                'order_id'   => $order->get_id(),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'session_id' => $session_id ],
            [ '%s', '%d', '%s' ],
            [ '%s' ]
        );
    }

    // ── Display custom name above product in cart/checkout ─────────────

    public static function display_custom_name_in_cart( string $name, array $cart_item, string $key ) : string {
        $custom_name = $cart_item['tf_custom_name'] ?? '';
        if ( $custom_name ) {
            $name = '<span class="tf-cart-build-label">Your build: ' . esc_html( $custom_name ) . '</span><br>' . $name;
        }
        return $name;
    }

    // ── Helper: get builder product ID ────────────────────────────────

    public static function get_builder_product_id() : int {
        return (int) get_option( self::PRODUCT_ID_OPTION, 0 );
    }

    // ── Helper: set builder product ID (called from admin or setup) ────

    public static function set_builder_product_id( int $id ) : void {
        update_option( self::PRODUCT_ID_OPTION, $id );
    }
}
