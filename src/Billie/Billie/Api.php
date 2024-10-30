<?php

namespace Billie\Billie;

use Billie\Billie\Api\Token;
use Billie\Exceptions\BillieException;
use Billie\Exceptions\CredentialsNotSetException;
use Billie\Exceptions\ResponseException;
use Billie\Plugin;
use WP_Error;

class Api {
	private $settings;

	private $logger;

	public function __construct( $gateway_settings ) {
		$this->settings = $gateway_settings;
		$this->logger   = wc_get_logger();
	}

	/**
	 * @throws BillieException
	 * @throws CredentialsNotSetException
	 * @throws ResponseException
	 */
	public function validate_credentials( $billie_client_id, $billie_client_secret, $is_sandbox ): void {
		$oauthToken = $this->request_oauth_token( $billie_client_id, $billie_client_secret, $is_sandbox );

		$result = $this->get( '/oauth/authorization', null, $oauthToken, $is_sandbox );

		$validationResult = json_decode( $result['body'], true );

		if ( ! isset( $validationResult['client_id'] ) ) {
			throw new BillieException( __( 'Unexpected validation format', 'billie-rechnungskauf' ) );
		}

		if ( $validationResult['client_id'] !== $billie_client_id ) {
			throw new BillieException( __( 'Unexpected validation client id', 'billie-rechnungskauf' ) );
		}
	}

	/**
	 * @param string $client_id
	 * @param string $client_secret
	 * @param bool $sandbox
	 *
	 * @return Token
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function request_oauth_token( string $client_id, string $client_secret, bool $sandbox = false ): Token {
		$body = [
			'grant_type'    => 'client_credentials',
			'client_id'     => $client_id,
			'client_secret' => $client_secret
		];

		$result = $this->post( '/oauth/token', $body, null, $sandbox, false );

		$tokenResult = json_decode( $result['body'], true );

		if ( ! isset( $tokenResult['expires_in'], $tokenResult['access_token'] ) ) {
			throw new BillieException( 'Unexpected Token format' );
		}

		return new Token( $tokenResult['access_token'], $tokenResult['expires_in'] );
	}

	/**
	 * @param $wooCommerceSessionId
	 *
	 * @return string
	 * @throws BillieException
	 * @throws ResponseException
	 * @throws CredentialsNotSetException
	 */
	public function get_billie_session_id( $wooCommerceSessionId ): string {
		$body = [
			'merchant_customer_id' => $wooCommerceSessionId,
		];

		if (
			empty( $this->settings ) || ! is_array( $this->settings ) || ! array_key_exists( 'billie_client_id', $this->settings ) || $this->settings['billie_client_id'] === '' || $this->settings['billie_client_id'] === null ||
			! array_key_exists( 'billie_client_secret', $this->settings ) || $this->settings['billie_client_secret'] === '' || $this->settings['billie_client_secret'] === null
		) {
			throw new CredentialsNotSetException( __( 'Missing Credentials', 'billie-rechnungskauf' ) );
		}

		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$result        = $this->post( '/checkout-sessions', $body, $oauthToken, $this->is_sandbox() );
		$sessionResult = json_decode( $result['body'], true );

		if ( ! is_array( $sessionResult ) || ! isset( $sessionResult['id'] ) ) {
			throw new BillieException( 'Unexpected Session format' );
		}

		return $sessionResult['id'];
	}

	private function extract_street_and_housenumber( string $street ): array {
		$split = explode( ' ', $street );
		if ( ! preg_match( '/^\d+[a-zA-Z]*$/', $split[ count( $split ) - 1 ] ) ) {
			return array( $street, '' );
		}
		$street_name = $split[0];
		for ( $i = 1; $i < count( $split ) - 1; $i ++ ) {
			$street_name .= ' ' . $split[ $i ];
		}

		return array( $street_name, $split[ count( $split ) - 1 ] );
	}

	public function extract_billie_order_data_from_order( string $billie_session_id, \WC_Order $order, int $duration ): array {
		[ $street, $house_number ] = $this->extract_street_and_housenumber( $order->get_billing_address_1() );
		$billing_address = [
			'addition'     => $order->get_billing_address_2(),
			'street'       => $street,
			'house_number' => $house_number,
			'city'         => $order->get_billing_city(),
			'postal_code'  => $order->get_billing_postcode(),
			'country'      => $order->get_billing_country(),
		];
		[ $street, $house_number ] = $this->extract_street_and_housenumber( $order->get_shipping_address_1() );
		$delivery_address = [
			'addition'     => $order->get_shipping_address_2(),
			'street'       => $street,
			'house_number' => $house_number,
			'city'         => $order->get_shipping_city(),
			'postal_code'  => $order->get_shipping_postcode(),
			'country'      => $order->get_shipping_country(),
		];

		$line_items = [];
		foreach ( $order->get_items() as $item_data ) {
			$product      = $item_data->get_product();
			$line_items[] = [
				'external_id' => ( string ) ( $item_data['product_id'] ?? '' ),
				'title'       => $product->get_title(),
				'quantity'    => $item_data['quantity'] ?? null,
				'amount'      => [
					'net'   => round( ( float ) $item_data['line_total'], 2 ),
					'gross' => round( ( float ) $item_data['line_total'] + ( float ) $item_data['line_tax'], 2 ),
					'tax'   => round( ( float ) $item_data['line_tax'], 2 ),
				]
			];
		}
		[ $street, $house_number ] = $this->extract_street_and_housenumber( $order->get_billing_address_1() );
		$billie_order_data = [
			'channel'          => 'hpp_ecommerce',
			'duration'         => $duration,
			'amount'           => [
				'gross' => round( $order->get_total(), 2 ),
				'net'   => round( $order->get_total() - $order->get_total_tax(), 2 ),
				'tax'   => round( $order->get_total_tax(), 2 ),
			],
			'order_id'         => ( string ) $order->get_id(),
			'delivery_address' => $delivery_address,
			'billing_address'  => $billing_address,
			'debtor_company'   => [
				'merchant_customer_id' => $billie_session_id,
				'name'                 => $order->get_billing_company() ?? $order->get_billing_last_name(),
				'legal_form'           => '', // needs to be present, but can be empty
				'address_street'       => $street,
				'address_house_number' => $house_number,
				'address_city'         => $order->get_billing_city(),
				'address_postal_code'  => $order->get_billing_postcode(),
				'address_country'      => $order->get_billing_country(),
			],
			'debtor_person'    => [
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
			],
			'line_items'       => $line_items,
			'merchant_urls'    => [
				'accept_url'  => Plugin::get_callback_url( [ 'type' => 'billie-accept' ] ),
				'decline_url' => Plugin::get_callback_url( [ 'type' => 'billie-decline' ] ),
			],
		];

		$salutation = (int) $order->get_meta( '_billing_title' ); // from plugin "germanized"
		if ( $salutation === 1 ) {
			$billie_order_data['debtor_person']['salutation'] = 'm';
		} elseif ( $salutation === 2 ) {
			$billie_order_data['debtor_person']['salutation'] = 'f';
		} else {
			$salutation = (string) $order->get_meta( '_billing_title' ); // from plugin "f4"
			if ( $salutation === 'mr' ) {
				$billie_order_data['debtor_person']['salutation'] = 'm';
			} elseif ( $salutation === 'mrs' ) {
				$billie_order_data['debtor_person']['salutation'] = 'f';
			}
		}

		return $billie_order_data;
	}

	public function hpp_checkout_session_create( array $billie_session_data ): array {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$result = $this->post( '/hpp-checkout-sessions', $billie_session_data, $oauthToken, $this->is_sandbox() );

		return json_decode( $result['body'], true );
	}

	/**
	 * @param string $billie_session_id
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function checkout_session_authorization( string $billie_session_id ): array {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );
		$result     = $this->get( sprintf( '/checkout-sessions/%s/authorization', $billie_session_id ), null, $oauthToken, $this->is_sandbox() );

		return json_decode( $result['body'], true );
	}

	/**
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function checkout_session_confirm( \WC_Order $order, string $billie_session_id, array $billie_order_data, array $authorization_data ): array {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$updated_billie_order_data = [
			'amount'           => $billie_order_data['amount'],
			'duration'         => $billie_order_data['duration'],
			'debtor'           => [
				'name'            => $authorization_data['debtor']['name'],
				'company_address' => [
					'house_number' => $authorization_data['debtor']['company_address']['house_number'],
					'street'       => $authorization_data['debtor']['company_address']['street'],
					'postal_code'  => $authorization_data['debtor']['company_address']['postal_code'],
					'city'         => $authorization_data['debtor']['company_address']['city'],
					'country'      => $authorization_data['debtor']['company_address']['country'],
				],
			],
			'delivery_address' => $billie_order_data['delivery_address'],
			'external_code'    => (string) $order->get_id(),
		];

		$result = $this->put( sprintf( '/checkout-sessions/%s/confirm', $billie_session_id ), $updated_billie_order_data, $oauthToken, $this->is_sandbox() );

		return json_decode( $result['body'], true );
	}

	/**
	 * @param string $billie_uuid
	 * @param array $parameters
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function update_order( $billie_uuid, $parameters ): void {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$this->post( sprintf( '/orders/%s', $billie_uuid ), $parameters, $oauthToken, $this->is_sandbox() );
	}

	/**
	 * @param $billie_uuid
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function cancel_order( $billie_uuid ): void {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$this->post( sprintf( '/orders/%s/cancel', $billie_uuid ), [], $oauthToken, $this->is_sandbox() );
	}

	/**
	 * @param array $parameters
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function create_invoice( array $parameters ): array {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$result = $this->post( '/invoices', $parameters, $oauthToken, $this->is_sandbox() );

		return json_decode( $result['body'], true );
	}

	/**
	 * @param string $billie_invoice_uuid
	 * @param array $parameters
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function create_invoice_note( $billie_invoice_uuid, $parameters ): array {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$result = $this->post( sprintf( '/invoices/%s/credit-notes', $billie_invoice_uuid ), $parameters, $oauthToken, $this->is_sandbox() );

		return json_decode( $result['body'], true );
	}

	/**
	 * @param string $billie_invoice_uuid
	 *
	 * @throws BillieException
	 * @throws ResponseException
	 */
	public function cancel_invoice( $billie_invoice_uuid ): void {
		$oauthToken = $this->request_oauth_token( $this->settings['billie_client_id'], $this->settings['billie_client_secret'], $this->is_sandbox() );

		$this->delete( sprintf( '/invoices/%s', $billie_invoice_uuid ), [], $oauthToken, $this->is_sandbox() );
	}

	/**
	 * @param $path
	 * @param array|string|null $body
	 * @param Token|null $token
	 * @param bool $sandbox
	 *
	 * @param bool $json_request
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function post( $path, array $body = null, Token $token = null, bool $sandbox = false, bool $json_request = true ): array {
		return $this->request( $path, $body, $json_request, $token, 'POST', $sandbox );
	}

	/**
	 * @param $path
	 * @param array|string|null $body
	 * @param Token|null $token
	 * @param bool $sandbox
	 *
	 * @param bool $json_request
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function put( $path, array $body = null, Token $token = null, bool $sandbox = false, bool $json_request = true ): array {
		return $this->request( $path, $body, $json_request, $token, 'PUT', $sandbox );
	}

	/**
	 * @param $path
	 * @param array|null $body
	 * @param null $token
	 * @param bool $sandbox
	 * @param bool $json_request
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 * @noinspection PhpSameParameterValueInspection
	 * @noinspection PhpReturnValueOfMethodIsNeverUsedInspection
	 */
	private function patch( $path, array $body = null, $token = null, bool $sandbox = false, bool $json_request = true ): array {
		return $this->request( $path, $body, $json_request, $token, 'PATCH', $sandbox );
	}

	/**
	 * @param $path
	 * @param array|null $body
	 * @param null $token
	 * @param bool $sandbox
	 * @param bool $json_request
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 * @noinspection PhpSameParameterValueInspection
	 * @noinspection PhpReturnValueOfMethodIsNeverUsedInspection
	 */
	private function delete( $path, array $body = null, $token = null, bool $sandbox = false, bool $json_request = true ): array {
		return $this->request( $path, $body, $json_request, $token, 'DELETE', $sandbox );
	}

	/**
	 * @param string $path
	 * @param array|null $parameters
	 * @param Token|null $token
	 * @param bool $sandbox
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function get( string $path, array $parameters = null, Token $token = null, bool $sandbox = false ): array {
		if ( $parameters !== null ) {
			$path .= '&' . http_build_query( $parameters );
		}

		return $this->request( $path, null, false, $token, 'GET', $sandbox );
	}


	/**
	 * @param $result
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function validate_remote_result( $result ): array {
		$this->logger->debug( 'validating', [
			'result' => $result,
		] );


		if ( $result instanceof WP_Error ) {
			throw new BillieException( $result->get_error_message(), $result->get_error_code() );
		}

		if ( ! isset( $result['body'], $result['response']['code'], $result['response']['message'] ) || ! is_array( $result ) ) {
			throw new BillieException( 'Unexpected API response format' );
		}

		if ( strpos( $result['response']['code'], '2' ) !== 0 ) {
			$result['body'] = json_decode( $result['body'], true );
			$message        = $result['body']['errors'][0]['title'] ?? $result['response']['message'];

			throw new ResponseException( $message, $result['response']['code'], $result['body'] );
		}

		return $result;
	}

	private function is_sandbox(): bool {
		if (
			is_array( $this->settings ) &&
			isset( $this->settings['billie_sandbox_or_production'] ) &&
			$this->settings['billie_sandbox_or_production'] === 'production'
		) {
			return false;
		}

		return true;
	}

	/**
	 * @param $path
	 * @param $body
	 * @param bool $json_request
	 * @param Token|null $token
	 * @param string $method
	 * @param bool $sandbox
	 *
	 * @return array
	 * @throws BillieException
	 * @throws ResponseException
	 */
	private function request( $path, $body, bool $json_request = false, Token $token = null, string $method = 'PUT', bool $sandbox = false ): array {
		$url = $sandbox ? BILLIE_SANDBOX_URL : BILLIE_PRODUCTION_URL;
		$url .= $path;

		$this->logger->debug( 'request', [
			'method'       => $method,
			'url'          => $url,
			'body'         => $body,
			'json_request' => $json_request,
			'token'        => $token ? $token->get_access_token() : 'null',
			'sandbox'      => $sandbox
		] );

		$headers = [];

		if ( $token !== null ) {
			$headers['Authorization'] = sprintf( 'Bearer %s', $token->get_access_token() );
		}

		if ( $json_request ) {
			$headers['Content-Type'] = 'application/json; charset=utf-8';
			$body                    = json_encode( $body );
		}

		$args = [
			'body'    => $body,
			'headers' => $headers,
			'method'  => $method
		];

		if ( $json_request ) {
			$args['data_format'] = $body;
		}

		if ( WC()->session ) {
			WC()->session->set( 'billieSession', null );
		}

		return $this->validate_remote_result( wp_remote_request( $url, $args ) );
	}
}
