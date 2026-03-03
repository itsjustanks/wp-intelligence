<?php
/**
 * Register custom REST API routes.
 *
 * @package wp-intelligence
 * @since   1.3.0
 */

namespace WPI\Visibility\RestApi;

defined('ABSPATH') || exit;

use WPI_Visibility_REST_Settings_Controller;
use WPI_Visibility_REST_Variables_Controller;

/**
 * Function to register our new routes from the controller.
 */
require_once WPI_BV_DIR . '/api/controllers/class-wpi-visibility-rest-settings-controller.php';
require_once WPI_BV_DIR . '/api/controllers/class-wpi-visibility-rest-variables-controller.php';

function register_routes() {
	$settings_controller = new WPI_Visibility_REST_Settings_Controller();
	$settings_controller->register_routes();
	$variables_controller = new WPI_Visibility_REST_Variables_Controller();
	$variables_controller->register_routes();
}
add_action('rest_api_init', __NAMESPACE__ . '\register_routes');
