<?php defined( 'ABSPATH' ) or die( 'Direct access not allowed' ); ?>
<div class="wrap">
    <h1><?php esc_html_e( 'Billie Settings', 'billie-rechnungskauf' ); ?></h1>
	<?php settings_errors(); ?>
    <form method="post" action="options.php">
		<?php
		settings_fields( 'billie' );
		do_settings_sections( 'billie-settings-account' );
		submit_button();
		?>
    </form>
	<?php if ( isset( $oauthPossible ) && ( $oauthPossible === true ) ): ?>
        <h2><?php esc_html_e( 'Validate Credentials', 'billie-rechnungskauf' ); ?></h2>
		<?php if ( isset( $validationError ) && $validationError !== null ): ?>
            <p><?php echo esc_html( $validationError ); ?></p>
		<?php endif; ?>
		<?php if ( isset( $credentialsValidated ) && $credentialsValidated !== false ): ?>
            <p> ✅ <?php esc_html_e( 'Credentials validated:', 'billie-rechnungskauf' ); ?>
				<?php echo esc_html( date_i18n( get_option( 'date_format' ), $credentialsValidated ) ); ?>
            </p>
		<?php endif; ?>
        <form method="post">
			<?php
			wp_nonce_field( 'validate-credentials' );
			submit_button( __( 'Validate Credentials', 'billie-rechnungskauf' ) );
			?>
        </form>
	<?php endif; ?>
</div>
