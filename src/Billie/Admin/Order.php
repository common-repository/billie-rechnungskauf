<?php

namespace Billie\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Billie\Billie\Gateway;
use Billie\Billie\PaymentInfo;
use WC_Order;
use WP_Post;

class Order {
	public function init() {
		add_action( 'add_meta_boxes_shop_order', [ $this, 'add_payment_info_box' ] );

		if ( ! class_exists( \WC_GZDP_Invoice::class ) ) {
			add_action( 'add_meta_boxes_shop_order', [ $this, 'add_invoice_box' ] );
			add_action( 'save_post', [ $this, 'save_invoice_id' ] );
		}
	}

	public function add_payment_info_box( $post_or_order_object ) {
		$order = $this->check_and_get_billie_order( $post_or_order_object );

		if ( $order === null ) {
			return;
		}

		$bille_payment_html = PaymentInfo::get_billie_payment_html( $order->get_id(), true );
		if ( $bille_payment_html ) {
			$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';
			add_meta_box( 'billie_payment_info',
				__( 'Payment info', 'billie-rechnungskauf' ),
				static function () use ( $bille_payment_html ) {
					echo wp_kses_post( $bille_payment_html );
				},
				$screen,
				'normal'
			);
		}
	}

	public function add_invoice_box( $post_or_order_object ) {
		$order = $this->check_and_get_billie_order( $post_or_order_object );

		if ( $order === null ) {
			return;
		}

		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'billie_invoice_box',
			__( 'Invoice', 'billie-rechnungskauf' ),
			static function () use ( $order ) {
				$invoice_id     = PaymentInfo::get_invoice_id( $order );
				$invoice_status = $order->get_status();
				include BILLIE_VIEW_PATH . '/admin/order-invoice.php';
			},
			$screen,
			'normal'
		);
	}

	public function save_invoice_id( $post_id ) {
		if ( array_key_exists( 'billie_invoice_id', $_POST )
			&& 	check_admin_referer( 'billie_invoice_id' . $post_id, '_wpnonce_billie_invoice_id' )
		) {
			$order = wc_get_order( $post_id );
			$order->update_meta_data( '_billie_invoice_id', sanitize_text_field( $_POST['billie_invoice_id'] ) );
			$order->save();
		}
	}

	private function check_and_get_billie_order( $post_or_order_object ) {
		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( $order->get_payment_method() !== Gateway::GATEWAY_ID ) {
			return null;
		}

		return $order;
	}
}