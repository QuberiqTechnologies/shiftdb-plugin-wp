<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShiftDB_Cleanup {
	public static function counts(): array {
		global $wpdb;
		$revisions = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$spam      = (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$trash     = (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only diagnostic count.
		$transient = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(option_id) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'revisions'  => $revisions,
			'spam'       => $spam,
			'ttrash'     => $trash,
			'transients' => $transient,
		);
	}

	public static function cleanup( array $types ): array {
		global $wpdb;
		$allowed = array( 'revisions', 'spam', 'trash', 'expired_transients', 'all_transients' );
		$types   = array_values( array_intersect( array_map( 'sanitize_key', $types ), $allowed ) );
		$result  = array();

		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'revisions':
					$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$count = 0;
					foreach ( $ids as $id ) {
						if ( wp_delete_post_revision( (int) $id ) ) {
							++$count;
						}
					}
					$result[ $type ] = $count;
					break;
				case 'spam':
					$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$count = 0;
					foreach ( $ids as $id ) {
						if ( wp_delete_comment( (int) $id, true ) ) {
							++$count;
						}
					}
					$result[ $type ] = $count;
					break;
				case 'trash':
					$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$count = 0;
					foreach ( $ids as $id ) {
						if ( wp_delete_comment( (int) $id, true ) ) {
							++$count;
						}
					}
					$result[ $type ] = $count;
					break;
				case 'expired_transients':
					$result[ $type ] = self::delete_expired_transients();
					break;
				case 'all_transients':
					$result[ $type ] = self::delete_all_transients();
					break;
			}
		}

		return $result;
	}

	private static function delete_expired_transients(): int {
		global $wpdb;
		$now   = time();
		// Direct read is intentional to locate expired transient keys before using the core deletion API.
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);
		$count = 0;
		foreach ( $names as $timeout_name ) {
			$key = substr( $timeout_name, strlen( '_transient_timeout_' ) );
			if ( delete_transient( $key ) ) {
				++$count;
			}
		}
		return $count;
	}

	private static function delete_all_transients(): int {
		global $wpdb;
		$patterns = array(
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
		);
		// Direct read is intentional to enumerate transient keys before using the core deletion APIs.
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$patterns[0],
				$patterns[1]
			)
		);
		$count = 0;
		foreach ( $names as $name ) {
			if ( str_starts_with( $name, '_transient_timeout_' ) || str_starts_with( $name, '_site_transient_timeout_' ) ) {
				continue;
			}
			if ( str_starts_with( $name, '_transient_' ) ) {
				$key = substr( $name, strlen( '_transient_' ) );
				if ( delete_transient( $key ) ) {
					++$count;
				}
			} elseif ( str_starts_with( $name, '_site_transient_' ) ) {
				$key = substr( $name, strlen( '_site_transient_' ) );
				if ( delete_site_transient( $key ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	public static function optimize_tables( array $tables ): array|WP_Error {
		global $wpdb;
		$tables = ShiftDB_Database::validate_tables( $tables );
		if ( empty( $tables ) ) {
			return new WP_Error( 'shiftdb_no_tables', __( 'Select at least one valid database table.', 'shiftdb' ) );
		}
		$result = array();
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->prepare( 'OPTIMIZE TABLE %i', $table ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			$result[ $table ] = $row['Msg_text'] ?? __( 'Optimization completed.', 'shiftdb' );
		}
		return $result;
	}
}
