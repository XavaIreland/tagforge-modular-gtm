<?php
/**
 * TagForge\AI_DB
 *
 * Manages the tagforge_builder_sessions database table.
 *
 * - Created on plugin activation
 * - Optionally deleted on deactivation (admin checkbox in tagforge_options)
 * - Always dropped cleanly on plugin deletion (see uninstall.php)
 *
 * ADDITIVE ONLY — no existing files modified.
 *
 * @package TagForge
 * @since   4.0.0
 */

namespace TagForge;

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_DB {

    const TABLE_VERSION_KEY = 'tagforge_sessions_db_version';
    const TABLE_VERSION     = '1.0';

    // ── Table name ─────────────────────────────────────────────────────

    public static function table_name() : string {
        global $wpdb;
        return $wpdb->prefix . 'tagforge_builder_sessions';
    }

    // ── Create table ───────────────────────────────────────────────────

    public static function create_table() : void {
        global $wpdb;

        $table      = self::table_name();
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id    VARCHAR(64)     NOT NULL,
            email         VARCHAR(255)    DEFAULT NULL,
            answers       LONGTEXT        DEFAULT NULL COMMENT 'JSON: Q1-Q5 answers',
            modules       LONGTEXT        DEFAULT NULL COMMENT 'JSON: module slug array',
            custom_name   VARCHAR(255)    DEFAULT NULL COMMENT 'Claude-generated container name',
            price         DECIMAL(8,2)    DEFAULT NULL COMMENT 'Calculated tier price',
            refinements   TINYINT         NOT NULL DEFAULT 0 COMMENT 'Downstream refinements used',
            status        VARCHAR(32)     NOT NULL DEFAULT 'active' COMMENT 'active|purchased|expired',
            follow_up     TINYINT         NOT NULL DEFAULT 0 COMMENT 'Admin follow-up flag',
            order_id      BIGINT UNSIGNED DEFAULT NULL COMMENT 'WooCommerce order ID on purchase',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY   (id),
            UNIQUE KEY    session_id (session_id),
            KEY           email (email),
            KEY           status (status),
            KEY           created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::TABLE_VERSION_KEY, self::TABLE_VERSION );
    }

    // ── Drop table ─────────────────────────────────────────────────────

    public static function drop_table() : void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::TABLE_VERSION_KEY );
    }

    // ── Check table exists ─────────────────────────────────────────────

    public static function table_exists() : bool {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
    }

    // ── Activation hook ────────────────────────────────────────────────

    public static function on_activate() : void {
        self::create_table();
    }

    // ── Deactivation hook ──────────────────────────────────────────────
    // Only drops the table if the admin explicitly ticked the option.

    public static function on_deactivate() : void {
        $opts = Helpers::get_options();
        if ( ! empty( $opts['builder_delete_on_deactivate'] ) ) {
            self::drop_table();
        }
    }

    // ── Maybe upgrade ──────────────────────────────────────────────────
    // Called on init — ensures the table is up to date if plugin was
    // updated without deactivating first.

    public static function maybe_upgrade() : void {
        if ( get_option( self::TABLE_VERSION_KEY ) !== self::TABLE_VERSION ) {
            self::create_table();
        }
    }
}
