<?php
/**
 * Data layer for legal-guarantee (Modulo B) claims — art. 128-135 Cod. Consumo.
 *
 * Legally distinct from withdrawal (Modulo A): defective / non-conforming goods,
 * not a change of mind. Kept in a SEPARATE table with its own fields to avoid
 * mixing legal bases and confusing the two institutes.
 *
 * Privacy-by-design: references the WooCommerce order, does not duplicate
 * personal data. Text-only claims (no uploads) per minimisation.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Garanzia_DB {

	const DB_VERSION = '1.0.0';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dbr54_garanzia';
	}

	public static function create_table(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			claimant_type VARCHAR(10) NOT NULL DEFAULT 'user',
			claimant_email VARCHAR(191) DEFAULT NULL,
			product_ref VARCHAR(191) DEFAULT NULL,
			defect_description TEXT NOT NULL,
			preferred_remedy VARCHAR(20) DEFAULT NULL,
			received_at DATETIME NOT NULL,
			received_tz VARCHAR(40) NOT NULL DEFAULT 'UTC',
			order_hash CHAR(64) NOT NULL,
			receipt_ref VARCHAR(191) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'aperta',
			merchant_note TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function insert( array $data ): int {
		global $wpdb;

		$defaults = array(
			'order_id'           => 0,
			'claimant_type'      => 'user',
			'claimant_email'     => null,
			'product_ref'        => null,
			'defect_description' => '',
			'preferred_remedy'   => null,
			'received_at'        => gmdate( 'Y-m-d H:i:s' ),
			'received_tz'        => 'UTC',
			'order_hash'         => '',
			'receipt_ref'        => null,
			'status'             => 'aperta',
			'merchant_note'      => null,
		);
		$data = wp_parse_args( $data, $defaults );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'order_id'           => (int) $data['order_id'],
				'claimant_type'      => (string) $data['claimant_type'],
				'claimant_email'     => $data['claimant_email'] ? (string) $data['claimant_email'] : null,
				'product_ref'        => $data['product_ref'] ? (string) $data['product_ref'] : null,
				'defect_description' => (string) $data['defect_description'],
				'preferred_remedy'   => $data['preferred_remedy'] ? (string) $data['preferred_remedy'] : null,
				'received_at'        => (string) $data['received_at'],
				'received_tz'        => (string) $data['received_tz'],
				'order_hash'         => (string) $data['order_hash'],
				'receipt_ref'        => $data['receipt_ref'] ? (string) $data['receipt_ref'] : null,
				'status'             => (string) $data['status'],
				'merchant_note'      => $data['merchant_note'] ? (string) $data['merchant_note'] : null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_fields( int $id, array $fields ): bool {
		global $wpdb;
		if ( empty( $fields ) ) {
			return false;
		}
		return (bool) $wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
		return $row ? $row : null;
	}

	/**
	 * @return object[]
	 */
	public static function get_by_order( int $order_id ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC", $order_id )
		);
		return $rows ? $rows : array();
	}

	/**
	 * Claims tied to a user's email (for DSAR export / erase declarations).
	 *
	 * @return object[]
	 */
	public static function get_by_email( string $email ): array {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return array();
		}

		$order_ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'ids',
			)
		);
		$order_ids = array_map( 'intval', (array) $order_ids );

		$clauses = array( $wpdb->prepare( 'claimant_email = %s', $email ) );
		if ( ! empty( $order_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$clauses[] = $wpdb->prepare( "order_id IN ($placeholders)", $order_ids );
		}

		$where = implode( ' OR ', $clauses );
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC" );

		return $rows ? $rows : array();
	}

	/**
	 * @return object[]
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'per_page' => 20,
				'offset'   => 0,
				'orderby'  => 'received_at',
				'order'    => 'DESC',
			)
		);

		$allowed_orderby = array( 'id', 'received_at', 'status', 'order_id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'received_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$table    = self::table();
		$sql      = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = (int) $args['per_page'];
		$params[] = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		return $rows ? $rows : array();
	}

	public static function count( string $status = '' ): int {
		global $wpdb;
		$table = self::table();
		if ( $status ) {
			return (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
