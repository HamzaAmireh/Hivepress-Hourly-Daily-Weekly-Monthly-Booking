<?php
/**
 * Scripts configuration.
 *
 * @package HivePress\Configs
 */

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'chartjs' => [
		'scope' => [ 'vendor_dashboard_page' ],
	],
    
    'marketplace_price_toggle' => [
        'handle'  => 'hivepress-marketplace-price-toggle',
        'src'     => hivepress()->get_url( 'marketplace' ) . '/assets/js/price-toggle.js',
        'version' => time(),
        'deps'    => ['jquery'],
        'scope'   => [ 'frontend', 'backend' ],
        'in_footer' => true,
    ],
];