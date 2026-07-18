<?php
/**
 * Core withdrawal logic (Modulo A — art. 54-bis).
 *
 * Eligibility, art. 59 exceptions, evidential hash + timestamp, record creation,
 * receipt generation, transactional emails, admin notification.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Recesso {

	private static ?DBR54_Recesso $instance = null;

	public static function instance(): DBR54_Recesso {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function settings(): DBR54_Settings {
		return DBR54_Settings::instance();
	}

	/**
	 * Is this order still within the withdrawal window and in an eligible state?
	 */
	public function is_order_eligible( WC_Order $order ): bool {
		$eligible_statuses = (array) $this->settings()->get( 'eligible_statuses', array() );
		if ( ! in_array( $order->get_status(), $eligible_statuses, true ) ) {
			return false;
		}

		// Already withdrawn?
		if ( DBR54_DB::has_for_order( $order->get_id() ) ) {
			return false;
		}

		// B2C only: skip if a VAT/company field indicates a business purchase.
		if ( $order->get_meta( '_billing_vat', true ) || $order->get_billing_company() ) {
			/**
			 * Allow forcing eligibility for company orders if the merchant wants to.
			 */
			if ( ! apply_filters( 'dbr54_allow_business_orders', false, $order ) ) {
				return false;
			}
		}

		return $this->days_remaining( $order ) > 0;
	}

	/**
	 * Days left in the withdrawal window, measured from delivery date if known,
	 * otherwise from order completion/creation. Conservative: uses the latest
	 * known "start" so we never cut the window short by mistake.
	 */
	public function days_remaining( WC_Order $order ): int {
		$days  = (int) $this->settings()->get( 'withdrawal_days', 14 );
		$start = $this->window_start( $order );
		if ( ! $start ) {
			return 0;
		}

		$deadline = $start->modify( "+{$days} days" )->setTime( 23, 59, 59 );
		$now      = new DateTimeImmutable( 'now', wp_timezone() );

		if ( $deadline <= $now ) {
			return 0;
		}

		// Whole days remaining, rounded up so a partial final day still counts.
		$seconds = $deadline->getTimestamp() - $now->getTimestamp();
		return (int) ceil( $seconds / DAY_IN_SECONDS );
	}

	private function window_start( WC_Order $order ): ?DateTimeImmutable {
		$candidates = array();

		$delivery = $order->get_meta( '_dbr54_delivery_date', true );
		if ( $delivery ) {
			$candidates[] = $delivery;
		}
		$completed = $order->get_date_completed();
		if ( $completed ) {
			$candidates[] = $completed->date( 'Y-m-d H:i:s' );
		}
		$created = $order->get_date_created();
		if ( $created ) {
			$candidates[] = $created->date( 'Y-m-d H:i:s' );
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		// Use the earliest available anchor (widest window in the consumer's favour).
		$start = null;
		foreach ( $candidates as $c ) {
			try {
				$dt = new DateTimeImmutable( $c, wp_timezone() );
			} catch ( Exception $e ) {
				continue;
			}
			if ( null === $start || $dt < $start ) {
				$start = $dt;
			}
		}
		return $start;
	}

	/**
	 * Order line items excluded from withdrawal per art. 59 (sealed goods,
	 * personalised, perishable, etc.), based on excluded categories or a
	 * per-product meta flag `_dbr54_art59_excluded`.
	 *
	 * @return array List of ['name' => string, 'reason' => string].
	 */
	public function get_art59_exceptions( WC_Order $order ): array {
		$excluded_cats = array_map( 'intval', (array) $this->settings()->get( 'excluded_cats', array() ) );
		$exceptions    = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$product    = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$is_excluded = false;
			$reason      = __( 'Prodotto escluso dal diritto di recesso (art. 59 Cod. Consumo).', 'db-recesso-54bis' );

			if ( 'yes' === get_post_meta( $product_id, '_dbr54_art59_excluded', true ) ) {
				$is_excluded = true;
			}
			if ( ! $is_excluded && ! empty( $excluded_cats ) ) {
				$terms = wc_get_product_term_ids( $product_id, 'product_cat' );
				if ( array_intersect( $excluded_cats, $terms ) ) {
					$is_excluded = true;
				}
			}

			$is_excluded = (bool) apply_filters( 'dbr54_item_art59_excluded', $is_excluded, $item, $order );

			if ( $is_excluded ) {
				$exceptions[] = array(
					'name'   => $item->get_name(),
					'reason' => $reason,
				);
			}
		}

		return $exceptions;
	}

	/**
	 * SHA-256 of a canonical snapshot of the order state at withdrawal time.
	 * Proves integrity of what was declared without duplicating personal data.
	 */
	public function compute_order_hash( WC_Order $order ): string {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'  => $item->get_name(),
				'qty'   => $item instanceof WC_Order_Item_Product ? $item->get_quantity() : null,
				'total' => (string) $item->get_total(),
			);
		}

		$snapshot = array(
			'order_id' => $order->get_id(),
			'number'   => $order->get_order_number(),
			'currency' => $order->get_currency(),
			'total'    => (string) $order->get_total(),
			'created'  => $order->get_date_created() ? $order->get_date_created()->date( DateTime::ATOM ) : null,
			'items'    => $items,
		);

		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', (string) $json );
	}

	/**
	 * Process a confirmed withdrawal declaration.
	 *
	 * @param WC_Order    $order          Target order.
	 * @param string      $declarant_type 'user' | 'guest'.
	 * @param string|null $declarant_email Email for guests.
	 * @param string      $reason         Optional free-text reason.
	 * @return array{success:bool, record_id?:int, receipt_url?:string, error?:string}
	 */
	public function process( WC_Order $order, string $declarant_type, ?string $declarant_email, string $reason = '' ): array {

		if ( DBR54_DB::has_for_order( $order->get_id() ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Per questo ordine è già stato registrato un recesso.', 'db-recesso-54bis' ),
			);
		}

		$tz          = wp_timezone();
		$now         = new DateTimeImmutable( 'now', $tz );
		$received_at = ( clone $now )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$order_hash  = $this->compute_order_hash( $order );

		$record_id = DBR54_DB::insert(
			array(
				'order_id'        => $order->get_id(),
				'declarant_type'  => $declarant_type,
				'declarant_email' => 'guest' === $declarant_type ? $declarant_email : null,
				'reason'          => $reason,
				'received_at'     => $received_at,
				'received_tz'     => $tz->getName(),
				'order_hash'      => $order_hash,
				'status'          => 'ricevuto',
			)
		);

		if ( ! $record_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Errore nel salvataggio del recesso. Riprova o contatta il venditore.', 'db-recesso-54bis' ),
			);
		}

		$record = DBR54_DB::get( $record_id );

		// Optional reinforced timestamp (RFC 3161). Additive; failure is non-fatal.
		if ( 'tsa' === $this->settings()->get( 'timestamp_mode' ) ) {
			$token = $this->request_tsa_token( $order_hash );
			if ( $token ) {
				DBR54_DB::update_fields( $record_id, array( 'tsa_token' => $token ) );
				$record->tsa_token = $token;
			}
		}

		// Durable-medium receipt (PDF, self-contained).
		$receipt = new DBR54_Receipt();
		$result  = $receipt->generate( $order, $record );
		if ( ! empty( $result['ref'] ) ) {
			DBR54_DB::update_fields( $record_id, array( 'receipt_ref' => $result['ref'] ) );
			$record->receipt_ref = $result['ref'];
		}

		// Order note (evidential trail on the order itself).
		$order->add_order_note(
			sprintf(
				/* translators: 1: date, 2: hash */
				__( 'Recesso art. 54-bis ricevuto il %1$s. Hash ordine (SHA-256): %2$s', 'db-recesso-54bis' ),
				$now->format( 'd/m/Y H:i T' ),
				$order_hash
			),
			false
		);

		$this->send_emails( $order, $record, $result['path'] ?? '' );

		/**
		 * Fires after a withdrawal is fully processed.
		 */
		do_action( 'dbr54_withdrawal_processed', $record_id, $order );

		return array(
			'success'     => true,
			'record_id'   => $record_id,
			'receipt_url' => $result['url'] ?? '',
		);
	}

	/**
	 * Request an RFC 3161 timestamp token for the given hash from the configured
	 * TSA. Returns the raw DER token or null on any failure. Requires openssl.
	 */
	private function request_tsa_token( string $hash ): ?string {
		$tsa_url = trim( (string) $this->settings()->get( 'tsa_url', '' ) );
		if ( '' === $tsa_url || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return null;
		}

		// Build a minimal TimeStampReq for the SHA-256 digest.
		$digest_bin = hex2bin( $hash );
		if ( false === $digest_bin ) {
			return null;
		}

		$tsq = $this->build_timestamp_request( $digest_bin );
		if ( null === $tsq ) {
			return null;
		}

		$response = wp_remote_post(
			$tsa_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/timestamp-query' ),
				'body'    => $tsq,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		return $body ? $body : null;
	}

	/**
	 * Build a DER-encoded RFC 3161 TimeStampReq (version 1, SHA-256, certReq TRUE).
	 */
	private function build_timestamp_request( string $digest_bin ): ?string {
		// OID 2.16.840.1.101.3.4.2.1 (sha256).
		$sha256_oid = "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01";

		$der = static function ( $tag, $content ) {
			$len = strlen( $content );
			if ( $len < 0x80 ) {
				$len_bytes = chr( $len );
			} else {
				$tmp = ltrim( pack( 'N', $len ), "\x00" );
				$len_bytes = chr( 0x80 | strlen( $tmp ) ) . $tmp;
			}
			return chr( $tag ) . $len_bytes . $content;
		};

		$algo_id       = $der( 0x30, $sha256_oid . $der( 0x05, '' ) ); // AlgorithmIdentifier + NULL.
		$hashed_message = $der( 0x04, $digest_bin );                    // OCTET STRING.
		$message_imprint = $der( 0x30, $algo_id . $hashed_message );

		$version  = $der( 0x02, "\x01" );      // INTEGER 1.
		$cert_req = $der( 0x01, "\xFF" );        // BOOLEAN TRUE.

		$req = $der( 0x30, $version . $message_imprint . $cert_req );
		return $req ? $req : null;
	}

	private function send_emails( WC_Order $order, object $record, string $attachment_path ): void {
		$attachments = ( $attachment_path && file_exists( $attachment_path ) ) ? array( $attachment_path ) : array();

		$to_customer = 'guest' === $record->declarant_type ? $record->declarant_email : $order->get_billing_email();
		if ( $to_customer ) {
			$subject = sprintf(
				/* translators: %s: order number */
				__( 'Conferma di recesso — ordine %s', 'db-recesso-54bis' ),
				$order->get_order_number()
			);
			$body = $this->email_body_customer( $order, $record );
			$this->mail( $to_customer, $subject, $body, $attachments );
		}

		$admin_email = $this->settings()->get( 'admin_email' );
		if ( $admin_email ) {
			$subject = sprintf(
				/* translators: %s: order number */
				__( 'Nuovo recesso ricevuto — ordine %s', 'db-recesso-54bis' ),
				$order->get_order_number()
			);
			$body = $this->email_body_admin( $order, $record );
			$this->mail( $admin_email, $subject, $body, $attachments );
		}
	}

	private function mail( string $to, string $subject, string $body, array $attachments ): void {
		$mailer = WC()->mailer();
		$html   = $mailer->wrap_message( $subject, wpautop( $body ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$mailer->send( $to, $subject, $html, $headers, $attachments );
	}

	private function email_body_customer( WC_Order $order, object $record ): string {
		$when = $this->format_received( $record );
		return sprintf(
			/* translators: 1: order number, 2: datetime */
			esc_html__( 'Abbiamo ricevuto la tua dichiarazione di recesso per l\'ordine %1$s in data %2$s. In allegato trovi la ricevuta su supporto durevole. Il rimborso sarà effettuato secondo i termini di legge.', 'db-recesso-54bis' ),
			esc_html( $order->get_order_number() ),
			esc_html( $when )
		);
	}

	private function email_body_admin( WC_Order $order, object $record ): string {
		$when = $this->format_received( $record );
		return sprintf(
			/* translators: 1: order number, 2: datetime, 3: hash */
			esc_html__( 'Ricevuto un recesso per l\'ordine %1$s in data %2$s. Hash ordine: %3$s. Gestisci lo stato dalla schermata Recessi.', 'db-recesso-54bis' ),
			esc_html( $order->get_order_number() ),
			esc_html( $when ),
			esc_html( $record->order_hash )
		);
	}

	public function format_received( object $record ): string {
		try {
			$dt = new DateTimeImmutable( $record->received_at, new DateTimeZone( 'UTC' ) );
			$dt = $dt->setTimezone( new DateTimeZone( $record->received_tz ? $record->received_tz : 'UTC' ) );
			return $dt->format( 'd/m/Y H:i T' );
		} catch ( Exception $e ) {
			return $record->received_at . ' UTC';
		}
	}
}
