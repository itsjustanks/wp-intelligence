<?php
/**
 * Conditionally renders each block based on its visibility settings.
 *
 * @package wp-intelligence
 * @since   1.0.0
 */

namespace WPI\Visibility\Frontend;

defined('ABSPATH') || exit;

use WP_HTML_Tag_Processor;
use function WPI\Visibility\Frontend\VisibilityTests\hide_block_test;
use function WPI\Visibility\Frontend\VisibilityTests\visibility_presets_test;
use function WPI\Visibility\Frontend\VisibilityTests\control_sets_test;
use function WPI\Visibility\Frontend\VisibilityTests\control_sets_custom_classes;

/**
 * Check if the given block type is disabled via the visibility settings.
 *
 * @since 1.0.0
 *
 * @param array $settings The plugin settings.
 * @param array $block    The block info and attributes.
 * @return boolean        Is the block disabled or not.
 */
function is_block_type_disabled( $settings, $block ) {
	$disabled_blocks = isset( $settings['disabled_blocks'] )
		? $settings['disabled_blocks']
		: false;

	if ( ! $disabled_blocks ) {
		return false;
	}

	if ( in_array( $block['blockName'], $disabled_blocks, true ) ) {
		return true;
	}

	return false;
}

/**
 * Check if the given block has visibility settings.
 *
 * @since 1.0.0
 *
 * @param array $block The block info and attributes.
 * @return boolean     Are there visibility settings or not.
 */
function has_visibility_settings( $block ) {
	if ( isset( $block['attrs']['blockVisibility'] ) ) {
		return true;
	}

	return false;
}

/**
 * Fetch and memoize visibility settings for current request.
 *
 * @since 3.7.2
 *
 * @return array
 */
function get_visibility_settings_cached() {
	static $settings_cache = null;

	if ( null !== $settings_cache ) {
		return $settings_cache;
	}

	$settings = get_option( 'block_visibility_settings' );
	$settings_cache = is_array( $settings ) ? $settings : array();

	return $settings_cache;
}

/**
 * Check if the given block has visibility settings.
 *
 * @since 2.3.1
 *
 * @param array $settings   The plugin settings.
 * @param array $attributes The block attributes.
 * @return boolean          Should the block be visible or not.
 */
function is_visible( $settings, $attributes ) {

	$is_visible = apply_filters(
		'wpi_visibility_is_block_visible',
		true,
		$settings,
		$attributes
	);

	$enable_local_controls =
		isset( $settings['visibility_controls']['general']['enable_local_controls'] )
			? $settings['visibility_controls']['general']['enable_local_controls']
			: true;

	if ( $is_visible && $enable_local_controls && isset( $attributes['controlSets'] ) ) {
		$is_visible = control_sets_test(
			$is_visible,
			$settings,
			$attributes['controlSets'],
			'local'
		);
	}

	return $is_visible;
}

/**
 * Add custom block classes.
 *
 * @since 2.4.1
 *
 * @param array $settings   The plugin settings.
 * @param array $attributes The block attributes.
 * @return array            Custom classes to be added on render.
 */
function add_custom_classes( $settings, $attributes ) {

	$custom_classes = apply_filters(
		'wpi_visibility_add_custom_classes',
		array(),
		$settings,
		$attributes
	);

	$enable_local_controls =
		isset( $settings['visibility_controls']['general']['enable_local_controls'] )
			? $settings['visibility_controls']['general']['enable_local_controls']
			: true;

	if ( $enable_local_controls && isset( $attributes['controlSets'] ) ) {
		$custom_classes = control_sets_custom_classes(
			$custom_classes,
			$settings,
			$attributes['controlSets'],
			'local'
		);
	}

	return $custom_classes;
}

/**
 * Append custom classes to the block frontend content.
 *
 * @since 3.6.0
 *
 * @param string $block_content   The block frontend output.
 * @param array  $content_classes Custom classes to be added in array form.
 * @return string                 Return the $block_content with the custom classes added.
 */
function append_content_classes( $block_content, $content_classes ) {

	if ( empty( $content_classes ) ) {
		return $block_content;
	}

	$class_string = implode( ' ', array_unique( $content_classes ) );

	$tags = new WP_HTML_Tag_Processor( $block_content );

	if ( $tags->next_tag() ) {
		$tags->add_class( $class_string );
	}

	return $tags->get_updated_html();
}

/**
 * Render block with visibility checks.
 *
 * @since 1.0.0
 *
 * @param string $block_content The block frontend output.
 * @param array  $block         The block info and attributes.
 * @return mixed                Return either the $block_content or nothing depending on visibility settings.
 */
function render_with_visibility( $block_content, $block ) {

	$attributes = $block['attrs']['blockVisibility'] ?? null;

	if ( ! $attributes ) {
		return $block_content;
	}

	$settings = get_visibility_settings_cached();

	if ( is_block_type_disabled( $settings, $block ) ) {
		return $block_content;
	}

	if ( ! hide_block_test( $settings, $attributes ) ) {
		return '';
	}

	if ( is_visible( $settings, $attributes ) ) {

		$content_classes = add_custom_classes( $settings, $attributes );

		if ( ! empty( $content_classes ) ) {
			$block_content = append_content_classes( $block_content, $content_classes );
		}

		return $block_content;
	} else {
		return '';
	}
}
add_filter( 'render_block', __NAMESPACE__ . '\render_with_visibility', 10, 2 );

/**
 * Check visibility for block-based widgets.
 *
 * @since 2.3.1
 *
 * @param array $instance The widget instance.
 * @return mixed          Return either the widget $instance or nothing depending on visibility settings.
 */
function render_block_widget_with_visibility( $instance ) {

	if ( strpos( wp_get_raw_referer(), '/wp-admin/widgets.php' ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' ) ) {
		return $instance;
	}

	if ( ! empty( $instance['content'] ) && has_blocks( $instance['content'] ) ) {
		$blocks = parse_blocks( $instance['content'] );

		$settings   = get_visibility_settings_cached();
		$attributes = isset( $blocks[0]['attrs']['blockVisibility'] )
			? $blocks[0]['attrs']['blockVisibility']
			: null;

		if (
			is_block_type_disabled( $settings, $blocks[0] ) ||
			! isset( $attributes )
		) {
			return $instance;
		}

		if ( ! hide_block_test( $settings, $attributes ) ) {
			return false;
		}

		if ( is_visible( $settings, $attributes ) ) {
			return $instance;
		} else {
			return false;
		}
	}

	return $instance;
}
add_filter( 'widget_display_callback', __NAMESPACE__ . '\render_block_widget_with_visibility' );

// Core visibility tests.
require_once WPI_BV_DIR . '/features/control-sets.php';
require_once WPI_BV_DIR . '/features/hide-block.php';
require_once WPI_BV_DIR . '/features/browser-device.php';
require_once WPI_BV_DIR . '/features/cookie.php';
require_once WPI_BV_DIR . '/features/date-time.php';
require_once WPI_BV_DIR . '/features/location.php';
require_once WPI_BV_DIR . '/features/metadata.php';
require_once WPI_BV_DIR . '/features/query-string.php';
require_once WPI_BV_DIR . '/features/referral-source.php';
require_once WPI_BV_DIR . '/features/screen-size.php';
require_once WPI_BV_DIR . '/features/url-path.php';
require_once WPI_BV_DIR . '/features/user-role.php';
require_once WPI_BV_DIR . '/features/visibility-presets.php';

// Integration tests.
require_once WPI_BV_DIR . '/integrations/acf.php';
require_once WPI_BV_DIR . '/integrations/edd/edd.php';
require_once WPI_BV_DIR . '/integrations/wp-fusion.php';
require_once WPI_BV_DIR . '/integrations/woocommerce/woocommerce.php';

// Utility functions for tests.
require_once WPI_BV_DIR . '/utils/value-compare-helpers.php';
require_once WPI_BV_DIR . '/utils/create-date-time.php';
require_once WPI_BV_DIR . '/utils/is-control-enabled.php';
require_once WPI_BV_DIR . '/utils/get-setting.php';
