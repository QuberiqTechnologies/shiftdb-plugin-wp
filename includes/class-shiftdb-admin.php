<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShiftDB_Admin {
	private const CAPABILITY = 'manage_options';
	private const NONCE      = 'shiftdb_admin_nonce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_shiftdb_dry_run', array( $this, 'ajax_dry_run' ) );
		add_action( 'wp_ajax_shiftdb_replace', array( $this, 'ajax_replace' ) );
		add_action( 'wp_ajax_shiftdb_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_shiftdb_delete_snapshot', array( $this, 'ajax_delete_snapshot' ) );
		add_action( 'wp_ajax_shiftdb_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_shiftdb_optimize', array( $this, 'ajax_optimize' ) );
		add_action( 'admin_post_shiftdb_export', array( $this, 'handle_export' ) );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'ShiftDB', 'shiftdb' ),
			__( 'ShiftDB', 'shiftdb' ),
			self::CAPABILITY,
			'shiftdb',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_shiftdb' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'shiftdb-admin', SHIFTDB_URL . 'assets/css/admin.css', array(), SHIFTDB_VERSION );
		wp_enqueue_script( 'shiftdb-admin', SHIFTDB_URL . 'assets/js/admin.js', array(), SHIFTDB_VERSION, true );
		wp_localize_script(
			'shiftdb-admin',
			'ShiftDBAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working' => __( 'Working...', 'shiftdb' ),
					'confirmReplace' => __( 'This will modify your database. A recovery snapshot will be created. Continue?', 'shiftdb' ),
					'confirmRestore' => __( 'Restore this recovery snapshot? Current values in affected rows will be overwritten.', 'shiftdb' ),
					'confirmDelete'  => __( 'Delete this recovery snapshot permanently?', 'shiftdb' ),
				),
			)
		);
	}

	public function render_page(): void {
		$this->authorize();
		$tables     = ShiftDB_Database::get_tables();
		$autoload   = ShiftDB_Database::autoload_options( 50 );
		$snapshots  = ShiftDB_Backup::list_snapshots();
		$history    = ShiftDB_Logger::recent( 50 );
		$cleanup    = ShiftDB_Cleanup::counts();
		$total_size = 0;
		foreach ( $tables as $table ) {
			$total_size += (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 );
		}
		?>
		<div class="wrap shiftdb-wrap">
			<div class="shiftdb-header">
				<div class="shiftdb-brand">
					<div class="shiftdb-brand-mark" aria-hidden="true">
						<img src="<?php echo esc_url( SHIFTDB_URL . 'assets/images/shiftdb-logo.svg' ); ?>" alt="">
					</div>
					<div class="shiftdb-brand-copy">
						<div class="shiftdb-title-row">
							<h1><?php esc_html_e( 'ShiftDB', 'shiftdb' ); ?></h1>
							<span class="shiftdb-product-label"><?php esc_html_e( 'Database Toolkit', 'shiftdb' ); ?></span>
						</div>
						<p><?php esc_html_e( 'Safe database search, replacement, recovery, cleanup, exports and diagnostics for WordPress.', 'shiftdb' ); ?></p>
					</div>
				</div>
				<div class="shiftdb-header-meta">
					<span class="shiftdb-version-label"><?php esc_html_e( 'Version', 'shiftdb' ); ?></span>
					<span class="shiftdb-version"><?php echo esc_html( SHIFTDB_VERSION ); ?></span>
				</div>
			</div>

			<div class="shiftdb-stats">
				<?php $this->stat_card( __( 'Database Size', 'shiftdb' ), size_format( $total_size, 2 ) ); ?>
				<?php $this->stat_card( __( 'Tables', 'shiftdb' ), number_format_i18n( count( $tables ) ) ); ?>
				<?php $this->stat_card( __( 'Recovery Points', 'shiftdb' ), number_format_i18n( count( $snapshots ) ) ); ?>
				<?php $this->stat_card( __( 'Autoload Entries', 'shiftdb' ), number_format_i18n( count( $autoload ) ) . '+' ); ?>
			</div>

			<nav class="shiftdb-tabs" aria-label="<?php esc_attr_e( 'ShiftDB sections', 'shiftdb' ); ?>">
				<button class="is-active" data-tab="search-replace"><?php esc_html_e( 'Search & Replace', 'shiftdb' ); ?></button>
				<button data-tab="database"><?php esc_html_e( 'Database', 'shiftdb' ); ?></button>
				<button data-tab="cleanup"><?php esc_html_e( 'Cleanup', 'shiftdb' ); ?></button>
				<button data-tab="recovery"><?php esc_html_e( 'Recovery', 'shiftdb' ); ?></button>
				<button data-tab="history"><?php esc_html_e( 'History', 'shiftdb' ); ?></button>
			</nav>

			<section class="shiftdb-panel is-active" data-panel="search-replace">
				<div class="shiftdb-card">
					<div class="shiftdb-card-head">
						<div><h2><?php esc_html_e( 'Safe Search & Replace', 'shiftdb' ); ?></h2><p><?php esc_html_e( 'Preview every operation before it changes data. Serialized values are handled safely and post GUIDs are protected by default.', 'shiftdb' ); ?></p></div>
					</div>
					<div class="shiftdb-grid-2">
						<label><?php esc_html_e( 'Search for', 'shiftdb' ); ?><input type="text" id="shiftdb-search" autocomplete="off" placeholder="https://staging.example.com"></label>
						<label><?php esc_html_e( 'Replace with', 'shiftdb' ); ?><input type="text" id="shiftdb-replace" autocomplete="off" placeholder="https://example.com"></label>
					</div>
					<div class="shiftdb-options-row">
						<label><input type="checkbox" id="shiftdb-case-sensitive" checked> <?php esc_html_e( 'Case sensitive', 'shiftdb' ); ?></label>
						<label><input type="checkbox" id="shiftdb-protect-guids" checked> <?php esc_html_e( 'Protect WordPress post GUIDs', 'shiftdb' ); ?></label>
					</div>
					<?php $this->render_table_selector( $tables, 'shiftdb-sr-tables' ); ?>
					<div class="shiftdb-actions">
						<button class="button button-secondary" id="shiftdb-dry-run"><?php esc_html_e( 'Run Dry Test', 'shiftdb' ); ?></button>
						<button class="button button-primary" id="shiftdb-run-replace" disabled><?php esc_html_e( 'Run Replacement', 'shiftdb' ); ?></button>
					</div>
					<div id="shiftdb-sr-result" class="shiftdb-result" aria-live="polite"></div>
				</div>
			</section>

			<section class="shiftdb-panel" data-panel="database">
				<div class="shiftdb-card">
					<div class="shiftdb-card-head"><div><h2><?php esc_html_e( 'Database Tables', 'shiftdb' ); ?></h2><p><?php esc_html_e( 'Inspect table health, size and row counts, then export or optimize selected WordPress tables.', 'shiftdb' ); ?></p></div></div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="shiftdb-export-form">
						<input type="hidden" name="action" value="shiftdb_export">
						<?php wp_nonce_field( 'shiftdb_export', 'shiftdb_export_nonce' ); ?>
						<div class="shiftdb-table-wrap"><table class="widefat striped"><thead><tr><th><input type="checkbox" class="shiftdb-check-all" data-target="db-table"></th><th><?php esc_html_e( 'Table', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Rows', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Engine', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Size', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Overhead', 'shiftdb' ); ?></th></tr></thead><tbody>
						<?php foreach ( $tables as $table ) : $name = (string) ( $table['Name'] ?? '' ); $size = (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 ); ?>
						<tr><td><input type="checkbox" class="db-table" name="tables[]" value="<?php echo esc_attr( $name ); ?>"></td><td><code><?php echo esc_html( $name ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) ( $table['Rows'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( (string) ( $table['Engine'] ?? '-' ) ); ?></td><td><?php echo esc_html( size_format( $size, 2 ) ); ?></td><td><?php echo esc_html( size_format( (int) ( $table['Data_free'] ?? 0 ), 2 ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table></div>
						<div class="shiftdb-actions"><button class="button" type="submit"><?php esc_html_e( 'Export Selected SQL', 'shiftdb' ); ?></button><button class="button" type="button" id="shiftdb-optimize"><?php esc_html_e( 'Optimize Selected', 'shiftdb' ); ?></button></div>
					</form>
					<div id="shiftdb-db-result" class="shiftdb-result" aria-live="polite"></div>
				</div>

				<div class="shiftdb-card">
					<div class="shiftdb-card-head"><div><h2><?php esc_html_e( 'Autoload Analyzer', 'shiftdb' ); ?></h2><p><?php esc_html_e( 'Largest autoloaded options first. This view is read-only to avoid accidental configuration damage.', 'shiftdb' ); ?></p></div></div>
					<div class="shiftdb-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Option', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Autoload', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Size', 'shiftdb' ); ?></th></tr></thead><tbody>
					<?php foreach ( $autoload as $row ) : ?>
					<tr><td><code><?php echo esc_html( (string) $row['option_name'] ); ?></code></td><td><?php echo esc_html( (string) $row['autoload'] ); ?></td><td><?php echo esc_html( size_format( (int) $row['bytes'], 2 ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table></div>
				</div>
			</section>

			<section class="shiftdb-panel" data-panel="cleanup">
				<div class="shiftdb-card">
					<div class="shiftdb-card-head"><div><h2><?php esc_html_e( 'Database Cleanup', 'shiftdb' ); ?></h2><p><?php esc_html_e( 'Remove common WordPress database clutter using core deletion functions where available.', 'shiftdb' ); ?></p></div></div>
					<div class="shiftdb-clean-grid">
						<?php $this->cleanup_item( 'revisions', __( 'Post Revisions', 'shiftdb' ), (int) $cleanup['revisions'] ); ?>
						<?php $this->cleanup_item( 'spam', __( 'Spam Comments', 'shiftdb' ), (int) $cleanup['spam'] ); ?>
						<?php $this->cleanup_item( 'trash', __( 'Trashed Comments', 'shiftdb' ), (int) $cleanup['ttrash'] ); ?>
						<?php $this->cleanup_item( 'expired_transients', __( 'Expired Transients', 'shiftdb' ), (int) $cleanup['transients'] ); ?>
					</div>
					<div class="shiftdb-actions"><button class="button button-primary" id="shiftdb-cleanup"><?php esc_html_e( 'Clean Selected', 'shiftdb' ); ?></button></div>
					<div id="shiftdb-clean-result" class="shiftdb-result" aria-live="polite"></div>
				</div>
			</section>

			<section class="shiftdb-panel" data-panel="recovery">
				<div class="shiftdb-card">
					<div class="shiftdb-card-head"><div><h2><?php esc_html_e( 'Recovery Points', 'shiftdb' ); ?></h2><p><?php esc_html_e( 'Search-and-replace operations create row-level recovery snapshots before changes are written.', 'shiftdb' ); ?></p></div></div>
					<?php if ( empty( $snapshots ) ) : ?>
						<div class="shiftdb-empty"><?php esc_html_e( 'No recovery points yet.', 'shiftdb' ); ?></div>
					<?php else : ?>
						<div class="shiftdb-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Snapshot', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Created', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Size', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Actions', 'shiftdb' ); ?></th></tr></thead><tbody>
						<?php foreach ( $snapshots as $snapshot ) : ?>
						<tr><td><code><?php echo esc_html( $snapshot['name'] ); ?></code></td><td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $snapshot['time'] ) ); ?></td><td><?php echo esc_html( size_format( $snapshot['size'], 2 ) ); ?></td><td><button class="button shiftdb-restore" data-file="<?php echo esc_attr( $snapshot['name'] ); ?>"><?php esc_html_e( 'Restore', 'shiftdb' ); ?></button> <button class="button-link-delete shiftdb-delete-snapshot" data-file="<?php echo esc_attr( $snapshot['name'] ); ?>"><?php esc_html_e( 'Delete', 'shiftdb' ); ?></button></td></tr>
						<?php endforeach; ?>
						</tbody></table></div>
					<?php endif; ?>
					<div id="shiftdb-recovery-result" class="shiftdb-result" aria-live="polite"></div>
				</div>
			</section>

			<section class="shiftdb-panel" data-panel="history">
				<div class="shiftdb-card">
					<div class="shiftdb-card-head"><div><h2><?php esc_html_e( 'Operation History', 'shiftdb' ); ?></h2><p><?php esc_html_e( 'A local audit trail of ShiftDB operations. Sensitive replacement values are not stored in the log.', 'shiftdb' ); ?></p></div></div>
					<div class="shiftdb-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Time', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Action', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Status', 'shiftdb' ); ?></th><th><?php esc_html_e( 'User', 'shiftdb' ); ?></th><th><?php esc_html_e( 'Details', 'shiftdb' ); ?></th></tr></thead><tbody>
					<?php foreach ( $history as $entry ) : $details = json_decode( (string) $entry['details'], true ); ?>
					<tr><td><?php echo esc_html( (string) $entry['created_at'] ); ?></td><td><code><?php echo esc_html( (string) $entry['action'] ); ?></code></td><td><?php echo esc_html( ucfirst( (string) $entry['status'] ) ); ?></td><td><?php echo esc_html( (string) $entry['user_id'] ); ?></td><td><?php echo esc_html( wp_json_encode( is_array( $details ) ? $details : array() ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table></div>
				</div>
			</section>
		</div>
		<?php
	}

	private function stat_card( string $label, string $value ): void {
		?><div class="shiftdb-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></div><?php
	}

	private function render_table_selector( array $tables, string $class ): void {
		?>
		<div class="shiftdb-table-selector">
			<div class="shiftdb-selector-head"><strong><?php esc_html_e( 'Tables', 'shiftdb' ); ?></strong><label><input type="checkbox" class="shiftdb-check-all" data-target="<?php echo esc_attr( $class ); ?>" checked> <?php esc_html_e( 'Select all', 'shiftdb' ); ?></label></div>
			<div class="shiftdb-table-list">
				<?php foreach ( $tables as $table ) : $name = (string) ( $table['Name'] ?? '' ); ?>
				<label><input type="checkbox" class="<?php echo esc_attr( $class ); ?>" value="<?php echo esc_attr( $name ); ?>" checked> <code><?php echo esc_html( $name ); ?></code></label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function cleanup_item( string $type, string $label, int $count ): void {
		?>
		<label class="shiftdb-clean-item"><input type="checkbox" class="shiftdb-clean-type" value="<?php echo esc_attr( $type ); ?>"><span><strong><?php echo esc_html( $label ); ?></strong><small><?php /* translators: %s: Number of cleanup items found. */ echo esc_html( sprintf( _n( '%s item found', '%s items found', $count, 'shiftdb' ), number_format_i18n( $count ) ) ); ?></small></span></label>
		<?php
	}

	private function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to use ShiftDB.', 'shiftdb' ) );
		}
	}

	private function ajax_authorize(): void {
		$this->authorize();
	}

	private function selected_tables(): array {
		check_ajax_referer( self::NONCE, 'nonce' );
		$tables = isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tables'] ) ) : array();
		return ShiftDB_Database::validate_tables( $tables );
	}

	public function ajax_dry_run(): void {
		$this->ajax_authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		$search = isset( $_POST['search'] ) ? sanitize_textarea_field( wp_unslash( $_POST['search'] ) ) : '';
		$replace = isset( $_POST['replace'] ) ? sanitize_textarea_field( wp_unslash( $_POST['replace'] ) ) : '';
		$case = ! empty( $_POST['case_sensitive'] );
		$result = ShiftDB_Database::dry_run( $search, $replace, $this->selected_tables(), $case );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
		}
		ShiftDB_Logger::add( 'dry_run', 'success', array( 'rows' => $result['total_rows'], 'replacements' => $result['total_replacements'] ) );
		wp_send_json_success( $result );
	}

	public function ajax_replace(): void {
		$this->ajax_authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		$search  = isset( $_POST['search'] ) ? sanitize_textarea_field( wp_unslash( $_POST['search'] ) ) : '';
		$replace = isset( $_POST['replace'] ) ? sanitize_textarea_field( wp_unslash( $_POST['replace'] ) ) : '';
		$case    = ! empty( $_POST['case_sensitive'] );
		$protect = ! empty( $_POST['protect_guids'] );
		$result  = ShiftDB_Database::replace( $search, $replace, $this->selected_tables(), $case, $protect );
		if ( is_wp_error( $result ) ) {
			ShiftDB_Logger::add( 'replace', 'error', array( 'code' => $result->get_error_code() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
		}
		ShiftDB_Logger::add( 'replace', 'success', array( 'rows' => $result['rows'], 'replacements' => $result['replacements'], 'snapshot' => $result['snapshot'] ) );
		wp_send_json_success( $result );
	}

	public function ajax_restore(): void {
		$this->ajax_authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		$file   = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$result = ShiftDB_Database::restore_snapshot( $file );
		if ( is_wp_error( $result ) ) {
			ShiftDB_Logger::add( 'restore', 'error', array( 'code' => $result->get_error_code() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
		}
		ShiftDB_Logger::add( 'restore', 'success', array( 'snapshot' => $file, 'rows' => $result['rows'] ) );
		wp_send_json_success( $result );
	}

	public function ajax_delete_snapshot(): void {
		$this->ajax_authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		if ( ! ShiftDB_Backup::delete_snapshot( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'Recovery snapshot could not be deleted.', 'shiftdb' ) ), 400 );
		}
		ShiftDB_Logger::add( 'delete_snapshot', 'success', array( 'snapshot' => $file ) );
		wp_send_json_success( array( 'message' => __( 'Recovery snapshot deleted.', 'shiftdb' ) ) );
	}

	public function ajax_cleanup(): void {
		$this->ajax_authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		$types  = isset( $_POST['types'] ) && is_array( $_POST['types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['types'] ) ) : array();
		$result = ShiftDB_Cleanup::cleanup( $types );
		ShiftDB_Logger::add( 'cleanup', 'success', $result );
		wp_send_json_success( $result );
	}

	public function ajax_optimize(): void {
		$this->ajax_authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		$result = ShiftDB_Cleanup::optimize_tables( $this->selected_tables() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
		}
		ShiftDB_Logger::add( 'optimize', 'success', array( 'tables' => array_keys( $result ) ) );
		wp_send_json_success( $result );
	}

	public function handle_export(): void {
		$this->authorize();
		check_admin_referer( 'shiftdb_export', 'shiftdb_export_nonce' );
		$tables = isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tables'] ) ) : array();
		$sql    = ShiftDB_Database::export_sql( $tables );
		if ( is_wp_error( $sql ) ) {
			wp_die( esc_html( $sql->get_error_message() ) );
		}
		ShiftDB_Logger::add( 'export', 'success', array( 'tables' => count( ShiftDB_Database::validate_tables( $tables ) ) ) );
		nocache_headers();
		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="shiftdb-' . gmdate( 'Ymd-His' ) . '.sql"' );
		echo $sql; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL download response, not HTML.
		exit;
	}
}
