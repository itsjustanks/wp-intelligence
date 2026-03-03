<?php
/**
 * Helper function for loading the asset file for the given script or style.
 *
 * @package wp-intelligence
 * @since   1.0.0
 */

namespace WPI\Visibility\Utils;

defined('ABSPATH') || exit;

/**
 * Loads the asset file for the given script or style.
 * Returns a default if the asset file is not found.
 *
 * @since 1.0.0
 *
 * @param string $filepath The name of the file without the extension.
 * @return array           The asset file contents.
 */
function get_asset_file( $filepath ) {
	$asset_path = WPI_BV_DIR . '/' . $filepath . '.asset.php';

	return file_exists( $asset_path )
		? include $asset_path
		: array(
			'dependencies' => array(),
			'version'      => BLOCK_VISIBILITY_VERSION,
		);
}
