<?php

namespace Billie\Billie;

use Billie\Admin\Option\Account;
use Billie\Exceptions\BillieException;
use Billie\Exceptions\NoInvoiceFoundException;
use Billie\Exceptions\ResponseException;
use Billie\Plugin;
use WC_Data_Exception;
use WC_Order;
use WC_Order_Refund;
use WC_Payment_Gateway;

class Gateway extends WC_Payment_Gateway {
	const GATEWAY_ID = 'billie';
	const CAPTURE_TARGET_STATUS_DEFAULT = 'wc-processing';

	/**
	 * @var string|void
	 */
	protected $method_name;

	/**
	 * @var void
	 */
	private $logger;

	/** @var Api */
	private $api;

	private $is_first_call_of_updated_order = true;

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->icon               = '';
		$this->has_fields         = true;
		$this->title              = __( 'Pay After Delivery', 'billie-rechnungskauf' );
		$this->method_title       = __( 'Billie', 'billie-rechnungskauf' );
		$this->method_description = __( 'Billie Description', 'billie-rechnungskauf' );
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		if ( isset( $this->settings['title'] ) ) {
			$this->title = $this->settings['title'];
		}

		if ( isset( $this->settings['description'], $this->settings['payment_term'] ) ) {
			$this->method_description = str_replace( '{Zahlungsziel}', $this->settings['payment_term'], $this->settings['description'] );
		}

		$this->api = new Api( $this->settings );

		$this->logger = wc_get_logger();
	}

	/**
	 * @param array $methods
	 *
	 * @return array
	 *
	 * This adds Billie as a payment method at the top of the method list
	 */
	public static function add( array $methods ): array {
		array_unshift( $methods, static::class );

		return $methods;
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'               => [
				'title'   => __( 'Enable/Disable', 'billie-rechnungskauf' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'billie-rechnungskauf' ),
				'default' => 'no',
			],
			'title'                 => [
				'title'       => __( 'Title', 'billie-rechnungskauf' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'billie-rechnungskauf' ),
				'default'     => __( 'Billie Rechnungskauf', 'billie-rechnungskauf' ),
				'desc_tip'    => true,
			],
			'payment_term'          => [
				'title'       => __( 'Payment Term', 'billie-rechnungskauf' ),
				'type'        => 'integer',
				'description' => __( 'Based upon your Billie contract, your customers will have between 7 and 120 days (payment term) to pay your invoices.', 'billie-rechnungskauf' ),
				'default'     => 30,
				'desc_tip'    => true,
			],
			'capture_target_status' => [
				'title'       => __( 'Sending invoice at this order status', 'billie-rechnungskauf' ),
				'type'        => 'select',
				'options'     => wc_get_order_statuses(),
				'default'     => self::CAPTURE_TARGET_STATUS_DEFAULT,
				'description' => __( 'When order changes to this status, the payment is captured at Billie.', 'billie-rechnungskauf' ),

			],
			'hide_logo'             => [
				'title' => __( 'Hide Logo', 'billie-rechnungskauf' ),
				'type'  => 'checkbox',
				'label' => __( 'Hide Billie Logo', 'billie-rechnungskauf' ),
			],
			'description'           => [
				'title'   => __( 'Customer Message', 'billie-rechnungskauf' ),
				'type'    => 'textarea',
				'default' => __( 'Bezahlen Sie bequem und sicher auf Rechnung - innerhalb von {Zahlungsziel} Tagen nach Erhalt der Ware.', 'billie-rechnungskauf' ),
			],
			'billie_sandbox_or_production' => [
				'title' => __( 'Sandbox or production', 'billie-rechnungskauf' ),
				'type'  => 'select',
				'options' => [
					'sandbox'    => __( 'Sandbox', 'billie-rechnungskauf' ),
					'production' => __( 'Production', 'billie-rechnungskauf' ),
				],
				'default' => 'sandbox',
			],
			'billie_client_id' => [
				'title' => __( 'Client ID', 'billie-rechnungskauf' ),
				'type' => 'text',
			],
			'billie_client_secret' => [
				'title' => __( 'Client Secret', 'billie-rechnungskauf' ),
				'type' => 'text',
			],
		];

		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, function ( $settings ) {
			if ( isset( $settings['payment_term'] ) ) {
				if ( ! is_numeric( $settings['payment_term'] ) ) {
					$settings['payment_term'] = 7;
				} elseif ( $settings['payment_term'] < 7 ) {
					$settings['payment_term'] = 7;
				} elseif ( $settings['payment_term'] > 120 ) {
					$settings['payment_term'] = 120;
				}
			}

			if ( isset( $settings['billie_sandbox_or_production'] )
			     && isset( $settings['billie_client_id'] )
			     && isset( $settings['billie_client_secret'] )
			) {
				$sandbox_or_production = $settings['billie_sandbox_or_production'];
				$billie_client_id = $settings['billie_client_id'];
				$billie_client_secret = $settings['billie_client_secret'];

				try {
					$this->api->validate_credentials(
						$billie_client_id,
						$billie_client_secret,
						$sandbox_or_production === 'sandbox'
					);
				} catch ( BillieException $e ) {
					\WC_Admin_Settings::add_error(
						sprintf(
							__( 'Client settings error: %s', 'billie-rechnungskauf' ),
							$e->getMessage()
						)
					);
				}
			}

			return $settings;
		} );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [
			$this,
			'process_admin_options'
		] );
	}

	public function payment_fields(): void {
		include BILLIE_VIEW_PATH . '/checkout/payment-form.php';
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 * @throws WC_Data_Exception
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		$billie_session_id = $this->get_billie_session_id( WC()->session->get_customer_id() );
		$duration          = ( is_array( $this->settings ) && isset( $this->settings['payment_term'] ) && is_numeric( $this->settings['payment_term'] ) ) ? $this->settings['payment_term'] : 7;
		$billie_order_data = $this->api->extract_billie_order_data_from_order( $billie_session_id, $order, $duration );
		$order->update_meta_data( Plugin::ORDER_DATA_KEY, $billie_order_data );
		$response = $this->api->hpp_checkout_session_create( $billie_order_data );
		$order->update_meta_data( Plugin::SESSION_ID_KEY, $response['session_id'] );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $response['hpp_url'],
		);
	}

	public function process_decline( string $billie_session_id ): void {
		$order_id = $this->get_order_id_for_billie_session_id( $billie_session_id );
		$order    = wc_get_order( $order_id );

		try {
			$authorization_data = $this->api->checkout_session_authorization( $billie_session_id );
			$decline_reason     = $authorization_data['decline_reason'] ?? '';
			if ( in_array( $decline_reason, array( 'risk_policy', 'debtor_limit_exceeded' ) ) ) {
				WC()->session->set( 'billie_decline_reason', $decline_reason );
			}
			wc_add_notice( __( 'Payment declined.', 'billie-rechnungskauf' ), 'error' );
			$order->update_status( 'failed', __( 'Payment declined.', 'billie-rechnungskauf' ) );
		} catch ( \Exception $e ) {
			wc_add_notice( __( 'Payment failed.', 'billie-rechnungskauf' ), 'error' );
			$order->update_status( 'failed', __( 'Payment failed.', 'billie-rechnungskauf' ) );
		}
		wp_redirect( wc_get_checkout_url() );
		exit;
	}

	public function process_accept( string $billie_session_id ): void {
		$order_id = $this->get_order_id_for_billie_session_id( $billie_session_id );
		if ( ! $order_id ) {
			wc_add_notice( __( 'Order not found.', 'billie-rechnungskauf' ), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit;
		}
		$order = wc_get_order( $order_id );

		$authorization_data = $this->api->checkout_session_authorization( $billie_session_id );
		if ( $authorization_data['state'] !== 'authorized' ) {
			wc_add_notice( __( 'Payment not authorized.', 'billie-rechnungskauf' ), 'error' );
			wp_redirect( wc_get_checkout_url() );
		}

		$billie_order_data = $order->get_meta( Plugin::ORDER_DATA_KEY );
		$billie_order_data = $this->api->checkout_session_confirm( $order, $billie_session_id, $billie_order_data, $authorization_data );
		$this->logger->debug( 'billie_order_data', $billie_order_data );

		$order->update_meta_data( Plugin::ORDER_DATA_KEY, $billie_order_data );
		$order->update_meta_data( Plugin::ORDER_ID_KEY, $billie_order_data['uuid'] );
		$order->save();

		if ( isset( $billie_order_data['debtor']['bank_account']['iban'] ) ) {
			$order->set_transaction_id( $billie_order_data['debtor']['bank_account']['iban'] );
			$this->logger->debug( 'setting transaction id', [ 'transaction_id' => $billie_order_data['debtor']['bank_account']['iban'] ] );
		}

		$use_shipping_address = ( $order->get_formatted_billing_address() !== $order->get_formatted_shipping_address() );

		$billing_company = null;

		if ( isset( $billie_order_data['debtor']['name'] ) ) {
			$billing_company = sanitize_text_field( $billie_order_data['debtor']['name'] );
		}

		if ( $billing_company !== null ) {
			$order->set_billing_company( $billing_company );
			$this->logger->debug( 'setting billing company', [ 'company' => $billing_company ] );
		}

		/*
		 * Rechnungsadresse übernehmen
		 */
		$billing_address_1 = null;
		$billing_postcode  = null;
		$billing_city      = null;
		$billing_country   = null;

		if ( isset( $billie_order_data['debtor']['billing_address']['street'], $billie_order_data['debtor']['billing_address']['house_number'] ) ) {
			$billing_address_1 = implode( ' ', [
				sanitize_text_field( $billie_order_data['debtor']['billing_address']['street'] ),
				sanitize_text_field( $billie_order_data['debtor']['billing_address']['house_number'] ),
			] );
		}

		if ( isset( $billie_order_data['debtor']['billing_address']['postal_code'] ) ) {
			$billing_postcode = sanitize_text_field( $billie_order_data['debtor']['billing_address']['postal_code'] );
		}

		if ( isset( $billie_order_data['debtor']['billing_address']['city'] ) ) {
			$billing_city = sanitize_text_field( $billie_order_data['debtor']['billing_address']['city'] );
		}
		if ( isset( $billie_order_data['debtor']['billing_address']['country'] ) ) {
			$billing_country = sanitize_text_field( $billie_order_data['debtor']['billing_address']['country'] );
		}

		if ( $billing_address_1 !== null ) {
			$order->set_billing_address_1( $billing_address_1 );
			$this->logger->debug( 'setting billing address 1', [ 'address_1' => $billing_address_1 ] );
		}

		if ( $billing_postcode !== null ) {
			$order->set_billing_postcode( $billing_postcode );
			$this->logger->debug( 'setting billing postcode', [ 'postcode' => $billing_postcode ] );
		}

		if ( $billing_city !== null ) {
			$order->set_billing_city( $billing_city );
			$this->logger->debug( 'setting billing city', [ 'city' => $billing_city ] );
		}

		if ( $billing_country !== null ) {
			$order->set_billing_country( $billing_country );
			$this->logger->debug( 'setting billing country', [ 'country' => $billing_country ] );
		}


		if ( ! $use_shipping_address ) {
			if ( $billing_company !== null ) {
				$order->set_shipping_company( $billing_company );
			}

			if ( $billing_address_1 !== null ) {
				$order->set_shipping_address_1( $billing_address_1 );
				$this->logger->debug( 'setting shipping address 1 to billing address 1', [ 'address_1' => $billing_address_1 ] );
			}

			if ( $billing_postcode !== null ) {
				$order->set_shipping_postcode( $billing_postcode );
				$this->logger->debug( 'setting shipping postcode to billing postcode', [ 'postcode' => $billing_postcode ] );
			}

			if ( $billing_city !== null ) {
				$order->set_shipping_city( $billing_city );
				$this->logger->debug( 'setting shipping city to billing city', [ 'city' => $billing_city ] );
			}

			if ( $billing_country !== null ) {
				$order->set_shipping_country( $billing_country );
				$this->logger->debug( 'setting shipping country to billing country', [ 'country' => $billing_country ] );
			}
		} else {
			/*
             * Lieferadresse übernehmen
             */
			$shipping_address_1 = null;
			$shipping_postcode  = null;
			$shipping_city      = null;
			$shipping_country   = null;

			if ( isset( $billie_order_data['delivery_address']['street'], $billie_order_data['delivery_address']['house_number'] ) ) {
				$shipping_address_1 = implode( ' ', [
					sanitize_text_field( $billie_order_data['delivery_address']['street'] ),
					sanitize_text_field( $billie_order_data['delivery_address']['house_number'] ),
				] );
			}

			if ( isset( $billie_order_data['delivery_address']['postal_code'] ) ) {
				$shipping_postcode = sanitize_text_field( $billie_order_data['delivery_address']['postal_code'] );
			}

			if ( isset( $billie_order_data['delivery_address']['city'] ) ) {
				$shipping_city = sanitize_text_field( $billie_order_data['delivery_address']['city'] );
			}
			if ( isset( $billie_order_data['delivery_address']['country'] ) ) {
				$shipping_country = sanitize_text_field( $billie_order_data['delivery_address']['country'] );
			}

			if ( $shipping_address_1 !== null ) {
				$order->set_shipping_address_1( $shipping_address_1 );
				$this->logger->debug( 'setting shipping address 1', [ 'address_1' => $shipping_address_1 ] );
			}

			if ( $shipping_postcode !== null ) {
				$order->set_shipping_postcode( $shipping_postcode );
				$this->logger->debug( 'setting shipping postcode', [ 'postcode' => $shipping_postcode ] );
			}

			if ( $shipping_city !== null ) {
				$order->set_shipping_city( $shipping_city );
				$this->logger->debug( 'setting shipping city', [ 'city' => $shipping_city ] );
			}

			if ( $shipping_country !== null ) {
				$order->set_shipping_country( $shipping_country );
				$this->logger->debug( 'setting shipping country', [ 'country' => $shipping_country ] );
			}
		}

		$order->update_status( 'wc-on-hold', wc_get_order_status_name( 'wc-on-hold' ) );
		$order->add_order_note(
			sprintf(
				__( '[Billie] Order has been created: %s', 'billie-rechnungskauf' ),
				$billie_order_data['uuid']
			)
		);

		WC()->cart->empty_cart();
		/*
		 * We remove the billie session id here,
		 * otherwise we might try to use the same session id for the next order, which will trigger an
		 * authorization error
		 */
		WC()->session->set( 'billieSession', null );

		wp_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * @throws ResponseException
	 * @throws BillieException
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		if ( (float) $amount <= 0.0 ) {
			return new \WP_Error( 1, __( 'Debit amount must be greater than zero.', 'billie-rechnungskauf' ) );
		}

		$order = new \WC_Order( $order_id );
		// The first item in the array is the refund for this call
		$refund = $order->get_refunds()[0];

		try {
			$this->create_credit_note( new \WC_Order( $order ), $refund );
		} catch ( \Exception $e ) {
			return new \WP_Error( 1, $e->getMessage() );
		}

		return true;
	}

	public function is_available() {
		if ( is_admin() ) {
			return true;
		}

		// not avialable outside of Germany
		if ( WC()->customer && WC()->customer->get_billing_country() !== 'DE' ) {
			return false;
		}

		// not available if specific errors occured
		if ( WC()->session ) {
			$declined_reason   = trim( (string) WC()->session->get( 'billie_decline_reason' ) );
			$reasons_to_remove = [
				'risk_policy',
				'debtor_limit_exceeded'
			];

			if ( $declined_reason && in_array( $declined_reason, $reasons_to_remove, true ) ) {
				return false;
			}
		}

		return parent::is_available();
	}

	public function validate_fields() {
		$billing_company = '';
		// Gets billing_company from "pay for order" page.
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( 0 < $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$billing_company = $order->get_billing_company();
			}

			// Gets billing_company from cart/checkout.
		} else {
			$billing_company = WC()->cart->get_customer()->get_billing_company();
		}

		if ( empty( $billing_company ) ) {
			wc_add_notice( __( 'This payment option is only available for company customers.', 'billie-rechnungskauf' ), 'error' );

			return false;
		}

		return true;
	}

	/**
	 * @param int $order_id
	 * @param WC_Order $order
	 *
	 * @return void
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function order_updated( $order_id, $order ): void {
		// This hook always is called twice
		if ( ! $this->is_first_call_of_updated_order ) {
			$this->is_first_call_of_updated_order = true;

			return;
		}
		$this->is_first_call_of_updated_order = false;

		$billie_order_id = $order->get_meta( Plugin::ORDER_ID_KEY );
		if ( $billie_order_id ) {
			$billie_invoice_uuid = $order->get_meta( Plugin::INVOICE_ID_KEY );
			if ( ! $billie_invoice_uuid ) {
				$this->api->update_order( $billie_order_id, [
					'amount' => [
						'gross' => round( $order->get_total(), 2 ),
						'net'   => round( $order->get_total() - $order->get_total_tax(), 2 ),
						'tax'   => round( $order->get_total_tax(), 2 ),
					],
				] );
				$order->add_order_note(
					sprintf(
						__( '[Billie] Order %s has been updated', 'billie-rechnungskauf' ),
						$billie_order_id
					)
				);
			}
		}
	}

	/**
	 * @param $order_id
	 * @param $from_status
	 * @param $to_status
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function order_status_changed( $order_id, $from_status, $to_status ): void {
		$order = new WC_Order( $order_id );
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$billie_invoice_uuid = $order->get_meta( Plugin::INVOICE_ID_KEY );
		if ( in_array( $to_status, [ 'cancelled', 'refunded' ] ) ) {
			if ( $billie_invoice_uuid ) {
				$this->cancel_invoice( $order );
			} else {
				$this->cancel_order( $order );
			}
		}

		$capture_target_status       = $this->settings['capture_target_status'] ?? self::CAPTURE_TARGET_STATUS_DEFAULT;
		$plain_capture_target_status = substr( $capture_target_status, 3 );
		if ( ! $billie_invoice_uuid && $to_status === $plain_capture_target_status ) {
			$this->create_invoice( $order );
		}
	}

	/**
	 * @param $wc_session_id
	 *
	 * @return string|null
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function get_billie_session_id( $wc_session_id ): ?string {
		$billie_session_id = WC()->session->get( 'billieSession' );

		$needs_new_session_id = false;

		if ( $billie_session_id === null ) {
			$needs_new_session_id = true;
		}

		if ( $needs_new_session_id ) {
			$billie_session_id = $this->api->get_billie_session_id( $wc_session_id );
			WC()->session->set( 'billieSession', $billie_session_id );
		}

		return $billie_session_id;
	}

	protected function get_order_id_for_billie_session_id( string $billie_session_id ): int {
		$orders = wc_get_orders(
			array(
				'meta_key'     => Plugin::SESSION_ID_KEY,
				'meta_value'   => $billie_session_id,
				'meta_compare' => 'LIKE',
			),
		);
		if ( count( $orders ) === 1 ) {
			return array_shift( $orders )->get_id();
		}

		return 0;
	}

	/**
	 * @param WC_Order $order
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function cancel_order( WC_Order $order ): void {
		$billie_order_id = $order->get_meta( Plugin::ORDER_ID_KEY );
		$this->api->cancel_order( $billie_order_id );
		$order->add_order_note(
			sprintf(
				__( '[Billie] Order %s has been deleted', 'billie-rechnungskauf' ),
				$billie_order_id
			)
		);
	}

	/**
	 * @param WC_Order $order
	 * @param WC_Order_Refund $refund
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function create_credit_note( WC_Order $order, $refund ): void {
		$billie_invoice_id = $order->get_meta( Plugin::INVOICE_ID_KEY );
		if ( ! $billie_invoice_id ) {
			throw new NoInvoiceFoundException( __( 'This order has not been captured yet.', 'billie-rechnungskauf' ) );
		}

		$parameters = [
			'external_code' => PaymentInfo::get_invoice_id( $order ),
			'amount'        => [
				'gross' => - round( $refund->get_total(), 2 ),
				'net'   => - round( $refund->get_total() - $refund->get_total_tax(), 2 ),
				'tax'   => - round( $refund->get_total_tax(), 2 ),
			],
		];

		$response = $this->api->create_invoice_note( $billie_invoice_id, $parameters );
		$order->add_order_note(
			sprintf(
				__( '[Billie] Credit note for invoice %s has been created: %s', 'billie-rechnungskauf' ),
				$billie_invoice_id,
				$response['uuid']
			)
		);
	}

	/**
	 * @param WC_Order $order
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function create_invoice( WC_Order $order ): void {
		$billie_order_id = $order->get_meta( Plugin::ORDER_ID_KEY );

		$invoice_id = PaymentInfo::get_invoice_id( $order );

		if ( trim( (string) $invoice_id ) === '' ) {
			$this->logger->error( 'Invoice ID not set', [
				'order' => $order->get_id(),
			] );
			throw new BillieException( __( 'Invoice ID not set', 'billie-rechnungskauf' ) );
		}

		$parameters = [
			'orders'        => [ $billie_order_id ],
			'external_code' => $invoice_id,
			'invoice_url'   => $this->get_return_url( $order ),
			'amount'        => [
				'gross' => round( $order->get_total(), 2 ),
				'net'   => round( $order->get_total() - $order->get_total_tax(), 2 ),
				'tax'   => round( $order->get_total_tax(), 2 ),
			],
		];

		$response = $this->api->create_invoice( $parameters );
		$order->update_meta_data( Plugin::INVOICE_ID_KEY, $response['uuid'] );
		$order->save();
		$order->add_order_note(
			sprintf(
				__( '[Billie] Invoice has been created: %s', 'billie-rechnungskauf' ),
				$response['uuid']
			)
		);
	}

	/**
	 * @param WC_Order $order
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function cancel_invoice( $order ): void {
		$billie_invoice_uuid = $order->get_meta( Plugin::INVOICE_ID_KEY );

		$this->api->cancel_invoice( $billie_invoice_uuid );
		$order->add_order_note(
			sprintf(
				__( '[Billie] Invoice %s has been deleted', 'billie-rechnungskauf' ),
				$billie_invoice_uuid
			)
		);
	}
}
