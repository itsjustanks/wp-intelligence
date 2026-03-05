<?php
/**
 * Adds DataGlue visitor attribute visibility support.
 *
 * Requires an active DataGlue subscription (https://dataglue.io).
 * DataGlue's tracking script must be installed on the site for visitor
 * attributes to be available in browser storage.
 *
 * DataGlue stores visitor data in browser storage (localStorage, sessionStorage,
 * cookies). Since PHP cannot read localStorage/sessionStorage, this feature:
 *   1. Always passes server-side (returns true from the visibility filter).
 *   2. Injects a data attribute with serialized rules into the block markup.
 *   3. A companion frontend JS evaluates rules client-side and hides blocks.
 *
 * @package wp-intelligence
 * @since   3.9.0
 */

namespace WPI\Visibility\Frontend\VisibilityTests;

defined( 'ABSPATH' ) || exit;

use WP_HTML_Tag_Processor;
use function WPI\Visibility\Utils\is_control_enabled;

/**
 * Server-side pass-through for DataGlue visibility rules.
 *
 * Always returns true because DataGlue attributes live in browser storage
 * and cannot be evaluated server-side. The actual evaluation happens via
 * the companion frontend JS.
 *
 * @since 3.9.0
 *
 * @param boolean $is_visible The current value of the visibility test.
 * @param array   $settings   The core plugin settings.
 * @param array   $controls   The control set controls.
 * @return boolean            Always true (deferred to client-side).
 */
function data_glue_test( $is_visible, $settings, $controls ) {

	if ( ! $is_visible ) {
		return $is_visible;
	}

	if ( ! is_control_enabled( $settings, 'data_glue' ) ) {
		return true;
	}

	$control_atts = isset( $controls['dataGlue'] )
		? $controls['dataGlue']
		: null;

	if ( ! $control_atts ) {
		return true;
	}

	return true;
}
add_filter( 'wpi_visibility_control_set_is_block_visible', __NAMESPACE__ . '\data_glue_test', 15, 3 );

/**
 * Add CSS classes to blocks with DataGlue visibility rules.
 *
 * Blocks start hidden (via CSS) and are revealed by the frontend JS
 * after evaluation, preventing flash of content.
 *
 * @since 3.9.0
 *
 * @param array $custom_classes Existing custom classes.
 * @param array $settings       The plugin settings.
 * @param array $controls       The control set controls.
 * @return array                Updated classes array.
 */
function add_data_glue_classes( $custom_classes, $settings, $controls ) {

	if ( ! is_control_enabled( $settings, 'data_glue' ) ) {
		return $custom_classes;
	}

	$control_atts = isset( $controls['dataGlue'] )
		? $controls['dataGlue']
		: null;

	if ( ! $control_atts ) {
		return $custom_classes;
	}

	$rule_sets = isset( $control_atts['ruleSets'] )
		? $control_atts['ruleSets']
		: array();

	if ( ! is_array( $rule_sets ) || empty( $rule_sets ) ) {
		return $custom_classes;
	}

	foreach ( $rule_sets as $rule_set ) {
		$enable = isset( $rule_set['enable'] ) ? $rule_set['enable'] : true;
		$rules  = isset( $rule_set['rules'] ) ? $rule_set['rules'] : array();

		if ( $enable && ! empty( $rules ) ) {
			$custom_classes[] = 'block-visibility-glue-pending';
			break;
		}
	}

	return $custom_classes;
}
add_filter( 'wpi_visibility_control_set_add_custom_classes', __NAMESPACE__ . '\add_data_glue_classes', 10, 3 );

/**
 * Inject DataGlue visibility rules as a data attribute on rendered blocks.
 *
 * Runs after the main render_with_visibility filter (priority 20) so that
 * blocks already hidden by server-side rules are skipped.
 *
 * @since 3.9.0
 *
 * @param string $block_content The block frontend output.
 * @param array  $block         The block info and attributes.
 * @return string               Modified block content with data attribute.
 */
function inject_data_glue_attributes( $block_content, $block ) {

	if ( empty( $block_content ) ) {
		return $block_content;
	}

	$attributes = isset( $block['attrs']['blockVisibility'] )
		? $block['attrs']['blockVisibility']
		: null;

	if ( ! $attributes ) {
		return $block_content;
	}

	$settings = \WPI\Visibility\Frontend\get_visibility_settings_cached();

	if ( ! is_control_enabled( $settings, 'data_glue' ) ) {
		return $block_content;
	}

	$control_sets = isset( $attributes['controlSets'] )
		? $attributes['controlSets']
		: array();

	$glue_rules = array();

	foreach ( $control_sets as $control_set ) {
		$enable   = isset( $control_set['enable'] ) ? $control_set['enable'] : true;
		$controls = isset( $control_set['controls'] ) ? $control_set['controls'] : array();

		if ( ! $enable || empty( $controls ) ) {
			continue;
		}

		$control_atts = isset( $controls['dataGlue'] )
			? $controls['dataGlue']
			: null;

		if ( ! $control_atts ) {
			continue;
		}

		$rule_sets    = isset( $control_atts['ruleSets'] ) ? $control_atts['ruleSets'] : array();
		$hide_on      = isset( $control_atts['hideOnRuleSets'] ) ? $control_atts['hideOnRuleSets'] : false;

		if ( ! empty( $rule_sets ) ) {
			$glue_rules[] = array(
				'ruleSets'       => $rule_sets,
				'hideOnRuleSets' => $hide_on,
			);
		}
	}

	if ( empty( $glue_rules ) ) {
		return $block_content;
	}

	enqueue_data_glue_frontend();

	$tags = new WP_HTML_Tag_Processor( $block_content );

	if ( $tags->next_tag() ) {
		$tags->set_attribute(
			'data-glue-visibility',
			wp_json_encode( $glue_rules )
		);
	}

	return $tags->get_updated_html();
}
add_filter( 'render_block', __NAMESPACE__ . '\inject_data_glue_attributes', 20, 2 );

/**
 * Enqueue the DataGlue frontend evaluation script and anti-FOUC styles.
 *
 * Uses a static flag to ensure assets are only enqueued once per request.
 *
 * @since 3.9.0
 */
function enqueue_data_glue_frontend() {
	static $enqueued = false;

	if ( $enqueued ) {
		return;
	}

	$enqueued = true;

	wp_enqueue_script(
		'block-visibility-data-glue-frontend',
		WPI_BV_URL . 'assets/data-glue-frontend.js',
		array(),
		BLOCK_VISIBILITY_VERSION,
		array( 'strategy' => 'defer' )
	);

	wp_register_style(
		'block-visibility-data-glue-styles',
		false,
		array(),
		BLOCK_VISIBILITY_VERSION
	);
	wp_enqueue_style( 'block-visibility-data-glue-styles' );

	wp_add_inline_style(
		'block-visibility-data-glue-styles',
		'.block-visibility-glue-pending { display: none !important; }'
	);
}
