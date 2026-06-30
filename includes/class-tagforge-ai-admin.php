<?php
/**
 * TagForge\AI_Admin
 *
 * Admin UI for the v4.0 AI Builder feature.
 * - Registers two new submenus under the existing TagForge menu:
 *     tagforge-ai-builder  → API key, model settings, usage limits
 *     tagforge-builder-sessions → Session log table with follow-up flags
 *
 * ADDITIVE ONLY — no existing files modified.
 * Hooked from tagforge-modular-gtm.php alongside existing admin hooks.
 *
 * Settings stored in existing tagforge_options array:
 *     claude_api_key           string  Anthropic API key (server-side only)
 *     builder_refinement_limit int     Max downstream refinements per session (default 2)
 *     builder_rate_limit       int     Max Claude calls per session per hour (default 10)
 *
 * @package TagForge
 * @since   4.0.0
 */

namespace TagForge;

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Admin {

    const MENU_PARENT     = 'tagforge';
    const SLUG_SETTINGS   = 'tagforge-ai-builder';
    const SLUG_SESSIONS   = 'tagforge-builder-sessions';
    const NONCE_SETTINGS  = 'tagforge_ai_settings_nonce';
    const NONCE_SESSION   = 'tagforge_ai_session_nonce';

    // ── Bootstrap ─────────────────────────────────────────────────────

    public static function register() : void {
        add_action( 'admin_menu',             [ __CLASS__, 'add_menus'          ] );
        add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue'            ] );
        add_action( 'wp_ajax_tagforge_ai_save_settings',  [ __CLASS__, 'ajax_save_settings'  ] );
        add_action( 'wp_ajax_tagforge_ai_test_key',       [ __CLASS__, 'ajax_test_key'        ] );
        add_action( 'wp_ajax_tagforge_ai_flag_session',   [ __CLASS__, 'ajax_flag_session'    ] );
        add_action( 'wp_ajax_tagforge_ai_export_sessions',[ __CLASS__, 'ajax_export_sessions' ] );
        add_action( 'wp_ajax_tagforge_ai_purge_expired',  [ __CLASS__, 'ajax_purge_expired'   ] );
    }

    // ── Menus ──────────────────────────────────────────────────────────

    public static function add_menus() : void {
        add_submenu_page(
            self::MENU_PARENT,
            'AI Builder',
            'AI Builder',
            'manage_woocommerce',
            self::SLUG_SETTINGS,
            [ __CLASS__, 'render_settings_page' ]
        );
        add_submenu_page(
            self::MENU_PARENT,
            'Builder Sessions',
            'Builder Sessions',
            'manage_woocommerce',
            self::SLUG_SESSIONS,
            [ __CLASS__, 'render_sessions_page' ]
        );
        add_submenu_page(
            self::MENU_PARENT,
            'Shortcodes & Usage',
            'Shortcodes & Usage',
            'manage_woocommerce',
            'tagforge-shortcodes',
            [ __CLASS__, 'render_shortcodes_page' ]
        );
    }

    // ── Enqueue ────────────────────────────────────────────────────────

    public static function enqueue( string $hook ) : void {
        // Only load on our pages
        $our_pages = [ 'tagforge_page_' . self::SLUG_SETTINGS, 'tagforge_page_' . self::SLUG_SESSIONS ];
        if ( ! in_array( $hook, $our_pages, true ) ) return;

        wp_enqueue_style(
            'tagforge-ai-admin',
            TAGFORGE_URL . 'assets/tagforge-ai-admin.css',
            [],
            TAGFORGE_VERSION
        );
        wp_enqueue_script(
            'tagforge-ai-admin',
            TAGFORGE_URL . 'assets/tagforge-ai-admin.js',
            [ 'jquery' ],
            TAGFORGE_VERSION,
            true
        );
        wp_localize_script( 'tagforge-ai-admin', 'TF_AI_Admin', [
            'nonce'    => wp_create_nonce( self::NONCE_SETTINGS ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'version'  => TAGFORGE_VERSION,
        ] );
    }

    // ── Settings page ──────────────────────────────────────────────────

    public static function render_settings_page() : void {
        $opts       = Helpers::get_options();
        $api_key    = $opts['claude_api_key'] ?? '';
        $ref_limit  = (int) ( $opts['builder_refinement_limit'] ?? 2 );
        $rate_limit = (int) ( $opts['builder_rate_limit'] ?? 10 );
        $has_key    = ! empty( $api_key );

        // Session stats
        $stats = self::get_session_stats();
        ?>
        <div class="wrap tf-ai-wrap">

            <div class="tf-ai-header">
                <div class="tf-ai-header__brand">
                    <span class="tf-ai-badge">AI</span>
                    <div>
                        <h1 class="tf-ai-header__title">TagForge AI Builder</h1>
                        <span class="tf-ai-header__version">v<?php echo esc_html( TAGFORGE_VERSION ); ?></span>
                    </div>
                </div>
            </div>

            <div class="tf-ai-grid">

                <?php /* ── Column 1: API key + limits ── */ ?>
                <div class="tf-ai-col">

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">Claude API Key</h2>
                        <p class="tf-ai-card__desc">
                            Used for the AI Builder conversation engine. Never exposed to the browser or included in any page source.
                            Get your key at <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>.
                        </p>

                        <div class="tf-ai-field-row">
                            <input
                                type="password"
                                id="tf-ai-api-key"
                                class="tf-ai-input tf-ai-input--wide"
                                value="<?php echo esc_attr( $api_key ); ?>"
                                placeholder="sk-ant-api03-..."
                                autocomplete="new-password"
                            >
                            <button class="tf-ai-btn tf-ai-btn--primary" id="tf-ai-save-key">Save key</button>
                            <button class="tf-ai-btn tf-ai-btn--secondary" id="tf-ai-test-key">Test</button>
                        </div>

                        <div class="tf-ai-key-status <?php echo $has_key ? 'tf-ai-key-status--set' : 'tf-ai-key-status--missing'; ?>" id="tf-ai-key-status">
                            <?php if ( $has_key ) : ?>
                                <span class="tf-ai-dot tf-ai-dot--green"></span>
                                API key set · model: <strong>claude-sonnet-4-20250514</strong>
                            <?php else : ?>
                                <span class="tf-ai-dot tf-ai-dot--red"></span>
                                No API key set — AI Builder will not function
                            <?php endif; ?>
                        </div>
                        <span class="tf-ai-save-msg" id="tf-ai-key-msg"></span>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">Session Limits</h2>
                        <p class="tf-ai-card__desc">Server-side limits enforced on every request. These cannot be bypassed client-side.</p>

                        <table class="tf-ai-limits-table">
                            <tr>
                                <td class="tf-ai-limits-table__label">
                                    Refinements per session
                                    <span class="tf-ai-hint">Max downstream adjustments after initial recommendation</span>
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        id="tf-ai-ref-limit"
                                        class="tf-ai-input tf-ai-input--num"
                                        value="<?php echo esc_attr( $ref_limit ); ?>"
                                        min="0"
                                        max="10"
                                    >
                                </td>
                            </tr>
                            <tr>
                                <td class="tf-ai-limits-table__label">
                                    Rate limit (calls/hour/session)
                                    <span class="tf-ai-hint">Max Claude API calls per session per hour</span>
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        id="tf-ai-rate-limit"
                                        class="tf-ai-input tf-ai-input--num"
                                        value="<?php echo esc_attr( $rate_limit ); ?>"
                                        min="1"
                                        max="100"
                                    >
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 16px;">
                            <button class="tf-ai-btn tf-ai-btn--primary" id="tf-ai-save-limits">Save limits</button>
                            <span class="tf-ai-save-msg" id="tf-ai-limits-msg"></span>
                        </div>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">Pricing Tiers</h2>
                        <p class="tf-ai-card__desc">Read-only — edit via <code>tagforge_builder_price_tiers</code> filter in your child theme or mu-plugin.</p>
                        <table class="tf-ai-limits-table">
                            <?php foreach ( self::get_price_tiers() as $tier ) : ?>
                            <tr>
                                <td class="tf-ai-limits-table__label"><?php echo esc_html( $tier['label'] ); ?></td>
                                <td><strong>€<?php echo esc_html( $tier['price'] ); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                </div>

                <?php /* ── Column 2: Session stats + data management ── */ ?>
                <div class="tf-ai-col">

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">Session Stats</h2>
                        <div class="tf-ai-stat-grid">
                            <div class="tf-ai-stat">
                                <div class="tf-ai-stat__num"><?php echo esc_html( $stats['total'] ); ?></div>
                                <div class="tf-ai-stat__label">Total sessions</div>
                            </div>
                            <div class="tf-ai-stat">
                                <div class="tf-ai-stat__num"><?php echo esc_html( $stats['with_email'] ); ?></div>
                                <div class="tf-ai-stat__label">Email captured</div>
                            </div>
                            <div class="tf-ai-stat">
                                <div class="tf-ai-stat__num"><?php echo esc_html( $stats['purchased'] ); ?></div>
                                <div class="tf-ai-stat__label">Purchased</div>
                            </div>
                            <div class="tf-ai-stat">
                                <div class="tf-ai-stat__num"><?php echo esc_html( $stats['flagged'] ); ?></div>
                                <div class="tf-ai-stat__label">Flagged for follow-up</div>
                            </div>
                        </div>
                        <?php if ( $stats['total'] > 0 ) : ?>
                        <div class="tf-ai-conversion">
                            <?php
                            $rate = $stats['total'] > 0 ? round( ( $stats['purchased'] / $stats['total'] ) * 100, 1 ) : 0;
                            ?>
                            Builder conversion rate: <strong><?php echo esc_html( $rate ); ?>%</strong>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">Session Data</h2>
                        <p class="tf-ai-card__desc">Manage the <code><?php echo esc_html( self::get_table_name() ); ?></code> database table.</p>

                        <div class="tf-ai-data-actions">
                            <div class="tf-ai-data-row">
                                <div>
                                    <strong>Export sessions to CSV</strong>
                                    <span class="tf-ai-hint">All sessions including email, modules, status</span>
                                </div>
                                <button class="tf-ai-btn tf-ai-btn--secondary" id="tf-ai-export-csv">Export CSV</button>
                            </div>
                            <div class="tf-ai-data-row">
                                <div>
                                    <strong>Purge expired sessions</strong>
                                    <span class="tf-ai-hint">Removes sessions older than 30 days with status = expired</span>
                                </div>
                                <button class="tf-ai-btn tf-ai-btn--danger" id="tf-ai-purge-expired">Purge expired</button>
                            </div>
                        </div>
                        <span class="tf-ai-save-msg" id="tf-ai-data-msg"></span>
                    </div>

                    <div class="tf-ai-card tf-ai-card--info">
                        <h2 class="tf-ai-card__title">How it works</h2>
                        <ol class="tf-ai-how-list">
                            <li>Customer clicks Q1 on homepage widget</li>
                            <li>Redirected to <code>/build</code> — conversation continues (Q2–Q5)</li>
                            <li>Claude recommends a custom-named container + module list + price</li>
                            <li>Email captured at partial JSON preview gate</li>
                            <li>Up to <?php echo esc_html( $ref_limit ); ?> downstream refinements allowed</li>
                            <li>Customer provides IDs (or skips with placeholder fallback)</li>
                            <li>Builder populates hidden WooCommerce Add-ons fields + fires Add to Cart</li>
                            <li>Factory pipeline assembles container as normal</li>
                        </ol>
                    </div>

                </div>

            </div><!-- .tf-ai-grid -->

        </div><!-- .tf-ai-wrap -->
        <?php
    }

    // ── Sessions page ──────────────────────────────────────────────────

    public static function render_sessions_page() : void {
        global $wpdb;

        $table      = self::get_table_name();
        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $paged      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page   = 25;
        $offset     = ( $paged - 1 ) * $per_page;

        $where = '';
        if ( $status_filter && in_array( $status_filter, [ 'active', 'purchased', 'expired', 'flagged' ], true ) ) {
            if ( $status_filter === 'flagged' ) {
                $where = "WHERE follow_up = 1";
            } else {
                $where = $wpdb->prepare( "WHERE status = %s", $status_filter );
            }
        }

        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
        $sessions = $wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}",
            ARRAY_A
        );

        $pages = ceil( $total / $per_page );

        $status_tabs = [
            ''          => 'All (' . self::get_session_stats()['total'] . ')',
            'active'    => 'Active',
            'purchased' => 'Purchased',
            'expired'   => 'Expired',
            'flagged'   => 'Follow-up',
        ];
        ?>
        <div class="wrap tf-ai-wrap">

            <div class="tf-ai-header">
                <div class="tf-ai-header__brand">
                    <span class="tf-ai-badge">AI</span>
                    <div>
                        <h1 class="tf-ai-header__title">Builder Sessions</h1>
                        <span class="tf-ai-header__version"><?php echo esc_html( $total ); ?> sessions</span>
                    </div>
                </div>
                <div class="tf-ai-header__actions">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ); ?>" class="tf-ai-btn tf-ai-btn--secondary">← AI Builder settings</a>
                </div>
            </div>

            <nav class="tf-ai-status-tabs">
                <?php foreach ( $status_tabs as $slug => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SESSIONS . ( $slug ? '&status=' . $slug : '' ) ) ); ?>"
                   class="tf-ai-status-tab <?php echo $status_filter === $slug ? 'tf-ai-status-tab--active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <?php if ( empty( $sessions ) ) : ?>
                <div class="tf-ai-card">
                    <p style="color:#888; margin:0;">No sessions found<?php echo $status_filter ? ' with status "' . esc_html( $status_filter ) . '"' : ''; ?>.</p>
                </div>
            <?php else : ?>

            <table class="wp-list-table widefat fixed striped tf-ai-sessions-table">
                <thead>
                    <tr>
                        <th style="width:200px">Email</th>
                        <th>Container name</th>
                        <th style="width:80px">Modules</th>
                        <th style="width:70px">Price</th>
                        <th style="width:80px">Refines</th>
                        <th style="width:90px">Status</th>
                        <th style="width:80px">Order</th>
                        <th style="width:100px">Date</th>
                        <th style="width:90px">Follow-up</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sessions as $row ) :
                    $modules = json_decode( $row['modules'] ?? '[]', true ) ?: [];
                    $answers = json_decode( $row['answers'] ?? '{}', true ) ?: [];
                    $order_link = $row['order_id']
                        ? '<a href="' . esc_url( get_edit_post_link( $row['order_id'] ) ) . '" target="_blank">#' . esc_html( $row['order_id'] ) . '</a>'
                        : '—';
                    $status_class = 'tf-ai-status--' . esc_attr( $row['status'] ?? 'active' );
                    $flagged = ! empty( $row['follow_up'] );
                    $ref_limit = (int) ( Helpers::get_options()['builder_refinement_limit'] ?? 2 );
                ?>
                    <tr data-session-id="<?php echo esc_attr( $row['session_id'] ); ?>">
                        <td>
                            <strong><?php echo esc_html( $row['email'] ?: '—' ); ?></strong>
                        </td>
                        <td>
                            <?php echo esc_html( $row['custom_name'] ?: '—' ); ?>
                            <?php if ( ! empty( $answers ) ) : ?>
                            <div class="tf-ai-session-answers">
                                <?php foreach ( $answers as $q => $a ) : ?>
                                <span class="tf-ai-answer-chip"><?php echo esc_html( $a ); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $modules ) ) : ?>
                            <span class="tf-ai-module-count" title="<?php echo esc_attr( implode( ', ', $modules ) ); ?>">
                                <?php echo esc_html( count( $modules ) ); ?> modules
                            </span>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php echo $row['price'] ? '€' . esc_html( number_format( (float) $row['price'], 0 ) ) : '—'; ?>
                        </td>
                        <td>
                            <span class="tf-ai-refine-count <?php echo (int)$row['refinements'] >= $ref_limit ? 'tf-ai-refine-count--max' : ''; ?>">
                                <?php echo esc_html( (int) $row['refinements'] ); ?> / <?php echo esc_html( $ref_limit ); ?>
                            </span>
                        </td>
                        <td>
                            <span class="tf-ai-status-pill <?php echo $status_class; ?>">
                                <?php echo esc_html( $row['status'] ?? 'active' ); ?>
                            </span>
                        </td>
                        <td><?php echo $order_link; ?></td>
                        <td style="font-size:12px; color:#888;">
                            <?php echo esc_html( date( 'd M Y', strtotime( $row['created_at'] ) ) ); ?>
                        </td>
                        <td>
                            <button
                                class="tf-ai-flag-btn <?php echo $flagged ? 'tf-ai-flag-btn--active' : ''; ?>"
                                data-session="<?php echo esc_attr( $row['session_id'] ); ?>"
                                data-flagged="<?php echo $flagged ? '1' : '0'; ?>"
                                title="<?php echo $flagged ? 'Remove follow-up flag' : 'Flag for follow-up'; ?>"
                            >
                                <?php echo $flagged ? '★ Flagged' : '☆ Flag'; ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
            <div class="tf-ai-pagination">
                <?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SESSIONS . '&paged=' . $i . ( $status_filter ? '&status=' . $status_filter : '' ) ) ); ?>"
                   class="tf-ai-page-btn <?php echo $paged === $i ? 'tf-ai-page-btn--active' : ''; ?>">
                    <?php echo esc_html( $i ); ?>
                </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>

        </div>
        <?php
    }

    // ── AJAX: Save settings ────────────────────────────────────────────

    public static function ajax_save_settings() : void {
        check_ajax_referer( self::NONCE_SETTINGS, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Insufficient permissions.' );

        $field = sanitize_text_field( $_POST['field'] ?? '' );
        $opts  = Helpers::get_options();

        switch ( $field ) {
            case 'api_key':
                $key = sanitize_text_field( $_POST['value'] ?? '' );
                $opts['claude_api_key'] = $key;
                update_option( 'tagforge_options', $opts );
                wp_send_json_success( 'API key saved.' );
                break;

            case 'limits':
                $opts['builder_refinement_limit'] = max( 0, min( 10, (int) ( $_POST['refinement_limit'] ?? 2 ) ) );
                $opts['builder_rate_limit']        = max( 1, min( 100, (int) ( $_POST['rate_limit'] ?? 10 ) ) );
                update_option( 'tagforge_options', $opts );
                wp_send_json_success( 'Limits saved.' );
                break;

            case 'zoho':
                $opts['zoho_endpoint'] = sanitize_url( $_POST['zoho_endpoint'] ?? '' );
                $opts['zoho_form_id']  = sanitize_text_field( $_POST['zoho_form_id'] ?? '' );
                $opts['zoho_uid']      = sanitize_text_field( $_POST['zoho_uid'] ?? '' );
                update_option( 'tagforge_options', $opts );
                wp_send_json_success( 'Zoho settings saved.' );
                break;

            default:
                wp_send_json_error( 'Unknown field.' );
        }
    }

    // ── AJAX: Test API key ─────────────────────────────────────────────

    public static function ajax_test_key() : void {
        check_ajax_referer( self::NONCE_SETTINGS, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Insufficient permissions.' );

        $opts    = Helpers::get_options();
        $api_key = $opts['claude_api_key'] ?? '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( 'No API key set.' );
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 15,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 10,
                'messages'   => [
                    [ 'role' => 'user', 'content' => 'Say OK.' ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Connection failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $body['content'] ) ) {
            wp_send_json_success( 'API key valid. Connection confirmed.' );
        } elseif ( $code === 401 ) {
            wp_send_json_error( 'Invalid API key — authentication failed.' );
        } else {
            $err = $body['error']['message'] ?? 'Unknown error.';
            wp_send_json_error( "API error ({$code}): {$err}" );
        }
    }

    // ── AJAX: Flag session for follow-up ───────────────────────────────

    public static function ajax_flag_session() : void {
        check_ajax_referer( self::NONCE_SETTINGS, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Insufficient permissions.' );

        global $wpdb;
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $flagged    = (int) ( $_POST['flagged'] ?? 0 );

        if ( ! $session_id ) wp_send_json_error( 'Missing session ID.' );

        $table = self::get_table_name();
        $wpdb->update(
            $table,
            [ 'follow_up' => $flagged ],
            [ 'session_id' => $session_id ],
            [ '%d' ],
            [ '%s' ]
        );

        wp_send_json_success( [ 'flagged' => (bool) $flagged ] );
    }

    // ── AJAX: Export sessions CSV ──────────────────────────────────────

    public static function ajax_export_sessions() : void {
        check_ajax_referer( self::NONCE_SETTINGS, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Insufficient permissions.' );

        global $wpdb;
        $table    = self::get_table_name();
        $sessions = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

        $csv  = "Session ID,Email,Custom Name,Modules,Price,Refinements,Status,Order ID,Follow Up,Created\n";
        foreach ( $sessions as $row ) {
            $modules = implode( ' | ', json_decode( $row['modules'] ?? '[]', true ) ?: [] );
            $csv .= implode( ',', [
                '"' . esc_attr( $row['session_id'] ) . '"',
                '"' . esc_attr( $row['email'] ?? '' ) . '"',
                '"' . esc_attr( $row['custom_name'] ?? '' ) . '"',
                '"' . esc_attr( $modules ) . '"',
                '"' . esc_attr( $row['price'] ?? '' ) . '"',
                '"' . esc_attr( $row['refinements'] ?? 0 ) . '"',
                '"' . esc_attr( $row['status'] ?? '' ) . '"',
                '"' . esc_attr( $row['order_id'] ?? '' ) . '"',
                '"' . esc_attr( $row['follow_up'] ?? 0 ) . '"',
                '"' . esc_attr( $row['created_at'] ?? '' ) . '"',
            ] ) . "\n";
        }

        wp_send_json_success( [ 'csv' => $csv, 'filename' => 'tagforge-sessions-' . date( 'Y-m-d' ) . '.csv' ] );
    }

    // ── AJAX: Purge expired sessions ───────────────────────────────────

    public static function ajax_purge_expired() : void {
        check_ajax_referer( self::NONCE_SETTINGS, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Insufficient permissions.' );

        global $wpdb;
        $table   = self::get_table_name();
        $cutoff  = date( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status = 'expired' AND created_at < %s",
                $cutoff
            )
        );

        wp_send_json_success( [ 'deleted' => (int) $deleted ] );
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public static function get_table_name() : string {
        global $wpdb;
        return $wpdb->prefix . 'tagforge_builder_sessions';
    }

    private static function get_session_stats() : array {
        global $wpdb;
        $table = self::get_table_name();

        // Check table exists before querying
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [ 'total' => 0, 'with_email' => 0, 'purchased' => 0, 'flagged' => 0 ];
        }

        return [
            'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'with_email' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email IS NOT NULL AND email != ''" ),
            'purchased'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'purchased'" ),
            'flagged'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE follow_up = 1" ),
        ];
    }

    /**
     * Price tiers — filterable via tagforge_builder_price_tiers hook.
     */
    public static function get_price_tiers() : array {
        $default = [
            [ 'min' => 1,  'max' => 5,  'price' => 49,  'label' => '1–5 modules' ],
            [ 'min' => 6,  'max' => 9,  'price' => 79,  'label' => '6–9 modules' ],
            [ 'min' => 10, 'max' => 13, 'price' => 109, 'label' => '10–13 modules' ],
            [ 'min' => 14, 'max' => 17, 'price' => 129, 'label' => '14–17 modules' ],
            [ 'min' => 18, 'max' => 999,'price' => 149, 'label' => '18+ modules' ],
        ];
        return (array) apply_filters( 'tagforge_builder_price_tiers', $default );
    }

    /**
     * Calculate price for a given module count.
     */
    public static function price_for_count( int $count ) : int {
        foreach ( self::get_price_tiers() as $tier ) {
            if ( $count >= $tier['min'] && $count <= $tier['max'] ) {
                return (int) $tier['price'];
            }
        }
        return 149; // fallback
    }

    // ── Shortcodes & Usage info page ──────────────────────────────────────

    public static function render_shortcodes_page() : void {
        ?>
        <div class="wrap tf-ai-wrap">
            <div class="tf-ai-header">
                <div class="tf-ai-header__brand">
                    <span class="tf-ai-badge">SC</span>
                    <div>
                        <h1 class="tf-ai-header__title">Shortcodes &amp; Usage</h1>
                        <span class="tf-ai-header__version">v<?php echo esc_html( TAGFORGE_VERSION ); ?></span>
                    </div>
                </div>
            </div>

            <div class="tf-ai-grid">

                <div class="tf-ai-col tf-ai-col--wide">

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">[tagforge_builder]</h2>
                        <p class="tf-ai-card__desc">
                            <strong>Full two-column buying experience.</strong> Renders the complete builder —
                            product meta, AI chat, WooCommerce Add-on fields, and Add to Cart button.
                            Self-contained. Use this on the homepage, the <code>/build</code> product page,
                            or any landing page where you want the full purchase flow.
                        </p>
                        <table class="tf-ai-limits-table">
                            <tr><th>Attribute</th><th>Default</th><th>Options</th><th>Notes</th></tr>
                            <tr>
                                <td><code>product_id</code></td>
                                <td>option: <code>tagforge_builder_product_id</code></td>
                                <td>Any WooCommerce product ID</td>
                                <td>The Custom Container product</td>
                            </tr>
                            <tr>
                                <td><code>chat_style</code></td>
                                <td><code>conversational</code></td>
                                <td><code>conversational</code> | <code>guided</code></td>
                                <td>Conversational = natural expert flow. Guided = strict Q1-Q4.</td>
                            </tr>
                        </table>
                        <div class="tf-ai-code-block">
                            <code>[tagforge_builder]</code><br>
                            <code>[tagforge_builder chat_style="guided"]</code><br>
                            <code>[tagforge_builder product_id="1242" chat_style="conversational"]</code>
                        </div>
                        <p class="tf-ai-card__desc" style="margin-top:10px;">
                            <strong>Legacy alias:</strong> <code>[tagforge_builder_full]</code> maps to this shortcode for backward compatibility.
                        </p>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">[tagforge_chat]</h2>
                        <p class="tf-ai-card__desc">
                            <strong>Chat-only entry widget.</strong> Displays the Q1 site-type buttons only.
                            On selection, immediately redirects to <code>/build?site_type=VALUE</code> where
                            Q2-Q4 complete inside the full builder. Use in sidebars, blog posts, product pages —
                            anywhere you want a lightweight entry point. Mobile-safe — purchase always
                            completes on <code>/build</code>.
                        </p>
                        <table class="tf-ai-limits-table">
                            <tr><th>Attribute</th><th>Default</th><th>Notes</th></tr>
                            <tr>
                                <td><code>theme</code></td>
                                <td><code>dark</code></td>
                                <td>Widget colour theme</td>
                            </tr>
                            <tr>
                                <td><code>title</code></td>
                                <td>Build your container</td>
                                <td>Widget heading text</td>
                            </tr>
                            <tr>
                                <td><code>subtitle</code></td>
                                <td>Answer a few questions…</td>
                                <td>Widget subheading text</td>
                            </tr>
                        </table>
                        <div class="tf-ai-code-block">
                            <code>[tagforge_chat]</code><br>
                            <code>[tagforge_chat theme="dark" title="Build your GTM container"]</code>
                        </div>
                        <p class="tf-ai-card__desc" style="margin-top:10px;">
                            <strong>Legacy alias:</strong> <code>[tagforge_builder_widget]</code> maps to this shortcode for backward compatibility.
                        </p>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">Recommended page setup</h2>
                        <table class="tf-ai-limits-table">
                            <tr><th>Page / Location</th><th>Shortcode</th><th>Notes</th></tr>
                            <tr>
                                <td>Homepage</td>
                                <td><code>[tagforge_builder]</code></td>
                                <td>Full two-column experience, no redirect needed</td>
                            </tr>
                            <tr>
                                <td>/build product page</td>
                                <td><code>[tagforge_builder]</code></td>
                                <td>Via builder-product.php template or Elementor shortcode widget</td>
                            </tr>
                            <tr>
                                <td>Blog post sidebar</td>
                                <td><code>[tagforge_chat]</code></td>
                                <td>Redirects to /build after Q1</td>
                            </tr>
                            <tr>
                                <td>Product page sidebar</td>
                                <td><code>[tagforge_chat]</code></td>
                                <td>Redirects to /build after Q1</td>
                            </tr>
                            <tr>
                                <td>Landing pages</td>
                                <td><code>[tagforge_builder]</code></td>
                                <td>Full experience anywhere</td>
                            </tr>
                        </table>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">JavaScript assets</h2>
                        <table class="tf-ai-limits-table">
                            <tr><th>File</th><th>Handle</th><th>Loaded by</th><th>Purpose</th></tr>
                            <tr>
                                <td><code>tagforge-builder.js</code></td>
                                <td><code>tagforge-builder</code></td>
                                <td>Both shortcodes</td>
                                <td>Conversation engine, Claude proxy, recommendation parsing, overlays</td>
                            </tr>
                            <tr>
                                <td><code>tagforge-buildpage.js</code></td>
                                <td><code>tagforge-buildpage</code></td>
                                <td><code>[tagforge_builder]</code> only</td>
                                <td>Left column state — TF_BuildPage object, form reveal, field population</td>
                            </tr>
                            <tr>
                                <td><code>tagforge-builder.css</code></td>
                                <td><code>tagforge-builder</code></td>
                                <td>Both shortcodes</td>
                                <td>All builder styles</td>
                            </tr>
                        </table>
                    </div>

                    <div class="tf-ai-card">
                        <h2 class="tf-ai-card__title">REST endpoints</h2>
                        <table class="tf-ai-limits-table">
                            <tr><th>Method</th><th>Endpoint</th><th>Purpose</th></tr>
                            <tr><td>POST</td><td><code>/wp-json/tagforge/v1/builder/session</code></td><td>Initialise session</td></tr>
                            <tr><td>POST</td><td><code>/wp-json/tagforge/v1/builder/chat</code></td><td>Send message to Claude</td></tr>
                            <tr><td>GET</td><td><code>/wp-json/tagforge/v1/builder/cmps</code></td><td>Get available CMP modules dynamically</td></tr>
                            <tr><td>POST</td><td><code>/wp-json/tagforge/v1/builder/send-preview</code></td><td>Assemble preview container, POST to Zoho</td></tr>
                            <tr><td>POST</td><td><code>/wp-json/tagforge/v1/builder/complete</code></td><td>Mark session purchased</td></tr>
                        </table>
                    </div>

                </div>

            </div>
        </div>
        <?php
    }


} // class AI_Admin
