<?php
namespace TagForge;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TagForge\Order - v3.5.7
 *
 * Supported Add-ons field names (must match product Add-ons titles exactly):
 *   'GA4 ID'                        → GA4_MEASUREMENT_ID
 *   'Meta Pixel ID'                 → PIXEL_ID
 *   'Google Ads Conversion ID'      → GADS_CONVERSION_ID
 *   'Google Ads Conversion Label'   → GADS_CONVERSION_LABEL
 *   'LinkedIn Partner ID'           → LI_PARTNER_ID
 *   'TikTok Pixel ID'               → TIKTOK_PIXEL_ID
 *   'Microsoft Clarity Project ID'  → CLARITY_PROJECT_ID
 *   'Hotjar Site ID'                → HOTJAR_SITE_ID
 *   'Bing UET Tag ID'               → BING_UET_TAG_ID
 *   'Pinterest Tag ID'              → PINTEREST_TAG_ID
 */
class Order {

    public static function ensure_artifact( int $order_id ) : void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        // Skip if all items are WooCommerce downloadable — Factory not needed
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && ! $product->is_downloadable() ) {
                // Has at least one dynamic item — proceed
                if ( $order->get_meta( '_tagforge_download_url' ) ) return;
                self::handle_order( $order_id );
                return;
            }
        }
        // All downloadable — skip silently
    }

    public static function handle_order( int $order_id ) : void {
        if ( ! class_exists( '\WC_Order' ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Skip Factory entirely for orders containing only WooCommerce
        // downloadable products — these are static files served by WooCommerce
        // directly (e.g. Consent Timing Fix standalone). The Factory is only
        // needed for dynamically assembled containers.
        $has_dynamic = false;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            // A product is "dynamic" if it is NOT a WooCommerce downloadable
            if ( ! $product->is_downloadable() ) {
                $has_dynamic = true;
                break;
            }
        }
        if ( ! $has_dynamic ) {
            Helpers::dbg( $order, 'TagForge: all items are WooCommerce downloadable products — skipping Factory.' );
            return;
        }

        // Mutex via order meta - more reliable than transients on all hosts.
        // If _tagforge_building is set, another process is already handling this order.
        if ( $order->get_meta( '_tagforge_building' ) ) {
            error_log( '[TagForge] Order ' . $order_id . ' - already building, skipping duplicate.' );
            return;
        }
        // Mark as building immediately
        $order->update_meta_data( '_tagforge_building', time() );
        $order->save();

        // Re-check download URL after save - another process may have just completed
        $order = wc_get_order( $order_id );
        if ( $order->get_meta( '_tagforge_download_url' ) ) {
            $order->delete_meta_data( '_tagforge_building' );
            $order->save();
            return;
        }

        $opts = Helpers::get_options();
        Helpers::dbg( $order, "Handling order {$order_id}" );

        $all  = [];
        $vars = [
            'GA4_MEASUREMENT_ID'    => '',
            'PIXEL_ID'              => '',
            'GADS_CONVERSION_ID'    => '',
            'GADS_CONVERSION_LABEL' => '',
            'LI_PARTNER_ID'         => '',
            'TIKTOK_PIXEL_ID'       => '',
            'CLARITY_PROJECT_ID'    => '',
            'HOTJAR_SITE_ID'        => '',
            'BING_UET_TAG_ID'       => '',
            'PINTEREST_TAG_ID'      => '',
        ];

        foreach ( $order->get_items() as $item ) {
            $parsed = self::parse_item_addons( $item );

            $id_map = [
                'ga4_id'                => 'GA4_MEASUREMENT_ID',
                'pixel_id'              => 'PIXEL_ID',
                'gads_conversion_id'    => 'GADS_CONVERSION_ID',
                'gads_conversion_label' => 'GADS_CONVERSION_LABEL',
                'li_id'                 => 'LI_PARTNER_ID',
                'tiktok_id'             => 'TIKTOK_PIXEL_ID',
                'clarity_id'            => 'CLARITY_PROJECT_ID',
                'hotjar_id'             => 'HOTJAR_SITE_ID',
                'bing_id'               => 'BING_UET_TAG_ID',
                'pinterest_id'          => 'PINTEREST_TAG_ID',
            ];
            foreach ( $id_map as $parsed_key => $var_key ) {
                if ( ! empty( $parsed[ $parsed_key ] ) ) {
                    $vars[ $var_key ] = $parsed[ $parsed_key ];
                }
            }

            $defaults = self::product_defaults( $item );
            if ( ! empty( $defaults ) ) {
                foreach ( $defaults as $slug ) $all[] = $slug;
            } else {
                foreach ( $parsed['features'] as $slug ) $all[] = $slug;
                if ( $parsed['platform'] === 'meta'     && ! empty( $vars['PIXEL_ID'] ) )     $all[] = 'facebook-pixel';
                if ( $parsed['platform'] === 'linkedin' && ! empty( $vars['LI_PARTNER_ID'] ) ) $all[] = 'linkedin-insight';
            }
        }

        $global = array_filter( array_map( 'trim', explode( ',', (string) $opts['default_modules_csv'] ) ) );
        foreach ( $global as $slug ) $all[] = $slug;

        $all     = array_values( array_unique( array_filter( $all ) ) );
        $has_ga4 = ! empty( $vars['GA4_MEASUREMENT_ID'] );
        $all     = array_values( array_filter( $all, function( $s ) use ( $has_ga4 ) {
            return $s === 'gtag-basic' ? $has_ga4 : true;
        } ) );

        if ( empty( $all ) ) {
            $order->add_order_note( 'TagForge: No modules resolved - check product defaults and add-on field names.' );
            return;
        }

        Helpers::dbg( $order, 'TagForge vars: '    . wp_json_encode( $vars ) );
        Helpers::dbg( $order, 'TagForge modules: ' . implode( ', ', $all ) );

        Helpers::dbg( $order, 'TagForge: calling Factory::assemble() with ' . count( $all ) . ' modules' );
        $export = null;
        try {
            $export = Factory::assemble( $all, $vars );
        } catch ( \Throwable $e ) {
            $order->add_order_note( 'TagForge ERROR: Factory::assemble() threw: ' . $e->getMessage() );
            error_log( '[TagForge] Factory::assemble() exception: ' . $e->getMessage() );
            return;
        }

        if ( empty( $export ) ) {
            $order->add_order_note( 'TagForge ERROR: Factory::assemble() returned empty result.' );
            error_log( '[TagForge] Factory::assemble() returned empty for order ' . $order_id );
            return;
        }

        $json = wp_json_encode( $export );
        if ( empty( $json ) ) {
            $order->add_order_note( 'TagForge ERROR: wp_json_encode() returned empty. JSON error: ' . json_last_error_msg() );
            error_log( '[TagForge] wp_json_encode failed: ' . json_last_error_msg() );
            return;
        }

        Helpers::dbg( $order, 'TagForge: JSON encoded, ' . strlen( $json ) . ' bytes' );

        $uploads  = Helpers::uploads_dir();
        $filename = 'tagforge-order-' . $order_id . '-' . time() . '.json';
        $path     = $uploads['basedir'] . $filename;

        Helpers::dbg( $order, 'TagForge: writing to path: ' . $path );
        Helpers::dbg( $order, 'TagForge: dir exists: ' . ( is_dir( dirname( $path ) ) ? 'yes' : 'NO' ) . ' writable: ' . ( is_writable( dirname( $path ) ) ? 'yes' : 'NO' ) );

        $written = file_put_contents( $path, $json );
        if ( $written === false || $written === 0 ) {
            $order->add_order_note( 'TagForge ERROR: file_put_contents failed. Path: ' . $path . ' Dir writable: ' . ( is_writable( dirname( $path ) ) ? 'yes' : 'no' ) );
            error_log( '[TagForge] file_put_contents failed for path: ' . $path );
            return;
        }
        Helpers::dbg( $order, 'TagForge: wrote ' . $written . ' bytes to ' . $path );

        $expiry_days = max( 1, (int) $opts['expiry_days'] );
        $expires     = time() + $expiry_days * DAY_IN_SECONDS;
        $url         = Helpers::download_url( $path, $expires, $order_id );

        $order->update_meta_data( '_tagforge_download_url', $url );
        $order->update_meta_data( '_tagforge_download_expires', $expires );
        $order->delete_meta_data( '_tagforge_building' );  // Release mutex
        $order->save();

        $order->add_order_note( 'TagForge modules: ' . implode( ', ', $all ) );
        $order->add_order_note( 'TagForge download: ' . $url );
        $order->update_meta_data( '_tagforge_modules_list', implode( ', ', $all ) );

        // Email delivery handled by woocommerce_email_after_order_table hook
        // which injects the download link into the WooCommerce completed order email.
    }

    private static function product_defaults( \WC_Order_Item_Product $item ) : array {
        $product = $item->get_product();
        if ( ! $product ) return [];

        $raw = get_post_meta( $product->get_id(), '_tagforge_default_modules_array', true );

        // Meta may be stored as a serialised array OR as a comma-separated string
        // (the WooCommerce CSV importer stores it as a plain string, not a PHP array).
        if ( is_array( $raw ) && ! empty( $raw ) ) {
            // Proper array - use directly
            return array_values( array_filter( array_map( 'sanitize_key', $raw ) ) );
        }

        if ( is_string( $raw ) && $raw !== '' ) {
            // Plain string - may be comma-separated slugs
            $arr = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );
            if ( ! empty( $arr ) ) return array_values( $arr );
        }

        // Fallback: legacy _tagforge_default_modules CSV field
        $csv = (string) get_post_meta( $product->get_id(), '_tagforge_default_modules', true );
        if ( ! $csv ) return [];
        return array_values( array_filter( array_map( 'sanitize_key', explode( ',', $csv ) ) ) );
    }

    private static function parse_item_addons( \WC_Order_Item_Product $item ) : array {
        $found = [
            'GA4 ID'                        => [],
            'Meta Pixel ID'                 => [],
            'Google Ads Conversion ID'      => [],
            'Google Ads Conversion Label'   => [],
            'LinkedIn Partner ID'           => [],
            'TikTok Pixel ID'               => [],
            'Microsoft Clarity Project ID'  => [],
            'Hotjar Site ID'                => [],
            'Bing UET Tag ID'               => [],
            'Pinterest Tag ID'              => [],
            'Forge your tag'                => [],
            'Pixel Platform'                => [],
        ];

        $pao = $item->get_meta( '_pao_ids', true );
        if ( ! is_array( $pao ) ) {
            $maybe = maybe_unserialize( $pao );
            if ( is_array( $maybe ) ) $pao = $maybe;
        }

        if ( is_array( $pao ) ) {
            foreach ( $pao as $row ) {
                if ( ! is_array( $row ) ) continue;
                $k = Helpers::sanitize_label( isset( $row['key'] )   ? $row['key']   : '' );
                $v = Helpers::sanitize_label( isset( $row['value'] ) ? $row['value'] : '' );
                if ( $k === '' ) continue;
                if ( ! isset( $found[ $k ] ) ) $found[ $k ] = [];
                if ( $v !== '' ) $found[ $k ][] = $v;
            }
            Helpers::dbg( $item->get_order(), 'PAO parsed: ' . wp_json_encode( $found ) );
        } else {
            $raw = [];
            foreach ( $item->get_meta_data() as $meta ) {
                $d = $meta->get_data();
                $k = Helpers::sanitize_label( isset( $d['key'] )   ? $d['key']   : '' );
                $v = isset( $d['value'] ) ? $d['value'] : '';
                if ( $k === '' ) continue;
                $raw[ $k ] = array_merge(
                    isset( $raw[ $k ] ) ? $raw[ $k ] : [],
                    Helpers::normalize_multivalue( $v )
                );
            }
            foreach ( $found as $label => $_ ) {
                if ( ! empty( $raw[ $label ] ) ) $found[ $label ] = $raw[ $label ];
            }
            Helpers::dbg( $item->get_order(), 'Fallback meta parsed: ' . wp_json_encode( $found ) );
        }

        $ga4 = ( ! empty( $found['GA4 ID'][0] ) && Helpers::looks_like_ga4( $found['GA4 ID'][0] ) )
            ? $found['GA4 ID'][0] : '';

        $feature_map = [
            'ga4 configuration'                => 'gtag-basic',
            'ecom base'                        => 'ecom-base',
            'ecom base (4 core events)'        => 'ecom-base',
            'ecom advanced'                    => 'ecom-advanced',
            'ecom advanced (10 events + dlvs)' => 'ecom-advanced',
            'click tracking'                   => 'click-tracking',
            'scroll depth'                     => 'scroll-depth',
            'scroll depth (25/50/75/100%)'     => 'scroll-depth',
            'scroll tracking'                  => 'scroll-depth',
            'form tracking'                    => 'form-tracking',
            'outbound link tracking'           => 'outbound-link-tracking',
            'yt video tracking'                => 'yt-video-tracking',
            'youtube video tracking'           => 'yt-video-tracking',
            'engagement timer'                 => 'engagement-timer',
            'engagement timer (30s)'           => 'engagement-timer',
            'search tracking'                  => 'search-tracking',
            'meta pixel'                       => 'facebook-pixel',
            'meta pixel (facebook/insta)'      => 'facebook-pixel',
            'google ads conversion'            => 'google-ads-conversion',
            'google ads remarketing'           => 'google-ads-remarketing',
            'linkedin insight tag'             => 'linkedin-insight',
            'linkedin insight'                 => 'linkedin-insight',
            'tiktok pixel'                     => 'tiktok-pixel',
            'pinterest tag'                    => 'pinterest-tag',
            'microsoft/bing uet tag'           => 'bing-uet',
            'bing uet'                         => 'bing-uet',
            'microsoft clarity'                => 'microsoft-clarity',
            'hotjar'                           => 'hotjar',
            'consent mode v2'                  => 'consent-mode-v2',
            'consent mode v2 defaults'         => 'consent-mode-v2',
            'complianz cmp'                    => 'complianz-cmp',
            'complianz cmp integration'        => 'complianz-cmp',
        ];

        $features = [];
        $forge_tags = isset( $found['Forge your tag'] ) ? $found['Forge your tag'] : array();
        foreach ( $forge_tags as $lab ) {
            $norm = strtolower( trim( $lab ) );
            if ( isset( $feature_map[ $norm ] ) ) $features[] = $feature_map[ $norm ];
        }
        $features = array_values( array_unique( $features ) );

        $platform_raw = strtolower( trim( isset( $found['Pixel Platform'][0] ) ? $found['Pixel Platform'][0] : '' ) );
        $platform     = in_array( $platform_raw, array( 'meta', 'linkedin' ), true ) ? $platform_raw : '';

        // Build debug string using plain variables - avoids interpolation
        // of array keys with spaces which causes parse errors on some setups.
        $dbg_pixel   = isset( $found['Meta Pixel ID'][0] )                ? $found['Meta Pixel ID'][0]                : '';
        $dbg_gads    = isset( $found['Google Ads Conversion ID'][0] )     ? $found['Google Ads Conversion ID'][0]     : '';
        $dbg_li      = isset( $found['LinkedIn Partner ID'][0] )          ? $found['LinkedIn Partner ID'][0]          : '';
        $dbg_tiktok  = isset( $found['TikTok Pixel ID'][0] )              ? $found['TikTok Pixel ID'][0]              : '';
        $dbg_clarity = isset( $found['Microsoft Clarity Project ID'][0] ) ? $found['Microsoft Clarity Project ID'][0] : '';
        Helpers::dbg(
            $item->get_order(),
            "Parsed addons => GA4:[{$ga4}] pixel:[{$dbg_pixel}] gads:[{$dbg_gads}] li:[{$dbg_li}] tiktok:[{$dbg_tiktok}] clarity:[{$dbg_clarity}]"
        );

        return array(
            'ga4_id'                => $ga4,
            'pixel_id'              => trim( isset( $found['Meta Pixel ID'][0] )               ? $found['Meta Pixel ID'][0]               : '' ),
            'gads_conversion_id'    => trim( isset( $found['Google Ads Conversion ID'][0] )    ? $found['Google Ads Conversion ID'][0]    : '' ),
            'gads_conversion_label' => trim( isset( $found['Google Ads Conversion Label'][0] ) ? $found['Google Ads Conversion Label'][0] : '' ),
            'li_id'                 => trim( isset( $found['LinkedIn Partner ID'][0] )         ? $found['LinkedIn Partner ID'][0]         : '' ),
            'tiktok_id'             => trim( isset( $found['TikTok Pixel ID'][0] )             ? $found['TikTok Pixel ID'][0]             : '' ),
            'clarity_id'            => trim( isset( $found['Microsoft Clarity Project ID'][0] )? $found['Microsoft Clarity Project ID'][0]: '' ),
            'hotjar_id'             => trim( isset( $found['Hotjar Site ID'][0] )              ? $found['Hotjar Site ID'][0]              : '' ),
            'bing_id'               => trim( isset( $found['Bing UET Tag ID'][0] )             ? $found['Bing UET Tag ID'][0]             : '' ),
            'pinterest_id'          => trim( isset( $found['Pinterest Tag ID'][0] )            ? $found['Pinterest Tag ID'][0]            : '' ),
            'features'              => $features,
            'platform'              => $platform,
        );
    }

    private static function maybe_email(
        \WC_Order $order, array $modules, array $vars,
        string $url, int $expires_ts, array $opts
    ) : void {
        $to_customer = $order->get_billing_email();
        $to_admin    = $opts['admin_email'];
        $rep = array(
            '{customer_name}' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: 'there',
            '{modules}'       => implode( ', ', $modules ),
            '{ga4}'           => $vars['GA4_MEASUREMENT_ID'] ?: '-',
            '{download_url}'  => $url,
            '{expires_date}'  => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_ts ),
            '{order_id}'      => (string) $order->get_id(),
        );
        $sub     = strtr( (string) $opts['email_subject'], $rep );
        $body    = strtr( (string) $opts['email_body'],    $rep );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        if ( ! empty( $opts['email_customer'] ) && $to_customer ) wp_mail( $to_customer, $sub,            $body, $headers );
        if ( ! empty( $opts['email_admin'] )    && $to_admin    ) wp_mail( $to_admin,    '[Admin] ' . $sub, $body, $headers );
    }

    /**
     * Re-send the TagForge delivery email for an order that already has
     * a download URL. Called when an order moves from processing to completed
     * and the artifact was already built on the processing transition.
     */
    public static function resend_email( int $order_id ) : void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $url     = $order->get_meta( '_tagforge_download_url' );
        $expires = (int) $order->get_meta( '_tagforge_download_expires' );
        if ( ! $url || ! $expires ) return;

        // Reconstruct module list from order note for the email body
        $modules = [];
        $vars    = [ 'GA4_MEASUREMENT_ID' => '' ];
        foreach ( $order->get_items() as $item ) {
            $parsed  = self::parse_item_addons( $item );
            $defaults = self::product_defaults( $item );
            if ( ! empty( $defaults ) ) {
                foreach ( $defaults as $slug ) $modules[] = $slug;
            }
            if ( ! empty( $parsed['ga4_id'] ) ) {
                $vars['GA4_MEASUREMENT_ID'] = $parsed['ga4_id'];
            }
        }
        $modules = array_values( array_unique( array_filter( $modules ) ) );

        $opts = Helpers::get_options();
        self::maybe_email( $order, $modules, $vars, $url, $expires, $opts );
        Helpers::dbg( $order, 'TagForge: resent delivery email on completed transition.' );
    }

}
