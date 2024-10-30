<?php

namespace Billie\Admin;

use Billie\Admin\Option\Account;
use Billie\Billie\Api;
use Billie\Exceptions\BillieException;

class Settings {
	/** @var Account */
	private $accountOptions;

	/** @var Api */
	private $api;

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'plugin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_options' ] );
	}

	public function plugin_menu(): void {
		$notification = '';
		$options      = get_option( Account::OPTION_NAME );

		if (
			empty( $options ) ||
			! array_key_exists( 'billie_client_id', $options ) || $options['billie_client_id'] === '' ||
			! array_key_exists( 'billie_client_secret', $options ) || $options['billie_client_secret'] === ''
		) {
			$notification = ' <span class="awaiting-mod">1</span>';
			add_settings_error( "Credentials missing", "credentials_missing", "WARNUNG: Billie wird ohne Ihre Zugangsdaten nicht als Zahlungsmethode angezeigt" );
		}

		$menuName = __( 'Billie', 'billie-rechnungskauf' ) . $notification;
		add_menu_page( __( 'Billie Settings', 'billie-rechnungskauf' ),
			$menuName,
			'manage_options',
			'billie-settings-account',
			[ $this, 'render_account_options' ] );

	}

	public function register_options(): void {
		$this->accountOptions = new Account();
		$this->accountOptions->register();

		$this->api = new Api();
	}


	public function render_account_options(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		$validationError = null;
		$is_post_request = 'POST' === filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW );
		if ( $is_post_request && check_admin_referer( 'validate-credentials' ) ) {
			try {
				$this->api->validate_credentials();
				update_option( 'billie_credentials_validated', time() );
			} catch ( BillieException $e ) {
				$validationError = $e->getMessage();
			}
		}

		$this->accountOptions->render( $validationError );
	}
}
