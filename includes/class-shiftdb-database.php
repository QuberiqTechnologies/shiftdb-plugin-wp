<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShiftDB_Database {
	private const MAX_PREVIEW_ROWS = 100;

	public static function get_tables(): array {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$sql    = $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like );
		$tables = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $tables ) ? $tables : array();
	}

	public static function get_table_names(): array {
		return array_values(
			array_filter(
				array_map(
					static fn( $row ) => isset( $row['Name'] ) ? (string) $row['Name'] : '',
					self::get_tables()
				)
			)
		);
	}

	public static function validate_tables( array $tables ): array {
		$allowed = array_flip( self::get_table_names() );
		$valid   = array();
		foreach ( $tables as $table ) {
			$table = sanitize_text_field( wp_unslash( $table ) );
			if ( isset( $allowed[ $table ] ) ) {
				$valid[] = $table;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	public static function table_columns( string $table ): array {
		global $wpdb;
		if ( ! in_array( $table, self::get_table_names(), true ) ) {
			return array();
		}
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $columns ) ? $columns : array();
	}

	private static function searchable_columns( string $table ): array {
		$columns = self::table_columns( $table );
		$result  = array();
		foreach ( $columns as $column ) {
			$type = strtolower( (string) ( $column['Type'] ?? '' ) );
			if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)/', $type ) ) {
				$result[] = (string) $column['Field'];
			}
		}
		return $result;
	}

	private static function primary_key( string $table ): ?string {
		foreach ( self::table_columns( $table ) as $column ) {
			if ( 'PRI' === ( $column['Key'] ?? '' ) ) {
				return (string) $column['Field'];
			}
		}
		return null;
	}

	public static function dry_run( string $search, string $replace, array $tables, bool $case_sensitive = true ): array|WP_Error {
		global $wpdb;
		if ( '' === $search ) {
			return new WP_Error( 'shiftdb_empty_search', __( 'Search value cannot be empty.', 'shiftdb' ) );
		}

		$tables = self::validate_tables( $tables );
		if ( empty( $tables ) ) {
			return new WP_Error( 'shiftdb_no_tables', __( 'Select at least one valid database table.', 'shiftdb' ) );
		}

		$summary = array(
			'total_replacements' => 0,
			'total_rows'         => 0,
			'tables'             => array(),
			'preview'            => array(),
		);

		foreach ( $tables as $table ) {
			$columns = self::searchable_columns( $table );
			$pk      = self::primary_key( $table );
			if ( empty( $columns ) || ! $pk ) {
				continue;
			}

			$table_replacements = 0;
			$table_rows         = 0;
			$conditions         = array();
			$args               = array();
			foreach ( $columns as $column ) {
				$conditions[] = '`' . esc_sql( $column ) . '` LIKE %s';
				$args[]       = '%' . $wpdb->esc_like( $search ) . '%';
			}

			$sql = 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE ' . implode( ' OR ', $conditions );
			if ( $args ) {
				$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$row_replacements = 0;
				foreach ( $columns as $column ) {
					$value = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
					$row_replacements += self::count_occurrences( $value, $search, $case_sensitive );
				}
				if ( $row_replacements > 0 ) {
					++$table_rows;
					$table_replacements += $row_replacements;
					if ( count( $summary['preview'] ) < self::MAX_PREVIEW_ROWS ) {
						$summary['preview'][] = array(
							'table'        => $table,
							'primary_key'  => $pk,
							'row_id'       => (string) ( $row[ $pk ] ?? '' ),
							'replacements' => $row_replacements,
						);
					}
				}
			}

			$summary['tables'][ $table ] = array(
				'rows'         => $table_rows,
				'replacements' => $table_replacements,
			);
			$summary['total_rows']         += $table_rows;
			$summary['total_replacements'] += $table_replacements;
		}

		return $summary;
	}

	public static function replace( string $search, string $replace, array $tables, bool $case_sensitive = true, bool $protect_guids = true ): array|WP_Error {
		global $wpdb;
		$dry_run = self::dry_run( $search, $replace, $tables, $case_sensitive );
		if ( is_wp_error( $dry_run ) ) {
			return $dry_run;
		}
		if ( 0 === (int) $dry_run['total_replacements'] ) {
			return array(
				'replacements' => 0,
				'rows'         => 0,
				'snapshot'     => '',
			);
		}

		$tables        = self::validate_tables( $tables );
		$snapshot_rows = array();
		$pending       = array();
		$replacements  = 0;

		foreach ( $tables as $table ) {
			$columns = self::searchable_columns( $table );
			$pk      = self::primary_key( $table );
			if ( empty( $columns ) || ! $pk ) {
				continue;
			}

			if ( $protect_guids && $wpdb->posts === $table ) {
				$columns = array_values( array_diff( $columns, array( 'guid' ) ) );
			}
			if ( empty( $columns ) ) {
				continue;
			}

			$conditions = array();
			$args       = array();
			foreach ( $columns as $column ) {
				$conditions[] = '`' . esc_sql( $column ) . '` LIKE %s';
				$args[]       = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$sql  = 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE ' . implode( ' OR ', $conditions );
			$sql  = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$updates          = array();
				$row_replacements = 0;
				foreach ( $columns as $column ) {
					if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
						continue;
					}
					$original = (string) $row[ $column ];
					$changed  = self::replace_value( $original, $search, $replace, $case_sensitive, $row_replacements );
					if ( $changed !== $original ) {
						$updates[ $column ] = $changed;
					}
				}
				if ( empty( $updates ) ) {
					continue;
				}

				$snapshot_rows[] = array(
					'table'       => $table,
					'primary_key' => $pk,
					'row_id'      => $row[ $pk ],
					'row'         => $row,
				);
				$pending[] = array(
					'table'       => $table,
					'primary_key' => $pk,
					'row_id'      => $row[ $pk ],
					'updates'     => $updates,
				);
				$replacements += $row_replacements;
			}
		}

		$snapshot = ShiftDB_Backup::create_snapshot(
			array(
				'type'    => 'search_replace',
				'search'  => $search,
				'replace' => $replace,
				'rows'    => $snapshot_rows,
			)
		);

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$changed_rows = 0;
		foreach ( $pending as $change ) {
			$result = $wpdb->update(
				$change['table'],
				$change['updates'],
				array( $change['primary_key'] => $change['row_id'] )
			);
			if ( false === $result ) {
				return new WP_Error(
					'shiftdb_update_failed',
					sprintf( __( 'Database update failed for table %s. Use the generated recovery point to restore affected rows.', 'shiftdb' ), $change['table'] )
				);
			}
			++$changed_rows;
		}

		return array(
			'replacements' => $replacements,
			'rows'         => $changed_rows,
			'snapshot'     => $snapshot,
		);
	}

	public static function restore_snapshot( string $filename ): array|WP_Error {
		global $wpdb;
		$data = ShiftDB_Backup::read_snapshot( $filename );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = $data['payload']['rows'] ?? array();
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'shiftdb_snapshot_invalid_rows', __( 'Recovery snapshot contains no restorable rows.', 'shiftdb' ) );
		}

		$allowed  = array_flip( self::get_table_names() );
		$restored = 0;
		foreach ( $rows as $entry ) {
			$table = (string) ( $entry['table'] ?? '' );
			$pk    = (string) ( $entry['primary_key'] ?? '' );
			$row   = $entry['row'] ?? null;
			if ( ! isset( $allowed[ $table ] ) || ! is_array( $row ) || ! array_key_exists( $pk, $row ) ) {
				continue;
			}
			$key_value = $row[ $pk ];
			$update    = $row;
			unset( $update[ $pk ] );
			$result = $wpdb->update( $table, $update, array( $pk => $key_value ) );
			if ( false !== $result ) {
				++$restored;
			}
		}

		return array( 'rows' => $restored );
	}

	public static function autoload_options( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$sql   = $wpdb->prepare(
			"SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT %d",
			$limit
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function export_sql( array $tables ): string|WP_Error {
		global $wpdb;
		$tables = self::validate_tables( $tables );
		if ( empty( $tables ) ) {
			return new WP_Error( 'shiftdb_no_tables', __( 'Select at least one valid database table.', 'shiftdb' ) );
		}

		$out  = "-- ShiftDB database export\n";
		$out .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( isset( $create[1] ) ) {
				$out .= 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create[1] . ";\n\n";
			}
			$rows = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $row ) {
				$cols   = array();
				$values = array();
				foreach ( $row as $column => $value ) {
					$cols[] = '`' . str_replace( '`', '``', $column ) . '`';
					if ( null === $value ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . esc_sql( (string) $value ) . "'";
					}
				}
				$out .= 'INSERT INTO `' . $table . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $values ) . ");\n";
			}
			$out .= "\n";
		}
		return $out;
	}

	private static function count_occurrences( string $value, string $search, bool $case_sensitive ): int {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( ( false !== $data || 'b:0;' === trim( $value ) ) && ! self::contains_object( $data ) ) {
				return self::count_in_mixed( $data, $search, $case_sensitive );
			}
			return 0;
		}
		return $case_sensitive ? substr_count( $value, $search ) : substr_count( strtolower( $value ), strtolower( $search ) );
	}

	private static function count_in_mixed( mixed $value, string $search, bool $case_sensitive ): int {
		$count = 0;
		if ( is_string( $value ) ) {
			return $case_sensitive ? substr_count( $value, $search ) : substr_count( strtolower( $value ), strtolower( $search ) );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( $value as $item ) {
				$count += self::count_in_mixed( $item, $search, $case_sensitive );
			}
		}
		return $count;
	}

	private static function replace_value( string $value, string $search, string $replace, bool $case_sensitive, int &$count ): string {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( ( false !== $data || 'b:0;' === trim( $value ) ) && ! self::contains_object( $data ) ) {
				$changed = self::replace_mixed( $data, $search, $replace, $case_sensitive, $count );
				return serialize( $changed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
			return $value;
		}

		if ( $case_sensitive ) {
			$result = str_replace( $search, $replace, $value, $local_count );
		} else {
			$result = str_ireplace( $search, $replace, $value, $local_count );
		}
		$count += $local_count;
		return $result;
	}

	private static function contains_object( mixed $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::contains_object( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function replace_mixed( mixed $value, string $search, string $replace, bool $case_sensitive, int &$count ): mixed {
		if ( is_string( $value ) ) {
			if ( $case_sensitive ) {
				$result = str_replace( $search, $replace, $value, $local_count );
			} else {
				$result = str_ireplace( $search, $replace, $value, $local_count );
			}
			$count += $local_count;
			return $result;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_mixed( $item, $search, $replace, $case_sensitive, $count );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->{$key} = self::replace_mixed( $item, $search, $replace, $case_sensitive, $count );
			}
		}
		return $value;
	}
}
