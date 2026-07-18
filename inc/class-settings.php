<?php
/**
 * Settings store with sane defaults.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Settings {

	const OPTION = 'dbr54_settings';

	private static ?DBR54_Settings $instance = null;
	private array $settings                  = array();

	public static function instance(): DBR54_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$stored         = get_option( self::OPTION, array() );
		$this->settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function defaults(): array {
		return array(
			// Module toggles.
			'module_recesso_enabled'     => true,
			'module_garanzia_enabled'    => false,

			// Modulo A — Recesso.
			'button_label'               => __( 'Recedere dal contratto qui', 'db-recesso-54bis' ),
			'withdrawal_days'            => 14,
			'eligible_statuses'          => array( 'processing', 'completed', 'on-hold' ),
			'excluded_cats'              => array(), // ID categorie escluse ex art. 59.
			'timestamp_mode'             => 'base', // Valori possibili: base oppure tsa.
			'tsa_url'                    => '',

			// Modulo B — Garanzia legale.
			'garanzia_button_label'      => __( 'Prodotto difettoso? Apri una pratica di garanzia', 'db-recesso-54bis' ),
			'garanzia_eligible_statuses' => array( 'completed' ),

			// Shared.
			'admin_email'                => get_option( 'admin_email' ),
			'privacy_page_id'            => (int) get_option( 'wp_page_for_privacy_policy', 0 ),
			'retention_years'            => 10,
		);
	}

	public function get( string $key, $fallback = null ) {
		return $this->settings[ $key ] ?? $fallback;
	}

	public function all(): array {
		return $this->settings;
	}

	public function update( array $values ): void {
		$this->settings = wp_parse_args( $values, $this->settings );
		update_option( self::OPTION, $this->settings );
	}
}
