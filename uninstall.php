<?php
/**
 * TagForge Modular GTM — Uninstall
 *
 * Runs when the plugin is deleted via WP Admin → Plugins → Delete.
 * Does NOT run on deactivation — only on full deletion.
 *
 * Drops the tagforge_builder_sessions table and cleans up options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the AI Builder sessions table
$table = $wpdb->prefix . 'tagforge_builder_sessions';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Clean up version key
delete_option( 'tagforge_sessions_db_version' );

// Note: we deliberately do NOT delete tagforge_options on uninstall.
// Settings (API keys, email config) are preserved in case the admin
// reinstalls the plugin. If you want to purge all settings too,
// uncomment the line below.
// delete_option( 'tagforge_options' );
