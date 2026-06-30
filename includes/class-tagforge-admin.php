<?php
namespace TagForge;
if ( ! defined( 'ABSPATH' ) ) exit;
class Admin {
    public static function menus() : void {
        add_menu_page('TagForge', 'TagForge', 'manage_woocommerce', 'tagforge', [__CLASS__,'render_settings_page'], 'dashicons-analytics', 56);
        add_submenu_page('tagforge', 'Settings', 'Settings', 'manage_woocommerce', 'tagforge', [__CLASS__,'render_settings_page']);
        add_submenu_page('tagforge', 'Admin Test', 'Admin Test', 'manage_woocommerce', 'tagforge-test', [__CLASS__,'render_test_page']);
        add_submenu_page('tagforge', 'Readme / Changelog', 'Readme', 'manage_woocommerce', 'tagforge-readme', [__CLASS__,'render_readme']);
    }
    public static function register_settings() : void {
        register_setting('tagforge', 'tagforge_options', array(__CLASS__, 'sanitize_options'));
        add_settings_section('tagforge_main','TagForge Settings',function(){ echo '<p>Configure defaults for TagForge.</p>'; },'tagforge');
        add_settings_field('default_modules_csv','Global Default Modules (CSV)',[__CLASS__,'field_default_modules'],'tagforge','tagforge_main');
        add_settings_field('expiry_days','Download Link Expiry (days)',[__CLASS__,'field_expiry'],'tagforge','tagforge_main');
        add_settings_field('debug','Enable Debug',[__CLASS__,'field_debug'],'tagforge','tagforge_main');
        add_settings_field('email_customer','Email Customer',[__CLASS__,'field_email_customer'],'tagforge','tagforge_main');
        add_settings_field('email_admin','Email Admin',[__CLASS__,'field_email_admin'],'tagforge','tagforge_main');
        add_settings_field('admin_email','Admin Email',[__CLASS__,'field_admin_email'],'tagforge','tagforge_main');
        add_settings_field('email_subject','Email Subject',[__CLASS__,'field_email_subject'],'tagforge','tagforge_main');
        add_settings_field('email_body','Email Body (placeholders...)',[__CLASS__,'field_email_body'],'tagforge','tagforge_main');
    }
    public static function render_settings_page() : void {
        echo '<div class="wrap"><h1>TagForge Settings</h1><form method="post" action="options.php">';
        settings_fields('tagforge'); do_settings_sections('tagforge'); submit_button(); echo '</form></div>';
    }
    public static function field_default_modules(){ $o=Helpers::get_options(); printf('<input type="text" class="regular-text" name="tagforge_options[default_modules_csv]" value="%s" placeholder="ecom-base,click-tracking" />', esc_attr($o['default_modules_csv'])); }
    public static function field_expiry(){ $o=Helpers::get_options(); printf('<input type="number" min="1" class="small-text" name="tagforge_options[expiry_days]" value="%d" />', (int)$o['expiry_days']); }
    public static function field_debug(){ $o=Helpers::get_options(); printf('<label><input type="checkbox" name="tagforge_options[debug]" value="1" %s /> Enable verbose debug logs and order notes (also sets TAGFORGE_DEBUG)</label>', checked(!empty($o['debug']), true, false)); }
    public static function field_email_customer(){ $o=Helpers::get_options(); printf('<label><input type="checkbox" name="tagforge_options[email_customer]" value="1" %s /> Send download email to customer</label>', checked(!empty($o['email_customer']), true, false)); }
    public static function field_email_admin(){ $o=Helpers::get_options(); printf('<label><input type="checkbox" name="tagforge_options[email_admin]" value="1" %s /> Send a copy to admin</label>', checked(!empty($o['email_admin']), true, false)); }
    public static function field_admin_email(){ $o=Helpers::get_options(); printf('<input type="email" class="regular-text" name="tagforge_options[admin_email]" value="%s" />', esc_attr($o['admin_email'])); }
    public static function field_email_subject(){ $o=Helpers::get_options(); printf('<input type="text" class="regular-text" name="tagforge_options[email_subject]" value="%s" />', esc_attr($o['email_subject'])); }
    public static function field_email_body(){ $o=Helpers::get_options(); printf('<textarea class="large-text code" rows="8" name="tagforge_options[email_body]">%s</textarea>', esc_textarea($o['email_body'])); }
    public static function render_test_page() : void {
        if (isset($_POST['tagforge_test_nonce']) && wp_verify_nonce($_POST['tagforge_test_nonce'],'tagforge_test')) {
            $ga4 = sanitize_text_field($_POST['ga4'] ?? '');
            $features = array_map('sanitize_text_field', (array)($_POST['features'] ?? []));
            $platform = sanitize_text_field($_POST['platform'] ?? '');
            $pixel_id = sanitize_text_field($_POST['pixel_id'] ?? '');
            $li_id    = sanitize_text_field($_POST['li_id'] ?? '');
            $vars = ['GA4_MEASUREMENT_ID'=> (Helpers::looks_like_ga4($ga4)?$ga4:''), 'PIXEL_ID'=>$pixel_id, 'LI_PARTNER_ID'=>$li_id];
            $modules = []; if ($vars['GA4_MEASUREMENT_ID']) $modules[]='gtag-basic';
            $map=array('yt'=>'yt-video-tracking','click'=>'click-tracking','scroll'=>'scroll-depth'); foreach($features as $f){ if(isset($map[$f])) $modules[]=$map[$f]; }
            if ($platform==='meta' && $pixel_id) $modules[]='facebook-pixel';
            if ($platform==='linkedin' && $li_id) $modules[]='linkedin-insight';
            $globals = array_filter(array_map('trim', explode(',', (string)Helpers::get_options()['default_modules_csv'])));
            $modules = array_values(array_unique(array_merge($modules, $globals)));
            if (empty($vars['GA4_MEASUREMENT_ID'])) $modules = array_values(array_filter($modules, function($s){ return $s!=='gtag-basic'; }));
            $export = \TagForge\Factory::assemble($modules, $vars);
            $uploads = Helpers::uploads_dir(); $filename = 'tagforge-test-'.time().'.json'; $path=$uploads['basedir'].$filename; file_put_contents($path, wp_json_encode($export));
            $expires = time() + max(1,(int)Helpers::get_options()['expiry_days'])*DAY_IN_SECONDS; $url = Helpers::download_url($path, $expires, 0);
            echo '<div class="notice notice-success"><p><strong>Generated!</strong> Modules: '.esc_html(implode(', ', $modules)).'</p>';
            echo '<p><a class="button button-primary" href="'.esc_url($url).'">Download (expires '.esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), $expires)).')</a></p></div>';
        }
        echo '<div class="wrap"><h1>TagForge Admin Test</h1><form method="post">'; wp_nonce_field('tagforge_test','tagforge_test_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">GA4 ID</th><td><input type="text" name="ga4" class="regular-text" placeholder="G-XXXX12345"></td></tr>';
        echo '<tr><th scope="row">Features</th><td>
                <label><input type="checkbox" name="features[]" value="yt"> YouTube video tracking</label><br>
                <label><input type="checkbox" name="features[]" value="click"> Click tracking</label><br>
                <label><input type="checkbox" name="features[]" value="scroll"> Scroll tracking</label>
              </td></tr>';
        echo '<tr><th scope="row">Platform</th><td>
                <label><input type="radio" name="platform" value="" checked> None</label><br>
                <label><input type="radio" name="platform" value="meta"> Meta (Facebook) Pixel</label><br>
                <label><input type="radio" name="platform" value="linkedin"> LinkedIn Insight</label>
              </td></tr>';
        echo '<tr><th scope="row">Meta Pixel ID</th><td><input type="text" name="pixel_id" class="regular-text"></td></tr>';
        echo '<tr><th scope="row">LinkedIn Partner ID</th><td><input type="text" name="li_id" class="regular-text"></td></tr>';
        echo '</tbody></table>'; submit_button('Generate test container'); echo '</form></div>';
    }
    public static function render_readme() : void {
        $file = TAGFORGE_DIR . 'README.md';
        echo '<div class="wrap"><h1>TagForge Readme</h1>';
        if (file_exists($file)) { echo '<pre style="white-space:pre-wrap">' . esc_html(file_get_contents($file)) . '</pre>'; }
        else { echo '<p>No README found.</p>'; }
        echo '</div>';
    }

    /**
     * Sanitize tagforge_options on save.
     * WordPress Settings API does not submit unchecked checkboxes.
     * Without this callback, unchecking email_customer or email_admin
     * leaves the key absent from the saved array, and wp_parse_args()
     * restores the default (1 = enabled). This method explicitly writes
     * 0 for any missing checkbox key so the unticked state persists.
     */
    public static function sanitize_options( array $input ) : array {
        // Checkboxes - absent from POST when unchecked, must default to 0
        $checkboxes = array( 'debug', 'email_customer', 'email_admin' );
        foreach ( $checkboxes as $key ) {
            $input[ $key ] = isset( $input[ $key ] ) && $input[ $key ] ? 1 : 0;
        }

        // Text fields - sanitize
        $input['default_modules_csv'] = sanitize_text_field( $input['default_modules_csv'] ?? '' );
        $input['expiry_days']         = max( 1, (int) ( $input['expiry_days'] ?? 7 ) );
        $input['admin_email']         = sanitize_email( $input['admin_email'] ?? '' );
        $input['email_subject']       = sanitize_text_field( $input['email_subject'] ?? '' );
        $input['email_body']          = sanitize_textarea_field( $input['email_body'] ?? '' );

        return $input;
    }


    /**
     * Register TagForge meta box on the WooCommerce order edit screen.
     */
    public static function register_order_meta_box() : void {
        $screens = array( 'shop_order', 'woocommerce_page_wc-orders' ); // HPOS + classic
        foreach ( $screens as $screen ) {
            add_meta_box(
                'tagforge_order_box',
                'TagForge',
                array( __CLASS__, 'render_order_meta_box' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the TagForge meta box content on the order edit screen.
     */
    public static function render_order_meta_box( $post_or_order ) : void {
        $order_id = is_a( $post_or_order, 'WC_Order' )
            ? $post_or_order->get_id()
            : $post_or_order->ID;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $url     = $order->get_meta( '_tagforge_download_url' );
        $expires = (int) $order->get_meta( '_tagforge_download_expires' );
        $modules = $order->get_meta( '_tagforge_modules_list' );

        $regen_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tagforge_regen&order_id=' . $order_id ),
            'tagforge_regen_' . $order_id
        );

        // Status
        if ( ! $url ) {
            $status_html = '<span class="tf-ob-status tf-ob-nil">Not generated</span>';
        } elseif ( $expires && $expires < time() ) {
            $status_html = '<span class="tf-ob-status tf-ob-exp">Link expired</span>';
        } else {
            $status_html = '<span class="tf-ob-status tf-ob-ok">Ready</span>';
        }

        $expiry_html = $expires
            ? '<div class="tf-ob-row"><span class="tf-ob-label">Expires</span><span class="tf-ob-value">' . esc_html( date_i18n( get_option( 'date_format' ), $expires ) ) . '</span></div>'
            : '';

        $modules_html = '';
        if ( $modules ) {
            $slugs = is_array( $modules ) ? $modules : explode( ', ', $modules );
            $chips = '';
            foreach ( $slugs as $slug ) {
                $chips .= '<code style="display:inline-block;font-size:10px;background:#f5f5f5;padding:1px 5px;border-radius:3px;margin:1px">' . esc_html( trim( $slug ) ) . '</code> ';
            }
            $modules_html = '<hr class="tf-ob-divider"><div class="tf-ob-label" style="margin-bottom:5px">Modules (' . count( $slugs ) . ')</div><div style="line-height:1.8">' . $chips . '</div>';
        }

        $download_html = $url
            ? '<hr class="tf-ob-divider"><a href="' . esc_url( $url ) . '" class="tf-ob-btn" target="_blank">Download JSON &darr;</a>'
            : '';

        $btn_label  = $url ? 'Regenerate &amp; Resend' : 'Generate &amp; Send';
        $btn_confirm = esc_attr( 'Rebuild the container and resend the delivery email to the customer?' );
        $regen_html  = '<hr class="tf-ob-divider"><a href="' . esc_url( $regen_url ) . '" class="tf-ob-btn" onclick="return confirm(\'' . $btn_confirm . '\');">' . $btn_label . '</a>';

        ?>
        <style>
            .tf-ob { font-family:-apple-system,sans-serif; font-size:12px; }
            .tf-ob-row { display:flex; justify-content:space-between; margin-bottom:6px; }
            .tf-ob-label { color:#888; }
            .tf-ob-value { font-weight:600; color:#1e2130; text-align:right; max-width:60%; word-break:break-all; }
            .tf-ob-status { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
            .tf-ob-ok  { background:#f0fff4; color:#135200; border:1px solid #b7eb8f; }
            .tf-ob-exp { background:#fff2f0; color:#a8071a; border:1px solid #ffccc7; }
            .tf-ob-nil { background:#f5f5f5; color:#888;    border:1px solid #ddd; }
            .tf-ob-btn { display:block; width:100%; margin-top:10px; padding:6px 0;
                         background:linear-gradient(135deg,#8C167B,#E70028,#F3882A);
                         color:#fff !important; border:none; border-radius:6px; font-size:12px;
                         font-weight:600; cursor:pointer; text-align:center; text-decoration:none; }
            .tf-ob-btn:hover { opacity:.88; }
            .tf-ob-divider { border:none; border-top:1px solid #eee; margin:8px 0; }
        </style>
        <div class="tf-ob">
            <div class="tf-ob-row">
                <span class="tf-ob-label">Status</span>
                <?php echo $status_html; ?>
            </div>
            <?php echo $expiry_html; ?>
            <?php echo $modules_html; ?>
            <?php echo $download_html; ?>
            <?php echo $regen_html; ?>
        </div>
        <?php
    }

}
