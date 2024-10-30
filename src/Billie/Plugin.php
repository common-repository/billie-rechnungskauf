<?php

namespace Billie;

use Automattic\WooCommerce\Admin\Overrides\Order;
use Billie\Admin\Option\Account;
use Billie\Billie\Gateway;
use Billie\Billie\PaymentInfo;
use Billie\Exceptions\GatewayNotFoundException;
use Exception;
use WC_Logger;
use WC_Order;
use WP_Post;
use function wc_get_logger;

class Plugin {
	public const ORDER_DATA_KEY = '_billieOrderData';
	public const DURATION_KEY = '_billieDuration';
	public const ORDER_ID_KEY = '_billieOrderId';
	public const SESSION_ID_KEY = '_billieSessionId';
	public const INVOICE_ID_KEY = '_billieInvoiceId';

	/** @var WC_Logger|null */
	private $logger;

	/**
	 * @param array $query
	 *
	 * @return string
	 */
	public static function get_callback_url( array $query ): string {
		if ( get_option( 'permalink_structure' ) === '' ) {
			$url = site_url() . '/?wc-api=billieplugin';
		} else {
			$url = site_url() . '/wc-api/billieplugin/';
		}

		// Parse shop URL to operate on it
		$parsed_url = parse_url( $url );

		// Check if the shop URL could be parsed, return $url as fallback
		if ( ! is_array( $parsed_url ) ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->error( 'Cannot build Billie callback URL, parse_url() fails to parse shop URL.' );
			}

			return $url;
		}

		$query_data = [];

		// If the shop URL contains a query string, parse it too
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_data );
		}

		// Make new query string from combined query data
		$parsed_url['query'] = http_build_query( array_merge( $query_data, $query ) );

		// Build URL from parts
		$url = self::unparse_url( $parsed_url );

		return $url;
	}

	/**
	 * Makes URL from parse_url data.
	 * @see https://www.php.net/manual/en/function.parse-url.php
	 *
	 * @param array $parsed_url Data as returned from parse_url.
	 *
	 * @return string The URL.
	 */
	private static function unparse_url( array $parsed_url ) {
		$scheme   = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port     = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user     = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass     = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query    = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

		return "{$scheme}{$user}{$pass}{$host}{$port}{$path}{$query}{$fragment}";
	}

	public function init(): void {
		if ( is_admin() ) {
			$order = new Admin\Order();
			$order->init();
		}

		add_filter( 'woocommerce_payment_gateways', [ Gateway::class, 'add' ] );

		/*
		 * Adds the billie javascript to the list of WordPress javascripts
		 */
		add_action( 'wp_head', [ $this, 'add_billie_js' ] );

		$plugin_rel_path = dirname( plugin_basename( __FILE__ ) ) . '/../../lang/';
		load_plugin_textdomain( 'billie-rechnungskauf', false, $plugin_rel_path );

		add_action( 'woocommerce_order_status_changed', static function($order_id, $from_status, $to_status) {
			self::get_gateway()->order_status_changed($order_id, $from_status, $to_status);
		}, 10, 3 );
		add_action( 'woocommerce_update_order', static function($order_id, $order) {
			self::get_gateway()->order_updated($order_id, $order);
		}, 10, 2 );
		add_action( 'woocommerce_api_billieplugin', [ $this, 'handle_callback' ] );

		/*
		 * This one adds the payment information to a Germanized Pro Invoice
		 */
		add_filter( 'woocommerce_gzdp_pdf_static_content', [
			$this,
			'add_billie_payment_info_to_germanized_pdf'
		], 10, 3 );

		/*
		 * This one adds the payment information to a WCPDF Invoice
		 */
		add_action( 'wpo_wcpdf_after_order_details', [
			$this,
			'add_billie_payment_info_to_wcpdf_pdf'
		], 10, 2 );

		/*
		 * This one adds the context to the normal woocommerce log files
		 */
		add_filter( 'woocommerce_format_log_entry', static function ( $entry, $log_data ) {
			if ( is_array( $log_data ) && isset( $log_data['context'] ) ) {
				$entry .= ' ' . wp_json_encode( $log_data['context'] );
			}

			return $entry;
		}, 0, 2 );

		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'change_address_warning' ], 10, 1 );
		$this->logger = wc_get_logger();
	}

	public function change_address_warning( WC_Order $order ): void {
		if ( $order->get_payment_method() !== Gateway::GATEWAY_ID ) {
			return;
		}
		wc_enqueue_js( "
        jQuery(document).ready(function() {
            jQuery( 'a.edit_address' ).remove();
        });
    " );
		echo '<p>' . esc_html__( 'Since this order will be paid via Billie you won\'t be able to change the addresses.', 'billie-rechnungskauf' ) . '</p>';
	}

	public function add_billie_js(): void {
		if ( is_checkout() ) {
			require_once( BILLIE_VIEW_PATH . '/checkout/billie-checkout.html' );
		}
	}

	public function handle_callback(): void {
		$billie_session_id = sanitize_text_field( $_GET['session_id'] ?? '' );
		if ( $this->is_callback_billie_decline() ) {
			self::get_gateway()->process_decline( $billie_session_id );
		} elseif ( $this->is_callback_billie_accept() ) {
			self::get_gateway()->process_accept( $billie_session_id );
		}
	}

	/**
	 * @param string $html
	 * @param $invoice
	 * @param string $where
	 *
	 * @return string
	 * @throws Exception
	 */
	public function add_billie_payment_info_to_germanized_pdf( string $html, $invoice, string $where ): string {
		if ( $where !== 'after_table' ) {
			$this->logger->debug( 'add_billie_payment_info_to_germanized_pdf', [
				'result' => ' table not added',
				'reason' => 'where is not after table',
				'where'  => $where,
				'html'   => $html
			] );

			return $html;
		}

		if ( ! is_object( $invoice ) ) {
			$this->logger->debug( 'add_billie_payment_info_to_germanized_pdf', [
				'result' => ' table not added',
				'reason' => 'invoice is not an object',
				'where'  => $where,
				'html'   => $html
			] );

			return $html;
		}

		if ( ! isset( $invoice->post ) || ! $invoice->post instanceof WP_Post ) {
			$this->logger->debug( 'add_billie_payment_info_to_germanized_pdf', [
				'result' => ' table not added',
				'reason' => 'invoice does not have a post object',
				'where'  => $where,
				'html'   => $html
			] );

			return $html;
		}

		$htmlWithTable = sprintf( "%s\n<br>\n%s", $html, $this->get_billie_payment_html( $invoice->get_order_number() ) );

		$this->logger->debug( 'add_billie_payment_info_to_germanized_pdf', [
			'result'        => ' table added',
			'order_number'  => $invoice->get_order_number(),
			'where'         => $where,
			'html'          => $html,
			'htmlWithTable' => $htmlWithTable
		] );

		return $htmlWithTable;
	}

	/**
	 * @param $type
	 * @param Order $order
	 *
	 * @throws Exception
	 */
	public function add_billie_payment_info_to_wcpdf_pdf( $type, Order $order ): void {
		if ( $type !== 'invoice' ) {
			return;
		}

		$this->add_billie_payment_info( $order->get_id() );
	}

	/**
	 * @param $order_id
	 *
	 * @return string
	 * @throws Exception
	 */
	public function get_billie_payment_html( $order_id ): string {
		return PaymentInfo::get_billie_payment_html( $order_id );
	}

	/**
	 * @param $order_id
	 *
	 * @throws Exception
	 */
	public function add_billie_payment_info( $order_id ): void {
		echo wp_kses_post( $this->get_billie_payment_html( $order_id ) );
	}

	/**
	 * @return Gateway
	 * @throws GatewayNotFoundException
	 */
	public static function get_gateway() {
		$payment_gateways = WC()->payment_gateways()->payment_gateways();
		foreach ( $payment_gateways as $payment_gateway_id => $payment_gateway ) {
			if ( Gateway::GATEWAY_ID === $payment_gateway_id ) {
				return $payment_gateway;
			}
		}

		throw new GatewayNotFoundException( __( 'Gateway not found', 'billie-rechnungskauf' ) );
	}

	private function is_callback_billie_decline(): bool {
		return isset( $_GET['type'] ) && $_GET['type'] === 'billie-decline';
	}

	private function is_callback_billie_accept(): bool {
		return isset( $_GET['type'] ) && $_GET['type'] === 'billie-accept';
	}
}
