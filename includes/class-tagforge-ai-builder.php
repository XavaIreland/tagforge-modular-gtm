<?php
/**
 * TagForge\AI_Builder
 *
 * REST API proxy for the AI Builder conversation engine.
 * Registers shortcodes for the homepage widget and /build page.
 *
 * REST endpoints:
 *   POST /wp-json/tagforge/v1/builder/session   — initialise session
 *   POST /wp-json/tagforge/v1/builder/chat      — send message, get response
 *   POST /wp-json/tagforge/v1/builder/email     — capture email, trigger Zoho lead
 *   POST /wp-json/tagforge/v1/builder/complete  — mark purchased, link order
 *
 * The Claude API key is NEVER exposed to the browser. All Anthropic API
 * calls are made server-side from this class.
 *
 * ADDITIVE ONLY — no existing files modified.
 *
 * @package TagForge
 * @since   4.0.0
 */

namespace TagForge;

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Builder {

    const REST_NAMESPACE = 'tagforge/v1';
    const MODEL          = 'claude-sonnet-4-6';
    const ANTHROPIC_API  = 'https://api.anthropic.com/v1/messages';

    // Placeholder IDs — syntactically valid, GTM-importable dummy values
    const PLACEHOLDERS = [
        'GA4_MEASUREMENT_ID'    => 'G-XXXXXXXXXX',
        'PIXEL_ID'              => '000000000000000',
        'GADS_CONVERSION_ID'    => 'AW-0000000000',
        'GADS_CONVERSION_LABEL' => 'XXXXXXXXXXXXXXXX',
        'LI_PARTNER_ID'         => '0000000',
        'TIKTOK_PIXEL_ID'       => 'C000000000000000000',
        'CLARITY_PROJECT_ID'    => 'xxxxxxxxxx',
        'HOTJAR_SITE_ID'        => '0000000',
        'BING_UET_TAG_ID'       => '0000000',
        'PINTEREST_TAG_ID'      => '0000000000000',
        'COOKIEBOT_DOMAIN_ID'   => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    ];

    // ── Bootstrap ──────────────────────────────────────────────────────

    public static function register() : void {
        add_action( 'rest_api_init',   [ __CLASS__, 'register_routes'    ] );
        // v5.2 shortcodes
        add_shortcode( 'tagforge_builder',        [ __CLASS__, 'shortcode_builder' ] ); // full two-column experience
        add_shortcode( 'tagforge_chat',           [ __CLASS__, 'shortcode_chat'    ] ); // chat-only, redirects to /build after Q1
        // Legacy aliases — kept so existing Elementor pages don't break
        add_shortcode( 'tagforge_builder_widget', [ __CLASS__, 'shortcode_chat'    ] );
        add_shortcode( 'tagforge_builder_full',   [ __CLASS__, 'shortcode_builder' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend' ] );
        // AJAX handler for preview container downloads (no auth required — token is the key)
        add_action( 'wp_ajax_tagforge_preview_download',        [ __CLASS__, 'handle_preview_download' ] );
        add_action( 'wp_ajax_nopriv_tagforge_preview_download', [ __CLASS__, 'handle_preview_download' ] );
    }

    // ── Frontend assets ────────────────────────────────────────────────

    public static function enqueue_frontend() : void {
        // Only load builder assets when a builder shortcode is on the page.
        // We register here; actual enqueue happens inside the shortcode render.
        wp_register_style(
            'tagforge-builder',
            TAGFORGE_URL . 'assets/tagforge-builder.css',
            [],
            TAGFORGE_VERSION
        );
        wp_register_script(
            'tagforge-builder',
            TAGFORGE_URL . 'assets/tagforge-builder.js',
            [ 'jquery' ],
            TAGFORGE_VERSION,
            true
        );
        // Left column state manager — enqueued only by shortcode_builder()
        wp_register_script(
            'tagforge-buildpage',
            TAGFORGE_URL . 'assets/tagforge-buildpage.js',
            [ 'jquery', 'tagforge-builder' ],
            TAGFORGE_VERSION,
            true
        );
    }

    // ── REST routes ────────────────────────────────────────────────────

    public static function register_routes() : void {
        register_rest_route( self::REST_NAMESPACE, '/builder/session', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_init_session'  ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::REST_NAMESPACE, '/builder/chat', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_chat'          ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::REST_NAMESPACE, '/builder/email', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_capture_email' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::REST_NAMESPACE, '/builder/complete', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_complete'      ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::REST_NAMESPACE, '/builder/cmps', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'route_get_cmps'      ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::REST_NAMESPACE, '/builder/send-preview', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_send_preview'  ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Route: Init session ────────────────────────────────────────────

    public static function route_init_session( \WP_REST_Request $req ) {
        $site_type = sanitize_text_field( $req->get_param( 'site_type' ) ?? '' );

        $session_id = self::generate_session_id();

        global $wpdb;
        $table = AI_DB::table_name();

        // Store initial answer (Q1 — site type from homepage widget)
        $answers = $site_type ? [ 'q1_site_type' => $site_type ] : [];

        $wpdb->insert( $table, [
            'session_id' => $session_id,
            'answers'    => wp_json_encode( $answers ),
            'status'     => 'active',
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ], [ '%s', '%s', '%s', '%s', '%s' ] );

        if ( $wpdb->last_error ) {
            return new \WP_Error( 'db_error', 'Could not create session.', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'session_id' => $session_id,
            'site_type'  => $site_type,
        ] );
    }

    // ── Route: Chat ────────────────────────────────────────────────────

    public static function route_chat( \WP_REST_Request $req ) {
        $session_id = sanitize_text_field( $req->get_param( 'session_id' ) ?? '' );
        $message    = sanitize_textarea_field( $req->get_param( 'message' ) ?? '' );
        $history    = $req->get_param( 'history' ) ?? [];   // array of {role, content}
        $phase      = sanitize_text_field( $req->get_param( 'phase' ) ?? 'qualify' ); // qualify|refine|collect_ids

        if ( ! $session_id || ! $message ) {
            return new \WP_Error( 'missing_params', 'session_id and message are required.', [ 'status' => 400 ] );
        }

        // Load session
        $session = self::get_session( $session_id );
        if ( ! $session ) {
            return new \WP_Error( 'invalid_session', 'Session not found.', [ 'status' => 404 ] );
        }

        // Enforce refinement limit on refine phase
        $opts      = Helpers::get_options();
        $ref_limit = (int) ( $opts['builder_refinement_limit'] ?? 2 );

        if ( $phase === 'refine' && (int) $session['refinements'] >= $ref_limit ) {
            return new \WP_Error( 'refinement_limit', 'Refinement limit reached.', [ 'status' => 429 ] );
        }

        // Rate limit: max calls per session per hour
        $rate_limit = (int) ( $opts['builder_rate_limit'] ?? 10 );
        if ( ! self::check_rate_limit( $session_id, $rate_limit ) ) {
            return new \WP_Error( 'rate_limited', 'Too many requests. Please wait a moment.', [ 'status' => 429 ] );
        }

        // Build system prompt based on phase
        $system = self::build_system_prompt( $phase, $session );

        // Sanitize and validate history array
        $clean_history = [];
        if ( is_array( $history ) ) {
            foreach ( $history as $turn ) {
                if ( ! is_array( $turn ) ) continue;
                $role    = in_array( $turn['role'] ?? '', [ 'user', 'assistant' ], true ) ? $turn['role'] : 'user';
                $content = sanitize_textarea_field( $turn['content'] ?? '' );
                if ( $content ) {
                    $clean_history[] = [ 'role' => $role, 'content' => $content ];
                }
            }
        }

        // Append current user message
        $clean_history[] = [ 'role' => 'user', 'content' => $message ];

        // Call Claude
        $claude_response = self::call_claude( $system, $clean_history );

        if ( is_wp_error( $claude_response ) ) {
            return $claude_response;
        }

        // If refine phase, increment counter
        if ( $phase === 'refine' ) {
            self::increment_refinements( $session_id );
        }

        // Parse structured response from Claude
        $parsed = self::parse_claude_response( $claude_response, $phase );

        // Persist recommendation to session when Claude finalises
        if ( ! empty( $parsed['modules'] ) ) {
            self::update_session_recommendation( $session_id, $parsed );
        }

        // Update session answers if new ones present
        if ( ! empty( $parsed['answers'] ) ) {
            self::update_session_answers( $session_id, $parsed['answers'] );
        }

        return rest_ensure_response( [
            'reply'       => $parsed['reply'],
            'phase'       => $parsed['next_phase'] ?? $phase,
            'modules'     => $parsed['modules'] ?? null,
            'custom_name' => $parsed['custom_name'] ?? null,
            'price'       => isset( $parsed['modules'] ) ? AI_Admin::price_for_count( count( $parsed['modules'] ) ) : null,
            'refinements_used'      => (int) $session['refinements'] + ( $phase === 'refine' ? 1 : 0 ),
            'refinements_remaining' => max( 0, $ref_limit - (int) $session['refinements'] - ( $phase === 'refine' ? 1 : 0 ) ),
        ] );
    }

    // ── Route: Capture email ───────────────────────────────────────────

    public static function route_capture_email( \WP_REST_Request $req ) {
        $session_id = sanitize_text_field( $req->get_param( 'session_id' ) ?? '' );
        $email      = sanitize_email( $req->get_param( 'email' ) ?? '' );

        if ( ! $session_id || ! is_email( $email ) ) {
            return new \WP_Error( 'invalid_params', 'Valid session_id and email are required.', [ 'status' => 400 ] );
        }

        $session = self::get_session( $session_id );
        if ( ! $session ) {
            return new \WP_Error( 'invalid_session', 'Session not found.', [ 'status' => 404 ] );
        }

        // Save email to session
        global $wpdb;
        $wpdb->update(
            AI_DB::table_name(),
            [ 'email' => $email, 'updated_at' => current_time( 'mysql' ) ],
            [ 'session_id' => $session_id ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        // Build partial preview JSON (3 tags from recommended modules)
        $preview = self::build_partial_preview( $session );

        return rest_ensure_response( [
            'success'      => true,
            'preview_tags' => $preview['tags'],
            'tag_count'    => $preview['total_count'],
            'hidden_count' => $preview['hidden_count'],
        ] );
    }

    // ── Route: Send free preview container ────────────────────────────
    // Called when customer clicks "Send me the free preview" in overlay.
    // Assembles a 3-module preview container, stores as transient,
    // generates a 48-hour download URL, POSTs to Zoho Campaigns.

    public static function route_send_preview( \WP_REST_Request $req ) {
        $session_id = sanitize_text_field( $req->get_param( 'session_id' ) ?? '' );
        $email      = sanitize_email( $req->get_param( 'email' ) ?? '' );

        if ( ! $session_id || ! is_email( $email ) ) {
            return new \WP_Error( 'invalid_params', 'Valid session_id and email are required.', [ 'status' => 400 ] );
        }

        $session = self::get_session( $session_id );
        if ( ! $session ) {
            return new \WP_Error( 'invalid_session', 'Session not found.', [ 'status' => 404 ] );
        }

        // Save email to session
        global $wpdb;
        $wpdb->update(
            AI_DB::table_name(),
            [ 'email' => $email, 'updated_at' => current_time( 'mysql' ) ],
            [ 'session_id' => $session_id ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        // ── Assemble preview container ─────────────────────────────
        // Use gtag-basic + consent-mode-v2 + first non-consent module
        // from the session recommendation, or scroll-depth as fallback.
        $session_modules = json_decode( $session['modules'] ?? '[]', true ) ?: [];
        $preview_third   = 'scroll-depth'; // fallback

        foreach ( $session_modules as $slug ) {
            if ( ! in_array( $slug, [ 'gtag-basic', 'consent-mode-v2', 'complianz-cmp', 'consentmo-cmp', 'cookiebot-cmp' ], true ) ) {
                $preview_third = $slug;
                break;
            }
        }

        $preview_modules = [ 'gtag-basic', 'consent-mode-v2', $preview_third ];
        $vars            = self::PLACEHOLDERS;
        $container       = Factory::assemble( $preview_modules, $vars );
        $json            = wp_json_encode( $container, JSON_PRETTY_PRINT );

        // ── Store as transient (48 hours) ──────────────────────────
        $token     = bin2hex( random_bytes( 16 ) );
        $key       = 'tf_preview_' . $token;
        set_transient( $key, $json, 48 * HOUR_IN_SECONDS );

        // ── Generate download URL ──────────────────────────────────
        $download_url = add_query_arg( [
            'tf_preview' => $token,
            'action'     => 'tagforge_preview_download',
        ], admin_url( 'admin-ajax.php' ) );

        // ── POST to Zoho Campaigns ─────────────────────────────────
        \TagForge\Zoho::subscribe( $email, $download_url, $session );

        return rest_ensure_response( [
            'success'      => true,
            'message'      => 'Preview sent! Check your inbox — your free container is on its way.',
            'modules'      => $preview_modules,
        ] );
    }

    // ── Route: Mark purchased ──────────────────────────────────────────

    public static function route_complete( \WP_REST_Request $req ) {
        $session_id = sanitize_text_field( $req->get_param( 'session_id' ) ?? '' );
        $order_id   = (int) $req->get_param( 'order_id' );

        if ( ! $session_id ) {
            return new \WP_Error( 'missing_params', 'session_id is required.', [ 'status' => 400 ] );
        }

        global $wpdb;
        $wpdb->update(
            AI_DB::table_name(),
            [
                'status'     => 'purchased',
                'order_id'   => $order_id ?: null,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'session_id' => $session_id ],
            [ '%s', $order_id ? '%d' : null, '%s' ],
            [ '%s' ]
        );

        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Route: Get available CMP modules ──────────────────────────────
    // Scans /modules directory for *-cmp.json files dynamically.
    // Add a new CMP module file → it automatically appears in the Builder.

    public static function route_get_cmps( \WP_REST_Request $req ) {
        $map  = Factory::get_module_map();
        $cmps = [];
        foreach ( $map as $slug => $path ) {
            if ( substr( $slug, -4 ) === '-cmp' ) {
                // Generate a friendly label from slug
                $label = ucwords( str_replace( '-', ' ', str_replace( '-cmp', '', $slug ) ) );
                $cmps[] = [
                    'slug'  => $slug,
                    'label' => $label,
                    'value' => $slug,
                ];
            }
        }
        return rest_ensure_response( [ 'cmps' => $cmps ] );
    }

    // ── Claude API call ────────────────────────────────────────────────

    private static function call_claude( string $system, array $messages ) {
        $opts    = Helpers::get_options();
        $api_key = $opts['claude_api_key'] ?? '';

        if ( empty( $api_key ) ) {
            return new \WP_Error( 'no_api_key', 'Claude API key not configured.', [ 'status' => 500 ] );
        }

        $body = wp_json_encode( [
            'model'      => self::MODEL,
            'max_tokens' => 1024,
            'system'     => $system,
            'messages'   => $messages,
        ] );

        $response = wp_remote_post( self::ANTHROPIC_API, [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[TagForge AI] Claude API connection error: ' . $response->get_error_message() );
            return new \WP_Error( 'api_error', 'Could not reach Claude API.', [ 'status' => 502 ] );
        }

        $code      = wp_remote_retrieve_response_code( $response );
        $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err = $resp_body['error']['message'] ?? 'Unknown API error';
            error_log( "[TagForge AI] Claude API error {$code}: {$err}" );
            return new \WP_Error( 'api_error', $err, [ 'status' => $code ] );
        }

        return $resp_body['content'][0]['text'] ?? '';
    }

    // ── System prompts ─────────────────────────────────────────────────

    private static function build_system_prompt( string $phase, array $session ) : string {
        $modules_list = implode( ', ', array_keys( Factory::get_module_map() ) );
        $site_type    = json_decode( $session['answers'] ?? '{}', true )['q1_site_type'] ?? '';

        $base = "You are the TagForge AI Builder — a friendly, expert GTM configuration assistant for tagforge.io.
TagForge assembles pre-filled Google Tag Manager containers for WordPress/WooCommerce sites.

Available modules: {$modules_list}

Module descriptions:
- gtag-basic: GA4 Configuration tag (required for all GA4 tracking)
- ecom-base: GA4 ecommerce base events (view_item, add_to_cart, begin_checkout)
- ecom-advanced: GA4 full ecommerce funnel (purchase, refund, remove_from_cart)
- consent-mode-v2: Consent Mode v2 defaults — sets DENY before consent decision
- complianz-cmp: Complianz CMP integration — sends consent update signals to GTM (WordPress only)
- consentmo-cmp: Consentmo GDPR CMP integration — Consent Mode v2 for Shopify stores using Consentmo (requires Consentmo paid plan for full Consent Mode v2 support)
- cookiebot-cmp: Cookiebot CMP integration — Consent Mode v2 for any platform using Cookiebot/Usercentrics. Requires customer's Cookiebot Domain Group ID
- facebook-pixel: Meta Pixel base tag
- facebook-events: Meta standard events (ViewContent, AddToCart, Purchase etc) wired to GA4 dataLayer
- google-ads-conversion: Google Ads conversion tracking
- google-ads-remarketing: Google Ads remarketing tag
- linkedin-insight: LinkedIn Insight Tag for B2B audience targeting
- tiktok-pixel: TikTok Pixel
- pinterest-tag: Pinterest Tag
- bing-uet: Microsoft Bing UET Tag
- microsoft-clarity: Microsoft Clarity session recording
- hotjar: Hotjar session recording and heatmaps
- scroll-depth: Scroll depth tracking at 25/50/75/90%
- click-tracking: Generic click element tracking
- outbound-link-tracking: Tracks clicks on external links
- form-tracking: Form submission tracking
- search-tracking: Site search query tracking
- yt-video-tracking: YouTube video engagement (play, pause, complete)
- engagement-timer: Time on page / engagement timer

Rules:
- Always include consent-mode-v2 unless consentmo-cmp or cookiebot-cmp is in the recommendation — those CMP templates manage consent initialisation natively and consent-mode-v2 is redundant alongside them
- Always include gtag-basic if any GA4 module is included
- If facebook-events is included, include facebook-pixel too
- If ecom-advanced is included, include ecom-base too
- If the site is WordPress and uses Complianz, include complianz-cmp
- If the site is Shopify and uses Consentmo, include consentmo-cmp
- If the site uses Cookiebot (any platform), include cookiebot-cmp
- Never include more than one CMP module in the same recommendation
- If the customer is unsure of their CMP, ask before including a CMP module
- Always ask the platform question (WordPress or Shopify) before recommending modules — platform affects which CMP and ecom modules are appropriate
- Keep responses concise and conversational — no walls of text
- Do not mention competitor products or services
- When asking a multiple choice question, always format clickable options as:
  OPTIONS: [option 1] | [option 2] | [option 3] | [option 4]
  This line must appear immediately after your question on its own line.
  Always use OPTIONS format for Q2, Q3, Q4 and Q5 — never leave questions open-ended without options.
  - CRITICAL: For the advertising platforms question you MUST use MULTI_OPTIONS: not OPTIONS: — this enables multi-select buttons
  MULTI_OPTIONS: Google Ads | Meta (Facebook & Instagram) | LinkedIn | TikTok | Pinterest | Microsoft/Bing | None / Just Analytics

" . ( $site_type ? "The customer's site type is: {$site_type}.\n" : '' );

        switch ( $phase ) {
            case 'qualify':
                return $base . "
PHASE: Qualification (Q1-Q4)
You are asking 4 questions to understand what modules to recommend.
Ask one question at a time. After the 4th answer, output a RECOMMENDATION block.
Every question MUST include an OPTIONS: line with clickable choices.

Questions to work through (adapt wording naturally):
Q1: Platform — WordPress / WooCommerce or Shopify? This determines which ecom and CMP modules are appropriate.
    OPTIONS: WordPress / WooCommerce | Shopify | Other
Q2: Primary goal — adapts to site type and platform. For ecommerce: tracking sales / understanding traffic sources / retargeting.
    For lead gen: form fills / ad attribution / content engagement.
    For content: scroll depth / newsletter signups / outbound clicks.
Q3: Paid advertising platforms — Google Ads / Meta (Facebook & Instagram) / LinkedIn / TikTok / None yet / Multiple
Q4: Consent banner / CMP — Complianz (WordPress) / Consentmo (Shopify) / Cookiebot / Another CMP / No banner / Not sure
    If not sure, briefly explain why a CMP matters for GDPR compliance.
    Use the platform answer from Q1 to suggest the most appropriate CMP.

After Q4, output your recommendation in this EXACT format — include the delimiters:

===RECOMMENDATION===
CUSTOM_NAME: [A short evocative 3-5 word container name — e.g. 'The DTC Growth Stack', 'The B2B Lead Engine']
MODULES: [comma-separated module slugs from the available list]
RATIONALE: [One sentence explaining why this combination suits their situation]
===END===

Then write a friendly summary message to show the customer.";

            case 'qualify_conversational':
                return $base . "
PHASE: Conversational Qualification
You need to understand: the customer's platform, their primary tracking goal, which ad platforms they run, and their CMP situation.

Do NOT follow a rigid question list. Have a natural expert conversation to discover these four things.
You can combine questions when it makes sense — for example asking about platform and goal together.
Aim to reach a recommendation within 3 exchanges maximum.
Use OPTIONS: lines for any multiple choice question.

When you have enough signal on all four areas, output the RECOMMENDATION block immediately.
Do not say you are going to ask more questions if you already have enough information.
Think like a senior GTM consultant who quickly sizes up a client's needs.

===RECOMMENDATION===
CUSTOM_NAME: [evocative 3-5 word name]
MODULES: [comma-separated slugs]
RATIONALE: [one sentence]
NOTES: [caveats if any]
===END===

Then write a natural, confident summary — not a list of bullet points.";

            case 'refine':
                $current_modules = json_decode( $session['modules'] ?? '[]', true ) ?: [];
                $current_name    = $session['custom_name'] ?? '';
                return $base . "
PHASE: Refinement
Current recommendation:
Name: {$current_name}
Modules: " . implode( ', ', $current_modules ) . "

The customer wants to adjust the recommendation. Make the requested change and output a full updated RECOMMENDATION block in the same format:

===RECOMMENDATION===
CUSTOM_NAME: [updated or same name]
MODULES: [updated comma-separated module slugs]
RATIONALE: [Updated one sentence rationale]
===END===

Then confirm what changed in a friendly message.";

            case 'collect_ids':
                $modules = json_decode( $session['modules'] ?? '[]', true ) ?: [];
                $needed  = self::ids_needed_for_modules( $modules );
                $needed_list = implode( ', ', array_keys( $needed ) );
                return $base . "
PHASE: ID Collection
The customer has agreed to their container. Now collect their measurement IDs one by one.
IDs needed for their module selection: {$needed_list}

For each ID:
1. Ask for it by name in plain language
2. Include a ONE sentence instruction on where to find it (platform + location)
3. Tell them they can skip it — placeholder will be used, editable in GTM Variables later

IMPORTANT — Google Ads Conversion Label (GADS_CONVERSION_LABEL):
This is NOT a global account ID. It is unique to each conversion action.
For TagForge containers, it is wired to the Purchase conversion action only.
Tell the customer: This is the label for your Purchase conversion action specifically —
find it in Google Ads → Goals → Conversions → your Purchase action → Tag details → the string after the slash.
If they only run remarketing (no conversion tracking), they can skip this.

When you have all IDs (or skips), output:

===IDS===
[PLACEHOLDER_KEY]: [collected value OR 'SKIP']
===END===

Be encouraging. Frame skipping as normal and fine.";

            default:
                return $base;
        }
    }

    // ── Parse Claude response ──────────────────────────────────────────

    private static function parse_claude_response( string $text, string $phase ) : array {
        $result = [ 'reply' => $text ];

        // Extract RECOMMENDATION block
        if ( preg_match( '/===RECOMMENDATION===(.*?)===END===/s', $text, $m ) ) {
            $block = $m[1];

            if ( preg_match( '/CUSTOM_NAME:\s*(.+)/i', $block, $nm ) ) {
                $result['custom_name'] = trim( $nm[1] );
            }
            if ( preg_match( '/MODULES:\s*(.+)/i', $block, $mm ) ) {
                $slugs = array_filter( array_map( 'trim', explode( ',', $mm[1] ) ) );
                // Validate against known modules
                $known   = array_keys( Factory::get_module_map() );
                $result['modules'] = array_values( array_filter( $slugs, fn( $s ) => in_array( $s, $known, true ) ) );
            }

            // Strip the block from the visible reply
            $result['reply'] = trim( preg_replace( '/===RECOMMENDATION===(.*?)===END===/s', '', $text ) );
            $result['next_phase'] = 'email_gate';
        }

        // Extract IDS block
        if ( preg_match( '/===IDS===(.*?)===END===/s', $text, $m ) ) {
            $ids = [];
            foreach ( explode( "\n", trim( $m[1] ) ) as $line ) {
                if ( preg_match( '/^([A-Z_]+):\s*(.+)$/i', trim( $line ), $lm ) ) {
                    $key = strtoupper( trim( $lm[1] ) );
                    $val = trim( $lm[2] );
                    if ( array_key_exists( $key, self::PLACEHOLDERS ) ) {
                        $ids[ $key ] = ( strtolower( $val ) === 'skip' ) ? self::PLACEHOLDERS[ $key ] : sanitize_text_field( $val );
                    }
                }
            }
            $result['collected_ids'] = $ids;
            $result['reply']         = trim( preg_replace( '/===IDS===(.*?)===END===/s', '', $text ) );
            $result['next_phase']    = 'checkout';
        }

        return $result;
    }

    // ── Partial preview for JSON overlay ──────────────────────────────

    private static function build_partial_preview( array $session ) : array {
        $modules = json_decode( $session['modules'] ?? '[]', true ) ?: [];

        if ( empty( $modules ) ) {
            return [ 'tags' => [], 'total_count' => 0, 'hidden_count' => 0 ];
        }

        // Assemble the full container with dummy placeholders
        $export = Factory::assemble( $modules, self::PLACEHOLDERS );
        $tags   = $export['containerVersion']['tag'] ?? [];

        $total   = count( $tags );
        $preview = array_slice( $tags, 0, 3 );

        // Return simplified preview (name + type only — not full JSON)
        $simplified = array_map( function( $tag ) {
            return [
                'name' => $tag['name'] ?? 'Tag',
                'type' => $tag['type'] ?? '',
            ];
        }, $preview );

        return [
            'tags'         => $simplified,
            'total_count'  => $total,
            'hidden_count' => max( 0, $total - 3 ),
        ];
    }

    // ── IDs needed for module set ──────────────────────────────────────

    public static function ids_needed_for_modules( array $modules ) : array {
        $map = [
            'gtag-basic'             => [ 'GA4_MEASUREMENT_ID' ],
            'ecom-base'              => [ 'GA4_MEASUREMENT_ID' ],
            'ecom-advanced'          => [ 'GA4_MEASUREMENT_ID' ],
            'facebook-pixel'         => [ 'PIXEL_ID' ],
            'facebook-events'        => [ 'PIXEL_ID' ],
            'google-ads-conversion'  => [ 'GADS_CONVERSION_ID', 'GADS_CONVERSION_LABEL' ],
            'google-ads-remarketing' => [ 'GADS_CONVERSION_ID' ],
            'linkedin-insight'       => [ 'LI_PARTNER_ID' ],
            'tiktok-pixel'           => [ 'TIKTOK_PIXEL_ID' ],
            'microsoft-clarity'      => [ 'CLARITY_PROJECT_ID' ],
            'hotjar'                 => [ 'HOTJAR_SITE_ID' ],
            'bing-uet'               => [ 'BING_UET_TAG_ID' ],
            'pinterest-tag'          => [ 'PINTEREST_TAG_ID' ],
            'cookiebot-cmp'          => [ 'COOKIEBOT_DOMAIN_ID' ],
        ];

        $needed = [];
        foreach ( $modules as $slug ) {
            if ( isset( $map[ $slug ] ) ) {
                foreach ( $map[ $slug ] as $id_key ) {
                    $needed[ $id_key ] = self::PLACEHOLDERS[ $id_key ];
                }
            }
        }
        return $needed;
    }

    // ── Preview download AJAX handler ─────────────────────────────────

    public static function handle_preview_download() : void {
        $token = sanitize_text_field( $_GET['tf_preview'] ?? '' );
        if ( ! $token ) wp_die( 'Invalid request.' );

        $key  = 'tf_preview_' . $token;
        $json = get_transient( $key );

        if ( ! $json ) {
            wp_die( 'This preview link has expired. Please return to tagforge.io to generate a new one.' );
        }

        $filename = 'tagforge-preview-container.json';
        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json;
        exit;
    }

    // ── Zoho lead creation ─────────────────────────────────────────────

    private static function create_zoho_lead( string $email, array $session ) : void {
        // Non-blocking — errors logged but don't affect the session response
        try {
            $modules     = json_decode( $session['modules'] ?? '[]', true ) ?: [];
            $answers     = json_decode( $session['answers'] ?? '{}', true ) ?: [];
            $custom_name = $session['custom_name'] ?? '';
            $price       = $session['price'] ?? '';

            $note = "TagForge AI Builder Lead\n";
            $note .= "Container: {$custom_name}\n";
            $note .= "Modules (" . count( $modules ) . "): " . implode( ', ', $modules ) . "\n";
            $note .= "Price: €{$price}\n";
            $note .= "Q1 (site type): " . ( $answers['q1_site_type'] ?? '—' ) . "\n";

            // Use existing Zoho integration if class_exists
            // This is a placeholder — wire to your Zoho class when ready
            do_action( 'tagforge_builder_lead_captured', $email, $custom_name, $modules, $answers, $note );

        } catch ( \Throwable $e ) {
            error_log( '[TagForge AI] Zoho lead error: ' . $e->getMessage() );
        }
    }

    // ── Rate limiting ──────────────────────────────────────────────────

    private static function check_rate_limit( string $session_id, int $max ) : bool {
        $key   = 'tf_rl_' . md5( $session_id );
        $count = (int) get_transient( $key );
        if ( $count >= $max ) return false;
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    // ── Session helpers ────────────────────────────────────────────────

    private static function get_session( string $session_id ) : ?array {
        global $wpdb;
        $table = AI_DB::table_name();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private static function generate_session_id() : string {
        return bin2hex( random_bytes( 16 ) );
    }

    private static function increment_refinements( string $session_id ) : void {
        global $wpdb;
        $table = AI_DB::table_name();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET refinements = refinements + 1, updated_at = %s WHERE session_id = %s",
                current_time( 'mysql' ),
                $session_id
            )
        );
    }

    private static function update_session_recommendation( string $session_id, array $parsed ) : void {
        global $wpdb;
        $table   = AI_DB::table_name();
        $modules = $parsed['modules'] ?? null;
        $name    = $parsed['custom_name'] ?? null;
        $price   = $modules ? AI_Admin::price_for_count( count( $modules ) ) : null;

        $data   = [ 'updated_at' => current_time( 'mysql' ) ];
        $format = [ '%s' ];

        if ( $modules !== null ) { $data['modules']     = wp_json_encode( $modules ); $format[] = '%s'; }
        if ( $name !== null )    { $data['custom_name'] = $name;                       $format[] = '%s'; }
        if ( $price !== null )   { $data['price']       = $price;                      $format[] = '%f'; }

        $wpdb->update( $table, $data, [ 'session_id' => $session_id ], $format, [ '%s' ] );
    }

    private static function update_session_answers( string $session_id, array $new_answers ) : void {
        global $wpdb;
        $table   = AI_DB::table_name();
        $session = self::get_session( $session_id );
        if ( ! $session ) return;

        $existing = json_decode( $session['answers'] ?? '{}', true ) ?: [];
        $merged   = array_merge( $existing, $new_answers );

        $wpdb->update(
            $table,
            [ 'answers' => wp_json_encode( $merged ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'session_id' => $session_id ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }

    // ── Shortcode: [tagforge_builder] — full two-column experience ────────
    // Complete buying experience: meta, chat, WooCommerce form, Add to Cart.
    // Self-contained, embeddable anywhere. Enqueues all three builder assets.
    // [tagforge_builder_full] is registered as an alias for backward compat.

    public static function shortcode_builder( array $atts ) : string {
        $atts = shortcode_atts( [
            'product_id' => get_option( 'tagforge_builder_product_id', 1242 ),
            'chat_style' => 'conversational',
        ], $atts, 'tagforge_builder' );

        $product_id = (int) $atts['product_id'];
        $product    = wc_get_product( $product_id );
        if ( ! $product ) return '';

        wp_enqueue_style( 'tagforge-builder' );
        wp_enqueue_script( 'tagforge-builder' );
        wp_enqueue_script( 'tagforge-buildpage' );

        wp_localize_script( 'tagforge-builder', 'TF_Builder', [
            'rest_url'       => rest_url( self::REST_NAMESPACE . '/builder/' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'build_url'      => get_permalink( $product_id ),
            'mode'           => 'full',
            'product_id'     => $product_id,
            'chat_style'     => sanitize_key( $atts['chat_style'] ),
            'ref_limit'      => (int) ( Helpers::get_options()['builder_refinement_limit'] ?? 2 ),
            'price_tiers'    => AI_Admin::get_price_tiers(),
            'ids_needed_map' => self::ids_needed_for_modules( array_keys( Factory::get_module_map() ) ),
        ] );

        ob_start();
        global $post;
        $post = get_post( $product_id );
        setup_postdata( $post );
        ?>
        <div class="tf-build-page">

            <div class="tf-build-meta" id="tf-build-meta">
                <span class="tf-build-label">Custom Container &middot; AI Built</span>
                <h1 class="tf-build-title" id="tf-build-title"><?php echo esc_html( $product->get_name() ); ?></h1>
                <div class="tf-build-price-range" id="tf-build-price-range">
                    <span class="tf-build-price-from">From</span>
                    <span class="tf-build-price-val">&euro;49</span>
                    <span class="tf-build-price-note">&mdash; price set by your build</span>
                </div>
                <p class="tf-build-description">Answer a few questions in the chat and the AI Builder assembles the right GTM modules for your site &mdash; pre-filled with your IDs, ready to import in 60 seconds.</p>
                <div class="tf-build-invite" id="tf-build-invite">
                    <div class="tf-build-invite__inner">
                        <span class="tf-build-invite__arrow-desktop">&rarr;</span>
                        <span class="tf-build-invite__text">Use the chat to build your container</span>
                    </div>
                    <p class="tf-build-invite__sub">Tell us about your site &mdash; we&rsquo;ll recommend the right modules, collect your IDs, and assemble your container in seconds.</p>
                </div>
            </div>

            <div class="tf-build-left" id="tf-build-left">
                <div class="tf-build-form-wrap" id="tf-build-form-wrap">
                    <div class="tf-build-form-header" id="tf-build-form-header">
                        <div class="tf-build-form-badge">Your container</div>
                        <h2 class="tf-build-form-name" id="tf-build-form-name"></h2>
                        <div class="tf-build-form-modules" id="tf-build-form-modules"></div>
                        <div class="tf-build-form-price-row">
                            <span class="tf-build-form-price-label">Container price</span>
                            <span class="tf-build-form-price-val" id="tf-build-form-price"></span>
                        </div>
                    </div>
                    <div class="tf-build-wc-form">
                        <?php do_action( 'woocommerce_before_add_to_cart_form' ); ?>
                        <form class="cart" action="<?php echo esc_url( $product->add_to_cart_url() ); ?>" method="post" enctype="multipart/form-data">
                            <?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>
                            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>">
                            <input type="hidden" name="quantity" value="1">
                            <input type="hidden" name="tf_session_id" id="tf_session_id" value="">
                            <input type="hidden" name="tf_custom_name" id="tf_custom_name" value="">
                            <div class="tf-build-atc-wrap">
                                <button type="submit" class="single_add_to_cart_button button alt tf-build-atc-btn">
                                    <?php echo esc_html( $product->single_add_to_cart_text() ); ?>
                                </button>
                            </div>
                            <?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
                        </form>
                        <?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>
                    </div>
                    <p class="tf-build-form-note">Pre-filled with your IDs &middot; 7-day download link &middot; Works with any GTM container</p>
                </div>
            </div>

            <div class="tf-build-right" id="tf-build-right">
                <div class="tf-builder-full" id="tf-builder-full" data-product-id="<?php echo esc_attr( $product_id ); ?>">
                    <div class="tf-builder-chat" id="tf-builder-chat">
                        <div class="tf-builder-messages" id="tf-builder-messages"></div>
                        <div class="tf-builder-input-row" id="tf-builder-input-row">
                            <input type="text" id="tf-builder-input" class="tf-builder-input" placeholder="Type your answer&hellip;" autocomplete="off">
                            <button id="tf-builder-send" class="tf-builder-send-btn">Send</button>
                        </div>
                    </div>
                    <div class="tf-builder-recommendation" id="tf-builder-recommendation" style="display:none;">
                        <div class="tf-builder-rec__badge">Your container</div>
                        <h2 class="tf-builder-rec__name" id="tf-builder-rec-name">&mdash;</h2>
                        <div class="tf-builder-rec__modules" id="tf-builder-rec-modules"></div>
                        <div class="tf-builder-rec__price">
                            <span class="tf-builder-rec__price-label">Container price</span>
                            <span class="tf-builder-rec__price-val" id="tf-builder-rec-price">&mdash;</span>
                        </div>
                        <div class="tf-builder-rec__actions">
                            <button class="tf-builder-btn tf-builder-btn--primary" id="tf-builder-get-container">Get preview &rarr;</button>
                            <button class="tf-builder-btn tf-builder-btn--ghost" id="tf-builder-refine">Refine this</button>
                        </div>
                        <div class="tf-builder-refine-count" id="tf-builder-refine-count"></div>
                    </div>
                    <div class="tf-builder-overlay" id="tf-builder-email-gate" style="display:none;">
                        <div class="tf-builder-overlay__box">
                            <h3>Your container is ready to preview</h3>
                            <p>Where should we send the build summary?</p>
                            <input type="email" id="tf-builder-email-input" class="tf-builder-input" placeholder="your@email.com">
                            <button class="tf-builder-btn tf-builder-btn--primary" id="tf-builder-submit-email">Show my preview &rarr;</button>
                            <p class="tf-builder-overlay__note">No spam. Unsubscribe any time.</p>
                        </div>
                    </div>
                    <div class="tf-builder-overlay" id="tf-builder-preview-overlay" style="display:none;">
                        <div class="tf-builder-overlay__box tf-builder-overlay__box--preview">
                            <div class="tf-builder-preview__header">
                                <span class="tf-builder-preview__label">Sample from your container</span>
                                <button class="tf-builder-preview__close" id="tf-builder-preview-close">&times;</button>
                            </div>
                            <div class="tf-builder-preview__tags" id="tf-builder-preview-tags"></div>
                            <div class="tf-builder-preview__more" id="tf-builder-preview-more"></div>
                            <div class="tf-builder-preview__cta">
                                <button class="tf-builder-btn tf-builder-btn--primary tf-builder-btn--lg" id="tf-builder-send-preview-btn">Send me the free preview</button>
                                <button class="tf-builder-btn tf-builder-btn--ghost tf-builder-btn--lg tf-builder-btn--mt" id="tf-builder-checkout-btn">
                                    Get the full container &rarr;
                                    <span class="tf-builder-checkout-price" id="tf-builder-checkout-price"></span>
                                </button>
                            </div>
                            <div id="tf-preview-email-wrap" style="display:none;">
                                <input type="email" id="tf-preview-email-input" class="tf-builder-input tf-preview-email-input" placeholder="your@email.com">
                                <button class="tf-builder-btn tf-builder-btn--primary" id="tf-preview-email-submit">Send it &rarr;</button>
                                <p class="tf-builder-overlay__note">No spam. Unsubscribe any time.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- .tf-build-page -->
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    // ── Shortcode: [tagforge_chat] — chat-only widget ──────────────────
    // Q1 only. On selection redirects to /build?site_type=VALUE.
    // Q2-Q4 complete inside [tagforge_builder] on /build.
    // Also registered as [tagforge_builder_widget] for backward compat.

    public static function shortcode_chat( array $atts ) : string {
        $atts = shortcode_atts( [
            'theme'    => 'dark',
            'title'    => 'Build your container',
            'subtitle' => 'Answer a few questions — get a custom GTM setup in minutes.',
        ], $atts, 'tagforge_chat' );

        wp_enqueue_style( 'tagforge-builder' );
        wp_enqueue_script( 'tagforge-builder' );
        wp_localize_script( 'tagforge-builder', 'TF_Builder', [
            'rest_url'  => rest_url( self::REST_NAMESPACE . '/builder/' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'build_url' => get_permalink( get_option( 'tagforge_builder_product_id', 1242 ) ),
            'mode'      => 'widget',
        ] );

        ob_start();
        $theme    = sanitize_html_class( $atts['theme'] );
        $title    = esc_html( $atts['title'] );
        $subtitle = esc_html( $atts['subtitle'] );
        ?>
        <div class="tf-builder-widget tf-builder-widget--<?php echo $theme; ?>" id="tf-builder-widget">
            <div class="tf-builder-widget__inner">
                <h3 class="tf-builder-widget__title"><?php echo $title; ?></h3>
                <p class="tf-builder-widget__subtitle"><?php echo $subtitle; ?></p>
                <p class="tf-builder-widget__question">What best describes your site?</p>
                <div class="tf-builder-widget__options">
                    <button class="tf-builder-opt" data-value="shopify-ecommerce">Shopify &mdash; Ecommerce</button>
                    <button class="tf-builder-opt" data-value="wordpress-ecommerce">WordPress &mdash; Ecommerce</button>
                    <button class="tf-builder-opt" data-value="wordpress-lead-gen">WordPress &mdash; Lead gen &amp; B2B</button>
                    <button class="tf-builder-opt" data-value="wordpress-content">WordPress &mdash; Content &amp; blog</button>
                    <button class="tf-builder-opt" data-value="unsure">Not sure yet</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: Full builder (legacy alias) ─────────────────────────

    public static function shortcode_full( array $atts ) : string {
        $atts = shortcode_atts( [
            'product_id' => AI_Pricing::get_builder_product_id(),
            'chat_style' => 'guided', // 'guided' | 'conversational'
        ], $atts, 'tagforge_builder_full' );

        wp_enqueue_style( 'tagforge-builder' );
        wp_enqueue_script( 'tagforge-builder' );
        wp_localize_script( 'tagforge-builder', 'TF_Builder', [
            'rest_url'         => rest_url( self::REST_NAMESPACE . '/builder/' ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'build_url'        => home_url( '/build/' ),
            'mode'             => 'full',
            'product_id'       => (int) ( $atts['product_id'] ?? AI_Pricing::get_builder_product_id() ),
            'chat_style'       => sanitize_key( $atts['chat_style'] ),
            'ref_limit'        => (int) ( Helpers::get_options()['builder_refinement_limit'] ?? 2 ),
            'price_tiers'      => AI_Admin::get_price_tiers(),
            'ids_needed_map'   => self::ids_needed_for_modules( array_keys( Factory::get_module_map() ) ),
        ] );

        ob_start();
        ?>
        <div class="tf-builder-full" id="tf-builder-full">

            <!-- Conversation panel -->
            <div class="tf-builder-chat" id="tf-builder-chat">
                <div class="tf-builder-messages" id="tf-builder-messages">
                    <!-- Messages injected by JS -->
                </div>
                <div class="tf-builder-input-row" id="tf-builder-input-row">
                    <input type="text" id="tf-builder-input" class="tf-builder-input" placeholder="Type your answer…" autocomplete="off">
                    <button id="tf-builder-send" class="tf-builder-send-btn">Send</button>
                </div>
            </div>

            <!-- Recommendation panel (hidden until Claude responds) -->
            <div class="tf-builder-recommendation" id="tf-builder-recommendation" style="display:none;">
                <div class="tf-builder-rec__badge">Your container</div>
                <h2 class="tf-builder-rec__name" id="tf-builder-rec-name">—</h2>
                <div class="tf-builder-rec__modules" id="tf-builder-rec-modules"></div>
                <div class="tf-builder-rec__price">
                    <span class="tf-builder-rec__price-label">Container price</span>
                    <span class="tf-builder-rec__price-val" id="tf-builder-rec-price">—</span>
                </div>
                <div class="tf-builder-rec__actions">
                    <button class="tf-builder-btn tf-builder-btn--primary" id="tf-builder-get-container">Get preview →</button>
                    <button class="tf-builder-btn tf-builder-btn--ghost" id="tf-builder-refine">Refine this</button>
                </div>
                <div class="tf-builder-refine-count" id="tf-builder-refine-count"></div>
            </div>

            <!-- Email gate overlay -->
            <div class="tf-builder-overlay" id="tf-builder-email-gate" style="display:none;">
                <div class="tf-builder-overlay__box">
                    <h3>Your container is ready to preview</h3>
                    <p>Where should we send the build summary?</p>
                    <input type="email" id="tf-builder-email-input" class="tf-builder-input" placeholder="your@email.com">
                    <button class="tf-builder-btn tf-builder-btn--primary" id="tf-builder-submit-email">Show my preview →</button>
                    <p class="tf-builder-overlay__note">No spam. Unsubscribe any time.</p>
                </div>
            </div>

            <!-- Partial JSON preview overlay -->
            <div class="tf-builder-overlay" id="tf-builder-preview-overlay" style="display:none;">
                <div class="tf-builder-overlay__box tf-builder-overlay__box--preview">
                    <div class="tf-builder-preview__header">
                        <span class="tf-builder-preview__label">Sample from your container</span>
                        <button class="tf-builder-preview__close" id="tf-builder-preview-close">✕</button>
                    </div>
                    <div class="tf-builder-preview__tags" id="tf-builder-preview-tags">
                        <!-- Tag list injected by JS -->
                    </div>
                    <div class="tf-builder-preview__more" id="tf-builder-preview-more"></div>
                    <div class="tf-builder-preview__cta">
                        <button class="tf-builder-btn tf-builder-btn--primary tf-builder-btn--lg" id="tf-builder-send-preview-btn">
                            Send me the free preview
                        </button>
                        <button class="tf-builder-btn tf-builder-btn--ghost tf-builder-btn--lg tf-builder-btn--mt" id="tf-builder-checkout-btn">
                            Get the full container →
                            <span class="tf-builder-checkout-price" id="tf-builder-checkout-price"></span>
                        </button>
                    </div>
                    <!-- Email input for preview send -->
                    <div id="tf-preview-email-wrap" style="display:none;">
                        <input type="email" id="tf-preview-email-input" class="tf-builder-input tf-preview-email-input" placeholder="your@email.com">
                        <button class="tf-builder-btn tf-builder-btn--primary" id="tf-preview-email-submit">Send it →</button>
                        <p class="tf-builder-overlay__note">No spam. Unsubscribe any time.</p>
                    </div>
                </div>
            </div>

            <!-- Hidden WooCommerce form (populated by JS before Add to Cart) -->
            <div id="tf-builder-woo-form" style="display:none;">
                <?php if ( function_exists( 'woocommerce_template_single_add_to_cart' ) ) : ?>
                    <?php // WooCommerce add to cart form rendered by Elementor template — this is a placeholder ?>
                <?php endif; ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
