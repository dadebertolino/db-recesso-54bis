<?php
/**
 * Core legal-guarantee logic (Modulo B — art. 128-135 Cod. Consumo).
 *
 * Opens a lightweight RMA-style claim for defective / non-conforming goods.
 * The chosen remedy (repair/replacement/price reduction/termination) is decided
 * by the merchant, not automated here: the customer may express a preference,
 * which is recorded as informative only.
 *
 * No automatic time-window enforcement: the merchant assesses whether the claim
 * falls within the legal guarantee period (art. 133 — 24 months + prescription).
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Garanzia {

	private static ?DBR54_Garanzia $instance = null;

	public static function instance(): DBR54_Garanzia {
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
	 * Allowed (informative) remedy preferences the customer can indicate.
	 */
	public static function remedies(): array {
		return array(
			'riparazione'  => __( 'Riparazione', 'db-recesso-54bis' ),
			'sostituzione' => __( 'Sostituzione', 'db-recesso-54bis' ),
			'nessuna'      => __( 'Nessuna preferenza', 'db-recesso-54bis' ),
		);
	}

	public static function remedy_label( ?string $key ): string {
		$map = self::remedies();
		return $map[ $key ] ?? __( 'Nessuna preferenza', 'db-recesso-54bis' );
	}

	/**
	 * An order is eligible for a guarantee claim if it belongs to the customer
	 * and is in a delivered/complete-ish state. No time cap is applied here per
	 * the configured policy: the merchant assesses the guarantee window.
	 */
	public function is_order_eligible( WC_Order $order ): bool {
		$eligible_statuses = (array) $this->settings()->get( 'garanzia_eligible_statuses', array( 'completed' ) );
		return in_array( $order->get_status(), $eligible_statuses, true );
	}

	/**
	 * Products list for the order, to let the customer point at the defective item.
	 *
	 * @return array<int,string> item name keyed by a stable ref.
	 */
	public function order_products( WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$out[ (string) $item_id ] = $item->get_name();
			}
		}
		return $out;
	}

	/**
	 * Reuse the Modulo A hash routine for integrity proof (same canonical form).
	 */
	public function compute_order_hash( WC_Order $order ): string {
		return DBR54_Recesso::instance()->compute_order_hash( $order );
	}

	/**
	 * Process a submitted guarantee claim.
	 *
	 * @return array{success:bool, claim_id?:int, receipt_url?:string, error?:string}
	 */
	public function process( WC_Order $order, string $claimant_type, ?string $claimant_email, string $product_ref, string $defect, string $preferred_remedy = 'nessuna' ): array {

		$defect = trim( $defect );
		if ( '' === $defect ) {
			return array(
				'success' => false,
				'error'   => __( 'Descrivi il difetto o la non conformità per aprire la pratica.', 'db-recesso-54bis' ),
			);
		}

		$remedy = array_key_exists( $preferred_remedy, self::remedies() ) ? $preferred_remedy : 'nessuna';

		$tz          = wp_timezone();
		$now         = new DateTimeImmutable( 'now', $tz );
		$received_at = ( clone $now )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$order_hash  = $this->compute_order_hash( $order );

		$product_name = '';
		if ( '' !== $product_ref ) {
			$products     = $this->order_products( $order );
			$product_name = $products[ $product_ref ] ?? '';
		}

		$claim_id = DBR54_Garanzia_DB::insert(
			array(
				'order_id'           => $order->get_id(),
				'claimant_type'      => $claimant_type,
				'claimant_email'     => 'guest' === $claimant_type ? $claimant_email : null,
				'product_ref'        => $product_name ? $product_name : $product_ref,
				'defect_description' => $defect,
				'preferred_remedy'   => $remedy,
				'received_at'        => $received_at,
				'received_tz'        => $tz->getName(),
				'order_hash'         => $order_hash,
				'status'             => 'aperta',
			)
		);

		if ( ! $claim_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Errore nell\'apertura della pratica. Riprova o contatta il venditore.', 'db-recesso-54bis' ),
			);
		}

		$claim = DBR54_Garanzia_DB::get( $claim_id );

		// Durable-medium receipt (reuses the self-contained PDF builder).
		$receipt = new DBR54_Receipt();
		$result  = $receipt->generate_garanzia( $order, $claim );
		if ( ! empty( $result['ref'] ) ) {
			DBR54_Garanzia_DB::update_fields( $claim_id, array( 'receipt_ref' => $result['ref'] ) );
			$claim->receipt_ref = $result['ref'];
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: date, 2: claim id */
				__( 'Pratica di garanzia legale (art. 128-135) aperta il %1$s. Pratica #%2$d.', 'db-recesso-54bis' ),
				$now->format( 'd/m/Y H:i T' ),
				$claim_id
			),
			false
		);

		$this->send_emails( $order, $claim, $result['path'] ?? '' );

		/**
		 * Fires after a guarantee claim is fully processed.
		 */
		do_action( 'dbr54_garanzia_processed', $claim_id, $order );

		return array(
			'success'     => true,
			'claim_id'    => $claim_id,
			'receipt_url' => $result['url'] ?? '',
		);
	}

	private function send_emails( WC_Order $order, object $claim, string $attachment_path ): void {
		$attachments = ( $attachment_path && file_exists( $attachment_path ) ) ? array( $attachment_path ) : array();

		$to_customer = 'guest' === $claim->claimant_type ? $claim->claimant_email : $order->get_billing_email();
		if ( $to_customer ) {
			$subject = sprintf(
				/* translators: %s: order number */
				__( 'Pratica di garanzia aperta — ordine %s', 'db-recesso-54bis' ),
				$order->get_order_number()
			);
			$this->mail( $to_customer, $subject, $this->email_body_customer( $order, $claim ), $attachments );
		}

		$admin_email = $this->settings()->get( 'admin_email' );
		if ( $admin_email ) {
			$subject = sprintf(
				/* translators: %s: order number */
				__( 'Nuova pratica di garanzia — ordine %s', 'db-recesso-54bis' ),
				$order->get_order_number()
			);
			$this->mail( $admin_email, $subject, $this->email_body_admin( $order, $claim ), $attachments );
		}
	}

	private function mail( string $to, string $subject, string $body, array $attachments ): void {
		$mailer  = WC()->mailer();
		$html    = $mailer->wrap_message( $subject, wpautop( $body ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$mailer->send( $to, $subject, $html, $headers, $attachments );
	}

	private function email_body_customer( WC_Order $order, object $claim ): string {
		return sprintf(
			/* translators: 1: order number, 2: datetime */
			esc_html__( 'Abbiamo ricevuto la tua segnalazione di difetto per l\'ordine %1$s in data %2$s. La pratica di garanzia legale è stata aperta e sarà valutata dal venditore, che ti proporrà il rimedio previsto dalla legge. In allegato la ricevuta.', 'db-recesso-54bis' ),
			esc_html( $order->get_order_number() ),
			esc_html( $this->format_received( $claim ) )
		);
	}

	private function email_body_admin( WC_Order $order, object $claim ): string {
		return sprintf(
			/* translators: 1: order number, 2: product, 3: remedy */
			esc_html__( 'Nuova pratica di garanzia per l\'ordine %1$s. Prodotto: %2$s. Preferenza rimedio (informativa): %3$s. Gestisci dalla schermata Garanzia legale.', 'db-recesso-54bis' ),
			esc_html( $order->get_order_number() ),
			esc_html( $claim->product_ref ? $claim->product_ref : __( 'non specificato', 'db-recesso-54bis' ) ),
			esc_html( self::remedy_label( $claim->preferred_remedy ) )
		);
	}

	public function format_received( object $claim ): string {
		try {
			$dt = new DateTimeImmutable( $claim->received_at, new DateTimeZone( 'UTC' ) );
			$dt = $dt->setTimezone( new DateTimeZone( $claim->received_tz ? $claim->received_tz : 'UTC' ) );
			return $dt->format( 'd/m/Y H:i T' );
		} catch ( Exception $e ) {
			return $claim->received_at . ' UTC';
		}
	}
}
