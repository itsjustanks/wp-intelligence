<?php
/**
 * Enqueue visibility settings assets on the main WP Intelligence settings page.
 *
 * @package wp-intelligence
 * @since   1.0.0
 */

namespace WPI\Visibility\Admin;

defined('ABSPATH') || exit;

use WP_Block_Type_Registry;
use function WPI\Visibility\Utils\get_asset_file;

/**
 * Enqueue settings page scripts and styles.
 *
 * @since 1.0.0
 */
function enqueue_settings_assets() {

	// @codingStandardsIgnoreLine
	$page = isset($_GET['page']) ? $_GET['page'] : '';
	// @codingStandardsIgnoreLine
	$tab  = isset($_GET['tab'])  ? $_GET['tab']  : '';

	if ($page !== 'wp-intelligence' || $tab !== 'visibility') {
		return;
	}

	$asset_file = get_asset_file('assets/block-visibility-settings');

	wp_enqueue_script(
		'block-visibility-setting-scripts',
		WPI_BV_URL . 'assets/block-visibility-settings.js',
		array_merge($asset_file['dependencies'], ['wp-api', 'wp-core-data']),
		$asset_file['version'],
		true
	);

	$asset_file = get_asset_file('assets/block-visibility-setting-styles');

	wp_enqueue_style(
		'block-visibility-setting-styles',
		WPI_BV_URL . 'assets/block-visibility-setting-styles.css',
		['wp-edit-blocks'],
		$asset_file['version']
	);

	wp_add_inline_style('block-visibility-setting-styles', '
		#block-visibility__plugin-settings { margin-left: 0; }
		#block-visibility__plugin-settings .masthead,
		#block-visibility__plugin-settings .footer,
		#block-visibility__plugin-settings .ads-container { display: none !important; }
		#block-visibility__plugin-settings .setting-tabs .components-tab-panel__tab-content {
			flex-direction: column;
		}
		#block-visibility__plugin-settings .setting-tabs .components-tab-panel__tab-content .inner-container {
			max-width: 100%;
		}
		#block-visibility__plugin-settings .setting-tabs .components-tab-panel__tabs {
			border-radius: 10px;
			overflow: hidden;
		}
		#block-visibility__plugin-settings .setting-tabs .setting-tabs__setting-panels .settings-panel .settings-panel__header {
			border-radius: 10px 10px 0 0;
		}
		#block-visibility__plugin-settings .setting-tabs .setting-tabs__setting-panels .settings-panel .settings-panel__container {
			border-radius: 0 0 10px 10px;
		}
		#block-visibility__plugin-settings .setting-tabs .setting-tabs__setting-panels .settings-panel .settings-panel__upsell {
			display: none !important;
		}
		#block-visibility__plugin-settings .setting-tabs__block-manager .block-manager__block-category {
			border-radius: 10px;
			overflow: hidden;
		}
	');

	$block_categories = [];

	$post_or_context = get_post();
	if (! $post_or_context && class_exists('WP_Block_Editor_Context')) {
		$post_or_context = new \WP_Block_Editor_Context();
	}

	if ($post_or_context) {
		if (function_exists('gutenberg_get_block_categories')) {
			$block_categories = gutenberg_get_block_categories($post_or_context);
		} elseif (function_exists('get_block_categories')) {
			$block_categories = get_block_categories($post_or_context);
		}
	}

	wp_add_inline_script(
		'wp-blocks',
		sprintf(
			'wp.blocks.setCategories( %s );',
			wp_json_encode($block_categories)
		),
		'after'
	);

	$block_registry = WP_Block_Type_Registry::get_instance();

	foreach ($block_registry->get_all_registered() as $block_name => $block_type) {
		if (! empty($block_type->editor_script_handles)) {
			foreach ($block_type->editor_script_handles as $handle) {
				wp_enqueue_script($handle);
			}
		}
	}
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_settings_assets');
