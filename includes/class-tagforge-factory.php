<?php
namespace TagForge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TagForge\Factory
 * - Discovers modules in /modules
 * - Merges selected module JSONs into a single GTM export (formatVersion 2)
 * - Renumbers IDs and rewrites trigger references
 * - Normalizes trigger enum casing to GTM expectations
 * - Replaces {{PLACEHOLDERS}} with provided $vars (e.g., GA4_MEASUREMENT_ID)
 */
class Factory {

    /**
     * Discover available modules under /modules/*.json
     * @return array slug => relative path
     */
    public static function discover_map() : array {
        $dir = trailingslashit( TAGFORGE_DIR ) . 'modules/';
        $map = [];
        if ( is_dir( $dir ) ) {
            foreach ( glob( $dir . '*.json' ) as $file ) {
                $slug = sanitize_key( basename( $file, '.json' ) );
                $map[ $slug ] = 'modules/' . basename( $file );
            }
        }
        return $map;
    }

    /**
     * Cached module map
     */
    public static function get_module_map() : array {
        static $cached = null;
        if ( $cached === null ) {
            $cached = self::discover_map();
        }
        return $cached;
    }

    /**
     * Assemble a GTM export from selected module slugs and placeholder variables.
     *
     * @param array $slugs
     * @param array $vars  e.g. ['GA4_MEASUREMENT_ID' => 'G-XXXX']
     * @return array GTM export object
     */
    /**
     * CMP modules that manage consent initialisation natively.
     * When any of these are present, consent-mode-v2 is redundant
     * and is removed from the assembly list automatically.
     */
    private static function self_managing_cmps() : array {
        return [ 'consentmo-cmp', 'cookiebot-cmp' ];
    }

    public static function assemble( array $slugs, array $vars ) : array {

        // If a self-managing CMP is present, drop consent-mode-v2.
        // These CMP community templates call setDefaultConsentState()
        // internally — the standalone consent-mode-v2 HTML tag is
        // redundant and would double-set defaults.
        $has_self_managing_cmp = ! empty(
            array_intersect( $slugs, self::self_managing_cmps() )
        );
        if ( $has_self_managing_cmp ) {
            $slugs = array_values(
                array_filter( $slugs, fn( $s ) => $s !== 'consent-mode-v2' )
            );
            Helpers::dbg( null, 'Factory: consent-mode-v2 suppressed — self-managing CMP present' );
        }

        $result = [
            'containerVersion' => [
                'tag'             => [],
                'trigger'         => [],
                'variable'        => [],
                'builtInVariable' => [],
            ],
        ];

        // ID allocator for unique tag/trigger/variable ids
        $nextId = 1000000000;
        $alloc  = function() use ( &$nextId ) {
            return (string) $nextId++;
        };

        foreach ( $slugs as $slug ) {
            $path = self::resolve_path( $slug );
            if ( ! $path || ! file_exists( $path ) ) {
                Helpers::dbg( null, "Factory: missing module {$slug}" );
                continue;
            }

            $json = json_decode( file_get_contents( $path ), true );
            if ( ! $json ) {
                Helpers::dbg( null, "Factory: invalid JSON in {$slug}" );
                continue;
            }

            // Module can be a full export or just containerVersion
            $cv = ! empty( $json['containerVersion'] ) ? $json['containerVersion'] : $json;

            // Renumber items and normalize trigger types
            $idmap = [];

            foreach ( ['variable', 'trigger', 'tag'] as $type ) {
                if ( empty( $cv[ $type ] ) || ! is_array( $cv[ $type ] ) ) {
                    continue;
                }
                foreach ( $cv[ $type ] as &$item ) {
                    $oldKey = ($type === 'variable') ? 'variableId' : (($type === 'trigger') ? 'triggerId' : 'tagId');
                    $old    = isset( $item[ $oldKey ] ) ? (string) $item[ $oldKey ] : null;
                    $new    = $alloc();
                    if ( $old ) {
                        $idmap[ $old ] = $new;
                    }
                    $item[ $oldKey ] = $new;

                    // Uppercase trigger enums to match GTM schema
                    if ( $type === 'trigger' && isset( $item['type'] ) && is_string( $item['type'] ) ) {
                        $item['type'] = self::upper_enum( $item['type'] );
                    }
                }
                unset( $item );
            }

            // Rewrite tag trigger references using the new ids
            if ( ! empty( $cv['tag'] ) ) {
                foreach ( $cv['tag'] as &$tag ) {
                    foreach ( ['firingTriggerId', 'blockingTriggerId'] as $rk ) {
                        if ( empty( $tag[ $rk ] ) ) continue;

                        if ( is_array( $tag[ $rk ] ) ) {
                            foreach ( $tag[ $rk ] as $i => $rid ) {
                                $rid = (string) $rid;
                                if ( isset( $idmap[ $rid ] ) ) {
                                    $tag[ $rk ][ $i ] = $idmap[ $rid ];
                                }
                            }
                        } else {
                            $rid = (string) $tag[ $rk ];
                            if ( isset( $idmap[ $rid ] ) ) {
                                $tag[ $rk ] = $idmap[ $rid ];
                            }
                        }
                    }
                }
                unset( $tag );
            }

            // Normalise parameter type enums to UPPERCASE - GTM import rejects lowercase.
            self::uppercase_param_types( $cv );

            // Replace {{PLACEHOLDERS}} throughout
            Helpers::deep_replace( $cv, $vars );

            // Merge module parts
            foreach ( ['variable', 'trigger', 'tag', 'builtInVariable'] as $t ) {
                if ( ! empty( $cv[ $t ] ) && is_array( $cv[ $t ] ) ) {
                    $result['containerVersion'][ $t ] = array_merge(
                        $result['containerVersion'][ $t ],
                        $cv[ $t ]
                    );
                }
            }
        }

        // Deduplicate triggers/variables merged from multiple modules
        self::dedup_merged( $result['containerVersion'] );

        self::normalise_for_gtm( $result['containerVersion'] );

        $export = [
            'exportFormatVersion' => 2,
            'exportTime'          => gmdate( 'Y-m-d H:i:s' ),
            'containerVersion'    => $result['containerVersion'],
        ];
        self::add_tagforge_meta( $export );
        return $export;
    }

    /**
     * Normalize trigger type casing to GTM's accepted enum values.
     */
    private static function upper_enum( string $t ) : string {
        $map = array(
            // Lowercase / mixed variants
            'pageview'          => 'PAGEVIEW',
            'click'             => 'CLICK',
            'link_click'        => 'LINK_CLICK',
            'form_submit'       => 'FORM_SUBMIT',
            'history_change'    => 'HISTORY_CHANGE',
            'javascript_error'  => 'JAVASCRIPT_ERROR',
            'scrolldepth'       => 'SCROLL_DEPTH',   // no separator
            'scroll_depth'      => 'SCROLL_DEPTH',   // FIX: underscore variant was missing
            'scrollDepth'       => 'SCROLL_DEPTH',   // camelCase
            'timer'             => 'TIMER',
            'customevent'       => 'CUSTOM_EVENT',
            'custom'            => 'CUSTOM_EVENT',
            'custom_event'      => 'CUSTOM_EVENT',
            'windowloaded'      => 'WINDOW_LOADED',
            'window_loaded'     => 'WINDOW_LOADED',
            'domready'          => 'DOM_READY',
            'dom_ready'         => 'DOM_READY',

            // Already-correct uppercase values (pass-through)
            'PAGEVIEW'          => 'PAGEVIEW',
            'CLICK'             => 'CLICK',
            'LINK_CLICK'        => 'LINK_CLICK',
            'FORM_SUBMIT'       => 'FORM_SUBMIT',
            'HISTORY_CHANGE'    => 'HISTORY_CHANGE',
            'JAVASCRIPT_ERROR'  => 'JAVASCRIPT_ERROR',
            'SCROLL_DEPTH'      => 'SCROLL_DEPTH',
            'TIMER'             => 'TIMER',
            'CUSTOM_EVENT'      => 'CUSTOM_EVENT',
            'WINDOW_LOADED'     => 'WINDOW_LOADED',
            'DOM_READY'         => 'DOM_READY',
        );
        // Try exact match first, then lowercase fallback
        if ( isset( $map[ $t ] ) ) return $map[ $t ];
        $lower = strtolower( $t );
        return isset( $map[ $lower ] ) ? $map[ $lower ] : $t;
    }

    /**
     * Resolve a module slug to an absolute file path.
     */
    private static function resolve_path( string $slug ) : ?string {
        $map = self::get_module_map();
        if ( isset( $map[ $slug ] ) ) {
            return trailingslashit( TAGFORGE_DIR ) . ltrim( $map[ $slug ], '/' );
        }
        // FIX: was using + (addition) instead of . (concatenation) in old code.
        // The active path below is correct - using string concatenation throughout.
        $candidate = trailingslashit( TAGFORGE_DIR ) . 'modules/' . $slug . '.json';
        return file_exists( $candidate ) ? $candidate : null;
    }

    /**
     * Recursively uppercase parameter type enum values throughout a container fragment.
     * GTM import requires TEMPLATE, BOOLEAN, LIST, MAP etc. - lowercase causes
     * "Error deserializing enum type [Type]" on import.
     */
    private static function uppercase_param_types( &$node ) : void {
        if ( is_array( $node ) ) {
            // If this looks like a parameter object (has 'type' and 'key'), uppercase the type.
            // Guard: do NOT uppercase trigger 'type' - that is handled by upper_enum() separately.
            // Trigger objects have 'triggerId'; parameter objects have 'key'.
            if ( isset( $node['type'], $node['key'] ) && ! isset( $node['triggerId'] ) ) {
                $map = array(
                    // Parameter types
                    'template'          => 'TEMPLATE',
                    'boolean'           => 'BOOLEAN',
                    'integer'           => 'INTEGER',
                    'list'              => 'LIST',
                    'map'               => 'MAP',
                    'tag_reference'     => 'TAG_REFERENCE',
                    'trigger_reference' => 'TRIGGER_REFERENCE',
                    // Condition/filter types
                    'contains'          => 'CONTAINS',
                    'equals'            => 'EQUALS',
                    'starts_with'       => 'STARTS_WITH',
                    'ends_with'         => 'ENDS_WITH',
                    'less_than'         => 'LESS_THAN',
                    'greater_than'      => 'GREATER_THAN',
                    'match_regex'       => 'MATCH_REGEX',
                    'css_selector'      => 'CSS_SELECTOR',
                );
                $lo = strtolower( (string) $node['type'] );
                if ( isset( $map[ $lo ] ) ) {
                    $node['type'] = $map[ $lo ];
                }
            }
            foreach ( $node as &$child ) {
                self::uppercase_param_types( $child );
            }
            unset( $child );
        }
    }



    /**
     * Normalise assembled container to match GTM import format exactly.
     * Based on real GTM export structure (Xava Live container, April 2026).
     */
    private static function normalise_for_gtm( array &$cv ) : void {
        $account_id   = '0';
        $container_id = '0';
        $fingerprint  = (string) round( microtime( true ) * 1000 );
        $now          = gmdate( 'Y-m-d H:i:s' );

        // containerVersion metadata
        $cv['path']               = "accounts/{$account_id}/containers/{$container_id}/versions/0";
        $cv['accountId']          = $account_id;
        $cv['containerId']        = $container_id;
        $cv['containerVersionId'] = '0';
        $cv['fingerprint']        = $fingerprint;
        $cv['tagManagerUrl']      = "https://tagmanager.google.com/#/versions/accounts/{$account_id}/containers/{$container_id}/versions/0?apiLink=version";

        // container object
        $cv['container'] = array(
            'path'          => "accounts/{$account_id}/containers/{$container_id}",
            'accountId'     => $account_id,
            'containerId'   => $container_id,
            'name'          => 'TagForge Container',
            'publicId'      => 'GTM-TAGFORGE',
            'usageContext'  => array( 'WEB' ),
            'fingerprint'   => $fingerprint,
            'tagManagerUrl' => "https://tagmanager.google.com/#/container/accounts/{$account_id}/containers/{$container_id}/workspaces?apiLink=container",
            'features'      => array(
                'supportUserPermissions'  => true, 'supportEnvironments'    => true,
                'supportWorkspaces'       => true, 'supportGtagConfigs'     => false,
                'supportBuiltInVariables' => true, 'supportClients'         => false,
                'supportFolders'          => true, 'supportTags'            => true,
                'supportTemplates'        => true, 'supportTriggers'        => true,
                'supportVariables'        => true, 'supportVersions'        => true,
                'supportZones'            => true, 'supportTransformations' => false,
            ),
            'tagIds' => array( 'GTM-TAGFORGE' ),
        );

        // Tags
        foreach ( $cv['tag'] as &$tag ) {
            $tag['accountId']       = $account_id;
            $tag['containerId']     = $container_id;
            $tag['fingerprint']     = $fingerprint;
            $tag['tagFiringOption'] = 'ONCE_PER_EVENT';
            if ( ! isset( $tag['monitoringMetadata'] ) ) {
                $tag['monitoringMetadata'] = array( 'type' => 'MAP' );
            }
            $tag['consentSettings'] = array( 'consentStatus' => 'NOT_SET' );

            // GA4 event tags (gaawe) require a non-empty measurementIdOverride
            if ( $tag['type'] === 'gaawe' ) {
                $has_override = false;
                foreach ( $tag['parameter'] as $p ) {
                    if ( isset( $p['key'] ) && $p['key'] === 'measurementIdOverride' && ! empty( $p['value'] ) ) {
                        $has_override = true;
                        break;
                    }
                }
                if ( ! $has_override ) {
                    array_unshift( $tag['parameter'], array(
                        'type'  => 'TEMPLATE',
                        'key'   => 'measurementIdOverride',
                        'value' => '{{GA4_MEASUREMENT_ID}}',
                    ) );
                }
            }
        }
        unset( $tag );

        // Apply per-tag consent types based on tag name/type
        self::apply_consent_types( $cv );

        // Triggers
        foreach ( $cv['trigger'] as &$trigger ) {
            // Fix numeric-value parameters that have wrong BOOLEAN type
            if ( isset( $trigger['parameter'] ) ) {
                $integer_keys = array( 'waitForTagsTimeout' );
                foreach ( $trigger['parameter'] as &$param ) {
                    if ( in_array( $param['key'] ?? '', $integer_keys, true ) ) {
                        $param['type'] = 'INTEGER';
                    }
                }
                unset( $param );
            }
            $trigger['accountId']   = $account_id;
            $trigger['containerId'] = $container_id;
            $trigger['fingerprint'] = $fingerprint;

            // CUSTOM_EVENT triggers use customEventFilter, not parameter
            if ( $trigger['type'] === 'CUSTOM_EVENT' && isset( $trigger['parameter'] ) ) {
                $event_name = '';
                foreach ( $trigger['parameter'] as $p ) {
                    if ( isset( $p['key'] ) && $p['key'] === 'eventName' ) {
                        $event_name = $p['value'];
                        break;
                    }
                }
                if ( $event_name !== '' ) {
                    $trigger['customEventFilter'] = array(
                        array(
                            'type'      => 'EQUALS',
                            'parameter' => array(
                                array( 'type' => 'TEMPLATE', 'key' => 'arg0', 'value' => '{{_event}}' ),
                                array( 'type' => 'TEMPLATE', 'key' => 'arg1', 'value' => $event_name ),
                            ),
                        ),
                    );
                }
                unset( $trigger['parameter'] );
            }
            if ( ! isset( $trigger['filter'] ) ) {
                $trigger['filter'] = array();
            }
        }
        unset( $trigger );

        // builtInVariables - add accountId, containerId, name
        $biv_names = array(
            'VIDEO_TITLE'    => 'Video Title',   'VIDEO_STATUS'  => 'Video Status',
            'VIDEO_PERCENT'  => 'Video Percent', 'VIDEO_PROVIDER'=> 'Video Provider',
            'VIDEO_URL'      => 'Video URL',     'VIDEO_DURATION'=> 'Video Duration',
            'VIDEO_CURRENT_TIME' => 'Video Current Time',
            'CLICK_URL'      => 'Click URL',     'CLICK_TEXT'    => 'Click Text',
            'CLICK_ELEMENT'  => 'Click Element', 'CLICK_CLASSES' => 'Click Classes',
            'CLICK_ID'       => 'Click ID',      'CLICK_TARGET'  => 'Click Target',
            'FORM_ELEMENT'   => 'Form Element',  'FORM_CLASSES'  => 'Form Classes',
            'FORM_ID'        => 'Form ID',       'FORM_TARGET'   => 'Form Target',
            'FORM_URL'       => 'Form URL',      'FORM_TEXT'     => 'Form Text',
            'PAGE_URL'       => 'Page URL',      'PAGE_PATH'     => 'Page Path',
            'PAGE_HOSTNAME'  => 'Page Hostname', 'REFERRER'      => 'Referrer',
            'EVENT'          => 'Event',
            'SCROLL_DEPTH_THRESHOLD'  => 'Scroll Depth Threshold',
            'SCROLL_DEPTH_UNITS'      => 'Scroll Depth Units',
            'SCROLL_DEPTH_DIRECTION'  => 'Scroll Direction',
        );
        foreach ( $cv['builtInVariable'] as &$biv ) {
            $biv['accountId']   = $account_id;
            $biv['containerId'] = $container_id;
            if ( ! isset( $biv['name'] ) ) {
                $t = isset( $biv['type'] ) ? $biv['type'] : '';
                $biv['name'] = isset( $biv_names[ $t ] ) ? $biv_names[ $t ] : ucwords( strtolower( str_replace( '_', ' ', $t ) ) );
            }
        }
        unset( $biv );

        // Variables
        foreach ( $cv['variable'] as &$var ) {

            $var['accountId']   = $account_id;
            $var['containerId'] = $container_id;
            $var['fingerprint'] = $fingerprint;
            if ( ! isset( $var['formatValue'] ) || ( is_array( $var['formatValue'] ) && empty( $var['formatValue'] ) ) ) {
                $var['formatValue'] = new \stdClass();
            }
        }
        unset( $var );
    }


    /**
     * Deduplicate triggers and variables by name after merging all modules.
     * Multiple modules may define the same trigger (e.g. "All Pages (gtm.js)").
     * Keep the first occurrence, remap all tag firingTriggerId references to it.
     */
    private static function dedup_merged( array &$cv ) : void {
        // Deduplicate tags by name — ecom-base and ecom-advanced share tag names
        if ( ! empty( $cv['tag'] ) ) {
            $seen_tags = array();
            $cv['tag'] = array_filter( $cv['tag'], function( $tag ) use ( &$seen_tags ) {
                $name = $tag['name'] ?? '';
                if ( isset( $seen_tags[ $name ] ) ) return false;
                $seen_tags[ $name ] = true;
                return true;
            } );
            $cv['tag'] = array_values( $cv['tag'] );
        }

        // ── Deduplicate triggers ──────────────────────────────────────────
        $seen_triggers = array();  // name → triggerId of first occurrence
        $remap         = array();  // old triggerId → canonical triggerId
        $unique        = array();

        foreach ( $cv['trigger'] as $trigger ) {
            $name = $trigger['name'];
            if ( ! isset( $seen_triggers[ $name ] ) ) {
                $seen_triggers[ $name ] = $trigger['triggerId'];
                $unique[] = $trigger;
            } else {
                // Map this duplicate's ID to the canonical one
                $remap[ $trigger['triggerId'] ] = $seen_triggers[ $name ];
            }
        }
        $cv['trigger'] = $unique;

        // Remap tag firingTriggerId references
        if ( ! empty( $remap ) ) {
            foreach ( $cv['tag'] as &$tag ) {
                foreach ( array( 'firingTriggerId', 'blockingTriggerId' ) as $key ) {
                    if ( empty( $tag[ $key ] ) ) continue;
                    foreach ( $tag[ $key ] as &$tid ) {
                        if ( isset( $remap[ $tid ] ) ) {
                            $tid = $remap[ $tid ];
                        }
                    }
                    unset( $tid );
                }
            }
            unset( $tag );
        }

        // ── Deduplicate variables by name ─────────────────────────────────
        $seen_vars  = array();
        $unique_vars = array();
        foreach ( $cv['variable'] as $var ) {
            $name = $var['name'];
            if ( ! isset( $seen_vars[ $name ] ) ) {
                $seen_vars[ $name ] = true;
                $unique_vars[] = $var;
            }
        }
        $cv['variable'] = $unique_vars;

        // ── Deduplicate builtInVariables by type ──────────────────────────
        $seen_biv  = array();
        $unique_biv = array();
        foreach ( $cv['builtInVariable'] as $biv ) {
            $type = $biv['type'];
            if ( ! isset( $seen_biv[ $type ] ) ) {
                $seen_biv[ $type ] = true;
                $unique_biv[] = $biv;
            }
        }
        $cv['builtInVariable'] = $unique_biv;
    }


    /**
     * Apply per-tag consent settings based on tag type and name.
     * Replaces the generic NOT_SET applied by normalise_for_gtm().
     *
     * Consent type reference:
     *   analytics_storage  - GA4, session recording (Hotjar, Clarity)
     *   ad_storage         - Ad pixels (Meta, Google Ads, LinkedIn, TikTok etc.)
     *   ad_user_data       - Required alongside ad_storage for user data processing
     *
     * Tags that must fire regardless of consent (set to NOT_SET):
     *   - Consent Mode v2 default tag (sets the defaults)
     *   - Complianz / CMP integration tags (update consent signals)
     *   - GA4 Configuration + event tags (managed by Consent Mode via config tag)
     */
    private static function apply_consent_types( array &$cv ) : void {
        // GTM consentType must be a LIST parameter object, not a plain array.
        // Format confirmed from real GTM export (Jam Art container, April 2026).
        // Updated April 2026: GA4 event tags now require analytics_storage.
        // GA4 Configuration tag (googtag) stays NOT_SET — Consent Mode architecture
        // means the config tag fires on all pages for cookieless modelling pings,
        // but event tags (gaawe) require analytics_storage consent before firing.
        $ad_consent        = array(
            'consentStatus' => 'NEEDED',
            'consentType'   => array(
                'type' => 'LIST',
                'list' => array(
                    array( 'type' => 'TEMPLATE', 'value' => 'ad_storage' ),
                    array( 'type' => 'TEMPLATE', 'value' => 'ad_user_data' ),
                ),
            ),
        );
        $analytics_consent = array(
            'consentStatus' => 'NEEDED',
            'consentType'   => array(
                'type' => 'LIST',
                'list' => array(
                    array( 'type' => 'TEMPLATE', 'value' => 'analytics_storage' ),
                ),
            ),
        );
        $no_consent = array( 'consentStatus' => 'NOT_SET' );

        // Tags that must fire before consent decision — always NOT_SET
        $exempt_keywords = array( 'consent mode', 'complianz', 'cmp', 'consent init', 'consent default' );

        // Ad pixel keywords — require ad_storage + ad_user_data
        $ad_keywords = array( 'meta pixel', 'meta - event', 'facebook', 'linkedin', 'tiktok', 'pinterest', 'bing', 'uet', 'google ads - conversion', 'google ads - remarketing' );

        // Analytics / session recording keywords — require analytics_storage
        $analytics_keywords = array( 'hotjar', 'clarity', 'ga4 - event', 'ga4 - config' );

        // GA4 engagement tags by name prefix
        $ga4_event_prefixes = array( 'ga4 - event -', 'ga4 - event-' );

        foreach ( $cv['tag'] as &$tag ) {
            $name = strtolower( $tag['name'] );
            $type = $tag['type'] ?? '';

            // Always exempt — must fire before consent decision
            foreach ( $exempt_keywords as $kw ) {
                if ( strpos( $name, $kw ) !== false ) {
                    $tag['consentSettings'] = $no_consent;
                    continue 2;
                }
            }

            // GA4 Configuration tag (googtag) — NOT_SET
            // Fires on all pages for Consent Mode cookieless modelling
            if ( $type === 'googtag' ) {
                $tag['consentSettings'] = $no_consent;
                continue;
            }

            // GA4 event tags (gaawe) — analytics_storage
            if ( $type === 'gaawe' ) {
                $tag['consentSettings'] = $analytics_consent;
                continue;
            }

            // Ad pixels — ad_storage + ad_user_data
            foreach ( $ad_keywords as $kw ) {
                if ( strpos( $name, $kw ) !== false ) {
                    $tag['consentSettings'] = $ad_consent;
                    continue 2;
                }
            }

            // Session recording / analytics tools — analytics_storage
            foreach ( $analytics_keywords as $kw ) {
                if ( strpos( $name, $kw ) !== false ) {
                    $tag['consentSettings'] = $analytics_consent;
                    continue 2;
                }
            }

            // Default — NOT_SET for anything unrecognised
            // This covers html tags that aren't pixels (e.g. custom scripts)
            $tag['consentSettings'] = $no_consent;
        }
        unset( $tag );
    }


    /**
     * Add TagForge metadata block to the assembled container root.
     * GTM ignores unknown root keys - this is human-readable documentation
     * embedded in the JSON for the customer's reference.
     */
    private static function add_tagforge_meta( array &$export ) : void {
        $export['_tagforge_meta'] = array(
            'generated_by'            => 'TagForge.io - tagforge.io',
            'version'                 => TAGFORGE_VERSION,
            'licence'                 => 'Single site licence. Not for resale or redistribution. tagforge.io',
            'consent_note'            => 'Consent Mode v2 is configured in this container. Tags requiring user consent (ad pixels, session recording) are set to consentStatus=NEEDED. GA4 tags use NOT_SET - managed automatically by the GA4 Configuration tag via Consent Mode. IMPORTANT: This container requires a CMP to send consent signals. Without a CMP, ad pixel tags will NOT fire. The consent-mode-v2 module sets default DENY. Pair with complianz-cmp or configure your CMP to push gtag consent update calls.',
            'custom_implementation'   => 'Meta event tags read from the GA4 ecommerce dataLayer format (ecommerce.items, ecommerce.value). If your site uses Meta native dataLayer format, contact TagForge for a custom implementation - tagforge.io.',
        );
    }

}
