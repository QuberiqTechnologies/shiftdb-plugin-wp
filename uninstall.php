<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally preserve operation history and recovery snapshots on uninstall.
// Users can remove generated backup files from the ShiftDB settings screen before uninstalling.
