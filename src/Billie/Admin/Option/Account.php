<?php

namespace Billie\Admin\Option;

class Account extends Helper {
	const OPTION_NAME = 'billie_account';

	public function __construct() {
		$this->options = get_option( self::OPTION_NAME );
	}

	public function register() {
		register_setting( 'billie', self::OPTION_NAME );

		/*
		 * General Settings
		 */
		add_settings_section( 'billie_account_settings_general',
			__( 'Settings', 'billie-rechnungskauf' ),
			[ $this, 'account_info' ],
			'billie-settings-account' );
		add_settings_field( 'billie_sandbox_or_production',
			__( 'Sandbox or production', 'billie-rechnungskauf' ),
			[ $this, 'field_billie_sandbox_or_production' ],
			'billie-settings-account',
			'billie_account_settings_general' );
		add_settings_field( 'billie_client_id',
			__( 'Client ID', 'billie-rechnungskauf' ),
			[ $this, 'field_billie_client_id' ],
			'billie-settings-account',
			'billie_account_settings_general' );
		add_settings_field( 'billie_client_secret',
			__( 'Client Secret', 'billie-rechnungskauf' ),
			[ $this, 'field_billie_client_secret' ],
			'billie-settings-account',
			'billie_account_settings_general' );
	}

	public function account_info() {
		esc_html_e( 'plugin.settings.info', 'billie-rechnungskauf' );
	}

	public function field_billie_client_id() {
		$this->text_field( self::OPTION_NAME, 'billie_client_id' );
	}

	public function field_billie_client_secret() {
		$this->text_field( self::OPTION_NAME, 'billie_client_secret' );
	}

	public function field_billie_sandbox_or_production() {
		$this->select_field( self::OPTION_NAME, 'billie_sandbox_or_production', [
			'sandbox'    => __( 'Sandbox', 'billie-rechnungskauf' ),
			'production' => __( 'Production', 'billie-rechnungskauf' ),
		], 'single' );
	}

	public function render( $validationError = null ) {
		$credentialsValidated = get_option( 'billie_credentials_validated' );

		$oauthPossible = is_array( $this->options ) && isset( $this->options['billie_client_id'], $this->options['billie_client_secret'] );

		include BILLIE_VIEW_PATH . '/admin/options.php';
	}
}
