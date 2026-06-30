/* TagForge AI Builder — Admin JS
   tagforge-ai-admin.js · v4.0.0 */

jQuery( function( $ ) {

    const ajax  = TF_AI_Admin.ajax_url;
    const nonce = TF_AI_Admin.nonce;

    // ── Helpers ────────────────────────────────────────────────────────

    function showMsg( $el, text, isError ) {
        $el.text( text )
           .removeClass( 'tf-ai-save-msg--ok tf-ai-save-msg--err' )
           .addClass( isError ? 'tf-ai-save-msg--err' : 'tf-ai-save-msg--ok' )
           .show();
        clearTimeout( $el.data( 'timer' ) );
        $el.data( 'timer', setTimeout( function() { $el.fadeOut( 300 ); }, 4000 ) );
    }

    function setKeyStatus( state, message ) {
        var $status = $( '#tf-ai-key-status' );
        $status.removeClass( 'tf-ai-key-status--set tf-ai-key-status--missing tf-ai-key-status--testing' );
        var dot = state === 'set' ? 'tf-ai-dot--green' : ( state === 'testing' ? 'tf-ai-dot--amber' : 'tf-ai-dot--red' );
        $status.addClass( 'tf-ai-key-status--' + state );
        $status.html( '<span class="tf-ai-dot ' + dot + '"></span> ' + message );
    }

    // ── Save API key ───────────────────────────────────────────────────

    $( '#tf-ai-save-key' ).on( 'click', function() {
        var $btn = $( this );
        var val  = $( '#tf-ai-api-key' ).val().trim();
        var $msg = $( '#tf-ai-key-msg' );

        $btn.prop( 'disabled', true ).text( 'Saving…' );

        $.post( ajax, {
            action: 'tagforge_ai_save_settings',
            nonce:  nonce,
            field:  'api_key',
            value:  val,
        }, function( res ) {
            $btn.prop( 'disabled', false ).text( 'Save key' );
            if ( res.success ) {
                showMsg( $msg, '✓ ' + res.data, false );
                if ( val ) {
                    setKeyStatus( 'set', 'API key set · model: <strong>claude-sonnet-4-20250514</strong>' );
                } else {
                    setKeyStatus( 'missing', 'No API key set — AI Builder will not function' );
                }
            } else {
                showMsg( $msg, '✗ ' + res.data, true );
            }
        } ).fail( function() {
            $btn.prop( 'disabled', false ).text( 'Save key' );
            showMsg( $msg, '✗ Request failed', true );
        } );
    } );

    // ── Test API key ───────────────────────────────────────────────────

    $( '#tf-ai-test-key' ).on( 'click', function() {
        var $btn = $( this );
        var $msg = $( '#tf-ai-key-msg' );

        $btn.prop( 'disabled', true ).text( 'Testing…' );
        setKeyStatus( 'testing', 'Testing connection…' );

        $.post( ajax, {
            action: 'tagforge_ai_test_key',
            nonce:  nonce,
        }, function( res ) {
            $btn.prop( 'disabled', false ).text( 'Test' );
            if ( res.success ) {
                showMsg( $msg, '✓ ' + res.data, false );
                setKeyStatus( 'set', 'API key valid · model: <strong>claude-sonnet-4-20250514</strong>' );
            } else {
                showMsg( $msg, '✗ ' + res.data, true );
                setKeyStatus( 'missing', res.data );
            }
        } ).fail( function() {
            $btn.prop( 'disabled', false ).text( 'Test' );
            showMsg( $msg, '✗ Request failed', true );
            setKeyStatus( 'missing', 'Connection failed' );
        } );
    } );

    // ── Save limits ────────────────────────────────────────────────────

    $( '#tf-ai-save-limits' ).on( 'click', function() {
        var $btn = $( this );
        var $msg = $( '#tf-ai-limits-msg' );

        $btn.prop( 'disabled', true ).text( 'Saving…' );

        $.post( ajax, {
            action:            'tagforge_ai_save_settings',
            nonce:             nonce,
            field:             'limits',
            refinement_limit:  $( '#tf-ai-ref-limit' ).val(),
            rate_limit:        $( '#tf-ai-rate-limit' ).val(),
        }, function( res ) {
            $btn.prop( 'disabled', false ).text( 'Save limits' );
            if ( res.success ) {
                showMsg( $msg, '✓ ' + res.data, false );
            } else {
                showMsg( $msg, '✗ ' + res.data, true );
            }
        } ).fail( function() {
            $btn.prop( 'disabled', false ).text( 'Save limits' );
            showMsg( $msg, '✗ Request failed', true );
        } );
    } );

    // ── Flag session ───────────────────────────────────────────────────

    $( document ).on( 'click', '.tf-ai-flag-btn', function() {
        var $btn       = $( this );
        var sessionId  = $btn.data( 'session' );
        var wasFlagged = parseInt( $btn.data( 'flagged' ), 10 );
        var newFlag    = wasFlagged ? 0 : 1;

        $btn.prop( 'disabled', true );

        $.post( ajax, {
            action:     'tagforge_ai_flag_session',
            nonce:      nonce,
            session_id: sessionId,
            flagged:    newFlag,
        }, function( res ) {
            $btn.prop( 'disabled', false );
            if ( res.success ) {
                $btn.data( 'flagged', newFlag );
                if ( newFlag ) {
                    $btn.addClass( 'tf-ai-flag-btn--active' ).text( '★ Flagged' );
                } else {
                    $btn.removeClass( 'tf-ai-flag-btn--active' ).text( '☆ Flag' );
                }
            }
        } );
    } );

    // ── Export CSV ─────────────────────────────────────────────────────

    $( '#tf-ai-export-csv' ).on( 'click', function() {
        var $btn = $( this );
        var $msg = $( '#tf-ai-data-msg' );

        $btn.prop( 'disabled', true ).text( 'Exporting…' );

        $.post( ajax, {
            action: 'tagforge_ai_export_sessions',
            nonce:  nonce,
        }, function( res ) {
            $btn.prop( 'disabled', false ).text( 'Export CSV' );
            if ( res.success ) {
                var blob = new Blob( [ res.data.csv ], { type: 'text/csv' } );
                var url  = URL.createObjectURL( blob );
                var a    = document.createElement( 'a' );
                a.href     = url;
                a.download = res.data.filename;
                document.body.appendChild( a );
                a.click();
                document.body.removeChild( a );
                URL.revokeObjectURL( url );
                showMsg( $msg, '✓ CSV downloaded', false );
            } else {
                showMsg( $msg, '✗ ' + res.data, true );
            }
        } ).fail( function() {
            $btn.prop( 'disabled', false ).text( 'Export CSV' );
            showMsg( $msg, '✗ Request failed', true );
        } );
    } );

    // ── Purge expired ──────────────────────────────────────────────────

    $( '#tf-ai-purge-expired' ).on( 'click', function() {
        if ( ! confirm( 'Delete all expired sessions older than 30 days? This cannot be undone.' ) ) return;

        var $btn = $( this );
        var $msg = $( '#tf-ai-data-msg' );

        $btn.prop( 'disabled', true ).text( 'Purging…' );

        $.post( ajax, {
            action: 'tagforge_ai_purge_expired',
            nonce:  nonce,
        }, function( res ) {
            $btn.prop( 'disabled', false ).text( 'Purge expired' );
            if ( res.success ) {
                showMsg( $msg, '✓ Deleted ' + res.data.deleted + ' session(s)', false );
            } else {
                showMsg( $msg, '✗ ' + res.data, true );
            }
        } ).fail( function() {
            $btn.prop( 'disabled', false ).text( 'Purge expired' );
            showMsg( $msg, '✗ Request failed', true );
        } );
    } );

} );
