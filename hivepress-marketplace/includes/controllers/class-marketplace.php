<?php
/**
 * Marketplace controller.
 *
 * @package HivePress\Controllers
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Forms;
use HivePress\Emails;
use HivePress\Blocks;
use HivePress\Templates;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Marketplace controller class.
 *
 * @class Marketplace
 */
final class Marketplace extends Controller {

	/**
	 * Class constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [
					'listing_buy_action'    => [
						'base'   => 'listing_resource',
						'path'   => '/buy',
						'method' => 'POST',
						'action' => [ $this, 'buy_listing' ],
						'rest'   => true,
					],

					'orders_resource'       => [
						'path' => '/orders',
						'rest' => true,
					],

					'order_resource'        => [
						'base' => 'orders_resource',
						'path' => '/(?P<order_id>\d+)',
						'rest' => true,
					],

					'order_deliver_action'  => [
						'base'   => 'order_resource',
						'path'   => '/deliver',
						'method' => 'POST',
						'action' => [ $this, 'deliver_order' ],
						'rest'   => true,
					],

					'order_reject_action'   => [
						'base'   => 'order_resource',
						'path'   => '/reject',
						'method' => 'POST',
						'action' => [ $this, 'reject_order' ],
						'rest'   => true,
					],

					'order_complete_action' => [
						'base'   => 'order_resource',
						'path'   => '/complete',
						'method' => 'POST',
						'action' => [ $this, 'complete_order' ],
						'rest'   => true,
					],

					'order_dispute_action'  => [
						'base'   => 'order_resource',
						'path'   => '/dispute',
						'method' => 'POST',
						'action' => [ $this, 'dispute_order' ],
						'rest'   => true,
					],

					'order_refund_action'   => [
						'base'   => 'order_resource',
						'path'   => '/refund',
						'method' => 'POST',
						'action' => [ $this, 'refund_order' ],
						'rest'   => true,
					],

					'orders_edit_page'      => [
						'base'      => 'vendor_account_page',
						'path'      => '/orders',
						'title'     => [ $this, 'get_orders_edit_title' ],
						'redirect'  => [ $this, 'redirect_orders_edit_page' ],
						'action'    => [ $this, 'render_orders_edit_page' ],
						'paginated' => true,
					],

					'order_edit_page'       => [
						'base'     => 'orders_edit_page',
						'path'     => '/(?P<order_id>\d+)',
						'title'    => [ $this, 'get_order_edit_title' ],
						'redirect' => [ $this, 'redirect_order_edit_page' ],
						'action'   => [ $this, 'render_order_edit_page' ],
					],

					'order_view_page'       => [
						'url'      => [ $this, 'get_order_view_url' ],
						'match'    => [ $this, 'is_order_view_page' ],
						'redirect' => [ $this, 'redirect_order_view_page' ],
					],

					'vendor_dashboard_page' => [
						'title'    => esc_html__( 'Dashboard', 'hivepress-marketplace' ),
						'base'     => 'vendor_account_page',
						'path'     => '/dashboard',
						'redirect' => [ $this, 'redirect_vendor_dashboard_page' ],
						'action'   => [ $this, 'render_vendor_dashboard_page' ],
					],

					'listing_buy_page'      => [
						'path'     => '/buy-listing/(?P<listing_id>\d+)',
						'redirect' => [ $this, 'redirect_listing_buy_page' ],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Buys listing.
	 *
	 * @param WP_REST_Request $request API request.
	 * @return WP_Rest_Response
	 */
	public function buy_listing( $request ) {

		// Change price format.
		// @todo find a better solution.
		add_filter(
			'hivepress/v1/models/listing/fields',
			function( $fields ) {
				$fields['price']['display_template'] = '%value%';

				return $fields;
			},
			200
		);

		// Get listing.
		$listing = Models\Listing::query()->get_by_id( $request->get_param( 'listing_id' ) );

		if ( ! $listing || ($listing->get_status() !== 'publish' && $listing->get_status() !== 'private') || ! hivepress()->marketplace->is_sellable( $listing ) ) {
			return hp\rest_error( 404 );
		}

		// Get meta.
		$meta = [];

		if ( get_option( 'hp_payout_system' ) === 'direct' ) {
			$meta['fees']['direct_payment'] = [
				'name'   => esc_html__( 'Direct Payment', 'hivepress-marketplace' ),
				'amount' => 0,
			];
		}

		// Add to cart.
		if ( ! hivepress()->marketplace->add_to_cart( $listing, $request->get_params(), $meta ) ) {
			return hp\rest_error( 400 );
		}

		// Render output.
		$output = '';

		if ( $request->get_param( '_render' ) ) {

			// Get price.
			$price = WC()->cart->get_total( 'edit' );

			if ( 'excl' === get_option( 'woocommerce_tax_display_shop' ) ) {
				$price -= WC()->cart->get_total_tax();
			}

			// Update price.
			$listing->set_price( $price );

			// Get block arguments.
			$block_args = hp\search_array_value( ( new Templates\Listing_View_Page() )->get_blocks(), [ 'blocks', 'listing_attributes_primary' ] );

			if ( ! $block_args ) {
				return hp\rest_error( 400 );
			}

			// Create block.
			$block = hp\create_class_instance(
				'\HivePress\Blocks\\' . $block_args['type'],
				[
					array_merge(
						$block_args,
						[
							'name'    => 'listing_attributes_primary',

							'context' => [
								'listing' => $listing,
							],
						]
					),
				]
			);

			// Render block.
			if ( $block ) {
				$output = $block->render();
			}
		}

		return hp\rest_response(
			200,
			[
				'html' => $output,
			]
		);
	}

	/**
	 * Delivers order.
	 *
	 * @param WP_REST_Request $request API request.
	 * @return WP_Rest_Response
	 */
	public function deliver_order( $request ) {

		// Check settings.
		if ( ! get_option( 'hp_order_require_delivery' ) ) {
			return hp\rest_error( 403 );
		}

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get order.
		$order = Models\Order::query()->get_by_id( $request->get_param( 'order_id' ) );

		if ( ! $order ) {
			return hp\rest_error( 404 );
		}

		// Check permissions.
		if ( $order->get_status() !== 'wc-processing' || $order->get_delivered_time() || $order->get_seller__id() !== get_current_user_id() ) {
			return hp\rest_error( 403 );
		}

		// Validate form.
		$form = ( new Forms\Order_Deliver() )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		// Update order.
		$order->set_delivered_time( time() )->save_delivered_time();

		// Get note text.
		$note_text = esc_html__( 'Order delivered', 'hivepress-marketplace' );

		if ( $form->get_value( 'note' ) ) {
			$note_text = '<strong>' . $note_text . '</strong>: ' . $form->get_value( 'note' );
		} else {
			$note_text .= '.';
		}

		// Create note.
		$note_id = wc_create_order_note( $order->get_id(), $note_text, true );

		if ( ! is_wp_error( $note_id ) ) {
			$note = Models\Order_Note::query()->get_by_id( $note_id );

			// Update note.
			$note->fill(
				[
					'author'               => $order->get_seller__id(),
					'author__display_name' => $order->get_vendor__name(),
					'author__email'        => $order->get_seller__email(),
				]
			)->save( [ 'author', 'author__display_name', 'author__email' ] );

			if ( get_option( 'hp_order_allow_attachment' ) ) {

				// Get attachment.
				$attachment = $order->get_attachment();

				if ( $attachment ) {

					// Update attachment.
					$attachment->fill(
						[
							'parent_model' => 'order_note',
							'parent__id'   => null,
							'parent'       => $note_id,
						]
					)->save( [ 'parent_model', 'parent__id', 'parent' ] );

					$order->set_attachment( null )->save_attachment();
					$note->set_attachment( $attachment->get_id() )->save_attachment();
				}
			}
		}

		// Send email.
		$user = $order->get_buyer();

		if ( $user ) {
			( new Emails\Order_Deliver(
				[
					'recipient' => $user->get_email(),

					'tokens'    => [
						'user'         => $user,
						'user_name'    => $user->get_display_name(),
						'order_number' => '#' . $order->get_id(),
						'order_url'    => hivepress()->router->get_url( 'order_view_page', [ 'order_id' => $order->get_id() ] ),
					],
				]
			) )->send();
		}

		return hp\rest_response(
			200,
			[
				'id' => $order->get_id(),
			]
		);
	}

	/**
	 * Rejects order.
	 *
	 * @param WP_REST_Request $request API request.
	 * @return WP_Rest_Response
	 */
	public function reject_order( $request ) {

		// Check settings.
		if ( ! get_option( 'hp_order_require_delivery' ) ) {
			return hp\rest_error( 403 );
		}

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get order.
		$order = Models\Order::query()->get_by_id( $request->get_param( 'order_id' ) );

		if ( ! $order ) {
			return hp\rest_error( 404 );
		}

		// Check permissions.
		if ( $order->get_status() !== 'wc-processing' || ! $order->get_delivered_time() || $order->get_revision_limit() === 0 || $order->get_buyer__id() !== get_current_user_id() ) {
			return hp\rest_error( 403 );
		}

		// Validate form.
		$form = ( new Forms\Order_Reject() )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		// Update order.
		$order->set_delivered_time( null );

		if ( $order->get_revision_limit() ) {
			$order->set_revision_limit( $order->get_revision_limit() - 1 );
		}

		$order->save( [ 'delivered_time', 'revision_limit' ] );

		// Get note text.
		$note_text = esc_html__( 'Delivery rejected', 'hivepress-marketplace' );

		if ( $form->get_value( 'note' ) ) {
			$note_text = '<strong>' . $note_text . '</strong>: ' . $form->get_value( 'note' );
		} else {
			$note_text .= '.';
		}

		// Create note.
		$note_id = wc_create_order_note( $order->get_id(), $note_text, true );

		if ( ! is_wp_error( $note_id ) ) {
			$note = Models\Order_Note::query()->get_by_id( $note_id );

			// Update note.
			$note->fill(
				[
					'author'               => $order->get_buyer__id(),
					'author__display_name' => $order->get_buyer__display_name(),
					'author__email'        => $order->get_buyer__email(),
				]
			)->save( [ 'author', 'author__display_name', 'author__email' ] );
		}

		// Send email.
		$user = $order->get_seller();

		if ( $user ) {
			( new Emails\Order_Reject(
				[
					'recipient' => $user->get_email(),

					'tokens'    => [
						'user'         => $user,
						'user_name'    => $user->get_display_name(),
						'order_number' => '#' . $order->get_id(),
						'order_note'   => $form->get_value( 'note' ),
						'order_url'    => hivepress()->router->get_url( 'order_edit_page', [ 'order_id' => $order->get_id() ] ),
					],
				]
			) )->send();
		}

		return hp\rest_response(
			200,
			[
				'id' => $order->get_id(),
			]
		);
	}

	/**
	 * Completes order.
	 *
	 * @param WP_REST_Request $request API request.
	 * @return WP_Rest_Response
	 */
	public function complete_order( $request ) {

		// Check settings.
		if ( ! get_option( 'hp_order_require_completion' ) ) {
			return hp\rest_error( 403 );
		}

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get order.
		$order = wc_get_order( $request->get_param( 'order_id' ) );

		if ( ! $order ) {
			return hp\rest_error( 404 );
		}

		// Check permissions.
		if ( $order->get_status() !== 'processing' || $order->get_user_id() !== get_current_user_id() ) {
			return hp\rest_error( 403 );
		}

		// Update order.
		$order->update_status( 'completed', '', true );

		return hp\rest_response(
			200,
			[
				'id' => $order->get_id(),
			]
		);
	}

	/**
	 * Disputes order.
	 *
	 * @param WP_REST_Request $request API request.
	 * @return WP_Rest_Response
	 */
	public function dispute_order( $request ) {

		// Check settings.
		if ( ! get_option( 'hp_order_allow_dispute' ) ) {
			return hp\rest_error( 403 );
		}

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get order.
		$order = Models\Order::query()->get_by_id( $request->get_param( 'order_id' ) );

		if ( ! $order ) {
			return hp\rest_error( 404 );
		}

		// Check permissions.
		if ( $order->get_status() !== 'wc-processing' || $order->get_buyer__id() !== get_current_user_id() ) {
			return hp\rest_error( 403 );
		}

		// Validate form.
		$form = ( new Forms\Order_Dispute() )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		// Send email.
		( new Emails\Order_Dispute(
			[
				'recipient' => get_option( 'admin_email' ),

				'tokens'    => [
					'order_number'    => '#' . $order->get_id(),
					'dispute_details' => $form->get_value( 'details' ),
					'order_url'       => admin_url(
						'post.php?' . http_build_query(
							[
								'action' => 'edit',
								'post'   => $order->get_id(),
							]
						)
					),
				],
			]
		) )->send();

		return hp\rest_response(
			200,
			[
				'id' => $order->get_id(),
			]
		);
	}

	/**
	 * Refunds order.
	 *
	 * @param WP_REST_Request $request API request.
	 * @return WP_Rest_Response
	 */
	public function refund_order( $request ) {

		// Check settings.
		if ( ! get_option( 'hp_order_allow_refunds' ) ) {
			return hp\rest_error( 403 );
		}

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		// Get order.
		$order = Models\Order::query()->get_by_id( $request->get_param( 'order_id' ) );

		if ( ! $order ) {
			return hp\rest_error( 404 );
		}

		// Check permissions.
		if ( $order->get_status() !== 'wc-processing' || get_current_user_id() !== $order->get_seller__id() ) {
			return hp\rest_error( 403 );
		}

		// Validate form.
		$form = ( new Forms\Order_Refund( [ 'model' => $order ] ) )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		// Get amount.
		$amount = $form->get_value( 'amount' );

		if ( 'full' === get_option( 'hp_order_allow_refunds' ) ) {
			$amount = $form->get_fields()['amount']->get_arg( 'max_value' );
		}

		// Refund order.
		if ( ! hivepress()->marketplace->refund_order(
			$order->get_id(),
			$amount,
			$form->get_value( 'reason' )
		) ) {
			return hp\rest_error( 400, hivepress()->translator->get_string( 'something_went_wrong' ) );
		}

		return hp\rest_response(
			200,
			[
				'id' => $order->get_id(),
			]
		);
	}

	/**
	 * Gets orders edit title.
	 *
	 * @return string
	 */
	public function get_orders_edit_title() {
		$title = null;

		if ( hivepress()->request->get_context( 'order_count' ) ) {
			$title = esc_html__( 'Received Orders', 'hivepress-marketplace' );
		} else {
			$title = hivepress()->translator->get_string( 'orders' );
		}

		return $title;
	}

	/**
	 * Redirects orders edit page.
	 *
	 * @return mixed
	 */
	public function redirect_orders_edit_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		// Check orders.
		if ( ! hivepress()->request->get_context( 'vendor_order_count' ) ) {
			return hivepress()->router->get_url( 'user_account_page' );
		}

		return false;
	}

	/**
	 * Renders orders edit page.
	 *
	 * @return string
	 */
	public function render_orders_edit_page() {

		// Query orders.
		hivepress()->request->set_context(
			'post_query',
			Models\Order::query()->filter(
				[
					'status__in' => [ 'wc-processing', 'wc-completed', 'wc-refunded' ],
					'seller'     => get_current_user_id(),
				]
			)->order( [ 'created_date' => 'desc' ] )
			->limit( 20 )
			->paginate( hivepress()->request->get_page_number() )
			->get_args()
		);

		// Render template.
		return ( new Blocks\Template(
			[
				'template' => 'orders_edit_page',

				'context'  => [
					'orders' => [],
				],
			]
		) )->render();
	}

	/**
	 * Gets order edit title.
	 *
	 * @return string
	 */
	public function get_order_edit_title() {
		$title = null;

		// Get order.
		$order = Models\Order::query()->get_by_id( hivepress()->request->get_param( 'order_id' ) );

		// Set title.
		if ( $order ) {
			/* translators: %s: order number. */
			$title = sprintf( esc_html__( 'Order %s', 'hivepress-marketplace' ), '#' . $order->get_id() );
		}

		// Set request context.
		hivepress()->request->set_context( 'order', $order );

		return $title;
	}

	/**
	 * Redirects order edit page.
	 *
	 * @return mixed
	 */
	public function redirect_order_edit_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		// Get order.
		$order = hivepress()->request->get_context( 'order' );

		if ( ! $order || ( ! current_user_can( 'edit_others_posts' ) && get_current_user_id() !== $order->get_seller__id() ) || ! in_array( $order->get_status(), [ 'wc-processing', 'wc-completed', 'wc-refunded' ], true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Renders order edit page.
	 *
	 * @return string
	 */
	public function render_order_edit_page() {
		return ( new Blocks\Template(
			[
				'template' => 'order_edit_page',

				'context'  => [
					'order' => hivepress()->request->get_context( 'order' ),
				],
			]
		) )->render();
	}

	/**
	 * Gets order view URL.
	 *
	 * @param array $params URL parameters.
	 * @return string
	 */
	public function get_order_view_url( $params ) {
		return wc_get_endpoint_url( 'view-order', hp\get_array_value( $params, 'order_id' ), wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * Matches order view URL.
	 *
	 * @return bool
	 */
	public function is_order_view_page() {
		return is_wc_endpoint_url( 'view-order' );
	}

	/**
	 * Redirects order view page.
	 *
	 * @return mixed
	 */
	public function redirect_order_view_page() {

		// Get order.
		$order = Models\Order::query()->get_by_id( get_query_var( 'view-order' ) );

		if ( ! $order ) {
			return true;
		}

		// Set request context.
		hivepress()->request->set_context( 'order', $order );

		return false;
	}

	/**
	 * Redirects vendor dashboard page.
	 *
	 * @return mixed
	 */
	public function redirect_vendor_dashboard_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		// Get vendor.
		$vendor = Models\Vendor::query()->filter(
			[
				'status__in' => [ 'pending', 'publish', 'private' ],
				'user'       => get_current_user_id(),
			]
		)->get_first();

		if ( ! $vendor ) {
			return true;
		}

		// Set request context.
		hivepress()->request->set_context( 'vendor', $vendor );

		return false;
	}

	/**
	 * Renders vendor dashboard page.
	 *
	 * @return string
	 */
	public function render_vendor_dashboard_page() {
		return ( new Blocks\Template(
			[
				'template' => 'vendor_dashboard_page',

				'context'  => [
					'vendor' => hivepress()->request->get_context( 'vendor' ),
				],
			]
		) )->render();
	}

	/**
	 * Redirects listing buy page.
	 *
	 * @return mixed
	 */
	public function redirect_listing_buy_page() {

		// Get listing.
		$listing = Models\Listing::query()->get_by_id( hivepress()->request->get_param( 'listing_id' ) );

		if ( ! $listing || ($listing->get_status() !== 'publish' && $listing->get_status() !== 'private') || ! hivepress()->marketplace->is_sellable( $listing ) ) {
			wp_die( esc_html__( 'Product not found.', 'hivepress-marketplace' ) );
		}

		// Get vendor.
		$vendor = $listing->get_vendor();

		if ( $vendor && $vendor->get_user__id() === get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t buy your own listings.', 'hivepress-marketplace' ) );
		}

		// Add to cart.
		if ( ! hivepress()->marketplace->add_to_cart( $listing, $_POST ) ) {
			wp_die( esc_html__( 'Product not found.', 'hivepress-marketplace' ) );
		}

		return wc_get_page_permalink( 'checkout' );
	}
}
