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

	const RETENTION_HOOK = 'dbr54_retention_purge';

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

		// Legacy compat: DB SEO Manager 1.2.x "Privacy SEO" register (the Hub dedupes by id).
		add_filter( 'dbseo_processing_register', array( $this, 'register_processing' ) );

		// Retention enforcement (art. 5.1.e GDPR): daily purge of expired records.
		add_action( 'init', array( __CLASS__, 'schedule_retention' ) );
		add_action( self::RETENTION_HOOK, array( $this, 'purge_expired' ) );
	}

	/**
	 * Schedule the daily retention purge once (idempotent).
	 */
	public static function schedule_retention(): void {
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	/**
	 * Delete withdrawal records and guarantee claims older than the declared
	 * retention (received_at is stored in UTC), together with their PDF
	 * receipts. A retention of 0/empty means "keep forever": nothing is purged.
	 */
	public function purge_expired(): void {
		$years = (int) $this->settings()->get( 'retention_years', 10 );
		if ( $years <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $years . ' years' ) );

		self::purge_table( DBR54_DB::table(), $cutoff );
		if ( class_exists( 'DBR54_Garanzia_DB' ) ) {
			self::purge_table( DBR54_Garanzia_DB::table(), $cutoff );
		}
	}

	/**
	 * Batch-delete rows with received_at < $cutoff from one plugin table,
	 * removing the related PDF receipt first.
	 */
	private static function purge_table( string $table, string $cutoff ): void {
		global $wpdb;

		// Bounded run: at most 20 × 500 rows per day, the rest on the next run.
		for ( $i = 0; $i < 20; $i++ ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, receipt_ref FROM {$table} WHERE received_at < %s ORDER BY id ASC LIMIT 500", $cutoff )
			);
			if ( empty( $rows ) ) {
				return;
			}

			$ids = array();
			foreach ( $rows as $row ) {
				if ( ! empty( $row->receipt_ref ) ) {
					DBR54_Receipt::delete_file( (string) $row->receipt_ref );
				}
				$ids[] = (int) $row->id;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", $ids ) );

			if ( count( $rows ) < 500 ) {
				return;
			}
		}
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
				_n( '%d anno, allineato ai termini di prescrizione ordinari. Cancellazione automatica giornaliera (record e ricevuta PDF) allo scadere.', '%d anni, allineati ai termini di prescrizione ordinari. Cancellazione automatica giornaliera (record e ricevute PDF) allo scadere.', $retention, 'db-recesso-54bis' ),
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
					_n( '%d anno, allineato ai termini di prescrizione ordinari. Cancellazione automatica giornaliera (record e ricevuta PDF) allo scadere.', '%d anni, allineati ai termini di prescrizione ordinari. Cancellazione automatica giornaliera (record e ricevute PDF) allo scadere.', $retention, 'db-recesso-54bis' ),
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

	/**
	 * Hub channel. The Hub mirrors this entry into wp_privacy_personal_data_*
	 * under the same slug, so the callback MUST return the WP core shape
	 * (exporter: data + done; eraser: items_removed/items_retained/messages/done).
	 * Both channels therefore share the core callbacks.
	 */
	public function hub_exporter( array $exporters ): array {
		$exporters['dbr54_recesso'] = array(
			'label'    => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
			'callback' => array( $this, 'core_export_callback' ),
		);
		return $exporters;
	}

	public function hub_eraser( array $erasers ): array {
		$erasers['dbr54_recesso'] = array(
			'label'    => __( 'Recessi art. 54-bis', 'db-recesso-54bis' ),
			'callback' => array( $this, 'core_erase_callback' ),
		);
		return $erasers;
	}

	// ---- WordPress core DSAR fallback ----

	public function core_exporter( array $exporters ): array {
		if ( class_exists( 'DBPH_DSAR' ) ) {
			return $exporters; // Hub present: registered via dbph_user_data_exporters.
		}
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
		if ( class_exists( 'DBPH_DSAR' ) ) {
			return $erasers; // Hub present: registered via dbph_user_data_erasers.
		}
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
				__( 'I record di recesso e garanzia sono conservati per accertamento, esercizio o difesa di un diritto (eccezione all\'art. 17.3.e GDPR) e cancellati automaticamente allo scadere della retention.', 'db-recesso-54bis' ),
			) : array(),
			'done'           => true,
		);
	}
}
