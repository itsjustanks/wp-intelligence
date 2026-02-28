<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-woocommerce.php';

WPI_Module_Manager::register('woocommerce', [
  'title'       => __('WooCommerce Optimization', 'wp-intelligence'),
  'description' => __('Conditional asset loading and checkout field persistence.', 'wp-intelligence'),
  'icon'        => 'cart',
  'requires'    => 'WooCommerce',
  'boot'        => ['WPI_WooCommerce', 'boot'],
  'default'     => true,
]);
