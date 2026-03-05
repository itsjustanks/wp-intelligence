<?php
/**
 * Add assets for the block editor.
 *
 * @package wp-intelligence
 * @since   1.0.0
 */

namespace WPI\Visibility\Admin;

defined('ABSPATH') || exit;

use function WPI\Visibility\Utils\get_asset_file;

/**
 * Enqueue plugin specific editor scripts.
 *
 * @since 1.0.0
 */
function enqueue_editor_scripts() {

	$asset_file = get_asset_file('assets/block-visibility-editor');

	$dependencies = $asset_file['dependencies'];

	if ('widgets' === get_current_screen()->id) {
		$dependencies = array_diff($dependencies, ['wp-editor', 'wp-edit-post', 'wp-edit-site']);
	}

	wp_enqueue_script(
		'block-visibility-editor-scripts',
		WPI_BV_URL . 'assets/block-visibility-editor.js',
		array_merge($dependencies, ['wp-api', 'wp-core-data']),
		$asset_file['version'],
		false
	);

	$is_full_control_mode = 'const blockVisibilityFullControlMode = ' . wp_json_encode(get_plugin_setting('enable_full_control_mode', true)) . ';';

	wp_add_inline_script(
		'block-visibility-editor-scripts',
		$is_full_control_mode,
		'before'
	);

	wp_enqueue_script(
		'block-visibility-data-glue-editor',
		WPI_BV_URL . 'assets/data-glue-editor.js',
		array( 'block-visibility-editor-scripts', 'wp-hooks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-primitives' ),
		BLOCK_VISIBILITY_VERSION,
		false
	);
}
add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_scripts');

/**
 * Enqueue plugin specific editor styles.
 *
 * @since 2.0.0
 */
function enqueue_editor_styles() {

	$asset_file = get_asset_file('assets/block-visibility-editor-styles');

	wp_enqueue_style(
		'block-visibility-editor-styles',
		WPI_BV_URL . 'assets/block-visibility-editor-styles.css',
		[],
		$asset_file['version']
	);
}
add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_styles');

/**
 * Enqueue block specific editor styles (contextual indicators, opacity, etc.)
 *
 * @since 3.4.0
 */
function enqueue_block_editor_styles() {

	if (get_plugin_setting('enable_contextual_indicators', true)) {

		$asset_file = get_asset_file('assets/block-visibility-contextual-indicator-styles');

		wp_enqueue_style(
			'block-visibility-contextual-indicator-styles',
			WPI_BV_URL . 'assets/block-visibility-contextual-indicator-styles.css',
			[],
			$asset_file['version']
		);

		$custom_color = get_plugin_setting('contextual_indicator_color');

		if ($custom_color) {
			$inline_style = '.block-visibility__has-visibility:not(.is-selected):not(.has-child-selected), .block-visibility__has-visibility.components-placeholder.components-placeholder:not(.is-selected):not(.has-child-selected), .block-visibility__has-visibility.components-placeholder:not(.is-selected):not(.has-child-selected) { outline-color: ' . $custom_color . ' } .block-visibility__has-visibility:not(.is-selected):not(.has-child-selected)::after { background-color: ' . $custom_color . ' }';

			wp_add_inline_style(
				'block-visibility-contextual-indicator-styles',
				$inline_style
			);
		}
	}

	if (get_plugin_setting('enable_block_opacity', true)) {

		$block_opacity = get_plugin_setting('block_opacity');

		if ($block_opacity) {
			$opacity = intval($block_opacity) * 0.01;

			$excluded_blocks = ['paragraph', 'heading', 'verse'];

			$excluded_blocks = apply_filters(
				'wpi_visibility_exclude_blocks_from_contextual_opacity',
				$excluded_blocks
			);

			$excluded_blocks_selectors = '';

			foreach ($excluded_blocks as $block) {
				$excluded_blocks_selectors .= ':not(.wp-block-' . $block . ')';
			}

			$inline_style = '.block-visibility__has-visibility:not(.is-selected):not(.has-child-selected)' . $excluded_blocks_selectors . ' > *:not(.wp-block-cover__background) { opacity: ' . $opacity . ' }';

			wp_add_inline_style(
				'block-visibility-contextual-indicator-styles',
				$inline_style
			);
		}
	}
}
add_action('enqueue_block_assets', __NAMESPACE__ . '\enqueue_block_editor_styles');

/**
 * Fetch the value of the given plugin setting.
 *
 * @since 2.4.0
 *
 * @param string $setting    The setting name.
 * @param string $is_boolean Is the setting value boolean.
 * @return mixed Returns boolean or the setting value.
 */
function get_plugin_setting($setting, $is_boolean = false) {
	$settings = get_option('block_visibility_settings');

	if (isset($settings['plugin_settings'][$setting])) {
		if ($settings['plugin_settings'][$setting]) {
			return $is_boolean ? true : $settings['plugin_settings'][$setting];
		}
	}

	return false;
}
