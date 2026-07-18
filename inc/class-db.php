<?php
/**
 * Data layer for withdrawal records.
 *
 * Privacy-by-design: the table does NOT duplicate personal data. It references
 * the WooCommerce order (order_id) and stores only the minimal fields required
 * to evidence the exercise of the right (art. 5.1.c GDPR — minimisation).
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_DB {

	const DB_VERSION = '1.1.0';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dbr54_recessi';
	}

	public static function create_table(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			declarant_type VARCHAR(10) NOT NULL DEFAULT 'user',
			declarant_email VARCHAR(191) DEFAULT NULL,
			reason TEXT DEFAULT NULL,
			received_at DATETIME NOT NULL,
			received_tz VARCHAR(40) NOT NULL DEFAULT 'UTC',
			order_hash CHAR(64) NOT NULL,
			receipt_ref VARCHAR(191) DEFAULT NULL,
			tsa_token LONGBLOB DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'ricevuto',
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a withdrawal record. Returns insert id or 0 on failure.
	 *
	 * @param array $data Sanitized data.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$defaults = array(
			'order_id'        => 0,
			'declarant_type'  => 'user',
			'declarant_email' => null,
			'reason'          => null,
			'received_at'     => gmdate( 'Y-m-d H:i:s' ),
			'received_tz'     => 'UTC',
			'order_hash'      => '',
			'receipt_ref'     => null,
			'tsa_token'       => null,
			'status'          => 'ricevuto',
		);
		$data = wp_parse_args( $data, $defaults );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'order_id'        => (int) $data['order_id'],
				'declarant_type'  => (string) $data['declarant_type'],
				'declarant_email' => $data['declarant_email'] ? (string) $data['declarant_email'] : null,
				'reason'          => ( null !== $data['reason'] && '' !== $data['reason'] ) ? (string) $data['reason'] : null,
				'received_at'     => (string) $data['received_at'],
				'received_tz'     => (string) $data['received_tz'],
				'order_hash'      => (string) $data['order_hash'],
				'receipt_ref'     => $data['receipt_ref'] ? (string) $data['receipt_ref'] : null,
				'tsa_token'       => $data['tsa_token'],
				'status'          => (string) $data['status'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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
	 * Latest withdrawal record for an order (if any).
	 */
	public static function get_by_order( int $order_id ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id )
		);
		return $row ? $row : null;
	}

	public static function has_for_order( int $order_id ): bool {
		return null !== self::get_by_order( $order_id );
	}

	/**
	 * Records tied to a user's email (for DSAR export / erase declarations).
	 *
	 * @return object[]
	 */
	public static function get_by_email( string $email ): array {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return array();
		}

		// Match guest declarant_email OR orders whose billing email matches.
		$order_ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'ids',
			)
		);
		$order_ids = array_map( 'intval', (array) $order_ids );

		$clauses = array( $wpdb->prepare( 'declarant_email = %s', $email ) );
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
	 * Paginated admin listing.
	 *
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
