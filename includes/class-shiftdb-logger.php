<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShiftDB_Logger {
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'shiftdb_log';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(80) NOT NULL,
			status varchar(20) NOT NULL,
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function add( string $action, string $status, array $details = array() ): void {
		global $wpdb;

		// Direct query is intentional: ShiftDB writes to its own local operation log table.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table_name(),
			array(
				'user_id'    => get_current_user_id(),
				'action'     => sanitize_key( $action ),
				'status'     => sanitize_key( $status ),
				'details'    => wp_json_encode( $details ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );
		// Direct query is intentional: ShiftDB maintains its own local operation log table.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
