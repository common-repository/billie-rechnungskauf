<?php defined( 'ABSPATH' ) or die( 'Direct access not allowed' ); ?>
<p>
    <?php wp_nonce_field( 'billie_invoice_id' . $order->get_id(), '_wpnonce_billie_invoice_id'); ?>
    <label for="billie_invoice_id">
		<?php esc_html_e( 'Invoice-ID', 'billie-rechnungskauf' ); ?>
    </label><br>
    <input name="billie_invoice_id" id="billie_invoice_id" type="text"
		<?php if ( 'completed' === $invoice_status ): ?>
            readonly
		<?php endif; ?>
           value="<?php echo esc_attr( $invoice_id ); ?>"/>
</p>
