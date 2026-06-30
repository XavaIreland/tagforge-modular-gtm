<?php
/**
 * TagForge\Zoho
 *
 * Handles Zoho Campaigns form POST subscription.
 * Uses the web-optin form endpoint — no OAuth required.
 *
 * The form POST endpoint and field names are configured in
 * TF Factory → AI Builder → Integrations tab.
 *
 * Default values are pre-filled from the TagForge Zoho form.
 *
 * ADDITIVE ONLY — no existing files modified.
 *
 * @package TagForge
 * @since   5.1.0
 */

namespace TagForge;

if ( ! defined( 'ABSPATH' ) ) exit;

class Zoho {

    const DEFAULT_ENDPOINT  = 'https://xwpd-zgpvh.maillist-manage.net/weboptin.zc';
    const DEFAULT_FORM_ID   = '3zbd0a0e339fda7f63b3493f6515fdddc9caafbe39f96adc228803fd7f6cdc24de';
    const DEFAULT_ZOHO_UID  = '129c54d10';
    const FIELD_EMAIL        = 'CONTACT_EMAIL';
    const FIELD_PREVIEW_URL  = 'CONTACT_CF4';

    /**
     * Subscribe an email to the TF - Free Download Zoho Campaigns list.
     * Non-blocking — errors are logged but never bubble up to the customer.
     *
     * @param string $email        Customer email
     * @param string $download_url Time-limited preview container download URL
     * @param array  $session      Builder session data (for context fields)
     */
    public static function subscribe( string $email, string $download_url, array $session = [] ) : void {
        try {
            $opts     = Helpers::get_options();
            $endpoint = ! empty( $opts['zoho_endpoint'] ) ? $opts['zoho_endpoint'] : self::DEFAULT_ENDPOINT;
            $form_id  = ! empty( $opts['zoho_form_id'] )  ? $opts['zoho_form_id']  : self::DEFAULT_FORM_ID;
            $uid      = ! empty( $opts['zoho_uid'] )       ? $opts['zoho_uid']       : self::DEFAULT_ZOHO_UID;

            if ( empty( $endpoint ) ) {
                error_log( '[TagForge Zoho] No endpoint configured — skipping subscription.' );
                return;
            }

            $body = [
                self::FIELD_EMAIL       => $email,
                self::FIELD_PREVIEW_URL => $download_url,
                // Required Zoho hidden fields
                'zc_formIx'             => $form_id,
                'zx'                    => $uid,
                'zcvers'                => '3.0',
                'submitType'            => 'optinCustomView',
                'mode'                  => 'OptinCreateView',
                'viewFrom'              => 'URL_ACTION',
            ];

            // Optional name from session answers
            $answers = json_decode( $session['answers'] ?? '{}', true ) ?: [];
            // We don't collect name in the builder yet — leave blank for now

            $response = wp_remote_post( $endpoint, [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( '[TagForge Zoho] POST failed: ' . $response->get_error_message() );
                return;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 && $code !== 302 ) {
                error_log( "[TagForge Zoho] Unexpected response code: {$code}" );
            } else {
                error_log( "[TagForge Zoho] Subscribed: {$email}" );
            }

        } catch ( \Throwable $e ) {
            error_log( '[TagForge Zoho] Exception: ' . $e->getMessage() );
        }
    }
}
