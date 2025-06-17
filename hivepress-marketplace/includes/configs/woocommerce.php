<?php
/**
 * WooCommerce configuration.
 *
 * @package HivePress\Configs
 */

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'item_meta' => [
		'price_tier'     => [
			'label'      => esc_html__( 'Tier', 'hivepress-marketplace' ),
			'type'       => 'text',
			'max_length' => 2048,
		],

		'quantity_prices' => [
			'type'      => 'array',
			'_external' => true,
		],

		'price_extras'   => [
			'label'      => esc_html__( 'Extras', 'hivepress-marketplace' ),
			'type'       => 'text',
			'max_length' => 10240,
		],

		'price_change'   => [
			'type'      => 'currency',
			'min_value' => 0,
		],

		'commission_fee' => [
			'type'      => 'currency',
			'min_value' => 0,
		],

		'revision_limit' => [
			'type'      => 'number',
			'min_value' => 0,
		],

		'quantity'       => [
			'type'      => 'number',
			'min_value' => 1,
		],

		'enable_price_hour' => [
			'type'      => 'boolean',
			'_external' => true,
		],

		'price_hour' => [
			'type'      => 'float',
			'_external' => true,
		],
	],

	'meta_keys' => [
		'price' => [
			'type'      => 'float',
			'_external' => true,
		],

		'price_7' => [
			'type'      => 'float',
			'_external' => true,
		],

		'price_30' => [
			'type'      => 'float',
			'_external' => true,
		],

		'price_custom' => [
			'type'      => 'float',
			'_external' => true,
		],

		'quantity' => [
			'type'       => 'int',
			'min_value'  => 1,
			'_external'  => true,
		],
	],
];
