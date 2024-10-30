<?php defined( 'ABSPATH' ) or die( 'Direct access not allowed' ); ?>

<style>
    #checkout_billie_logo {
        max-height: 2em;
    }
</style>

<p>
	<?php if ( ! isset( $this->settings['hide_logo'] ) || $this->settings['hide_logo'] === 'no' ) { ?>
        <img id="checkout_billie_logo"
             src="<?php echo esc_url( plugin_dir_url( __DIR__ ) . '/billie_logo_large.svg' ); ?>"
             alt="Billie">
	<?php } ?>
	<?php echo esc_html( nl2br( $this->method_description ) ); ?>
</p>
<p id="billie_error_b2b" class="error">
    <strong style="color:red">
	    <?php esc_html_e( 'This payment option is only available for company customers.', 'billie-rechnungskauf' ); ?>
    </strong>
</p>

