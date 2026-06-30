/**
 * TagForge Build Page — Left Column State Manager
 * tagforge-buildpage.js · v5.2.0
 *
 * Manages the left column of the [tagforge_builder] shortcode:
 * - Activates the WooCommerce form after Claude returns a recommendation
 * - Reveals only relevant Product Add-on fields for the selected modules
 * - Populates fields with collected IDs before Add to Cart
 * - Handles mobile scroll to form
 *
 * Depends on: jQuery, tagforge-builder.js (TF_Builder global)
 * Enqueued by: AI_Builder::shortcode_builder()
 */

( function( $ ) {
    'use strict';

    $( document ).ready( function() {

        window.TF_BuildPage = {

            /**
             * Called by tagforge-builder.js when Claude returns a recommendation.
             * Animates the form into view, updates product name and price,
             * reveals only relevant Add-on fields, adds highlight border.
             *
             * @param {string} customName  Claude-generated container name
             * @param {Array}  modules     Array of module slugs
             * @param {number} price       Calculated tier price
             */
            onRecommendation: function( customName, modules, price ) {

                // 1 — Update meta area
                $( '#tf-build-title' ).text( customName || 'Your Custom Container' );
                $( '.tf-build-price-val' ).text( '\u20ac' + price );
                $( '.tf-build-price-from, .tf-build-price-note' ).fadeOut( 200 );

                // 2 — Fade out invite message
                $( '#tf-build-invite' ).fadeOut( 300 );

                // 3 — Populate form header
                $( '#tf-build-form-name' ).text( customName || 'Your Custom Container' );
                $( '#tf-build-form-price' ).text( '\u20ac' + price );

                var $chips = $( '#tf-build-form-modules' ).empty();
                $.each( modules, function( i, slug ) {
                    $chips.append(
                        $( '<span>' ).addClass( 'tf-build-module-chip' ).text( slug )
                    );
                } );

                // 4 — Show only relevant Add-on fields
                TF_BuildPage.revealAddonFields( modules );

                // 5 — Animate form wrap into view
                $( '#tf-build-form-wrap' ).addClass( 'tf-build-form-wrap--active' );

                // 6 — Add highlight border to left column
                $( '#tf-build-left' ).addClass( 'tf-build-left--active' );

                // 7 — On mobile: smooth scroll to form after short delay
                if ( window.innerWidth < 768 ) {
                    setTimeout( function() {
                        $( 'html, body' ).animate( {
                            scrollTop: $( '#tf-build-left' ).offset().top - 20
                        }, 600 );
                    }, 500 );
                }
            },

            /**
             * Show only the Add-on fields relevant to the selected modules.
             * All others stay hidden. Uses data-addon-name to match.
             *
             * @param {Array} modules  Array of module slugs
             */
            revealAddonFields: function( modules ) {

                var moduleAddonMap = {
                    'gtag-basic':             [ 'GA4 ID' ],
                    'ecom-base':              [ 'GA4 ID' ],
                    'ecom-advanced':          [ 'GA4 ID' ],
                    'facebook-pixel':         [ 'Meta Pixel ID' ],
                    'facebook-events':        [ 'Meta Pixel ID' ],
                    'google-ads-conversion':  [ 'Google Ads Conversion ID', 'Google Ads Conversion Label' ],
                    'google-ads-remarketing': [ 'Google Ads Conversion ID' ],
                    'linkedin-insight':       [ 'LinkedIn Partner ID' ],
                    'tiktok-pixel':           [ 'TikTok Pixel ID' ],
                    'microsoft-clarity':      [ 'Microsoft Clarity Project ID' ],
                    'hotjar':                 [ 'Hotjar Site ID' ],
                    'bing-uet':               [ 'Bing UET Tag ID' ],
                    'pinterest-tag':          [ 'Pinterest Tag ID' ],
                    'cookiebot-cmp':          [ 'Cookiebot Domain ID' ],
                };

                // Collect needed addon names from selected modules
                var needed = {};
                $.each( modules, function( i, slug ) {
                    var addonNames = moduleAddonMap[ slug ] || [];
                    $.each( addonNames, function( j, name ) {
                        needed[ name ] = true;
                    } );
                } );

                // Hide all addon containers first
                $( '.wc-pao-addon-container' ).hide();

                // Show only needed ones
                $.each( needed, function( addonName ) {
                    $( '[data-addon-name="' + addonName + '"]' )
                        .closest( '.wc-pao-addon-container' )
                        .show()
                        .addClass( 'tf-addon-revealed' );
                } );
            },

            /**
             * Scroll to and pulse the first visible addon field.
             * Called by tagforge-builder.js when customer clicks Get full container.
             */
            focusAddonFields: function() {
                var $first = $( '.tf-addon-revealed' ).first();
                if ( ! $first.length ) return;

                $( 'html, body' ).animate( {
                    scrollTop: $( '#tf-build-left' ).offset().top - 20
                }, 500, function() {
                    $first.addClass( 'tf-addon-pulse' );
                    setTimeout( function() {
                        $first.removeClass( 'tf-addon-pulse' );
                    }, 1200 );
                } );
            },

            /**
             * Populate addon fields with IDs collected by the chat.
             * Called by tagforge-builder.js before form submission.
             *
             * @param {Object} collectedIds  Map of PLACEHOLDER_KEY => value
             */
            populateAddonFields: function( collectedIds ) {
                var idToAddon = {
                    'GA4_MEASUREMENT_ID':    'GA4 ID',
                    'PIXEL_ID':              'Meta Pixel ID',
                    'GADS_CONVERSION_ID':    'Google Ads Conversion ID',
                    'GADS_CONVERSION_LABEL': 'Google Ads Conversion Label',
                    'LI_PARTNER_ID':         'LinkedIn Partner ID',
                    'TIKTOK_PIXEL_ID':       'TikTok Pixel ID',
                    'CLARITY_PROJECT_ID':    'Microsoft Clarity Project ID',
                    'HOTJAR_SITE_ID':        'Hotjar Site ID',
                    'BING_UET_TAG_ID':       'Bing UET Tag ID',
                    'PINTEREST_TAG_ID':      'Pinterest Tag ID',
                    'COOKIEBOT_DOMAIN_ID':   'Cookiebot Domain ID',
                };

                $.each( collectedIds, function( key, val ) {
                    var addonName = idToAddon[ key ];
                    if ( ! addonName ) return;
                    $( '[data-addon-name="' + addonName + '"]' )
                        .closest( '.wc-pao-addon-container' )
                        .find( 'input[type="text"], input[type="number"]' )
                        .val( val );
                } );
            },

            /**
             * Submit the WooCommerce cart form programmatically.
             * Called by tagforge-builder.js after IDs are populated.
             *
             * @param {string} sessionId  Builder session ID
             * @param {string} customName Claude-generated container name
             */
            submitForm: function( sessionId, customName ) {
                $( '#tf_session_id' ).val( sessionId );
                $( '#tf_custom_name' ).val( customName );
                $( 'form.cart' ).submit();
            },

        }; // TF_BuildPage

    } ); // ready

} )( jQuery );
