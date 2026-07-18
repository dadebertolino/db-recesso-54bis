<?php
/**
 * Privacy integration.
 *
 * Hooks into the DB Privacy Hub filter contracts when available, and also
 * registers with WordPress core's personal-data exporter/eraser so DSAR works
 * even without the Hub. No consent is collected (legal bases: art. 6.1.b and
 * 6.1.c GDPR), so the consents register is deliberately NOT touched.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Privacy {

	private static ?DBR54_Privacy $instance = null;

	public static function instance(): DBR54_Privacy {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// DB Privacy Hub contracts.
		add_filter( 'dbph_processing_register', array( $this, 'register_processing' ) );
		add_filter( 'dbph_user_data_exporters', array( $this, 'hub_exporter' ) );
		add_filter( 'dbph_user_data_erasers', array( $this, 'hub_eraser' ) );

		// WordPress core DSAR fallback (works without the Hub).
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'core_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'core_eraser' ) );
	}

	private function settings(): DBR54_Settings {
		return DBR54_Settings::instance();
	}

	/**
	 * Declare the processing activity in the DB Privacy Hub register.
	 * Contract (from db-privacy-hub): entries keyed by lowercase 'id', read by
	 * the generator via label/status/purpose/legal_basis/data_collected/
	 * retention/transfers (all strings).
	 */
	public function register_processing( array $register ): array {
		$retention = (int) $this->settings()->get( 'retention_years', 10 );

		$register[] = array(
			'id'             => 'dbr54_recesso',
			'label'          => __( 'Gestione recesso art. 54-bis', 'db-recesso-54bis' ),
			'status'         => $this->settings()->get( 'module_recesso_enabled', true ) ? 'active' : 'inactive',
			'purpose'        => __( 'Esercizio e documentazione del diritto di recesso del consumatore (art. 54-bis Cod. Consumo).', 'db-recesso-54bis' ),
			'legal_basis'    => __( 'Esecuzione del contratto (art. 6.1.b GDPR) e obbligo legale (art. 6.1.c GDPR).', 'db-recesso-54bis' ),
			'data_collected' => __( 'Riferimento all\'ordine, email del dichiarante (solo ospiti), motivo del recesso (facoltativo), data e ora di ricezione.', 'db-recesso-54bis' ),
			'retention'      => sprintf(
				/* translators: %d: years */
				_n( '%d anno, allineato ai termini di prescrizione ordinari.', '%d anni, allineati ai termini di prescrizione ordinari.', $retention, 'db-recesso-54bis' ),
				$retention
			),
			'transfers'      => __( 'Nessuno.', 'db-recesso-54bis' ),
		);

		if ( $this->settings()->get( 'module_garanzia_enabled', false ) ) {
			$register[] = array(
				'id'             => 'dbr54_garanzia',
				'label'          => __( 'Gestione garanzia legale di conformità (art. 128-135)', 'db-recesso-54bis' ),
				'status'         => 'active',
				'purpose'        => __( 'Gestione delle pratiche di garanzia legale per prodotti difettosi o non conformi.', 'db-recesso-54bis' ),
				'legal_basis'    => __( 'Esecuzione del contratto (art. 6.1.b GDPR).', 'db-recesso-54bis' ),
				'data_collected' => __( 'Riferimento all\'ordine, email del richiedente (solo ospiti), prodotto interessato, descrizione del difetto, preferenza di rimedio (facoltativa), data e ora di apertura.', 'db-recesso-54bis' ),
				'retention'      => sprintf(
					/* translators: %d: years */
					_n( '%d anno, allineato ai termini di prescrizione ordinari.', '%d anni, allineati ai termini di prescrizione ordinari.', $retention, 'db-recesso-54bis' ),
					$retention
				),
				'transfers'      => __( 'Nessuno.', 'db-recesso-54bis' ),
			);
		}

		return $register;
	}

	/**
	 * @return object[] Withdrawal records tied to the email.
	 */
	private function records_for( string $email ): array {
		return DBR54_DB::get_by_email( $email );
	}

	/**
	 * @return object[] Guarantee claims tied to the email.
	 */
	private function garanzia_for( string $email ): array {
		if ( ! class_exists( 'DBR54_Garanzia_DB' ) ) {
			return array();
		}
		return DBR54_Garanzia_DB::get_by_email( $email );
	}

	private function has_any( string $email ): bool {
		return (bool) $this->records_for( $email ) || (bool) $this->garanzia_for( $email );
	}

	private function export_items_for( string $email ): array {
		$items = array();

		foreach ( $this->records_for( $email ) as $r ) {
			$data = array(
				array(
					'name' => __( 'ID recesso', 'db-recesso-54bis' ),
					'value' => $r->id,
				),
				array(
					'name' => __( 'Ordine', 'db-recesso-54bis' ),
					'value' => $r->order_id,
				),
				array(
					'name' => __( 'Ricevuto il', 'db-recesso-54bis' ),
					'value' => DBR54_Recesso::instance()->format_received( $r ),
				),
				array(
					'name' => __( 'Motivo', 'db-recesso-54bis' ),
					'value' => $r->reason ? $r->reason : __( 'non indicato', 'db-recesso-54bis' ),
				),
				array(
					'name' => __( 'Hash ordine', 'db-recesso-54bis' ),
					'value' => $r->order_hash,
				),
				array(
					'name' => __( 'Stato', 'db-recesso-54bis' ),
					'value' => $r->status,
				),
			);
			$items[] = array(
				'group_id'    => 'dbr54_recesso',
				'group_label' => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
				'item_id'     => 'dbr54-recesso-' . $r->id,
				'data'        => $data,
			);
		}

		foreach ( $this->garanzia_for( $email ) as $c ) {
			$data = array(
				array(
					'name' => __( 'ID pratica', 'db-recesso-54bis' ),
					'value' => $c->id,
				),
				array(
					'name' => __( 'Ordine', 'db-recesso-54bis' ),
					'value' => $c->order_id,
				),
				array(
					'name' => __( 'Aperta il', 'db-recesso-54bis' ),
					'value' => DBR54_Garanzia::instance()->format_received( $c ),
				),
				array(
					'name' => __( 'Prodotto', 'db-recesso-54bis' ),
					'value' => $c->product_ref ? $c->product_ref : __( 'non specificato', 'db-recesso-54bis' ),
				),
				array(
					'name' => __( 'Difetto', 'db-recesso-54bis' ),
					'value' => $c->defect_description,
				),
				array(
					'name' => __( 'Rimedio preferito', 'db-recesso-54bis' ),
					'value' => DBR54_Garanzia::remedy_label( $c->preferred_remedy ),
				),
				array(
					'name' => __( 'Stato', 'db-recesso-54bis' ),
					'value' => $c->status,
				),
			);
			$items[] = array(
				'group_id'    => 'dbr54_garanzia',
				'group_label' => __( 'Pratiche di garanzia legale', 'db-recesso-54bis' ),
				'item_id'     => 'dbr54-garanzia-' . $c->id,
				'data'        => $data,
			);
		}

		return $items;
	}

	// ---- DB Privacy Hub exporter/eraser ----

	public function hub_exporter( array $exporters ): array {
		$exporters['dbr54_recesso'] = array(
			'label'    => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
			'callback' => function ( string $email ) {
				return $this->export_items_for( $email );
			},
		);
		return $exporters;
	}

	public function hub_eraser( array $erasers ): array {
		$erasers['dbr54_recesso'] = array(
			'label'    => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
			'callback' => function ( string $email ) {
				// Evidential exception: do NOT delete. Declare and retain.
				return array(
					'items_removed'  => false,
					'items_retained' => $this->has_any( $email ),
					'messages'       => array(
						__( 'I record di recesso sono conservati per accertamento, esercizio o difesa di un diritto (eccezione all\'art. 17 GDPR) per la durata prevista dalla retention.', 'db-recesso-54bis' ),
					),
				);
			},
		);
		return $erasers;
	}

	// ---- WordPress core DSAR fallback ----

	public function core_exporter( array $exporters ): array {
		$exporters['dbr54_recesso'] = array(
			'exporter_friendly_name' => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
			'callback'               => array( $this, 'core_export_callback' ),
		);
		return $exporters;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- firma imposta dal contratto WP core.
	public function core_export_callback( string $email, int $page = 1 ): array {
		return array(
			'data' => $this->export_items_for( $email ),
			'done' => true,
		);
	}

	public function core_eraser( array $erasers ): array {
		$erasers['dbr54_recesso'] = array(
			'eraser_friendly_name' => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
			'callback'             => array( $this, 'core_erase_callback' ),
		);
		return $erasers;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- firma imposta dal contratto WP core.
	public function core_erase_callback( string $email, int $page = 1 ): array {
		$has = $this->has_any( $email );
		return array(
			'items_removed'  => false,
			'items_retained' => $has,
			'messages'       => $has ? array(
				__( 'Record di recesso conservati per esigenze probatorie (eccezione all\'art. 17 GDPR).', 'db-recesso-54bis' ),
			) : array(),
			'done'           => true,
		);
	}
}
