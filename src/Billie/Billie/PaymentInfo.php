<?php

namespace Billie\Billie;

use Billie\Plugin;
use DateInterval;
use Exception;
use WC_Order;

class PaymentInfo {
	/**
	 * @param $order_id
	 *
	 * @return null|array
	 * @throws Exception
	 * @noinspection PhpFullyQualifiedNameUsageInspection
	 */
	public static function get_billie_payment_info( $order_id ): ?array {
		$order = new WC_Order( $order_id );

        if ( $order->get_payment_method() !== Gateway::GATEWAY_ID ) {
			return null;
		}

		$billie_order_data = $order->get_meta( Plugin::ORDER_DATA_KEY );
		$bic               = $billie_order_data['debtor']['bank_account']['bic'] ?? '';
		if ( ! $bic ) {
			return null;
		}

		$date_completed    = null;
		$payment_goal_date = null;

		if ( function_exists( 'wc_gzdp_get_order_last_invoice' ) ) {
			/** @var \WC_GZDP_Invoice|null $invoice */
			$invoice = wc_gzdp_get_order_last_invoice( $order );

			if ( $invoice !== null ) {
				$date_completed = new \DateTime( $invoice->get_date( 'c' ) );
			}
		}

		if ( $date_completed === null ) {
			$date_completed = $order->get_date_completed();
		}

		if ( $date_completed !== null ) {
			$payment_goal_date = clone $date_completed;
			$billie_duration   = $order->get_meta( Plugin::DURATION_KEY );

			if ( (int) $billie_duration < 7 ) {
				$billie_duration = 7;
			}

			$duration_interval = new DateInterval( sprintf( 'P%sD', $billie_duration ) );

			$payment_goal_date->add( $duration_interval );
		}

		$billie_bank = self::get_bank( $bic );

		return [
			'paymentGoal' => ( $payment_goal_date !== null ) ? $payment_goal_date->format( 'd.m.Y' ) : null,
			'recipient'   => get_bloginfo( 'name' ),
			'subject'     => self::get_invoice_id( $order ),
			'iban'        => $billie_order_data['debtor']['bank_account']['iban'],
			'bic'         => $billie_order_data['debtor']['bank_account']['bic'],
			'bank'        => $billie_bank,
		];
	}

	/**
	 * @param      $order_id
	 * @param bool $metabox
	 *
	 * @return string
	 * @throws Exception
	 */
	public static function get_billie_payment_html( $order_id, bool $metabox = false ): string {
		$payment_info = self::get_billie_payment_info( $order_id );

		if ( null === $payment_info ) {
			return '';
		}

		ob_start();

		?>
        <section class="woocommerce-order-details billie-payment">
            <p>
				<?php
				if ( ! $metabox && null !== $payment_info['paymentGoal'] ) {
					echo esc_html(
						sprintf(
							__( 'Bitte überweisen Sie den Rechnungsbetrag bis zum %s unter Angabe der Rechnungsnummer auf folgendes Konto:', 'billie-rechnungskauf' ),
							$payment_info['paymentGoal']
						)
					);
				}
				?><br>

				<?php
				echo esc_html(
					sprintf(
						__( 'Kontoinhaber: %s', 'billie-rechnungskauf' ),
						$payment_info['recipient']
					)
				);
				?><br>

				<?php
				echo esc_html(
					sprintf(
						__( 'IBAN: %s', 'billie-rechnungskauf' ),
						$payment_info['iban']
					)
				);
				?><br>

				<?php
				echo esc_html(
					sprintf(
						__( 'BIC: %s', 'billie-rechnungskauf' ),
						$payment_info['bic']
					)
				);
				?><br>

				<?php
				echo esc_html(
					sprintf(
						__( 'Bank: %s', 'billie-rechnungskauf' ),
						$payment_info['bank']
					)
				);
				?><br>

				<?php
				if ( null !== $payment_info['paymentGoal'] ) {
					echo esc_html(
						sprintf(
							__( 'Fälligkeitsdatum: %s', 'billie-rechnungskauf' ),
							$payment_info['paymentGoal']
						)
					);
				} else {
					esc_html_e( 'Bestellung noch nicht versendet', 'billie-rechnungskauf' );
				}
				?><br>

				<?php
				echo esc_html(
					sprintf(
						__( 'Verwendungszweck: %s', 'billie-rechnungskauf' ),
						$payment_info['subject']
					)
				);
				?>
            </p>
        </section>
		<?php

		return ob_get_clean();
	}

	public static function get_bank( string $bic ) {
		$bic            = strtoupper( $bic );
		$transient_name = sprintf( 'bank_%s', $bic );
		$bank           = get_transient( $transient_name );

		if ( $bank !== false && trim( $bank ) !== '' ) {
			return $bank;
		}

		if ( ! file_exists( BILLIE_RESSOURCES_PATH . '/bic-de.csv' ) ) {
			return $bic;
		}

		$bic_csv = fopen( BILLIE_RESSOURCES_PATH . '/bic-de.csv', 'rb' );

		$bank = null;

		while ( $bank === null && $line = fgetcsv( $bic_csv ) ) {
			if ( is_array( $line ) && count( $line ) > 1 && strtoupper( trim( $line[0] ) ) === $bic ) {
				$bank = strtoupper( trim( $line[1] ) );
			}
		}

		if ( $bank === null ) {
			return $bic;
		}

		set_transient( $transient_name, $bank, 86400 );

		return $bank;
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return int|string
	 */
	public static function get_invoice_id( WC_Order $order ) {
		/**
		 * WC-PDF Invoice ID
		 */
		$wcPdf_invoice_number = $order->get_meta( '_wcpdf_invoice_number' );
		if ( $wcPdf_invoice_number !== false && $wcPdf_invoice_number !== 0 && trim( $wcPdf_invoice_number ) !== '' ) {
			return trim( $wcPdf_invoice_number );
		}

		/**
		 * Germanized Invoice ID
		 */
		if ( function_exists( 'wc_gzdp_get_order_last_invoice' ) ) {
			$invoice = wc_gzdp_get_order_last_invoice( $order );

			if ( $invoice !== null ) {
				$number = $invoice->number_formatted;
				if ( empty( $number ) ) {
					$number = $invoice->formatted_number;
				}

				return $number;
			}
		}

		/**
		 * German Market Invoice ID
		 */
		$german_market_invoice_number = $order->get_meta( '_wp_wc_running_invoice_number' );

		if ( $german_market_invoice_number !== false && $german_market_invoice_number !== 0 && trim( $german_market_invoice_number ) !== '' ) {
			return trim( $german_market_invoice_number );
		}

		/**
		 * Falls alle Stricke reißen: Manuell eingegebene Rechnungsnummer
		 */
		$billie_invoice_id = $order->get_meta( '_billie_invoice_id' );

		if ( $billie_invoice_id !== false && $billie_invoice_id !== 0 && trim( $billie_invoice_id ) !== '' ) {
			return trim( $billie_invoice_id );
		}

		return null;
	}
}
