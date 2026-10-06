<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShiftDB_Backup {
	public static function directory(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'shiftdb-recovery';
	}

	private static function filesystem(): WP_Filesystem_Base|WP_Error {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new WP_Error( 'shiftdb_filesystem_unavailable', __( 'WordPress filesystem access is unavailable.', 'shiftdb' ) );
		}

		return $wp_filesystem;
	}

	public static function ensure_storage(): bool {
		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return false;
		}

		$dir = self::directory();
		if ( ! $filesystem->is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! $filesystem->exists( $index ) && ! $filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE ) ) {
			return false;
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! $filesystem->exists( $htaccess ) && ! $filesystem->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE ) ) {
			return false;
		}

		$webconfig = trailingslashit( $dir ) . 'web.config';
		if ( ! $filesystem->exists( $webconfig ) ) {
			$rules = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></system.webServer></configuration>';
			if ( ! $filesystem->put_contents( $webconfig, $rules, FS_CHMOD_FILE ) ) {
				return false;
			}
		}

		return true;
	}

	public static function create_snapshot( array $payload ): string|WP_Error {
		if ( ! self::ensure_storage() ) {
			return new WP_Error( 'shiftdb_storage_unavailable', __( 'Recovery storage could not be created.', 'shiftdb' ) );
		}

		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$id       = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );
		$filename = 'snapshot-' . sanitize_file_name( $id ) . '.json';
		$path     = trailingslashit( self::directory() ) . $filename;
		$data     = array(
			'plugin_version' => SHIFTDB_VERSION,
			'created_at'     => current_time( 'mysql' ),
			'site_url'       => site_url(),
			'payload'        => $payload,
		);
		$json = wp_json_encode( $data );

		if ( false === $json || ! $filesystem->put_contents( $path, $json, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'shiftdb_snapshot_failed', __( 'Recovery snapshot could not be written.', 'shiftdb' ) );
		}

		return $filename;
	}

	public static function read_snapshot( string $filename ): array|WP_Error {
		$filename = sanitize_file_name( $filename );
		if ( ! self::is_snapshot_filename( $filename ) ) {
			return new WP_Error( 'shiftdb_snapshot_invalid', __( 'Recovery snapshot is unavailable.', 'shiftdb' ) );
		}

		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$path = trailingslashit( self::directory() ) . $filename;
		if ( ! $filesystem->is_file( $path ) || ! $filesystem->is_readable( $path ) ) {
			return new WP_Error( 'shiftdb_snapshot_invalid', __( 'Recovery snapshot is unavailable.', 'shiftdb' ) );
		}

		$contents = $filesystem->get_contents( $path );
		$data     = json_decode( (string) $contents, true );
		if ( ! is_array( $data ) || empty( $data['payload'] ) ) {
			return new WP_Error( 'shiftdb_snapshot_corrupt', __( 'Recovery snapshot is invalid or corrupt.', 'shiftdb' ) );
		}

		return $data;
	}

	public static function list_snapshots(): array {
		if ( ! self::ensure_storage() ) {
			return array();
		}

		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return array();
		}

		$files = $filesystem->dirlist( self::directory(), false, false );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$items = array();
		foreach ( $files as $name => $info ) {
			if ( ! self::is_snapshot_filename( (string) $name ) || 'f' !== ( $info['type'] ?? '' ) ) {
				continue;
			}
			$items[] = array(
				'name' => (string) $name,
				'size' => (int) ( $info['size'] ?? 0 ),
				'time' => (int) ( $info['lastmodunix'] ?? 0 ),
			);
		}

		usort( $items, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $items;
	}

	public static function delete_snapshot( string $filename ): bool {
		$filename = sanitize_file_name( $filename );
		if ( ! self::is_snapshot_filename( $filename ) ) {
			return false;
		}

		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return false;
		}

		$path = trailingslashit( self::directory() ) . $filename;
		return $filesystem->is_file( $path ) && $filesystem->delete( $path, false, 'f' );
	}

	private static function is_snapshot_filename( string $filename ): bool {
		return 1 === preg_match( '/^snapshot-[A-Za-z0-9-]+\.json$/', $filename );
	}
}
