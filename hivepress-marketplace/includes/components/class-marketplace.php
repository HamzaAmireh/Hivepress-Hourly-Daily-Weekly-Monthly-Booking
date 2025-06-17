<?php
/**
 * Marketplace component.
 *
 * @package HivePress\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Blocks;
use HivePress\Emails;
use HivePress\Fields;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Marketplace component class.
 *
 * @class Marketplace
 */
final class Marketplace extends Component {

	/**
	 * Cart fees.
	 *
	 * @var array
	 */
	protected $fees = [];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Activate extension.
		add_action( 'hivepress/v1/activate', [ $this, 'activate_extension' ] );

		// Complete orders.
		add_action( 'hivepress/v1/events/hourly', [ $this, 'complete_orders' ] );

		// Manage orders.
		add_action( 'woocommerce_new_order', [ $this, 'create_order' ], 10, 2 );

		add_filter( 'woocommerce_payment_complete_order_status', [ $this, 'set_order_status' ], 100, 3 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'update_order_status' ], 10, 4 );

		add_filter( 'hivepress/v1/models/order/meta', [ $this, 'set_order_type' ] );
		add_action( 'hivepress/v1/models/order/sync', [ $this, 'sync_order_posts' ] );

		// Manage refunds.
		add_action( 'woocommerce_order_refunded', [ $this, 'create_refund' ], 10, 2 );
		add_action( 'woocommerce_refund_deleted', [ $this, 'delete_refund' ], 10, 2 );

		// Manage cart.
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'set_cart_totals' ] );
		add_action( 'woocommerce_after_calculate_totals', [ $this, 'update_cart_totals' ] );
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'update_cart_fees' ] );

		// Manage products.
		add_action( 'woocommerce_product_set_stock', [ $this, 'update_product_quantity' ] );
		add_action( 'woocommerce_no_stock', [ $this, 'sellout_product' ] );

		// Manage requirements.
		if ( get_option( 'hp_order_allow_requirements' ) ) {
			add_action( 'woocommerce_after_order_notes', [ $this, 'render_requirement_fields' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'validate_requirement_fields' ] );
			add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'add_requirement_note' ] );
		}

		// Manage listings.
		add_action( 'hivepress/v1/models/listing/create', [ $this, 'update_listing' ], 10, 2 );
		add_action( 'hivepress/v1/models/listing/update', [ $this, 'update_listing' ], 10, 2 );
		add_action( 'hivepress/v1/models/listing/delete', [ $this, 'delete_listing' ], 10, 2 );

		// Add listing attributes.
		add_filter( 'hivepress/v1/models/listing/attributes', [ $this, 'add_listing_attributes' ] );

		// Alter listing fields.
		add_filter( 'hivepress/v1/models/listing/fields', [ $this, 'alter_listing_fields' ], 200, 2 );

		// Add vendor fields.
		add_filter( 'hivepress/v1/models/vendor', [ $this, 'add_vendor_fields' ] );

		// Validate models.
		add_filter( 'hivepress/v1/models/message/errors', [ $this, 'validate_message' ], 10, 2 );
		add_filter( 'hivepress/v1/models/review/errors', [ $this, 'validate_review' ], 10, 2 );

		// Alter forms.
		add_filter( 'hivepress/v1/forms/listing_buy', [ $this, 'alter_listing_buy_form' ], 10, 2 );
		add_filter( 'hivepress/v1/forms/order_reject', [ $this, 'alter_order_reject_form' ], 10, 2 );
		add_filter( 'hivepress/v1/forms/order_refund', [ $this, 'alter_order_refund_form' ], 10, 2 );

		if ( is_admin() ) {

			// Manage admin columns.
			add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_admin_columns' ] );
			add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_order_admin_columns' ], 10, 2 );

			if ( ! get_option( 'hp_payout_system' ) ) {
				add_filter( 'manage_hp_vendor_posts_columns', [ $this, 'add_vendor_admin_columns' ] );
				add_action( 'manage_hp_vendor_posts_custom_column', [ $this, 'render_vendor_admin_columns' ], 10, 2 );
			}

			// Render order details.
			add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_order_admin_details' ] );
			add_action( 'woocommerce_order_actions_end', [ $this, 'render_order_admin_links' ] );

			// Alter order statuses.
			add_filter( 'wc_order_statuses', [ $this, 'alter_order_statuses' ] );

			// Alter settings.
			add_filter( 'hivepress/v1/settings', [ $this, 'alter_settings' ] );
		} else {

			// Set request context.
			add_filter( 'hivepress/v1/components/request/context', [ $this, 'set_request_context' ], 100 );

			// Redirect pages.
			add_action( 'template_redirect', [ $this, 'redirect_pages' ] );

			// Add order class.
			add_filter( 'body_class', [ $this, 'add_order_class' ] );

			// Render order page.
			add_action( 'woocommerce_account_content', [ $this, 'render_order_header' ], 9 );
			add_action( 'woocommerce_account_content', [ $this, 'render_order_footer' ], 99 );

			add_action( 'woocommerce_my_account_my_orders_column_order-status', [ $this, 'render_order_status' ] );

			// Alter menus.
			add_filter( 'hivepress/v1/menus/user_account', [ $this, 'alter_user_account_menu' ], 100 );

			// Alter templates.
			add_filter( 'wc_get_template', [ $this, 'alter_order_edit_page' ], 10, 2 );
			add_action( 'woocommerce_order_details_before_order_table', [ $this, 'alter_order_view_page' ] );

			// Alter order totals.
			add_filter( 'woocommerce_get_order_item_totals', [ $this, 'alter_order_totals' ], 10, 2 );
		}

		// Alter templates.
		add_filter( 'hivepress/v1/templates/listing_view_page', [ $this, 'alter_listing_view_page' ] );
		add_filter( 'hivepress/v1/templates/listing_view_page/blocks', [ $this, 'alter_listing_view_page_blocks' ], 10, 2 );

		add_filter( 'hivepress/v1/templates/listing_view_block', [ $this, 'alter_message_view_block' ], 100 );
		add_filter( 'hivepress/v1/templates/listing_view_page', [ $this, 'alter_message_view_block' ], 100 );
		add_filter( 'hivepress/v1/templates/vendor_view_block', [ $this, 'alter_message_view_block' ], 100 );
		add_filter( 'hivepress/v1/templates/vendor_view_page', [ $this, 'alter_message_view_block' ], 100 );


		parent::__construct( $args );
	}

	/**
	 * Checks if the listing is sellable.
	 *
	 * @param  object $listing Listing object.
	 * @return bool
	 */
	public function is_sellable( $listing ) {
		$sellable = true;

		// Get category IDs.
		$category_ids = array_filter( (array) get_option( 'hp_listing_sale_categories' ) );

		if ( $category_ids ) {
			if ( $listing->get_categories__id() ) {

				// Get child category IDs.
				foreach ( $category_ids as $category_id ) {
					$category_ids = array_merge( $category_ids, get_term_children( $category_id, 'hp_listing_category' ) );
				}

				// Check listing.
				if ( ! array_intersect( (array) $listing->get_categories__id(), $category_ids ) ) {
					$sellable = false;
				}
			} else {
				$sellable = false;
			}
		}

		return $sellable;
	}

	/**
	 * Calculates the best price combination for a given quantity.
	 *
	 * @param int    $quantity Total quantity needed.
	 * @param float  $regular_price Price for 1 item.
	 * @param float  $price_7 Price for 7 items package.
	 * @param float  $price_30 Price for 30 items package.
	 * @return array Price calculation details.
	 */
	protected function calculate_package_price($quantity, $regular_price, $price_7, $price_30, $listing = null) {
		// Check if custom price exists and return it directly
		if ($listing && $listing->get_price_custom()) {
			return $listing->get_price_custom();
		}
		
		// Check if hourly pricing is enabled
		if ($listing && $listing->get_enable_price_hour()) {
			// Use hourly pricing for calculation
			$hourly_price = $listing->get_price_hour();
			
			if ($hourly_price) {
				// Calculate total based on hourly rate
				$total_price = $quantity * $hourly_price;
				
				// Return hourly pricing package
				return [
					'qty_hour' => $quantity,
					'total_price' => $total_price,
					'description' => sprintf(
						esc_html__('Hourly price: %s × %d hours', 'hivepress-marketplace'),
						hivepress()->woocommerce->format_price($hourly_price),
						$quantity
					)
				];
			}
		}
		
		// Continue with existing day/week/month pricing logic
		$packages = [
			'qty_30' => 0,
			'qty_7' => 0,
			'qty_1' => 0,
			'total_price' => 0,
			'description' => ''
		];

		// If quantity is less than 7, use regular price
		if ($quantity < 7) {
			$packages['qty_1'] = $quantity;
			$packages['total_price'] = $quantity * $regular_price;
			$packages['description'] = sprintf(
				esc_html__('Regular price: %s × %d days', 'hivepress-marketplace'),
				hivepress()->woocommerce->format_price($regular_price),
				$quantity
			);
			return $packages;
		}

		// Calculate packages of 30 if available
		if ($price_30 && $quantity >= 30) {
			$packages['qty_30'] = floor($quantity / 30);
			$quantity = $quantity % 30;
		}

		// Calculate packages of 7 for remaining quantity
		if ($price_7 && $quantity >= 7) {
			$packages['qty_7'] = floor($quantity / 7);
			$quantity = $quantity % 7;
		}

		// Remaining individual items
		$packages['qty_1'] = $quantity;

		// Calculate total price
		$packages['total_price'] = ($packages['qty_30'] * $price_30) + 
								 ($packages['qty_7'] * $price_7) + 
								 ($packages['qty_1'] * $regular_price);

		// Build description
		$parts = [];
		if ($packages['qty_30'] > 0) {
			$parts[] = sprintf(
				esc_html__('%d × 30 days package (%s)', 'hivepress-marketplace'),
				$packages['qty_30'],
				hivepress()->woocommerce->format_price($price_30)
			);
		}
		if ($packages['qty_7'] > 0) {
			$parts[] = sprintf(
				esc_html__('%d × 7 days package (%s)', 'hivepress-marketplace'),
				$packages['qty_7'],
				hivepress()->woocommerce->format_price($price_7)
			);
		}
		if ($packages['qty_1'] > 0) {
			$parts[] = sprintf(
				esc_html__('%d × regular price (%s)', 'hivepress-marketplace'),
				$packages['qty_1'],
				hivepress()->woocommerce->format_price($regular_price)
			);
		}
		$packages['description'] = implode(' + ', $parts);

		return $packages;
	}

	/**
	 * Adds listing to cart.
	 *
	 * @param object $listing Listing object.
	 * @param array  $args Form arguments.
	 * @param array  $meta Cart meta.
	 * @return bool
	 */
	public function add_to_cart( $listing, $args = [], $meta = [] ) {
		// Get product.
		$product = hivepress()->woocommerce->get_related_product( $listing->get_id() );

		if ( ! $product || ($product->get_status() !== 'publish' && $product->get_status() !== 'private') ) {
			return false;
		}

		// DIRECT META ACCESS - Get custom price directly from database
		$custom_price = get_post_meta( $listing->get_id(), 'hp_price_custom', true );
		
		// Filter cart.
		$cart = apply_filters(
			'hivepress/v1/models/listing/cart',
			[
				'args' => $args,
				'meta' => $meta,
			],
			$listing
		);

		$args = $cart['args'];
		$meta = $cart['meta'];

		// Get quantity.
		$quantity = max( 1, absint( hp\get_array_value( $args, '_quantity' ) ) );

		// Get meta.
		$meta = array_merge(
			[
				'quantity'     => $quantity,
				'fees'         => [],
				'price_tier'   => null,
				'price_extras' => [],
				'price_change' => 0,
			],
			$meta
		);

		// Check for custom price override - using the direct meta value
		if ( ! empty( $custom_price ) && is_numeric( $custom_price ) ) {
			// Set the custom price directly
			$meta['price'] = floatval( $custom_price );
			$meta['price_tier'] = esc_html__( 'Custom Price', 'hivepress-marketplace' );
			$meta['price_custom'] = floatval( $custom_price );
		} else {
			// Standard pricing calculation
			// Get prices
			$regular_price = $listing->get_price();
			$price_7 = $listing->get_price_7();
			$price_30 = $listing->get_price_30();

			// Check if hourly pricing is enabled
			if ($listing->get_enable_price_hour()) {
				// Use hourly price for calculation (passing hourly price instead of regular price)
				$hourly_price = $listing->get_price_hour();
				$price_calc = [
					'total_price' => $quantity * $hourly_price,
					'description' => sprintf(
						esc_html__('Hourly price: %s × %d hours', 'hivepress-marketplace'),
						hivepress()->woocommerce->format_price($hourly_price),
						$quantity
					)
				];
			} else {
				// Calculate best price combination with regular pricing tiers
				$price_calc = $this->calculate_package_price($quantity, $regular_price, $price_7, $price_30, $listing);
			}

			// Set price and description
			$meta['price'] = $price_calc['total_price'];
			$meta['price_tier'] = $price_calc['description'];
		}

		// Override WooCommerce quantity multiplication
		$meta['quantity'] = 1;
		$meta['_display_quantity'] = $quantity;

		if ( get_option( 'hp_listing_allow_price_extras' ) && !$listing->get_price_custom() ) {
			// Get extras.
			$extras = [];

			if ( $meta['price_extras'] ) {
				$extras = $meta['price_extras'];
				$meta['price_extras'] = [];
			} elseif ( $listing->get_price_extras() ) {
				$extra_ids = hp\get_array_value( $args, '_extras', [] );

				foreach ( $listing->get_price_extras() as $index => $item ) {
					if ( hp\get_array_value( $item, 'required' ) ) {
						$extra_ids[] = $index;
					}
				}

				if ( $extra_ids ) {
					$extras = array_intersect_key( $listing->get_price_extras(), array_flip( array_map( 'absint', (array) $extra_ids ) ) );
				}
			}

			if ( $extras ) {
				// Set meta.
				foreach ( $extras as $extra ) {
					$extra_type  = hp\get_array_value( $extra, 'type' );
					$extra_price = floatval( $extra['price'] );

					if ( $meta['quantity'] > 1 && ( ! $extra_type || 'per_quantity' === $extra_type ) ) {
						$extra_price *= $meta['quantity'];
					}

					if ( in_array( $extra_type, [ 'per_quantity', 'per_order' ], true ) ) {
						$meta['fees'][] = [
							'name'    => $extra['name'],
							'amount'  => $extra_price,
							'taxable' => (bool) get_option( 'hp_listing_tax_price_extras' ),
						];
					} else {
						/* translators: 1: extra name, 2: extra price. */
						$meta['price_extras'][] = esc_html( sprintf( _x( '%1$s (%2$s)', 'price extra format', 'hivepress-marketplace' ), $extra['name'], hivepress()->woocommerce->format_price( $extra_price ) ) );
						$meta['price_change']  += $extra_price;
					}
				}

				$meta['price_extras'] = implode( ', ', $meta['price_extras'] );
			}
		}

		// Add discounts (only if not using custom price)
		if ( !$listing->get_price_custom() && get_option( 'hp_listing_allow_discounts' ) && $listing->get_discounts() ) {
			foreach ( wp_list_sort( $listing->get_discounts(), 'quantity', 'DESC' ) as $discount ) {
				if ( $discount['quantity'] <= $quantity ) {
					$meta['fees'][] = [
						'name'   => esc_html__( 'Discount', 'hivepress-marketplace' ),
						'amount' => -floatval( $discount['amount'] ),
					];

					break;
				}
			}
		}

		// Add fees.
		if ( isset( $meta['fees']['direct_payment'] ) ) {
			$this->fees['direct_payment'] = $meta['fees']['direct_payment'];
			unset( $meta['fees']['direct_payment'] );
		}

		// Set revisions.
		if ( get_option( 'hp_order_limit_revisions' ) && $listing->get_revision_limit() ) {
			$meta['revision_limit'] = $listing->get_revision_limit();
		}

		// Set vendor.
		if ( $listing->get_vendor__id() ) {
			$meta['vendor'] = $listing->get_vendor__id();
		}

		// Filter meta.
		$meta = array_filter( array_combine( hp\prefix( array_keys( $meta ) ), $meta ) );

		// Load cart.
		if ( is_null( WC()->cart ) ) {
			wc_load_cart();
		}

		// Empty cart.
		WC()->cart->empty_cart();

		return WC()->cart->add_to_cart( $product->get_id(), 1, 0, [], $meta );
	}

	/**
	 * Updates vendor balance.
	 *
	 * @param mixed $vendor Vendor ID or object.
	 */
	public function update_vendor_balance( $vendor ) {
		global $wpdb;

		// Get vendor.
		if ( ! is_object( $vendor ) ) {
			$vendor = Models\Vendor::query()->get_by_id( $vendor );

			if ( ! $vendor ) {
				return;
			}
		}

		// Delete cached statistics.
		hivepress()->cache->delete_post_cache( $vendor->get_id(), 'statistics' );

		// Check settings.
		if ( get_option( 'hp_payout_system' ) ) {
			return;
		}

		// Calculate profit.
		if ( get_option( 'hp_vendor_include_taxes' ) ) {
			$profit = floatval(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT SUM(totals.meta_value*rates.meta_value-IFNULL(fees.meta_value, 0))
						FROM {$wpdb->posts} AS orders
						INNER JOIN {$wpdb->postmeta} AS totals ON orders.ID = totals.post_id
						INNER JOIN {$wpdb->postmeta} AS rates ON orders.ID = rates.post_id
						LEFT JOIN {$wpdb->postmeta} AS fees ON orders.ID = fees.post_id AND fees.meta_key = %s
						WHERE orders.post_type IN(%s, %s) AND orders.post_status IN(%s, %s)
						AND totals.meta_key = %s AND rates.meta_key = %s
						AND orders.post_author = %d",
						'hp_commission_fee',
						'shop_order',
						'shop_order_refund',
						'wc-completed',
						'wc-refunded',
						'_order_total',
						'hp_commission_rate',
						$vendor->get_user__id()
					)
				)
			);
		} else {
			$profit = floatval(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT SUM((totals.meta_value-taxes.meta_value)*rates.meta_value-IFNULL(fees.meta_value, 0))
						FROM {$wpdb->posts} AS orders
						INNER JOIN {$wpdb->postmeta} AS totals ON orders.ID = totals.post_id
						INNER JOIN {$wpdb->postmeta} AS taxes ON orders.ID = taxes.post_id
						INNER JOIN {$wpdb->postmeta} AS rates ON orders.ID = rates.post_id
						LEFT JOIN {$wpdb->postmeta} AS fees ON orders.ID = fees.post_id AND fees.meta_key = %s
						WHERE orders.post_type IN(%s, %s) AND orders.post_status IN(%s, %s)
						AND totals.meta_key = %s AND taxes.meta_key = %s AND rates.meta_key = %s
						AND orders.post_author = %d",
						'hp_commission_fee',
						'shop_order',
						'shop_order_refund',
						'wc-completed',
						'wc-refunded',
						'_order_total',
						'_order_tax',
						'hp_commission_rate',
						$vendor->get_user__id()
					)
				)
			);
		}

		// Calculate payout.
		$payout = floatval(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(meta_value)
					FROM {$wpdb->posts}
					INNER JOIN {$wpdb->postmeta} ON ID = post_id
					WHERE post_type = %s AND post_status IN(%s, %s)
					AND meta_key = %s AND post_author = %d",
					'hp_payout',
					'pending',
					'publish',
					'hp_amount',
					$vendor->get_user__id()
				)
			)
		);

		// Calculate balance.
		$balance = round( $profit - $payout, 2 );

		// Update balance.
		$vendor->set_balance( $balance )->save_balance();
	}

	/**
	 * Gets cart profit.
	 *
	 * @param  WC_Cart $cart Cart object.
	 * @return float
	 */
	protected function get_cart_profit( $cart ) {
		$profit = 0;

		// Get item.
		$item = hp\get_first_array_value( $cart->get_cart() );

		if ( ! $item || ! isset( $item['hp_vendor'] ) ) {
			return $profit;
		}

		// Get vendor.
		$vendor = Models\Vendor::query()->get_by_id( $item['hp_vendor'] );

		if ( ! $vendor ) {
			return $profit;
		}

		// Get commissions.
		$commission_rate = $this->get_commission_rate( $vendor );
		$commission_fee  = $this->get_commission_fee( $vendor );

		// Get total.
		$total = $cart->get_total( 'edit' ) - $cart->get_total_tax();

		// Get profit.
		if ( $total ) {
			$profit = $total * $commission_rate - $commission_fee;
		}

		return $profit;
	}

	/**
	 * Gets cart fee.
	 *
	 * @param  WC_Cart $cart Cart object.
	 * @param bool    $per_vendor Per vendor?
	 * @return float
	 */
	protected function get_cart_fee( $cart, $per_vendor = false ) {
		$fee = 0;

		// Get item.
		$item = hp\get_first_array_value( $cart->get_cart() );

		if ( ! $item || ! isset( $item['hp_vendor'] ) ) {
			return $fee;
		}

		// Get commissions.
		$commission_rate = floatval( get_option( 'hp_user_commission_rate' ) );
		$commission_fee  = floatval( get_option( 'hp_user_commission_fee' ) );

		if ( ! $commission_rate && ! $commission_fee ) {
			return $fee;
		}

		// Get total.
		$total = $cart->get_total( 'edit' ) - $cart->get_total_tax();

		// Get fee.
		if ( $total ) {
			$fee = $total * ( $commission_rate / 100 ) + $commission_fee;

			if ( $per_vendor ) {

				// Get vendor.
				$vendor = Models\Vendor::query()->get_by_id( $item['hp_vendor'] );

				if ( $vendor ) {
					$fee *= $this->get_commission_rate( $vendor );
				}
			}
		}

		return $fee;
	}

	/**
	 * Gets order profit.
	 *
	 * @param  WC_Order $order Order object.
	 * @return float
	 */
	public function get_order_profit( $order ) {
		$profit = 0;

		// Get commissions.
		$commission_rate = floatval( $order->get_meta( 'hp_commission_rate' ) );
		$commission_fee  = floatval( $order->get_meta( 'hp_commission_fee' ) );

		// Get total.
		$total = $order->get_total();

		if ( ! get_option( 'hp_vendor_include_taxes' ) ) {
			$total -= $order->get_total_tax();
		}

		if ( $total ) {
			$profit = $total * $commission_rate - $commission_fee;
		}

		return $profit;
	}

	/**
	 * Refunds order.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount Refund amount.
	 * @param string $reason Refund reason.
	 * @return bool
	 */
	public function refund_order( $order_id, $amount, $reason ) {

		// Get gateway.
		$gateway = wc_get_payment_gateway_by_order( $order_id );

		if ( ! $gateway ) {
			return false;
		}

		// Create refund.
		$refund = wc_create_refund(
			[
				'amount'         => $amount,
				'reason'         => $reason,
				'order_id'       => $order_id,
				'refund_payment' => $gateway->supports( 'refunds' ),
			]
		);

		// Set email arguments.
		$email_args = [
			'recipient' => get_option( 'admin_email' ),

			'tokens'    => [
				'order_number' => '#' . $order_id,
				'order_url'    => admin_url(
					'post.php?' . http_build_query(
						[
							'action' => 'edit',
							'post'   => $order_id,
						]
					)
				),
				'pdf_order_invoice' => do_shortcode('[e2pdf-download id="1" dataset="' . $order_id . '" button_title="Order Invoice"]'),

			],
		];

		if ( is_wp_error( $refund ) ) {

			// Set reason.
			$email_args['tokens']['fail_reason'] = $refund->get_error_message();

			// Send email.
			( new Emails\Order_Refund_Fail( $email_args ) )->send();

			return false;
		} elseif ( ! $gateway->supports( 'refunds' ) ) {

			// Send email.
			( new Emails\Order_Refund_Request( $email_args ) )->send();
		}

		return true;
	}

	/**
	 * Gets commission rate.
	 *
	 * @param object $vendor Vendor object.
	 * @return float
	 */
	public function get_commission_rate( $vendor ) {
		$rate = $vendor->get_commission_rate();

		if ( is_null( $rate ) ) {
			$rate = round( floatval( get_option( 'hp_vendor_commission_rate' ) ), 2 );
		}

		$rate = round( ( 100 - $rate ) / 100, 4 );

		return $rate;
	}

	/**
	 * Gets commission fee.
	 *
	 * @param object $vendor Vendor object.
	 * @return float
	 */
	protected function get_commission_fee( $vendor ) {
		$fee = $vendor->get_commission_fee();

		if ( is_null( $fee ) ) {
			$fee = round( floatval( get_option( 'hp_vendor_commission_fee' ) ), 2 );
		}

		return $fee;
	}

	/**
	 * Gets product listing.
	 *
	 * @param WC_Product $product Product object.
	 * @return mixed
	 */
	protected function get_product_listing( $product ) {
		$listing = null;

		// Get listing ID.
		$listing_id = $product->get_parent_id();

		if ( $listing_id ) {

			// Get listing.
			$listing = Models\Listing::query()->get_by_id( $listing_id );
		}

		return $listing;
	}

	/**
	 * Activates extension.
	 */
	public function activate_extension() {
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_checkout_login_reminder', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );

		// @todo Remove after HPOS integration.
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );
		hivepress()->scheduler->add_action( 'hivepress/v1/models/order/sync', [], time() + 300 );

		// @todo Remove after checkout integration.
		$page_id = get_option( 'woocommerce_checkout_page_id' );

		if ( $page_id ) {
			$page = get_post( $page_id );

			if ( $page && $page->post_date === $page->post_modified ) {
				wp_update_post(
					[
						'ID'           => $page_id,
						'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
					]
				);
			}
		}
	}

	/**
	 * Completes orders.
	 */
	public function complete_orders() {

		// Get period.
		$period = absint( get_option( 'hp_order_completion_period' ) );

		if ( ! $period ) {
			return;
		}

		// Get time.
		$time = time() - $period * DAY_IN_SECONDS;

		// Get orders.
		$args = [
			'type'   => 'shop_order',
			'status' => 'processing',
			'limit'  => 10,
		];

		if ( get_option( 'hp_order_require_delivery' ) ) {
			$args = array_merge(
				$args,
				[
					'meta_key'     => 'hp_delivered_time',
					'meta_value'   => $time,
					'meta_compare' => '<',
				]
			);
		} else {
			$args['date_modified'] = '<' . $time;
		}

		$orders = wc_get_orders( $args );

		// Update status.
		foreach ( $orders as $order ) {
			$order->update_status( 'completed' );
		}
	}

	/**
	 * Creates order.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order object.
	 */
	public function create_order( $order_id, $order ) {

		// Check order.
		if ( ! $order || $order->get_meta( 'hp_vendor' ) ) {
			return;
		}

		// Get item.
		$item = hp\get_first_array_value( $order->get_items() );

		if ( ! $item || ! $item->get_product_id() ) {
			return;
		}

		// Get vendor.
		$vendor = Models\Vendor::query()->filter(
			[
				'status__in' => [ 'draft', 'pending', 'publish', 'private' ],
				'user'       => absint( get_post_field( 'post_author', $item->get_product_id() ) ),
			]
			)->get_first();

		if ( ! $vendor ) {
			return;
		}

		// Get commissions.
		$commission_rate = $this->get_commission_rate( $vendor );
		$commission_fee  = $this->get_commission_fee( $vendor );

		if ( $item->get_meta( 'hp_commission_fee' ) ) {
			$commission_fee += floatval( $item->get_meta( 'hp_commission_fee' ) );
		}

		// Update order.
		wp_update_post(
			[
				'ID'          => $order->get_id(),
				'post_author' => $vendor->get_user__id(),
			]
		);

		update_post_meta( $order->get_id(), 'hp_vendor', $vendor->get_id() );
		update_post_meta( $order->get_id(), 'hp_commission_rate', $commission_rate );

		if ( $commission_fee ) {
			update_post_meta( $order->get_id(), 'hp_commission_fee', $commission_fee );
		}

		if ( get_option( 'hp_order_limit_revisions' ) && $item->get_meta( 'hp_revision_limit' ) ) {
			update_post_meta( $order->get_id(), 'hp_revision_limit', absint( $item->get_meta( 'hp_revision_limit' ) ) );
		}

		// Update balance.
		$this->update_vendor_balance( $vendor );
	}

	/**
	 * Sets order status.
	 *
	 * @param string   $status Order status.
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order object.
	 */
	public function set_order_status( $status, $order_id, $order ) {

		// Check order.
		if ( ! $order->get_meta( 'hp_vendor' ) ) {
			return $status;
		}

		// Set status.
		if ( $order->has_downloadable_item() ) {
			$status = 'completed';
		} else {
			$status = 'processing';
		}

		return $status;
	}

	/**
	 * Updates order status.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order Order object.
	 */
	public function update_order_status( $order_id, $old_status, $new_status, $order ) {

		// Check vendor.
		if ( ! $order->get_meta( 'hp_vendor' ) ) {
			return;
		}

		if ( in_array( $new_status, [ 'completed', 'refunded' ], true ) || in_array( $old_status, [ 'completed', 'refunded' ], true ) ) {

			// Update balance.
			$this->update_vendor_balance( $order->get_meta( 'hp_vendor' ) );
		}

		if ( in_array( $new_status, [ 'processing', 'completed', 'refunded' ], true ) ) {

			// Get user.
			$user = Models\User::query()->get_by_id( get_post_field( 'post_author', $order->get_id() ) );

			if ( ! $user ) {
				return;
			}

			// Send emails.
			$email_args = [
				'recipient' => $user->get_email(),

				'tokens'    => [
					'user'         => $user,
					'user_name'    => $user->get_display_name(),
					'order_number' => '#' . $order->get_id(),
					'order_amount' => hivepress()->woocommerce->format_price( $order->get_total() ),
					'order_url'    => hivepress()->router->get_url( 'order_edit_page', [ 'order_id' => $order->get_id() ] ),
					'pdf_order_invoice' => do_shortcode('[e2pdf-download id="1" dataset="' . $order_id . '" button_title="Order Invoice"]'),

				],
			];

			if ( 'processing' === $new_status ) {
				( new Emails\Order_Receive( $email_args ) )->send();
			} elseif ( 'completed' === $new_status ) {
				( new Emails\Order_Complete( $email_args ) )->send();
			} elseif ( 'refunded' === $new_status ) {
				( new Emails\Order_Refund( $email_args ) )->send();
			}
		}
	}

	/**
	 * Sets order post type.
	 *
	 * @todo Remove after HPOS integration.
	 * @param  array $meta Meta arguments.
	 * @return array
	 */
	public function set_order_type( $meta ) {
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$meta['alias'] = 'shop_order_placehold';
		}

		return $meta;
	}

	/**
	 * Syncs order posts.
	 *
	 * @todo Remove after HPOS integration.
	 */
	public function sync_order_posts() {
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	}

	/**
	 * Creates refund.
	 *
	 * @param int $order_id Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function create_refund( $order_id, $refund_id ) {

		// Get order.
		$order = wc_get_order( $order_id );

		if ( ! $order->get_meta( 'hp_vendor' ) ) {
			return;
		}

		// Get user ID.
		$user_id = absint( get_post_field( 'post_author', $order_id ) );

		// Update refund.
		wp_update_post(
			[
				'ID'          => $refund_id,
				'post_author' => $user_id,
			]
		);

		update_post_meta( $refund_id, 'hp_commission_rate', $order->get_meta( 'hp_commission_rate' ) );

		if ( $order->get_meta( 'hp_commission_fee' ) ) {
			update_post_meta( $refund_id, 'hp_commission_fee', $order->get_meta( 'hp_commission_fee' ) );
		}

		// Update balance.
		$this->update_vendor_balance( $order->get_meta( 'hp_vendor' ) );
	}

	/**
	 * Deletes refund.
	 *
	 * @param int $refund_id Refund ID.
	 * @param int $order_id Order ID.
	 */
	public function delete_refund( $refund_id, $order_id ) {

		// Get order.
		$order = wc_get_order( $order_id );

		if ( ! $order->get_meta( 'hp_vendor' ) ) {
			return;
		}

		// Update balance.
		$this->update_vendor_balance( $order->get_meta( 'hp_vendor' ) );
	}

	/**
	 * Sets cart totals.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function set_cart_totals( $cart ) {
		// Remove action.
		remove_action( 'woocommerce_before_calculate_totals', [ $this, 'set_cart_totals' ] );

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			// Check if this cart item has a custom price override
			if ( isset( $cart_item['hp_price_custom'] ) ) {
				// Set the price directly to the custom value and skip all other pricing logic
				$cart_item['data']->set_price( floatval( $cart_item['hp_price_custom'] ) );
				continue; // Skip the rest of price calculations for this item
			}

			// Standard price handling
			// Set price.
			if ( isset( $cart_item['hp_price'] ) ) {
				$cart_item['data']->set_price( floatval( $cart_item['hp_price'] ) );
			}

			// Set quantity.
			if ( isset( $cart_item['hp_quantity'] ) && get_option( 'hp_listing_multiply_quantity', true ) ) {
				$cart_item['data']->set_price( $cart_item['data']->get_price() * absint( $cart_item['hp_quantity'] ) );
			}

			// Change price.
			if ( isset( $cart_item['hp_price_change'] ) ) {
				$cart_item['data']->set_price( $cart_item['data']->get_price() + floatval( $cart_item['hp_price_change'] ) );
			}
		}

		return $cart;
	}

	/**
	 * Updates cart totals.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function update_cart_totals( $cart ) {
		$calculate = false;

		// Get service fee.
		$service_fee = $this->get_cart_fee( $cart );

		if ( $service_fee && ! isset( $this->fees['service_fee'] ) ) {

			// Add service fee.
			$this->fees['service_fee'] = [
				'name'   => esc_html__( 'Service Fee', 'hivepress-marketplace' ),
				'amount' => $service_fee,
			];

			// Add commission fee.
			foreach ( $cart->cart_contents as $item_key => $item ) {
				if ( ! isset( $cart->cart_contents[ $item_key ]['hp_service_fee'] ) ) {
					$commission_fee = round( $this->get_cart_fee( $cart, true ), 2 );

					$cart->cart_contents[ $item_key ]['hp_commission_fee'] = hp\get_array_value( $item, 'hp_commission_fee' ) + $commission_fee;
					$cart->cart_contents[ $item_key ]['hp_service_fee']    = $commission_fee;
				}

				break;
			}

			$calculate = true;
		}

		if ( get_option( 'hp_payout_system' ) === 'direct' && ! isset( $this->fees['direct_payment'] ) ) {

			// Add direct payment.
			$this->fees['direct_payment'] = [
				'name'   => esc_html__( 'Direct Payment', 'hivepress-marketplace' ),
				'amount' => -$this->get_cart_profit( $cart ),
			];

			$calculate = true;
		}

		// Update totals.
		if ( $calculate ) {
			$cart->calculate_totals();
		}
	}

	/**
	 * Updates cart fees.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function update_cart_fees( $cart ) {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['hp_fees'] ) ) {
				foreach ( $cart_item['hp_fees'] as $fee ) {
					if ( isset( $fee['amount'] ) ) {
						$cart->add_fee( $fee['name'], $fee['amount'], hp\get_array_value( $fee, 'taxable', false ) );
					}
				}
			}
		}

		foreach ( $this->fees as $fee ) {
			$cart->add_fee( $fee['name'], $fee['amount'] );
		}
	}

	/**
	 * Updates product quantity.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function update_product_quantity( $product ) {

		// Check permissions.
		if ( ! get_option( 'hp_listing_allow_quantity' ) ) {
			return;
		}

		// Get listing.
		$listing = $this->get_product_listing( $product );

		if ( ! $listing || $listing->get_quantity() === $product->get_stock_quantity() ) {
			return;
		}

		// Update listing.
		$listing->set_quantity( $product->get_stock_quantity() )->save_quantity();
	}

	/**
	 * Sellouts product.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function sellout_product( $product ) {

		// Check permissions.
		if ( ! get_option( 'hp_listing_allow_quantity' ) ) {
			return;
		}

		// Get listing.
		$listing = $this->get_product_listing( $product );

		if ( ! $listing ) {
			return;
		}

		// Update listing.
		$listing->set_status( 'draft' )->save_status();

		// Send email.
		$user = $listing->get_user();

		if ( $user ) {
			( new Emails\Listing_Sellout(
				[
					'recipient' => $user->get_email(),

					'tokens'    => [
						'user'          => $user,
						'listing'       => $listing,
						'user_name'     => $user->get_display_name(),
						'listing_title' => $listing->get_title(),
						'listing_url'   => hivepress()->router->get_url( 'listing_edit_page', [ 'listing_id' => $listing->get_id() ] ),
					],
				]
			) )->send();
		}
	}

	/**
	 * Gets requirement arguments.
	 *
	 * @param array $args Default arguments.
	 * @param int   $index Requirement index.
	 * @return array
	 */
	protected function get_requirement_args( $args, $index ) {

		// Set defaults.
		$args = array_merge(
			$args,
			[
				'name'              => hp\prefix( 'order_requirement_' . $index ),
				'type'              => hp\get_array_value( $args, 'type', 'textarea' ),
				'required'          => true,

				'custom_attributes' => [
					'required' => true,
				],
			]
		);

		if ( 'select_multiple' === $args['type'] ) {

			// Multiple choice.
			$args = hp\merge_arrays(
				$args,
				[
					'type'              => 'select',
					'multiple'          => true,

					'custom_attributes' => [
						'multiple' => true,
					],
				]
			);
		}

		if ( 'select' === $args['type'] ) {

			// Single choice.
			$args = hp\merge_arrays(
				$args,
				[
					'options'           => array_filter( array_map( 'trim', explode( ';', hp\get_array_value( $args, 'placeholder' ) ) ), 'strlen' ),

					'custom_attributes' => [
						'data-component' => 'select',
					],
				]
			);

			if ( ! hp\get_array_value( $args, 'multiple' ) ) {
				$args['options'] = [ '' => '&mdash;' ] + $args['options'];
			}

			unset( $args['placeholder'] );
		} else {

			// Text field.
			$args = hp\merge_arrays(
				$args,
				[
					'maxlength'  => 2048,
					'max_length' => 2048,
				]
			);
		}

		return $args;
	}

	/**
	 * Renders requirement fields.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public function render_requirement_fields( $checkout ) {

		// Check cart.
		if ( ! isset( WC()->cart ) ) {
			return;
		}

		// Get item.
		$item = hp\get_first_array_value( WC()->cart->get_cart() );

		if ( ! $item || ! $item['product_id'] ) {
			return;
		}

		// Get listing.
		$listing = $this->get_product_listing( wc_get_product( $item['product_id'] ) );

		if ( ! $listing || ! $listing->get_order_requirements() ) {
			return;
		}

		// Render fields.
		foreach ( $listing->get_order_requirements() as $index => $requirement ) {
			$field_args = $this->get_requirement_args( $requirement, $index );

			if ( hp\get_array_value( $field_args, 'multiple' ) ) {
				$field_args['name'] .= '[]';
			}

			woocommerce_form_field( $field_args['name'], $field_args );
		}
	}

	/**
	 * Validates requirement fields.
	 */
	public function validate_requirement_fields() {
		foreach ( $_POST as $name => $value ) {
			if ( strpos( $name, 'hp_order_requirement_' ) === 0 && ! $value && '0' !== $value ) {
				wc_add_notice( esc_html__( 'Please fill in all required fields.', 'hivepress-marketplace' ), 'error' );

				break;
			}
		}
	}

	/**
	 * Adds requirement note.
	 *
	 * @param int $order_id Order ID.
	 */
	public function add_requirement_note( $order_id ) {

		// Get order.
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_meta( 'hp_vendor' ) ) {
			return;
		}

		// Get item.
		$item = hp\get_first_array_value( $order->get_items() );

		if ( ! $item || ! $item->get_product_id() ) {
			return;
		}

		// Get listing.
		$listing = $this->get_product_listing( $item->get_product() );

		if ( ! $listing || ! $listing->get_order_requirements() ) {
			return;
		}

		// Get note.
		$note = '';

		foreach ( $listing->get_order_requirements() as $index => $requirement ) {

			// Create field.
			$field_args = $this->get_requirement_args( $requirement, $index );

			$field = hp\create_class_instance( '\HivePress\Fields\\' . $field_args['type'], [ $field_args ] );

			// Set field value.
			$field->set_value( hp\get_array_value( $_POST, $field_args['name'] ) );

			if ( ! $field->validate() ) {
				continue;
			}

			// Add requirement.
			$note .= '<strong>' . $field->get_label() . '</strong>: ' . sanitize_text_field( $field->get_display_value() ) . PHP_EOL;
		}

		if ( $note ) {

			// Add note.
			wc_create_order_note( $order->get_id(), $note, true );
		}
	}

	/**
	 * Updates listing.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param object $listing Listing object.
	 */
	public function update_listing( $listing_id, $listing ) {

		// Check listing.
		if ( ! $this->is_sellable( $listing ) ) {
			return;
		}

		// Get product.
		$product = hivepress()->woocommerce->get_related_product( $listing->get_id() );

		if ( ! $product ) {
			if ( $listing->get_status() === 'publish' || $listing->get_status() === 'private' ) {
				// Add product.
				$product = new \WC_Product();

				// Set properties.
				$product->set_props(
					[
						'parent_id'          => $listing->get_id(),
						'catalog_visibility' => 'hidden',
						'virtual'            => true,
					]
				);
			} else {
				return;
			}
		}

		if ( get_option( 'hp_listing_allow_price_tiers' ) && $listing->get_price_tiers() ) {

			// Get tier.
			$tier = hp\get_first_array_value( $listing->get_price_tiers() );

			if ( $listing->get_price() !== $tier['price'] ) {

				// Update price.
				$listing->set_price( $tier['price'] )->save_price();
			}
		}

		// Set properties.
		$product->set_props(
			[
				'name'          => $listing->get_title(),
				'slug'          => $listing->get_slug(),
				'status'        => $listing->get_status(),
				'date_created'  => $listing->get_created_date(),
				'date_modified' => $listing->get_modified_date(),
				'price'         => $listing->get_price(),
				'regular_price' => $listing->get_price(),
			]
		);

		if ( get_option( 'hp_listing_allow_quantity' ) ) {

			// Set quantity properties.
			if ( is_null( $listing->get_quantity() ) ) {
				$product->set_props(
					[
						'manage_stock'   => false,
						'stock_quantity' => null,
					]
				);
			} else {
				$product->set_props(
					[
						'manage_stock'   => true,
						'stock_quantity' => $listing->get_quantity(),
					]
				);
			}
		}

		if ( get_option( 'hp_listing_allow_purchase_note' ) ) {
			$product->set_props(
				[
					'purchase_note' => $listing->get_purchase_note(),
				]
			);
		}

		if ( get_option( 'hp_listing_require_attachment' ) ) {

			// Get attachment IDs.
			$attachment_ids = (array) $listing->get_attachments__id();

			if ( $attachment_ids ) {

				// Get downloads.
				$downloads = [];

				foreach ( $attachment_ids as $attachment_index => $attachment_id ) {

					// Get attachment URL and name.
					$attachment_url  = wp_get_attachment_url( $attachment_id );
					$attachment_name = preg_replace( '/-[a-z0-9]{6}\./', '.', wp_basename( $attachment_url ) );

					// Add download.
					$downloads[] = [
						'download_id' => md5( $listing->get_id() . '-' . $attachment_index ),
						'name'        => $attachment_name,
						'file'        => $attachment_url,
					];
				}

				// Set download properties.
				$product->set_props(
					[
						'downloadable' => true,
						'downloads'    => $downloads,
					]
				);
			}
		}

		// Update product.
		if ( $product->save() ) {

			// Set user.
			wp_update_post(
				[
					'ID'          => $product->get_id(),
					'post_author' => $listing->get_user__id(),
				]
			);

			if ( class_exists( 'wc_subscriptions' ) && get_option( 'hp_listing_allow_subscription' ) ) {
				if ( $listing->get_subscription_period() ) {
					if ( ! $product->is_type( 'subscription' ) ) {

						// Set type.
						wp_set_object_terms( $product->get_id(), 'subscription', 'product_type' );

						// Set interval.
						update_post_meta( $product->get_id(), '_subscription_period_interval', 1 );
					}

					// Set price.
					update_post_meta( $product->get_id(), '_subscription_price', $listing->get_price() );

					// Set period.
					update_post_meta( $product->get_id(), '_subscription_period', $listing->get_subscription_period() );
				} elseif ( ! $product->is_type( 'simple' ) ) {

					// Set type.
					wp_set_object_terms( $product->get_id(), 'simple', 'product_type' );

					// Set price.
					update_post_meta( $product->get_id(), '_regular_price', $listing->get_price() );
				}
			}
		}
	}

	/**
	 * Deletes listing.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param object $listing Listing object.
	 */
	public function delete_listing( $listing_id, $listing ) {

		// Check listing.
		if ( ! $this->is_sellable( $listing ) ) {
			return;
		}

		// Get product.
		$product = hivepress()->woocommerce->get_related_product( $listing_id );

		// Delete product.
		if ( $product ) {
			$product->delete();
		}
	}

	/**
	 * Adds listing attributes.
	 *
	 * @param array $attributes Attributes.
	 * @return array
	 */
	public function add_listing_attributes( $attributes ) {

		// Get category IDs.
		$category_ids = array_filter( (array) get_option( 'hp_listing_sale_categories' ) );
		// Get category IDs.
		$categorybooking_ids = array_filter( (array) get_option( 'hp_booking_categories' ) );
       
		// Add hourly pricing checkbox (always add this field)
		$attributes['enable_price_hour'] = [
			'editable'      => true,
			'_alias'        => 'enable_price_hour',
			'_external'     => true,
			'categories'    => $categorybooking_ids,

			'edit_field'    => [
				'label'       => esc_html__( 'Enable hourly pricing', 'hivepress-marketplace' ),
				'type'        => 'checkbox',
				'description' => esc_html__( 'Enable this to set pricing per hour instead of per day', 'hivepress-marketplace' ),
				'_order'      => 29,
			],
		];

		

		// Get the current value of enable_price_hour
		$enable_hourly = false;

		// Check if editing an existing listing
		if (isset($_REQUEST['listing_id'])) {
			$listing_id = absint($_REQUEST['listing_id']);
			if ($listing_id) {
				$raw_value = get_post_meta($listing_id, 'hp_enable_price_hour', true);
				$enable_hourly = (bool) $raw_value;
				
				
			}
		}

		// Also check if form was submitted
		if (isset($_POST['enable_price_hour'])) {
			$raw_post_value = $_POST['enable_price_hour'];
			$enable_hourly = (bool) $raw_post_value;
			
			
		}

		$attributes['price_hour'] = [
			'editable'      => true,
			'_alias'        => 'price_hour',
			'_external'     => true,
			'categories'    => $categorybooking_ids,

			'edit_field'    => [
				'label'       => esc_html__( 'Hourly Price', 'hivepress-marketplace' ),
				'type'        => 'currency',
				'min_value'   => 0,
				'required'    => true,
				'_order'      => 29.5,
				
			],
		];

		$attributes['price'] = [
			'editable'      => true,
			'filterable'    => true,
			'sortable'      => true,
			

			'display_areas' => [
				'view_block_primary',
				'view_page_primary',
			],

			'edit_field'    => [
				'label'       => esc_html__( 'Price', 'hivepress-marketplace' ),
				'type'        => 'currency',
				'min_value'   => 0,
				'required'    => true,
				'_order'      => 30,
				
			],

			'search_field'  => [
				'label'  => hivepress()->translator->get_string( 'price' ),
				'type'   => 'number_range',
				'_order' => 100,
			],
		];

		// Register all attributes but add JavaScript to control visibility
		$attributes['price_7'] = [
			'editable'      => true,
			'_alias'        => 'price_7',
			'_external'     => true,
			'categories'    => $categorybooking_ids,

			'edit_field'    => [
				'label'       => esc_html__( '1 Week Package Price', 'hivepress-marketplace' ),
				'type'        => 'currency',
				'min_value'   => 0,
				'_order'      => 31,
			],
		];

		$attributes['price_30'] = [
			'editable'      => true,
			'_alias'        => 'price_30',
			'_external'     => true,
			'categories'    => $categorybooking_ids,

			'edit_field'    => [
				'label'       => esc_html__( '1 Month Package Price', 'hivepress-marketplace' ),
				'type'        => 'currency',
				'min_value'   => 0,
				'_order'      => 32,
			],
		];

		

		if ( ! hivepress()->get_version( 'bookings' ) ) {
			$attributes['price']['categories'] = $category_ids;
			$attributes['price_7']['categories'] = $category_ids;
			$attributes['price_30']['categories'] = $category_ids;
			
		}

		if ( class_exists( 'wc_subscriptions' ) && get_option( 'hp_listing_allow_subscription' ) ) {

			// Add subscription attribute.
			$attributes['subscription_period'] = [
				'categories' => $category_ids,
				'editable'   => true,

				'edit_field' => [
					'label'     => esc_html__( 'Billing Period', 'hivepress-marketplace' ),
					'type'      => 'select',
					'_external' => true,
					'_order'    => 32,

					'options'   => [
						'day'   => esc_html__( 'day', 'hivepress-marketplace' ),
						'week'  => esc_html__( 'week', 'hivepress-marketplace' ),
						'month' => esc_html__( 'month', 'hivepress-marketplace' ),
						'year'  => esc_html__( 'year', 'hivepress-marketplace' ),
					],
				],
			];
		}

		if ( get_option( 'hp_listing_allow_quantity' ) ) {

			// Add quantity attribute.
			$attributes['quantity'] = [
				'categories' => $category_ids,
				'editable'   => true,

				'edit_field' => [
					'label'     => esc_html__( 'Quantity', 'hivepress-marketplace' ),
					'type'      => 'number',
					'min_value' => 0,
					'_order'    => 40,
				],
			];
		}

		if ( get_option( 'hp_order_limit_revisions' ) ) {
			$attributes['revision_limit'] = [
				/* translators: %s: number. */
				'display_format' => sprintf( esc_html__( 'Revisions: %s', 'hivepress-marketplace' ), '%value%' ),
				'categories'     => $category_ids,
				'editable'       => true,

				'display_areas'  => [
					'view_page_primary',
				],

				'edit_field'     => [
					'label'       => esc_html__( 'Revisions', 'hivepress-marketplace' ),
					'description' => esc_html__( 'Set the maximum number of revisions.', 'hivepress-marketplace' ),
					'type'        => 'number',
					'min_value'   => 0,
					'_order'      => 45,
				],
			];
		}

		if ( get_option( 'hp_listing_allow_price_tiers' ) ) {
			$attributes['price_tiers'] = [
				'categories' => $category_ids,
				'editable'   => true,

				'edit_field' => [
					'label'  => esc_html__( 'Tiers', 'hivepress-marketplace' ),
					'type'   => 'repeater',
					'_order' => 195,

					'fields' => [
						'name'        => [
							'placeholder' => hivepress()->translator->get_string( 'title' ),
							'type'        => 'text',
							'max_length'  => 256,
							'required'    => true,
							'_order'      => 10,
						],

						'price'       => [
							'placeholder' => hivepress()->translator->get_string( 'price' ),
							'type'        => 'currency',
							'min_value'   => 0,
							'required'    => true,
							'_order'      => 20,
						],

						'description' => [
							'placeholder' => hivepress()->translator->get_string( 'description' ),
							'type'        => 'text',
							'max_length'  => 512,
							'_order'      => 30,
						],
					],
				],
			];
		}

		if ( get_option( 'hp_listing_allow_price_extras' ) ) {
			$attributes['price_extras'] = [
				'editable'   => true,

				'edit_field' => [
					'label'  => esc_html__( 'Extras', 'hivepress-marketplace' ),
					'type'   => 'repeater',
					'_order' => 197,

					'fields' => [
						'name'  => [
							'placeholder' => hivepress()->translator->get_string( 'title' ),
							'type'        => 'text',
							'max_length'  => 256,
							'required'    => true,
							'_order'      => 10,
						],

						'price' => [
							'placeholder' => hivepress()->translator->get_string( 'price' ),
							'type'        => 'currency',
							'min_value'   => 0,
							'required'    => true,
							'_order'      => 20,
						],
					],
				],
			];

			if ( get_option( 'hp_listing_require_price_extras' ) ) {
				$attributes['price_extras']['edit_field']['fields']['required'] = [
					'caption' => esc_html_x( 'Required', 'price extra', 'hivepress-marketplace' ),
					'type'    => 'checkbox',
					'_order'  => 100,
				];
			}

			if ( ! hivepress()->get_version( 'bookings' ) ) {
				$attributes['price_extras']['categories'] = $category_ids;
			}
		}

		if ( get_option( 'hp_order_allow_requirements' ) ) {
			$attributes['order_requirements'] = [
				'categories' => $category_ids,
				'editable'   => true,

				'edit_field' => [
					'label'       => esc_html__( 'Requirements', 'hivepress-marketplace' ),
					'description' => esc_html__( 'Add custom fields to require specific details for the order.', 'hivepress-marketplace' ),
					'type'        => 'repeater',
					'_order'      => 199,

					'fields'      => [
						'label'       => [
							'placeholder' => hivepress()->translator->get_string( 'title' ),
							'type'        => 'text',
							'max_length'  => 256,
							'required'    => true,
							'_order'      => 10,
						],

						'type'        => [
							'type'        => 'select',
							'placeholder' => esc_html__( 'Text', 'hivepress-marketplace' ),
							'_order'      => 20,

							'options'     => [
								'select'          => esc_html__( 'Single Choice', 'hivepress-marketplace' ),
								'select_multiple' => esc_html__( 'Multiple Choice', 'hivepress-marketplace' ),
							],
						],

						'placeholder' => [
							'placeholder' => esc_html__( 'Placeholder or semicolon-separated options.', 'hivepress-marketplace' ),
							'type'        => 'text',
							'max_length'  => 512,
							'required'    => true,
							'_order'      => 30,
						],
					],
				],
			];
		}

		if ( get_option( 'hp_listing_allow_discounts' ) ) {
			$attributes['discounts'] = [
				'editable'   => true,

				'edit_field' => [
					'label'  => esc_html__( 'Discounts', 'hivepress-marketplace' ),
					'type'   => 'repeater',
					'_order' => 199,

					'fields' => [
						'quantity'   => [
							'placeholder' => esc_html__( 'Number of Days', 'hivepress-marketplace' ),
							'type'        => 'number',
							'min_value'   => 1,
							'max_value'   => 1000,
							'required'    => true,
							'_order'      => 10,
						],

						'amount' => [
							'placeholder' => esc_html__( 'Fixed Discount Amount', 'hivepress-marketplace' ),
							'type'        => 'currency',
							'min_value'   => 0,
							'required'    => true,
							'_order'      => 20,
						],
					],
				],
			];

			if ( ! hivepress()->get_version( 'bookings' ) ) {
				$attributes['discounts']['categories'] = $category_ids;
			}
		}

		if ( get_option( 'hp_listing_allow_purchase_note' ) ) {
			$attributes['purchase_note'] = [
				'categories' => $category_ids,
				'editable'   => true,

				'edit_field' => [
					'label'       => esc_html__( 'Purchase Note', 'hivepress-marketplace' ),
					'description' => esc_html__( 'Add a note that will be revealed to the buyer upon purchase.', 'hivepress-marketplace' ),
					'type'        => 'textarea',
					'max_length'  => 10240,
					'_order'      => 205,
				],
			];
		}

		if ( get_option( 'hp_listing_require_attachment' ) ) {

			// Get file formats.
			$formats = array_filter( explode( '|', implode( '|', (array) get_option( 'hp_listing_attachment_types' ) ) ) );

			// Add attachments attribute.
			$attributes['attachments'] = [
				'categories' => $category_ids,
				'editable'   => true,

				'edit_field' => [
					'label'     => esc_html__( 'File', 'hivepress-marketplace' ),
					'type'      => 'attachment_upload',
					'formats'   => $formats,
					'protected' => true,
					'required'  => true,
					'_model'    => 'attachment',
					'_order'    => 50,
				],
			];
		}


		return $attributes;
	}

	/**
	 * Alters listing fields.
	 *
	 * @param array  $fields Model fields.
	 * @param object $model Model object.
	 * @return array
	 */
	public function alter_listing_fields( $fields, $model ) {
		// Set billing period.
		if ( class_exists('wc_subscriptions') && get_option('hp_listing_allow_subscription') && isset($fields['price'], $fields['subscription_period'])) {
			$period = hp\get_array_value( $fields['subscription_period']['options'], get_post_meta($model->get_id(), 'hp_subscription_period', true));

			if ($period) {
				/* translators: 1: subscription price, 2: subscription period. */
				$fields['price']['display_template'] = esc_html(sprintf(__('%1$s / %2$s', 'hivepress-marketplace'), '%value%', $period));
			}
		} else {
			// Check if hourly pricing is enabled
			$enable_price_hour = get_post_meta($model->get_id(), 'hp_enable_price_hour', true);
			$price_hour = get_post_meta($model->get_id(), 'hp_price_hour', true);

			if ($enable_price_hour && $price_hour) {
				// Show only hourly pricing - directly use the hourly price value
				$fields['price']['display_template'] = sprintf(
					'<div class="hp-listing__price-primary">%s / hr</div>',
					hivepress()->woocommerce->format_price($price_hour)
				);
			} else {
				// Get package prices
				$price_7 = get_post_meta($model->get_id(), 'hp_price_7', true);
				$price_30 = get_post_meta($model->get_id(), 'hp_price_30', true);

				// Get categories
				$booking_categories = array_filter((array) get_option('hp_booking_categories'));
				$sale_categories = array_filter((array) get_option('hp_listing_sale_categories'));
				
				// Get listing categories
				$listing_categories = wp_get_post_terms($model->get_id(), 'hp_listing_category', array('fields' => 'ids'));

				// Check category type
				$is_booking = false;
				$is_sale = false;

				if (!empty($listing_categories)) {
					foreach ($listing_categories as $cat_id) {
						if (in_array($cat_id, $booking_categories)) {
							$is_booking = true;
							break;
						}
						if (in_array($cat_id, $sale_categories)) {
							$is_sale = true;
							break;
						}
					}
				}

				// Build display template
				$template_parts = [];

				// Add daily price based on category
				if ($is_booking) {
					$fields['price']['display_template'] = sprintf(
						'<div class="hp-listing__price-primary">%s / day</div>',
						'%value%'
					);
				} else {
					$fields['price']['display_template'] = sprintf(
						'<div class="hp-listing__price-primary">%s</div>',
						'%value%'
					);
				}

				// Add package prices to template if they exist
				if ($price_7 || $price_30) {
					$package_parts = [];
					
					if ($price_7) {
						$package_parts[] = sprintf(
							'<div class="hp-listing__price-package">%s / week</div>',
							hivepress()->woocommerce->format_price($price_7)
						);
					}

					if ($price_30) {
						$package_parts[] = sprintf(
							'<div class="hp-listing__price-package">%s / month</div>',
							hivepress()->woocommerce->format_price($price_30)
						);
					}

					if (!empty($package_parts)) {
						$fields['price']['display_template'] .= implode('', $package_parts);
					}
				}
			}

			// Add CSS styles
			add_action('wp_head', function() {
				?>
				<style type="text/css">
					.hp-listing__price-primary {
						font-weight: bold;
						color: #1abc9c;
						margin-bottom: 5px;
					}
					.hp-listing__price-package {
						font-size: 0.9em;
						color: #666;
						margin-top: 2px;
					}
				</style>
				<?php
			});
		}

		// Set product ID.
		if (!hp\is_rest()) {
			$product_id = hivepress()->woocommerce->get_related_product($model->get_id(), ['return' => 'ids']);

			if ($product_id) {
				$fields['price']['product'] = $product_id;
			}
		}

		return $fields;
	}

	/**
	 * Adds vendor fields.
	 *
	 * @param array $model Model arguments.
	 * @return array
	 */
	public function add_vendor_fields( $model ) {

		// Add balance field.
		$model['fields']['balance'] = [
			'type'      => 'currency',
			'_external' => true,
		];

		// Add commission fields.
		$model['fields']['commission_rate'] = [
			'type'      => 'number',
			'decimals'  => 2,
			'min_value' => 0,
			'max_value' => 100,
			'_external' => true,
		];

		$model['fields']['commission_fee'] = [
			'type'      => 'currency',
			'min_value' => 0,
			'_external' => true,
		];

		return $model;
	}

	/**
	 * Validates message.
	 *
	 * @param array  $errors Error messages.
	 * @param object $message Message object.
	 * @return array
	 */
	public function validate_message( $errors, $message ) {
		if ( empty( $errors ) && get_option( 'hp_order_message_restriction' ) ) {

			// Get listing.
			$listing = $message->get_listing();

			if ( $listing && $this->is_sellable( $listing ) ) {

				// Get vendor.
				$vendor = $listing->get_vendor();

				// Get product ID.
				$product_id = hivepress()->woocommerce->get_related_product( $listing->get_id(), [ 'return' => 'ids' ] );

				if ( $product_id && ! wc_customer_bought_product( '', $message->get_sender__id(), $product_id ) && ( ! $vendor || get_current_user_id() !== $vendor->get_user__id() ) ) {
					$errors[] = esc_html__( 'Only buyers can send messages.', 'hivepress-marketplace' );
				}
			}
		}

		return $errors;
	}

	/**
	 * Validates review.
	 *
	 * @param array  $errors Error messages.
	 * @param object $review Review object.
	 * @return array
	 */
	public function validate_review( $errors, $review ) {
		if ( empty( $errors ) && get_option( 'hp_order_review_restriction' ) ) {

			// Get listing.
			$listing = $review->get_listing();

			if ( $listing && $this->is_sellable( $listing ) ) {

				// Get vendor.
				$vendor = $listing->get_vendor();

				// Get product ID.
				$product_id = hivepress()->woocommerce->get_related_product( $listing->get_id(), [ 'return' => 'ids' ] );

				if ( $product_id && ! wc_customer_bought_product( '', $review->get_author__id(), $product_id ) && ( ! $vendor || get_current_user_id() !== $vendor->get_user__id() ) ) {
					$errors[] = esc_html__( 'Only buyers can submit reviews.', 'hivepress-marketplace' );
				}
			}
		}

		return $errors;
	}

	/**
	 * Alters listing buy form.
	 *
	 * @param array  $form_args Form arguments.
	 * @param object $form Form object.
	 * @return array
	 */
	public function alter_listing_buy_form( $form_args, $form ) {
		// Get listing.
		$listing = $form->get_model();

		if ( $listing ) {
			// Get prices
			$regular_price = $listing->get_price();
			$price_7 = $listing->get_price_7();
			$price_30 = $listing->get_price_30();

			// Set field arguments.
			$field_args = [
				'label'     => esc_html__( 'Select Quantity', 'hivepress-marketplace' ),
				'type'      => 'number',
				'min_value' => 1,
				'required'  => true,
				'_separate' => true,
				'_order'    => 10,
			];

			// Add description of available packages
			$description_parts = [
				sprintf(
					esc_html__('Regular price: %s x1', 'hivepress-marketplace'),
					hivepress()->woocommerce->format_price($regular_price)
				)
			];

			if ($price_7) {
				$description_parts[] = sprintf(
					esc_html__('1 week package: %s', 'hivepress-marketplace'),
					hivepress()->woocommerce->format_price($price_7)
				);
			}

			if ($price_30) {
				$description_parts[] = sprintf(
					esc_html__('1 month package: %s', 'hivepress-marketplace'),
					hivepress()->woocommerce->format_price($price_30)
				);
			}

			$field_args['description'] = implode(' | ', $description_parts);

			// Add field.
			$form_args['fields']['_quantity'] = $field_args;

			if ( get_option( 'hp_listing_allow_price_extras' ) && $listing->get_price_extras() ) {
				// Set field arguments.
				$field_args = [
					'type'      => 'checkboxes',
					'options'   => [],
					'default'   => [],
					'_separate' => true,
					'_order'    => 20,
				];

				if ( get_option( 'hp_listing_allow_price_tiers' ) && $listing->get_price_tiers() ) {
					$field_args['label'] = esc_html__( 'Extras', 'hivepress-marketplace' );
				}

				foreach ( $listing->get_price_extras() as $index => $item ) {
					$option_args = [
						/* translators: 1: extra name, 2: extra price. */
						'label' => esc_html( sprintf( _x( '%1$s (%2$s)', 'price extra format', 'hivepress-marketplace' ), $item['name'], hivepress()->woocommerce->format_price( $item['price'] ) ) ),
					];

					if ( hp\get_array_value( $item, 'required' ) ) {
						$option_args['attributes'] = [
							'class' => [ 'hp-field--readonly' ],
						];

						$field_args['default'][] = $index;
					}

					$field_args['options'][ $index ] = $option_args;
				}

				// Add field.
				$form_args['fields']['_extras'] = $field_args;
			}
		}

		return $form_args;
	}

	/**
	 * Alters order reject form.
	 *
	 * @param array  $form_args Form arguments.
	 * @param object $form Form object.
	 * @return array
	 */
	public function alter_order_reject_form( $form_args, $form ) {

		// Get order.
		$order = $form->get_model();

		if ( $order && $order->get_revision_limit() === 0 ) {

			// Disable button.
			$form_args['button'] = hp\merge_arrays(
				$form_args['button'],
				[
					'label'      => esc_html__( 'Limit Exceeded', 'hivepress-marketplace' ),

					'attributes' => [
						'disabled' => true,
					],
				]
			);
		}

		return $form_args;
	}

	/**
	 * Alters order refund form.
	 *
	 * @param array  $form_args Form arguments.
	 * @param object $form Form object.
	 * @return array
	 */
	public function alter_order_refund_form( $form_args, $form ) {

		// Get order.
		if ( ! $form->get_model() ) {
			return $form_args;
		}

		$order = wc_get_order( $form->get_model()->get_id() );

		if ( ! $order ) {
			return $form_args;
		}

		// Get amount.
		$amount = $order->get_total() - $order->get_total_refunded();

		// Alter fields.
		$form_args['fields']['amount']['max_value'] = $amount;
		$form_args['fields']['amount']['default']   = $amount;

		if ( 'full' === get_option( 'hp_order_allow_refunds' ) ) {
			$form_args['fields']['amount']['disabled'] = true;
		}

		return $form_args;
	}

	/**
	 * Adds order admin columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_order_admin_columns( $columns ) {
		return array_merge(
			array_slice( $columns, 0, 2, true ),
			[
				'vendor' => hivepress()->translator->get_string( 'vendor' ),
			],
			array_slice( $columns, 2, null, true )
		);
	}

	/**
	 * Renders order admin columns.
	 *
	 * @param string $column Column name.
	 * @param int    $order_id Order ID.
	 */
	public function render_order_admin_columns( $column, $order_id ) {
		if ( 'vendor' === $column ) {
			$output = '&mdash;';

			// Get vendor ID.
			$vendor_id = absint( get_post_meta( $order_id, 'hp_vendor', true ) );

			if ( $vendor_id ) {

				// Render link.
				$output = '<a href="' . esc_url( hivepress()->router->get_admin_url( 'post', $vendor_id ) ) . '">' . esc_html( get_the_title( $vendor_id ) ) . '</a>';
			}

			echo wp_kses_data( $output );
		}
	}

	/**
	 * Adds vendor admin columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_vendor_admin_columns( $columns ) {
		return array_merge(
			array_slice( $columns, 0, 3, true ),
			[
				'balance' => esc_html__( 'Balance', 'hivepress-marketplace' ),
			],
			array_slice( $columns, 3, null, true )
		);
	}

	/**
	 * Renders vendor admin columns.
	 *
	 * @param string $column Column name.
	 * @param int    $vendor_id Vendor ID.
	 */
	public function render_vendor_admin_columns( $column, $vendor_id ) {
		if ( 'balance' === $column ) {

			// Get balance.
			$balance = round( floatval( get_post_meta( $vendor_id, 'hp_balance', true ) ), 2 );

			// Output balance.
			echo esc_html( hivepress()->woocommerce->format_price( $balance ) );
		}
	}

	/**
	 * Renders order admin details.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function render_order_admin_details( $order ) {
		$output = '';

		// Get vendor ID.
		$vendor_id = absint( $order->get_meta( 'hp_vendor' ) );

		if ( $vendor_id ) {
			$output .= '<p class="form-field form-field-wide wc-vendor">';
			$output .= '<label>' . hivepress()->translator->get_string( 'vendor' ) . ':</label>';

			// Render link.
			$output .= '<a href="' . esc_url(
				admin_url(
					'post.php?' . http_build_query(
						[
							'action' => 'edit',
							'post'   => $vendor_id,
						]
					)
				)
			) . '">' . esc_html( get_the_title( $vendor_id ) ) . '</a>';

			$output .= '</p>';
		}

		echo wp_kses_post( $output );
	}

	/**
	 * Renders order admin links.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_order_admin_links( $order_id ) {

		// Get order.
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_meta( 'hp_vendor' ) || ! in_array( $order->get_status(), [ 'wc-processing', 'wc-completed', 'wc-refunded' ], true ) ) {
			return;
		}

		// Render links.
		echo '<li class="wide"><a href="' . esc_url( hivepress()->router->get_url( 'order_edit_page', [ 'order_id' => $order_id ] ) ) . '" target="_blank">' . esc_html__( 'View Order', 'hivepress-marketplace' ) . '</a></li>';
	}

	/**
	 * Alters order statuses.
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public function alter_order_statuses( $statuses ) {
		global $pagenow;

		if ( in_array( $pagenow, [ 'post.php', 'edit.php' ], true ) && get_post_type() === 'shop_order' ) {

			// Get order ID.
			$order_id = get_the_ID();

			if ( $order_id && get_post_meta( $order_id, 'hp_delivered_time', true ) ) {

				// Set status.
				$statuses['wc-processing'] = esc_attr_x( 'Delivered', 'order', 'hivepress-marketplace' );
			}
		}

		return $statuses;
	}

	/**
	 * Alters order totals.
	 *
	 * @param array    $rows Total rows.
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public function alter_order_totals( $rows, $order ) {

		// Check order.
		if ( ! $order->get_meta( 'hp_vendor' ) || 'order_edit_page' !== hivepress()->router->get_current_route_name() ) {
			return $rows;
		}

		// Get vendor.
		// @todo use vendor ID from context when available.
		$vendor = Models\Vendor::query()->get_by_id( $order->get_meta( 'hp_vendor' ) );

		if ( ! $vendor || ( ! current_user_can( 'edit_others_posts' ) && get_current_user_id() !== $vendor->get_user__id() ) ) {
			return $rows;
		}

		// Get item.
		$item = hp\get_first_array_value( $order->get_items() );

		if ( ! $item ) {
			return $rows;
		}

		// Remove taxes.
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			unset( $rows[ sanitize_title( $tax->get_rate_code() ) ] );
		}

		$rows['order_total']['value'] = wc_price( $order->get_total() - $order->get_total_tax() - $order->get_total_refunded() );

		// Remove method.
		unset( $rows['payment_method'] );

		// Get commissions.
		$commissions = [];

		$commission_rate = floatval( $order->get_meta( 'hp_commission_rate' ) );
		$commission_fee  = floatval( $order->get_meta( 'hp_commission_fee' ) );

		if ( $item->get_meta( 'hp_commission_fee' ) && ! $order->get_total_refunded() ) {
			$commission_fee -= floatval( $item->get_meta( 'hp_commission_fee' ) );
		}

		if ( $commission_rate ) {
			$commission_rate = round( ( 1 - $commission_rate ) * 100, 2 );

			if ( $commission_rate ) {
				$commissions[] = $commission_rate . '%';
			}
		}

		if ( $commission_fee ) {
			$commissions[] = wc_price( $commission_fee );
		}

		if ( ! $commissions ) {
			return $rows;
		}

		if ( $order->get_status() !== 'refunded' ) {

			// Add commissions.
			$rows['commission'] = [
				'label' => esc_html__( 'Commission', 'hivepress-marketplace' ) . ':',
				'value' => implode( ' + ', $commissions ),
			];

			// Add earnings.
			$rows['profit'] = [
				'label' => esc_html__( 'Earnings', 'hivepress-marketplace' ) . ':',
				'value' => '',
			];

			if ( get_option( 'hp_payout_system' ) === 'direct' ) {
				$label = esc_html__( 'Direct Payment', 'hivepress-marketplace' ) . ':';

				foreach ( $rows as $index => $row ) {
					if ( $row['label'] === $label ) {
						$rows['profit']['value'] = str_replace( '>-<', '><', $row['value'] );

						unset( $rows[ $index ] );

						break;
					}
				}

				unset( $rows['order_total'] );
			} else {
				$rows['profit']['value'] = wc_price( $this->get_order_profit( $order ) );
			}
		}

		return $rows;
	}

	/**
	 * Alters settings.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function alter_settings( $settings ) {
		if ( ! hivepress()->get_version( 'messages' ) ) {
			unset( $settings['orders']['sections']['restrictions']['fields']['order_message_restriction'] );
		}

		if ( ! hivepress()->get_version( 'reviews' ) ) {
			unset( $settings['orders']['sections']['restrictions']['fields']['order_review_restriction'] );
		}

		if ( class_exists( 'wc_subscriptions' ) ) {
			$settings['listings']['sections']['selling']['fields']['listing_allow_subscription'] = [
				'label'   => esc_html__( 'Subscriptions', 'hivepress-marketplace' ),
				'caption' => esc_html__( 'Allow sellers to sell subscriptions', 'hivepress-marketplace' ),
				'type'    => 'checkbox',
				'_order'  => 15,
			];
		}

		return $settings;
	}

	/**
	 * Sets request context.
	 *
	 * @param array $context Request context.
	 * @return array
	 */
	public function set_request_context( $context ) {

		// Get cached vendor count.
		$vendor_count = hivepress()->cache->get_user_cache( get_current_user_id(), 'vendor_count', 'models/vendor' );

		if ( is_null( $vendor_count ) ) {

			// Get vendor count.
			$vendor_count = Models\Vendor::query()->filter(
				[
					'status__in' => [ 'pending', 'publish', 'private' ],
					'user'       => get_current_user_id(),
				]
			)->get_count();

			// Cache vendor count.
			hivepress()->cache->set_user_cache( get_current_user_id(), 'vendor_count', 'models/vendor', $vendor_count );
		}

		// Set request context.
		$context['vendor_count'] = $vendor_count;

		// Get cached order count.
		$order_count = hivepress()->cache->get_user_cache( get_current_user_id(), 'vendor_order_count', 'models/order' );

		if ( is_null( $order_count ) ) {

			// Get order count.
			$order_count = Models\Order::query()->filter(
				[
					'status__in' => [ 'wc-processing', 'wc-completed', 'wc-refunded' ],
					'seller'     => get_current_user_id(),
				]
			)->get_count();

			// Cache order count.
			hivepress()->cache->set_user_cache( get_current_user_id(), 'vendor_order_count', 'models/order', $order_count );
		}

		// Set request context.
		$context['vendor_order_count'] = $order_count;

		// Get cached order count.
		$order_count = hivepress()->cache->get_user_cache( get_current_user_id(), 'vendor_order_processing_count', 'models/order' );

		if ( is_null( $order_count ) ) {

			// Get order count.
			$order_count = Models\Order::query()->filter(
				[
					'status' => 'wc-processing',
					'seller' => get_current_user_id(),
				]
			)->get_count();

			// Cache order count.
			hivepress()->cache->set_user_cache( get_current_user_id(), 'vendor_order_processing_count', 'models/order', $order_count );
		}

		// Set request context.
		if ( $order_count ) {
			$context['vendor_order_processing_count'] = $order_count;

			$context['notice_count'] = hp\get_array_value( $context, 'notice_count' ) + $order_count;
		}

		// Get cached payout count.
		$payout_count = hivepress()->cache->get_user_cache( get_current_user_id(), 'payout_count', 'models/payout' );

		if ( is_null( $payout_count ) ) {

			// Get payout count.
			$payout_count = Models\Payout::query()->filter(
				[
					'status__in' => [ 'draft', 'pending', 'publish', 'private' ],
					'user'       => get_current_user_id(),
				]
			)->get_count();

			// Cache payout count.
			hivepress()->cache->set_user_cache( get_current_user_id(), 'payout_count', 'models/payout', $payout_count );
		}

		// Set request context.
		$context['payout_count'] = $payout_count;

		return $context;
	}

	/**
	 * Redirects pages.
	 */
	public function redirect_pages() {
		$url = null;

		if ( is_singular( 'product' ) ) {
			$listing_id = absint( get_post_field( 'post_parent' ) );

			if ( $listing_id ) {
				$url = get_permalink( $listing_id );
			}
		} elseif ( is_cart() ) {
			$item = hp\get_last_array_value( WC()->cart->get_cart() );

			if ( $item && isset( $item['hp_vendor'] ) ) {
				$url = wc_get_checkout_url();
			}
		}

		if ( $url ) {
			wp_safe_redirect( $url );

			exit;
		}
	}

	/**
	 * Adds order class.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function add_order_class( $classes ) {
		if ( is_wc_endpoint_url( 'view-order' ) ) {
			$classes[] = 'hp-order--page hp-order--view-page';
		}

		return $classes;
	}

	/**
	 * Renders order header.
	 */
	public function render_order_header() {
		if ( is_wc_endpoint_url( 'view-order' ) ) {
			echo ( new Blocks\Template(
				[
					'template' => 'order_header_block',

					'context'  => [
						'order' => hivepress()->request->get_context( 'order' ),
					],
				]
			) )->render();
		}
	}

	/**
	 * Renders order footer.
	 */
	public function render_order_footer() {
		if ( is_wc_endpoint_url( 'view-order' ) ) {
			echo ( new Blocks\Template(
				[
					'template' => 'order_footer_block',

					'context'  => [
						'order' => hivepress()->request->get_context( 'order' ),
					],
				]
			) )->render();
		}
	}

	/**
	 * Renders order status.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function render_order_status( $order ) {
		echo ( new Blocks\Part(
			[
				'path'    => 'order/view/order-status',

				'context' => [
					'order' => Models\Order::query()->get_by_id( $order->get_id() ),
				],
			]
		) )->render();
	}

	/**
	 * Alters user account menu.
	 *
	 * @param array $menu Menu arguments.
	 * @return array
	 */
	public function alter_user_account_menu( $menu ) {

		// Add dashboard page.
		if ( hivepress()->request->get_context( 'vendor_count' ) ) {
			$menu['items']['vendor_dashboard'] = [
				'route'  => 'vendor_dashboard_page',
				'_order' => 5,
			];
		}

		// Add orders page.
		if ( hivepress()->request->get_context( 'vendor_order_count' ) ) {
			$item_args = [
				'route'  => 'orders_edit_page',
				'_order' => 35,
			];

			if ( hivepress()->request->get_context( 'vendor_order_processing_count' ) ) {
				$item_args['meta'] = hivepress()->request->get_context( 'vendor_order_processing_count' );
			}

			$menu['items']['orders_edit'] = $item_args;

			if ( hivepress()->request->get_context( 'order_count' ) ) {
				$menu['items']['orders_view']['label'] = esc_html__( 'Placed Orders', 'hivepress-marketplace' );
			}
		}

		// Add payouts page.
		if ( hivepress()->request->get_context( 'payout_count' ) ) {
			$menu['items']['payouts_view'] = [
				'route'  => 'payouts_view_page',
				'_order' => 45,
			];
		}

		return $menu;
	}

	/**
	 * Alters listing view page.
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function alter_listing_view_page( $template ) {
		return hp\merge_trees(
			$template,
			[
				'blocks' => [
					'listing_actions_primary' => [
						'blocks' => [
							'listing_buy_form' => [
								'type'       => 'form',
								'form'       => 'listing_buy',
								'_label'     => hivepress()->translator->get_string( 'listing_order_form' ),
								'_order'     => 5,

								'attributes' => [
									'class' => [ 'hp-form--narrow' ],
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Alters listing view page blocks.
	 *
	 * @param array  $blocks Block arguments.
	 * @param object $template Template object.
	 * @return array
	 */
	public function alter_listing_view_page_blocks( $blocks, $template ) {

		// Get listing.
		$listing = $template->get_context( 'listing' );
		if ( ! $listing || ! $this->is_sellable( $listing ) || is_null( $listing->get_price() ) || ( get_option( 'hp_listing_allow_quantity' ) && ! $listing->get_quantity() ) ) {

			// Remove buy form.
			$blocks = hp\merge_trees(
				[ 'blocks' => $blocks ],
				[
					'blocks' => [
						'listing_buy_form' => [
							'type' => 'content',
						],
					],
				]
			)['blocks'];
		}

		return $blocks;
	}

	/**
	 * Alters message view block.
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function alter_message_view_block( $template ) {
		if ( get_option( 'hp_order_message_restriction' ) ) {
			$template = hp\merge_trees(
				$template,
				[
					'blocks' => [
						'message_send_modal' => [
							'type' => 'content',
						],

						'message_send_link'  => [
							'type' => 'content',
						],
					],
				]
			);
		}

		return $template;
	}

	/**
	 * Alters order edit page.
	 *
	 * @param string $path Template path.
	 * @param string $name Template name.
	 * @return string
	 */
	public function alter_order_edit_page( $path, $name ) {

		// Check template action.
		if ( ! did_action( 'template_redirect' ) ) {
			return $path;
		}

		// Set template path.
		if ( hivepress()->router->get_current_route_name() === 'order_edit_page' ) {
			$paths = [
				'order/order-downloads.php',
				'order/order-again.php',
			];

			if ( ! get_option( 'hp_order_share_details' ) ) {
				$paths[] = 'order/order-details-customer.php';
			}

			if ( in_array( $name, $paths, true ) ) {
				$path = hivepress()->get_path() . '/templates/page/placeholder.php';
			}
		}

		return $path;
	}

	/**
	 * Alters order view page.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function alter_order_view_page( $order ) {
		if ( is_wc_endpoint_url( 'view-order' ) ) {
			echo ( new Blocks\Order_Notes(
				[
					'context' => [
						'order' => $order,
					],
				]
			) )->render();
		}
	}

	/**
	 * Gets quantity prices.
	 *
	 * @param object $listing Listing object.
	 * @return array|null
	 */
	protected function get_quantity_prices( $listing ) {
		$prices = [
			'price_7'  => $listing->get_price_7(),
			'price_30' => $listing->get_price_30(),
		];

		return array_filter( $prices );
	}

	
}

