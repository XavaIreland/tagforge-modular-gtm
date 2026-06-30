<?php
namespace TagForge;
if ( ! defined( 'ABSPATH' ) ) exit;
class Admin {
    public static function menus() : void {
        add_menu_page('TagForge', 'TagForge', 'manage_woocommerce', 'tagforge', [__CLASS__,'render_settings_page'], 'dashicons-analytics', 56);
        add_submenu_page('tagforge', 'Settings', 'Settings', 'manage_woocommerce', 'tagforge', [__CLASS__,'render_settings_page']);
        add_submenu_page('tagforge', 'Admin Test', 'Admin Test', 'manage_woocommerce', 'tagforge-test', [__CLASS__,'render_test_page']);
        add_submenu_page('tagforge', 'Master Export', 'Master Export', 'manage_woocommerce', 'tagforge-master', [__CLASS__,'render_master_export_page']);
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
     * Human-readable labels and descriptions for known placeholder keys.
     */
    private static function placeholder_meta() : array {
        return [
            'GA4_MEASUREMENT_ID' => [
                'label' => 'GA4 Measurement ID',
                'placeholder' => 'G-XXXXXXXXXX',
                'desc' => 'Google Analytics 4 — found in GA4 > Admin > Data Streams',
                'modules' => 'gtag-basic, ecom-base, ecom-advanced',
            ],
            'PIXEL_ID' => [
                'label' => 'Meta (Facebook) Pixel ID',
                'placeholder' => '123456789012345',
                'desc' => 'Meta Events Manager > Data Sources > Pixel ID',
                'modules' => 'facebook-pixel, facebook-events',
            ],
            'GADS_CONVERSION_ID' => [
                'label' => 'Google Ads Conversion ID',
                'placeholder' => 'AW-123456789',
                'desc' => 'Google Ads > Tools > Conversions > Tag setup',
                'modules' => 'google-ads-conversion, google-ads-remarketing',
            ],
            'GADS_CONVERSION_LABEL' => [
                'label' => 'Google Ads Conversion Label',
                'placeholder' => 'AbCdEfGhIjKlMnOp',
                'desc' => 'Found alongside the Conversion ID in Google Ads',
                'modules' => 'google-ads-conversion',
            ],
            'LI_PARTNER_ID' => [
                'label' => 'LinkedIn Partner ID',
                'placeholder' => '1234567',
                'desc' => 'LinkedIn Campaign Manager > Account Assets > Insight Tag',
                'modules' => 'linkedin-insight',
            ],
            'TIKTOK_PIXEL_ID' => [
                'label' => 'TikTok Pixel ID',
                'placeholder' => 'ABCDE12345',
                'desc' => 'TikTok Ads Manager > Assets > Events > Pixel',
                'modules' => 'tiktok-pixel',
            ],
            'PINTEREST_TAG_ID' => [
                'label' => 'Pinterest Tag ID',
                'placeholder' => '1234567890123',
                'desc' => 'Pinterest Ads > Conversions > Pinterest tag',
                'modules' => 'pinterest-tag',
            ],
            'BING_UET_TAG_ID' => [
                'label' => 'Bing / Microsoft UET Tag ID',
                'placeholder' => '12345678',
                'desc' => 'Microsoft Advertising > Tools > UET tags',
                'modules' => 'bing-uet',
            ],
            'HOTJAR_SITE_ID' => [
                'label' => 'Hotjar Site ID',
                'placeholder' => '1234567',
                'desc' => 'Hotjar > Settings > Site ID',
                'modules' => 'hotjar',
            ],
            'CLARITY_PROJECT_ID' => [
                'label' => 'Microsoft Clarity Project ID',
                'placeholder' => 'abcde12345',
                'desc' => 'Clarity > Settings > Setup > Project ID',
                'modules' => 'microsoft-clarity',
            ],
            'COOKIEBOT_DOMAIN_ID' => [
                'label' => 'Cookiebot Domain Group ID',
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                'desc' => 'Cookiebot > Dashboard > Domain group ID (UUID)',
                'modules' => 'cookiebot-cmp',
            ],
        ];
    }

    /**
     * Scan all module JSON files and return every unique {{PLACEHOLDER}} key found.
     * Auto-discovers new placeholders as new modules are added.
     */
    private static function discover_placeholders() : array {
        $found = [];
        foreach ( Factory::get_module_map() as $slug => $rel ) {
            $path = trailingslashit( TAGFORGE_DIR ) . ltrim( $rel, '/' );
            if ( ! file_exists( $path ) ) continue;
            $content = file_get_contents( $path );
            if ( preg_match_all( '/\{\{([A-Z_]+)\}\}/', $content, $m ) ) {
                foreach ( $m[1] as $key ) {
                    $found[ $key ] = true;
                }
            }
        }
        // GA4_MEASUREMENT_ID is injected by Factory::normalise_for_gtm — not in JSON files
        $found['GA4_MEASUREMENT_ID'] = true;
        return array_keys( $found );
    }

    /**
     * CMP module slugs — mutually exclusive, only one should be included.
     */
    private static function cmp_modules() : array {
        return [
            'none'          => [ 'label' => 'None (use standalone Consent Mode v2)', 'slug' => null ],
            'complianz-cmp' => [ 'label' => 'Complianz', 'slug' => 'complianz-cmp' ],
            'consentmo-cmp' => [ 'label' => 'Consentmo', 'slug' => 'consentmo-cmp' ],
            'cookiebot-cmp' => [ 'label' => 'Cookiebot', 'slug' => 'cookiebot-cmp' ],
        ];
    }

    /**
     * Master Export page — assembles every module into one testable container.
     * Automatically picks up new modules and new placeholders as they are added.
     */
    public static function render_master_export_page() : void {
        $meta       = self::placeholder_meta();
        $cmp_opts   = self::cmp_modules();
        $all_slugs  = array_keys( Factory::get_module_map() );
        $cmp_slugs  = array_column( $cmp_opts, 'slug' );
        $non_cmp    = array_values( array_filter( $all_slugs, fn( $s ) => ! in_array( $s, $cmp_slugs, true ) ) );
        $placeholders = self::discover_placeholders();
        $download_html = '';
        $error_html    = '';

        if ( isset( $_POST['tf_master_nonce'] ) && wp_verify_nonce( $_POST['tf_master_nonce'], 'tf_master_export' ) ) {

            $vars       = [];
            $saved_vals = [];

            foreach ( $placeholders as $key ) {
                $raw = sanitize_text_field( $_POST[ 'tf_var_' . $key ] ?? '' );
                $vars[ $key ] = $raw;
                $saved_vals[ $key ] = $raw;
            }

            $chosen_cmp = sanitize_key( $_POST['tf_cmp'] ?? 'none' );
            $cmp_slug   = isset( $cmp_opts[ $chosen_cmp ] ) ? $cmp_opts[ $chosen_cmp ]['slug'] : null;

            // Build slug list: all non-CMP modules + chosen CMP (if any)
            $slugs = $non_cmp;
            if ( $cmp_slug ) {
                $slugs[] = $cmp_slug;
            }

            try {
                $export   = Factory::assemble( $slugs, $vars );
                $uploads  = Helpers::uploads_dir();
                $filename = 'tagforge-master-' . date( 'Y-m-d-His' ) . '.json';
                $path     = $uploads['basedir'] . $filename;
                file_put_contents( $path, wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                $expires  = time() + 7 * DAY_IN_SECONDS;
                $url      = Helpers::download_url( $path, $expires, 0 );

                $module_count = count( $slugs );
                $tag_count    = count( $export['containerVersion']['tag'] ?? [] );
                $trigger_count = count( $export['containerVersion']['trigger'] ?? [] );
                $var_count    = count( $export['containerVersion']['variable'] ?? [] );

                $download_html = sprintf(
                    '<div class="notice notice-success" style="padding:16px">
                        <h3 style="margin-top:0">Master container generated</h3>
                        <p><strong>%d modules</strong> &rarr; %d tags, %d triggers, %d variables</p>
                        <p><a class="button button-primary" href="%s">Download master-container.json &darr;</a>
                        &nbsp; <em style="color:#666;font-size:12px">Link expires in 7 days</em></p>
                        <p style="font-size:12px;color:#666">In GTM: Admin &rsaquo; Import Container &rsaquo; Choose file &rsaquo; <strong>New</strong> workspace &rsaquo; Merge &rsaquo; Confirm</p>
                    </div>',
                    $module_count, $tag_count, $trigger_count, $var_count,
                    esc_url( $url )
                );
            } catch ( \Throwable $e ) {
                $error_html = '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html( $e->getMessage() ) . '</p></div>';
            }
        }

        // Prefill from last POST or saved option
        $saved = get_option( 'tagforge_master_ids', [] );
        if ( ! empty( $_POST['tf_master_nonce'] ) ) {
            foreach ( $placeholders as $key ) {
                $saved[ $key ] = sanitize_text_field( $_POST[ 'tf_var_' . $key ] ?? '' );
            }
            $saved['cmp'] = sanitize_key( $_POST['tf_cmp'] ?? 'none' );
            update_option( 'tagforge_master_ids', $saved );
        }

        $chosen_cmp = $saved['cmp'] ?? 'none';
        ?>
        <div class="wrap">
        <h1>TagForge — Master Export</h1>
        <p style="max-width:680px;color:#555">
            Assembles <strong>every module</strong> in <code>/modules/</code> into one container for full stack testing.
            Fill in the IDs you want replaced; blank fields leave the <code>{{PLACEHOLDER}}</code> token in place (fine for testing).
            Select your CMP — only one can be active per container.
        </p>

        <?php echo $download_html; echo $error_html; ?>

        <form method="post" style="max-width:800px">
            <?php wp_nonce_field( 'tf_master_export', 'tf_master_nonce' ); ?>

            <h2 style="border-bottom:2px solid #E70028;padding-bottom:6px">Tracking IDs</h2>
            <table class="form-table" style="max-width:800px">
            <tbody>
            <?php
            // Render fields in a defined order (meta keys first, then any unknown discovered ones)
            $ordered = array_keys( $meta );
            $extras  = array_diff( $placeholders, $ordered, [ 'COOKIEBOT_DOMAIN_ID' ] );
            $render_keys = array_merge( array_diff( $ordered, [ 'COOKIEBOT_DOMAIN_ID' ] ), $extras );

            foreach ( $render_keys as $key ) :
                $m     = $meta[ $key ] ?? [ 'label' => $key, 'placeholder' => '', 'desc' => '', 'modules' => '' ];
                $value = esc_attr( $saved[ $key ] ?? '' );
                ?>
                <tr>
                    <th scope="row" style="width:220px">
                        <label for="tf_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $m['label'] ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="tf_<?php echo esc_attr( $key ); ?>"
                               name="tf_var_<?php echo esc_attr( $key ); ?>"
                               value="<?php echo $value; ?>"
                               placeholder="<?php echo esc_attr( $m['placeholder'] ); ?>"
                               class="regular-text"
                        />
                        <?php if ( $m['desc'] ) : ?>
                            <p class="description"><?php echo esc_html( $m['desc'] ); ?>
                            <?php if ( $m['modules'] ) : ?>
                                &mdash; <em>used by: <?php echo esc_html( $m['modules'] ); ?></em>
                            <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>

            <h2 style="border-bottom:2px solid #E70028;padding-bottom:6px;margin-top:28px">Consent Management Platform (CMP)</h2>
            <table class="form-table" style="max-width:800px">
            <tbody>
            <tr>
                <th scope="row">CMP Module</th>
                <td>
                <?php foreach ( $cmp_opts as $val => $opt ) : ?>
                    <label style="display:block;margin-bottom:6px">
                        <input type="radio" name="tf_cmp" value="<?php echo esc_attr( $val ); ?>"
                            <?php checked( $chosen_cmp, $val ); ?>
                            onchange="document.getElementById('tf-cookiebot-row').style.display=this.value==='cookiebot-cmp'?'':'none'"
                        />
                        <?php echo esc_html( $opt['label'] ); ?>
                    </label>
                <?php endforeach; ?>
                </td>
            </tr>
            <tr id="tf-cookiebot-row" style="<?php echo $chosen_cmp === 'cookiebot-cmp' ? '' : 'display:none'; ?>">
                <th scope="row">
                    <label for="tf_COOKIEBOT_DOMAIN_ID"><?php echo esc_html( $meta['COOKIEBOT_DOMAIN_ID']['label'] ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="tf_COOKIEBOT_DOMAIN_ID"
                           name="tf_var_COOKIEBOT_DOMAIN_ID"
                           value="<?php echo esc_attr( $saved['COOKIEBOT_DOMAIN_ID'] ?? '' ); ?>"
                           placeholder="<?php echo esc_attr( $meta['COOKIEBOT_DOMAIN_ID']['placeholder'] ); ?>"
                           class="regular-text"
                    />
                    <p class="description"><?php echo esc_html( $meta['COOKIEBOT_DOMAIN_ID']['desc'] ); ?></p>
                </td>
            </tr>
            </tbody>
            </table>

            <h2 style="border-bottom:2px solid #E70028;padding-bottom:6px;margin-top:28px">Modules in this export</h2>
            <p style="color:#555;font-size:13px">All <?php echo count( $non_cmp ); ?> non-CMP modules are always included. CMP module depends on selection above.</p>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:24px">
            <?php foreach ( $non_cmp as $slug ) : ?>
                <code style="background:#f0f0f1;padding:3px 8px;border-radius:4px;font-size:12px"><?php echo esc_html( $slug ); ?></code>
            <?php endforeach; ?>
            </div>

            <?php submit_button( 'Generate Master Container', 'primary large' ); ?>
        </form>
        </div>
        <?php
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
