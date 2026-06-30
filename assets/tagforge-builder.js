/**
 * TagForge AI Builder — Frontend JS
 * tagforge-builder.js · v4.0.0
 *
 * Handles:
 * - Homepage widget (mode: 'widget') — Q1 answer, redirect to /build
 * - Full builder (mode: 'full') — conversation, recommendation, email gate,
 *   partial preview, ID collection, WooCommerce form population, Add to Cart
 */

( function( $ ) {
    'use strict';

    if ( typeof TF_Builder === 'undefined' ) return;

    const REST   = TF_Builder.rest_url;
    const NONCE  = TF_Builder.nonce;
    const MODE   = TF_Builder.mode;

    // ── Widget mode ────────────────────────────────────────────────────

    if ( MODE === 'widget' ) {
        $( '.tf-builder-opt' ).on( 'click', function() {
            var $btn     = $( this );
            var siteType = $btn.data( 'value' );

            $( '.tf-builder-opt' ).prop( 'disabled', true ).addClass( 'tf-builder-opt--loading' );
            $btn.addClass( 'tf-builder-opt--selected' );

            // Redirect to /build with site_type param
            window.location.href = TF_Builder.build_url + '?site_type=' + encodeURIComponent( siteType );
        } );
        return;
    }

    // ── Full builder mode ──────────────────────────────────────────────

    var state = {
        session_id:     null,
        phase:          'qualify',    // qualify | refine | email_gate | collect_ids | checkout
        history:        [],           // [{role, content}]
        modules:        [],
        custom_name:    '',
        price:          0,
        email:          '',
        collected_ids:  {},
        refs_used:      0,
        refs_remaining: TF_Builder.ref_limit || 2,
        available_cmps: [],           // loaded from REST /builder/cmps at init
    };

    // ── Init ───────────────────────────────────────────────────────────

    function init() {
        var siteType = getUrlParam( 'site_type' );

        // Initialise session
        apiPost( 'session', { site_type: siteType } )
            .done( function( res ) {
                state.session_id = res.session_id;

                if ( siteType ) {
                    // Q1 already answered on homepage — start from Q2
                    var greeting = siteTypeGreeting( siteType );
                    appendMessage( 'ai', greeting );
                    // Send Q1 answer to Claude to get Q2
                    sendToBuilder( greeting, true );
                } else {
                    // No Q1 — ask it here
                    appendMessage( 'ai', "Let's build your container. What best describes your site?", [
                        { label: 'Shopify — Ecommerce', value: 'shopify-ecommerce', multi: false },
                        { label: 'WordPress — Ecommerce', value: 'wordpress-ecommerce', multi: false },
                        { label: 'WordPress — Lead gen & B2B', value: 'wordpress-lead-gen', multi: false },
                        { label: 'WordPress — Content & blog', value: 'wordpress-content', multi: false },
                        { label: 'Not sure yet', value: 'unsure', multi: false },
                    ] );
                }
            } )
            .fail( function() {
                appendMessage( 'ai', "Sorry, something went wrong starting your session. Please refresh and try again." );
            } );

        // Load available CMP modules dynamically from plugin
        $.ajax( {
            url:     REST + 'cmps',
            method:  'GET',
            headers: { 'X-WP-Nonce': NONCE },
        } ).done( function( res ) {
            if ( res && res.cmps ) {
                state.available_cmps = res.cmps;
            }
        } );

        // Input send
        $( '#tf-builder-send' ).on( 'click', handleSend );
        $( '#tf-builder-input' ).on( 'keypress', function( e ) {
            if ( e.which === 13 ) handleSend();
        } );
    }

    // ── Send handler ───────────────────────────────────────────────────

    function handleSend() {
        var text = $( '#tf-builder-input' ).val().trim();
        if ( ! text || state.phase === 'checkout' ) return;
        $( '#tf-builder-input' ).val( '' );
        appendMessage( 'user', text );
        sendToBuilder( text );
    }

    // ── Send message to Claude proxy ───────────────────────────────────

    function sendToBuilder( userMsg, isInit ) {
        if ( ! state.session_id ) return;

        setInputLoading( true );
        showTyping();

        // Add to history if not an init auto-message
        if ( ! isInit ) {
            state.history.push( { role: 'user', content: userMsg } );
        }

        // Route to conversational or guided phase based on chat_style
        var chatStyle = TF_Builder.chat_style || 'guided';
        var phase = state.phase;
        if ( phase === 'email_gate' ) phase = 'qualify';
        if ( phase === 'qualify' && chatStyle === 'conversational' ) phase = 'qualify_conversational';

        apiPost( 'chat', {
            session_id: state.session_id,
            message:    userMsg,
            history:    state.history,
            phase:      phase,
        } )
        .done( function( res ) {
            hideTyping();
            setInputLoading( false );

            // Add AI reply to history
            if ( res.reply ) {
                state.history.push( { role: 'assistant', content: res.reply } );
                // Parse OPTIONS: line from Claude response into clickable buttons
                var parsedOpts = parseOptions( res.reply );
                appendMessage( 'ai', res.reply, parsedOpts.length ? parsedOpts : undefined );
            }

            // Handle recommendation received
            if ( res.modules && res.modules.length ) {
                state.modules     = res.modules;
                state.custom_name = res.custom_name || '';
                state.price       = res.price || 0;
                state.refs_used   = res.refinements_used || 0;
                state.refs_remaining = res.refinements_remaining !== undefined ? res.refinements_remaining : 2;

                showRecommendation();
                state.phase = 'email_gate';
            }

            // Handle IDs collected
            if ( res.collected_ids ) {
                state.collected_ids = Object.assign( state.collected_ids, res.collected_ids );
                state.phase = 'checkout';
                proceedToCheckout();
            }

            // Phase transition
            if ( res.phase ) {
                if ( res.phase === 'email_gate' && state.phase !== 'email_gate' ) {
                    state.phase = 'email_gate';
                }
                if ( res.phase === 'checkout' ) {
                    state.phase = 'checkout';
                }
            }
        } )
        .fail( function( xhr ) {
            hideTyping();
            setInputLoading( false );
            var msg = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : "Something went wrong. Please try again.";
            appendMessage( 'ai', msg );
        } );
    }

    // ── Show recommendation panel ──────────────────────────────────────

    function showRecommendation() {
        $( '#tf-builder-rec-name' ).text( state.custom_name || 'Your custom container' );
        $( '#tf-builder-rec-price' ).text( '€' + state.price );

        // Module chips
        var $chips = $( '#tf-builder-rec-modules' ).empty();
        $.each( state.modules, function( i, slug ) {
            $chips.append( $( '<span>' ).addClass( 'tf-module-chip' ).text( slug ) );
        } );

        // Refinement count
        var refText = state.refs_remaining > 0
            ? state.refs_remaining + ' refinement' + ( state.refs_remaining !== 1 ? 's' : '' ) + ' remaining'
            : 'No refinements remaining';
        $( '#tf-builder-refine-count' ).text( refText );

        if ( state.refs_remaining <= 0 ) {
            $( '#tf-builder-refine' ).prop( 'disabled', true );
        }

        $( '#tf-builder-recommendation' ).fadeIn( 300 );

        // ── Trigger left-column activation if on builder product page ──
        if ( window.TF_BuildPage ) {
            TF_BuildPage.onRecommendation( state.custom_name, state.modules, state.price );
        }

        // Recommendation actions
        //$( '#tf-builder-get-container' ).off( 'click' ).on( 'click', showEmailGate );
        $( '#tf-builder-get-container' ).off( 'click' ).on( 'click', showPreviewOverlay.bind( null, {} ) );
        $( '#tf-builder-refine' ).off( 'click' ).on( 'click', handleRefine );
    }

    // ── Refine handler ─────────────────────────────────────────────────

    function handleRefine() {
        if ( state.refs_remaining <= 0 ) return;
        state.phase = 'refine';
        $( '#tf-builder-recommendation' ).fadeOut( 200 );
        appendMessage( 'ai', "Of course — what would you like to change? You can ask me to add or remove any module." );
        $( '#tf-builder-input' ).focus();
    }

    // ── Email gate ─────────────────────────────────────────────────────

    function showEmailGate() {
        $( '#tf-builder-email-gate' ).fadeIn( 200 );
        $( '#tf-builder-email-input' ).focus();

        $( '#tf-builder-submit-email' ).off( 'click' ).on( 'click', function() {
            var email = $( '#tf-builder-email-input' ).val().trim();
            if ( ! isValidEmail( email ) ) {
                $( '#tf-builder-email-input' ).addClass( 'tf-input-error' );
                return;
            }
            $( '#tf-builder-email-input' ).removeClass( 'tf-input-error' );
            $( '#tf-builder-submit-email' ).prop( 'disabled', true ).text( 'Loading preview…' );

            state.email = email;
            captureEmail( email );
        } );
    }

    function captureEmail( email ) {
        apiPost( 'email', {
            session_id: state.session_id,
            email:      email,
        } )
        .done( function( res ) {
            $( '#tf-builder-email-gate' ).fadeOut( 200 );
            showPreviewOverlay( res );
        } )
        .fail( function() {
            $( '#tf-builder-submit-email' ).prop( 'disabled', false ).text( 'Show my preview →' );
            $( '#tf-builder-email-input' ).addClass( 'tf-input-error' );
        } );
    }

    // ── Partial JSON preview overlay ───────────────────────────────────

    function showPreviewOverlay( data ) {
        var $tags = $( '#tf-builder-preview-tags' ).empty();

        if ( data.preview_tags && data.preview_tags.length ) {
            $.each( data.preview_tags, function( i, tag ) {
                $tags.append(
                    $( '<span>' ).addClass( 'tf-preview-tag-line' ).html(
                        '<span class="tf-preview-tag-name">' + escHtml( tag.name ) + '</span>' +
                        '<span class="tf-preview-tag-type">· ' + escHtml( tag.type ) + '</span>'
                    )
                );
            } );
        } else {
            $tags.html( '<span style="color:#6b7280">Preview not available</span>' );
        }

        var hidden = data.hidden_count || 0;
        if ( hidden > 0 ) {
            $( '#tf-builder-preview-more' ).text( '+ ' + hidden + ' more tag' + ( hidden !== 1 ? 's' : '' ) + ' in your full container' );
        }

        $( '#tf-builder-checkout-price' ).text( '— €' + state.price );
        $( '#tf-builder-preview-overlay' ).fadeIn( 200 );

        $( '#tf-builder-preview-close' ).off( 'click' ).on( 'click', function() {
            $( '#tf-builder-preview-overlay' ).fadeOut( 200 );
        } );
        // Close on ESC key
$( document ).off( 'keydown.preview' ).on( 'keydown.preview', function( e ) {
    if ( e.key === 'Escape' ) {
        $( '#tf-builder-preview-overlay' ).fadeOut( 200 );
        $( document ).off( 'keydown.preview' );
    }
} );

// Close on click outside the box
$( '#tf-builder-preview-overlay' ).off( 'click.outside' ).on( 'click.outside', function( e ) {
    if ( $( e.target ).is( '#tf-builder-preview-overlay' ) ) {
        $( this ).fadeOut( 200 );
        $( document ).off( 'keydown.preview' );
    }
} );

        // "Send me the free preview" — show email input
        $( '#tf-builder-send-preview-btn' ).off( 'click' ).on( 'click', function() {
            $( '#tf-builder-send-preview-btn' ).hide();
            $( '#tf-preview-email-wrap' ).slideDown( 200 );
            $( '#tf-preview-email-input' ).focus();
        } );

        // Email submit — POST to /builder/send-preview
        $( '#tf-preview-email-submit' ).off( 'click' ).on( 'click', function() {
            var email = $( '#tf-preview-email-input' ).val().trim();
            if ( ! isValidEmail( email ) ) {
                $( '#tf-preview-email-input' ).addClass( 'tf-input-error' );
                return;
            }
            $( '#tf-preview-email-input' ).removeClass( 'tf-input-error' );
            $( '#tf-preview-email-submit' ).prop( 'disabled', true ).text( 'Sending…' );
            state.email = email;

            apiPost( 'send-preview', {
                session_id: state.session_id,
                email:      email,
            } )
            .done( function( res ) {
                $( '#tf-builder-preview-overlay' ).fadeOut( 200 );
                appendMessage( 'ai', res.message || 'Preview sent! Check your inbox.' );
            } )
            .fail( function() {
                $( '#tf-preview-email-submit' ).prop( 'disabled', false ).text( 'Send it →' );
                $( '#tf-preview-email-input' ).addClass( 'tf-input-error' );
            } );
        } );

        // "Get the full container" — close overlay, focus left column form
        $( '#tf-builder-checkout-btn' ).off( 'click' ).on( 'click', function() {
            $( '#tf-builder-preview-overlay' ).fadeOut( 200 );
            if ( window.TF_BuildPage ) {
                // Desktop — scroll to and pulse the left column form
                if ( window.TF_BuildPage.focusAddonFields ) {
                    setTimeout( function() { TF_BuildPage.focusAddonFields(); }, 300 );
                }
            } else {
                // Fallback — proceed to ID collection
                state.phase = 'collect_ids';
                startIdCollection();
            }
        } );
    }

    // ── ID collection — inline form ────────────────────────────────────

    // Full ID definition map — label, helper text, placeholder, addon field name
    var ID_DEFINITIONS = {
        'GA4_MEASUREMENT_ID': {
            label:       'GA4 Measurement ID',
            helper:      'GA4 → Admin → Data Streams → your stream → Measurement ID',
            placeholder: 'G-XXXXXXXXXX',
            addon_name:  'GA4 ID',
        },
        'PIXEL_ID': {
            label:       'Meta Pixel ID',
            helper:      'Meta Business Manager → Events Manager → your Pixel → Pixel ID',
            placeholder: '000000000000000',
            addon_name:  'Meta Pixel ID',
        },
        'GADS_CONVERSION_ID': {
            label:       'Google Ads Conversion ID',
            helper:      'Google Ads → Tools → Conversions → your action → Tag setup → AW-XXXXXXXXXX',
            placeholder: 'AW-0000000000',
            addon_name:  'Google Ads Conversion ID',
        },
        'GADS_CONVERSION_LABEL': {
            label:       'Google Ads Purchase Conversion Label',
            helper:      'This is unique to your Purchase conversion action — not a global ID. Google Ads → Goals → Conversions → your Purchase action → Tag details → the string after the slash e.g. AbCdEfGhIjKlMnOp',
            placeholder: 'XXXXXXXXXXXXXXXX',
            addon_name:  'Google Ads Conversion Label',
        },
        'LI_PARTNER_ID': {
            label:       'LinkedIn Partner ID',
            helper:      'LinkedIn Campaign Manager → Account Assets → Insight Tag → Partner ID',
            placeholder: '0000000',
            addon_name:  'LinkedIn Partner ID',
        },
        'TIKTOK_PIXEL_ID': {
            label:       'TikTok Pixel ID',
            helper:      'TikTok Ads Manager → Assets → Events → Web Events → your Pixel ID',
            placeholder: 'C000000000000000000',
            addon_name:  'TikTok Pixel ID',
        },
        'CLARITY_PROJECT_ID': {
            label:       'Microsoft Clarity Project ID',
            helper:      'clarity.microsoft.com → your project → Settings → Overview → Project ID',
            placeholder: 'xxxxxxxxxx',
            addon_name:  'Microsoft Clarity Project ID',
        },
        'HOTJAR_SITE_ID': {
            label:       'Hotjar Site ID',
            helper:      'Hotjar → Settings → Sites & Organisations → your site → Site ID',
            placeholder: '0000000',
            addon_name:  'Hotjar Site ID',
        },
        'BING_UET_TAG_ID': {
            label:       'Bing UET Tag ID',
            helper:      'Microsoft Advertising → Tools → UET Tags → your tag → Tag ID',
            placeholder: '0000000',
            addon_name:  'Bing UET Tag ID',
        },
        'PINTEREST_TAG_ID': {
            label:       'Pinterest Tag ID',
            helper:      'Pinterest Ads → Conversions → your tag → Tag ID',
            placeholder: '0000000000000',
            addon_name:  'Pinterest Tag ID',
        },
        'COOKIEBOT_DOMAIN_ID': {
            label:       'Cookiebot Domain Group ID',
            helper:      'Cookiebot → your domain → Your Scripts tab → Domain Group ID (format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)',
            placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            addon_name:  'Cookiebot Domain ID',
        },
    };

    // Module → required ID keys mapping
    var MODULE_ID_MAP = {
        'gtag-basic':             [ 'GA4_MEASUREMENT_ID' ],
        'ecom-base':              [ 'GA4_MEASUREMENT_ID' ],
        'ecom-advanced':          [ 'GA4_MEASUREMENT_ID' ],
        'facebook-pixel':         [ 'PIXEL_ID' ],
        'facebook-events':        [ 'PIXEL_ID' ],
        'google-ads-conversion':  [ 'GADS_CONVERSION_ID', 'GADS_CONVERSION_LABEL' ],
        'google-ads-remarketing': [ 'GADS_CONVERSION_ID' ],
        'linkedin-insight':       [ 'LI_PARTNER_ID' ],
        'tiktok-pixel':           [ 'TIKTOK_PIXEL_ID' ],
        'microsoft-clarity':      [ 'CLARITY_PROJECT_ID' ],
        'hotjar':                 [ 'HOTJAR_SITE_ID' ],
        'bing-uet':               [ 'BING_UET_TAG_ID' ],
        'pinterest-tag':          [ 'PINTEREST_TAG_ID' ],
        'cookiebot-cmp':          [ 'COOKIEBOT_DOMAIN_ID' ],
    };

    function getNeededIds() {
        var seen   = {};
        var needed = [];
        $.each( state.modules, function( i, slug ) {
            var keys = MODULE_ID_MAP[ slug ] || [];
            $.each( keys, function( j, key ) {
                if ( ! seen[ key ] && ID_DEFINITIONS[ key ] ) {
                    seen[ key ] = true;
                    needed.push( key );
                }
            } );
        } );
        return needed;
    }

    function startIdCollection() {
        var needed = getNeededIds();

        // ── Builder product page — use left-column form ────────────────
        if ( window.TF_BuildPage ) {
            appendMessage( 'ai', "Almost done! Fill in your IDs in the panel on the left — skip anything you don't have and I'll use a safe placeholder you can update in GTM later." );
            if ( window.TF_BuildPage.focusAddonFields ) {
                setTimeout( function() {
                    TF_BuildPage.focusAddonFields();
                }, 400 );
            }
            // Left-column form handles its own submission
            return;
        }

        // ── Fallback — inline chat form (non-builder-page context) ─────
        if ( needed.length === 0 ) {
            proceedToCheckout();
            return;
        }

        appendMessage( 'ai', "Almost done! I need a few platform IDs to pre-fill your container. Fill in what you have — anything you skip gets a safe placeholder you can update inside GTM later." );

        setTimeout( function() {
            showIdForm( needed );
        }, 400 );
    }

    function showIdForm( needed ) {
        // Build the inline ID form as a chat message
        var $msg = $( '<div>' ).addClass( 'tf-msg tf-msg--ai tf-msg--form' );
        $msg.append( $( '<div>' ).addClass( 'tf-msg__avatar' ).text( 'TF' ) );

        var $bubble = $( '<div>' ).addClass( 'tf-msg__bubble tf-id-form-bubble' );

        var $form = $( '<div>' ).addClass( 'tf-id-form' ).attr( 'id', 'tf-id-form' );

        $.each( needed, function( i, key ) {
            var def = ID_DEFINITIONS[ key ];
            if ( ! def ) return;

            var fieldId = 'tf-id-' + key.toLowerCase().replace( /_/g, '-' );

            var $field = $( '<div>' ).addClass( 'tf-id-field' );

            // Label row with skip link
            var $labelRow = $( '<div>' ).addClass( 'tf-id-field__label-row' );
            $labelRow.append(
                $( '<label>' )
                    .addClass( 'tf-id-field__label' )
                    .attr( 'for', fieldId )
                    .text( def.label )
            );
            $labelRow.append(
                $( '<button>' )
                    .addClass( 'tf-id-skip' )
                    .attr( 'type', 'button' )
                    .attr( 'data-key', key )
                    .text( 'Skip' )
            );
            $field.append( $labelRow );

            // Helper text
            $field.append(
                $( '<span>' ).addClass( 'tf-id-field__helper' ).text( def.helper )
            );

            // Input
            $field.append(
                $( '<input>' )
                    .attr( {
                        type:         'text',
                        id:           fieldId,
                        'data-key':   key,
                        'data-addon': def.addon_name,
                        placeholder:  def.placeholder,
                        autocomplete: 'off',
                        spellcheck:   'false',
                    } )
                    .addClass( 'tf-id-input' )
            );

            $form.append( $field );
        } );

        // Submit button
        $form.append(
            $( '<button>' )
                .attr( 'type', 'button' )
                .attr( 'id', 'tf-id-submit' )
                .addClass( 'tf-builder-btn tf-builder-btn--primary tf-id-submit-btn' )
                .text( 'Build my container →' )
        );

        $form.append(
            $( '<p>' ).addClass( 'tf-id-form__note' )
                .text( 'Skipped IDs use safe placeholders — update them in GTM → Variables after import.' )
        );

        $bubble.append( $form );
        $msg.append( $bubble );
        $( '#tf-builder-messages' ).append( $msg );
        scrollToBottom();

        // Disable chat input while form is showing
        setInputLoading( true );
        $( '#tf-builder-input' ).attr( 'placeholder', 'Fill in your IDs above then click Build →' );

        // Skip button handler
        $( '#tf-id-form' ).on( 'click', '.tf-id-skip', function() {
            var key     = $( this ).data( 'key' );
            var $field  = $( this ).closest( '.tf-id-field' );
            $field.addClass( 'tf-id-field--skipped' );
            $field.find( '.tf-id-input' ).val( '' ).prop( 'disabled', true );
            $( this ).text( 'Skipped' ).prop( 'disabled', true );
        } );

        // Submit handler
        $( '#tf-id-submit' ).on( 'click', function() {
            collectAndSubmitIds( needed );
        } );
    }

    function collectAndSubmitIds( needed ) {
        var collected = {};

        $.each( needed, function( i, key ) {
            var def      = ID_DEFINITIONS[ key ];
            var fieldId  = 'tf-id-' + key.toLowerCase().replace( /_/g, '-' );
            var $input   = $( '#' + fieldId );
            var val      = $.trim( $input.val() );
            var skipped  = $input.prop( 'disabled' ) || val === '';

            // Use entered value or placeholder
            collected[ key ] = skipped ? def.placeholder : val;
        } );

        state.collected_ids = collected;

        // Disable the form
        $( '#tf-id-form' ).find( 'input, button' ).prop( 'disabled', true );
        $( '#tf-id-submit' ).text( 'Building…' );

        // Count how many were filled vs skipped
        var filled   = 0;
        var skipped  = 0;
        $.each( collected, function( key, val ) {
            if ( val === ID_DEFINITIONS[ key ].placeholder ) {
                skipped++;
            } else {
                filled++;
            }
        } );

        // Friendly confirmation message
        var confirmMsg = filled > 0
            ? filled + ' ID' + ( filled !== 1 ? 's' : '' ) + ' saved' + ( skipped > 0 ? ', ' + skipped + ' using placeholders' : '' ) + '. Building your container now…'
            : 'No problem — building your container with placeholders. You\'ll get instructions to update them in GTM after download.';

        appendMessage( 'user', confirmMsg );

        // Re-enable input and proceed
        setInputLoading( false );
        $( '#tf-builder-input' ).attr( 'placeholder', 'Type your answer…' );

        proceedToCheckout();
    }

    // ── Proceed to checkout ────────────────────────────────────────────

    function proceedToCheckout() {
        appendMessage( 'ai', "Your container is assembled and ready. Taking you to checkout now — your IDs are all pre-filled." );

        setTimeout( function() {
            // ── Builder product page — delegate to TF_BuildPage ────────
            if ( window.TF_BuildPage ) {
                TF_BuildPage.populateAddonFields( state.collected_ids );
                TF_BuildPage.submitForm( state.session_id, state.custom_name );
                return;
            }
            // ── Fallback ───────────────────────────────────────────────
            populateWooCommerceForm();
            submitToCart();
        }, 1200 );
    }

    // ── Populate hidden WooCommerce form ───────────────────────────────

    function populateWooCommerceForm() {
        // Target WooCommerce Product Add-ons fields by data-addon-name attribute.
        // Confirmed from DOM: <label data-addon-name="GA4 ID"> etc.
        // The input field sits inside the same .wc-pao-addon-container as its label.
        $.each( state.collected_ids, function( key, val ) {
            var def = ID_DEFINITIONS[ key ];
            if ( ! def ) return;
            // Find the label with matching data-addon-name, then find the input in the same container
            var $label = $( '[data-addon-name="' + def.addon_name + '"]' );
            if ( $label.length ) {
                $label.closest( '.wc-pao-addon-container' ).find( 'input[type="text"], input[type="number"]' ).val( val );
            }
        } );

        // Set session ID and custom name as hidden fields on the cart form
        var $form = $( 'form.cart' );
        if ( $form.length ) {
            if ( ! $( '#tf_session_id' ).length ) {
                $form.append( '<input type="hidden" name="tf_session_id" id="tf_session_id">' );
            }
            if ( ! $( '#tf_custom_name' ).length ) {
                $form.append( '<input type="hidden" name="tf_custom_name" id="tf_custom_name">' );
            }
            $( '#tf_session_id' ).val( state.session_id );
            $( '#tf_custom_name' ).val( state.custom_name );
        }
    }

    // ── Submit to WooCommerce cart ─────────────────────────────────────

    function submitToCart() {
        var $form = $( 'form.cart' );
        if ( ! $form.length ) {
            // Fallback: redirect with session params in URL
            window.location.href = TF_Builder.build_url +
                '?add-to-cart=' + getBuilderProductId() +
                '&tf_session_id=' + encodeURIComponent( state.session_id ) +
                '&tf_custom_name=' + encodeURIComponent( state.custom_name );
            return;
        }
        $form.submit();
    }

    function getBuilderProductId() {
        // Read from data attribute on the builder form if present
        return $( '#tf-builder-full' ).data( 'product-id' ) || '';
    }

    // ── Chat UI helpers ────────────────────────────────────────────────

    function appendMessage( role, text, options ) {
        var $messages = $( '#tf-builder-messages' );
        var isAI      = role === 'ai';

        var $msg = $( '<div>' ).addClass( 'tf-msg tf-msg--' + ( isAI ? 'ai' : 'user' ) );

        var avatarText = isAI ? 'TF' : 'You';
        $msg.append( $( '<div>' ).addClass( 'tf-msg__avatar' ).text( avatarText ) );

        var $bubble = $( '<div>' ).addClass( 'tf-msg__bubble' ).html( formatText( text ) );

        // Option buttons — supports single-select and multi-select
        if ( options && options.length && isAI ) {
            var isMulti = options[0] && options[0].multi === true;
            var $opts   = $( '<div>' ).addClass( 'tf-chat-options' + ( isMulti ? ' tf-chat-options--multi' : '' ) );
            var msgId   = 'tf-opts-' + Date.now();
            $opts.attr( 'id', msgId );

            $.each( options, function( i, opt ) {
                var $btn = $( '<button>' )
                    .addClass( 'tf-chat-opt' + ( isMulti ? ' tf-chat-opt--multi' : '' ) )
                    .text( opt.label )
                    .data( 'value', opt.value );

                if ( isMulti ) {
                    // Multi-select: toggle on click
                    $btn.on( 'click', function() {
                        $( this ).toggleClass( 'tf-chat-opt--selected' );
                    } );
                } else {
                    // Single-select: fire immediately
                    $btn.on( 'click', function() {
                        $( '#' + msgId + ' .tf-chat-opt' ).prop( 'disabled', true );
                        $( this ).addClass( 'tf-chat-opt--selected' );
                        appendMessage( 'user', opt.label );
                        state.history.push( { role: 'user', content: opt.label } );
                        sendToBuilder( opt.label );
                    } );
                }
                $opts.append( $btn );
            } );

            if ( isMulti ) {
                // Done button always visible for multi-select
                var $done = $( '<button>' )
                    .addClass( 'tf-chat-opt-done' )
                    .text( 'Done →' )
                    .on( 'click', function() {
                        var selected = [];
                        $( '#' + msgId + ' .tf-chat-opt--selected' ).each( function() {
                            selected.push( $( this ).data( 'value' ) );
                        } );
                        if ( selected.length === 0 ) selected = [ 'None' ];
                        var answer = selected.join( ', ' );
                        $( '#' + msgId + ' .tf-chat-opt, #' + msgId + ' .tf-chat-opt-done' ).prop( 'disabled', true );
                        appendMessage( 'user', answer );
                        state.history.push( { role: 'user', content: answer } );
                        sendToBuilder( answer );
                    } );
                $opts.append( $done );
            }

            $bubble.append( $opts );
        }

        $msg.append( $bubble );
        $messages.append( $msg );
        scrollToBottom();
    }

    function showTyping() {
        var $typing = $( '<div>' )
            .addClass( 'tf-msg tf-msg--ai' )
            .attr( 'id', 'tf-typing-indicator' );
        $typing.append( $( '<div>' ).addClass( 'tf-msg__avatar' ).text( 'TF' ) );
        var $dots = $( '<div>' ).addClass( 'tf-typing' );
        $dots.append( $( '<div>' ).addClass( 'tf-typing__dot' ) );
        $dots.append( $( '<div>' ).addClass( 'tf-typing__dot' ) );
        $dots.append( $( '<div>' ).addClass( 'tf-typing__dot' ) );
        $typing.append( $dots );
        $( '#tf-builder-messages' ).append( $typing );
        scrollToBottom();
    }

    function hideTyping() {
        $( '#tf-typing-indicator' ).remove();
    }

    function scrollToBottom() {
        var $m = $( '#tf-builder-messages' );
        $m.scrollTop( $m[0].scrollHeight );
    }

    function setInputLoading( loading ) {
        $( '#tf-builder-send' ).prop( 'disabled', loading );
        $( '#tf-builder-input' ).prop( 'disabled', loading );
    }

    // ── Text formatting ────────────────────────────────────────────────

    function formatText( text ) {
        var clean = text.replace( /OPTIONS:.*?(?:\n|$)/mg, '' ).replace( /MULTI_OPTIONS:.*?(?:\n|$)/mg, '' ).trim();
        return escHtml( clean )
            .replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
            .replace( /\n/g, '<br>' );
    }

    /**
     * Parse OPTIONS: line from Claude response.
     * Returns array of {label, value} or empty array if no OPTIONS line.
     * Format: OPTIONS: Option One | Option Two | Option Three
     */
    function parseOptions( text ) {
        var multiMatch  = text.match( /MULTI_OPTIONS:\s*(.+?)(?:\n|$)/m );
        var singleMatch = text.match( /(?:^|\n)OPTIONS:\s*(.+?)(?:\n|$)/m );
        var match   = multiMatch || singleMatch;
        var isMulti = !! multiMatch;
        if ( ! match ) return [];
        return match[1].split( '|' ).map( function( opt ) {
            var label = opt.trim().replace( /^\[|\]$/g, '' ).trim();
            return { label: label, value: label.toLowerCase().replace( /[^a-z0-9]+/g, '-' ), multi: isMulti };
        } ).filter( function( opt ) {
            return opt.label.length > 0;
        } );
    }

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    // ── Greeting based on site type ────────────────────────────────────

    function siteTypeGreeting( siteType ) {
        var greetings = {
            'shopify-ecommerce':   'a Shopify ecommerce store',
            'wordpress-ecommerce': 'a WordPress / WooCommerce store',
            'wordpress-lead-gen':  'a WordPress lead gen site',
            'wordpress-content':   'a WordPress content site',
            // Legacy values
            'ecommerce': 'an ecommerce store',
            'lead-gen':  'a lead gen site',
            'content':   'a content site',
            'unsure':    'your site',
        };
        var label = greetings[ siteType ] || 'your site';
        // Pass platform context to Claude naturally
        return "Got it — you're setting this up for " + label + ". A couple of quick questions and I'll put together the right container for you.";
    }

    // Extract platform from combined site type value
    function platformFromSiteType( siteType ) {
        if ( siteType && siteType.indexOf( 'shopify' ) === 0 ) return 'shopify';
        if ( siteType && siteType.indexOf( 'wordpress' ) === 0 ) return 'wordpress';
        return '';
    }

    // ── REST API helper ────────────────────────────────────────────────

    function apiPost( endpoint, data ) {
        return $.ajax( {
            url:         REST + endpoint,
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify( data ),
            headers:     { 'X-WP-Nonce': NONCE },
        } ).then( function( res ) {
            return res;
        } );
    }

    // ── Utilities ──────────────────────────────────────────────────────

    function getUrlParam( key ) {
        var params = new URLSearchParams( window.location.search );
        return params.get( key ) || '';
    }

    function isValidEmail( email ) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
    }

    // ── Boot ───────────────────────────────────────────────────────────

    $( document ).ready( function() {
        if ( $( '#tf-builder-full' ).length ) {
            init();
        }
    } );

} )( jQuery );
