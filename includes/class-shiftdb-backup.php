<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShiftDB_Backup {
	public static function directory(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'shiftdb-recovery';
	}

	public static function ensure_storage(): bool {
		$dir = self::directory();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$webconfig = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			$rules = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></system.webServer></configuration>';
			file_put_contents( $webconfig, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	public static function create_snapshot( array $payload ): string|WP_Error {
		if ( ! self::ensure_storage() ) {
			return new WP_Error( 'shiftdb_storage_unavailable', __( 'Recovery storage could not be created.', 'shiftdb' ) );
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
		$json     = wp_json_encode( $data );

		if ( false === $json || false === file_put_contents( $path, $json, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'shiftdb_snapshot_failed', __( 'Recovery snapshot could not be written.', 'shiftdb' ) );
		}

		return $filename;
	}

	public static function read_snapshot( string $filename ): array|WP_Error {
		$filename = sanitize_file_name( $filename );
		$path     = trailingslashit( self::directory() ) . $filename;
		$real_dir = realpath( self::directory() );
		$real     = realpath( $path );

		if ( false === $real_dir || false === $real || 0 !== strpos( $real, $real_dir ) || ! is_readable( $real ) ) {
			return new WP_Error( 'shiftdb_snapshot_invalid', __( 'Recovery snapshot is unavailable.', 'shiftdb' ) );
		}

		$contents = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data     = json_decode( (string) $contents, true );
		if ( ! is_array( $data ) || empty( $data['payload'] ) ) {
			return new WP_Error( 'shiftdb_snapshot_corrupt', __( 'Recovery snapshot is invalid or corrupt.', 'shiftdb' ) );
		}

		return $data;
	}

	public static function list_snapshots(): array {
		self::ensure_storage();
		$files = glob( trailingslashit( self::directory() ) . 'snapshot-*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$items = array();
		foreach ( $files as $file ) {
			$items[] = array(
				'name' => basename( $file ),
				'size' => (int) filesize( $file ),
				'time' => (int) filemtime( $file ),
			);
		}
		usort( $items, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $items;
	}

	public static function delete_snapshot( string $filename ): bool {
		$filename = sanitize_file_name( $filename );
		$path     = trailingslashit( self::directory() ) . $filename;
		$real_dir = realpath( self::directory() );
		$real     = realpath( $path );
		if ( false === $real_dir || false === $real || 0 !== strpos( $real, $real_dir ) || ! is_file( $real ) ) {
			return false;
		}
		return unlink( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
