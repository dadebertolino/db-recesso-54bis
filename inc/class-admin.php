<?php
/**
 * Admin: settings page, withdrawals list, status management, product exclusion.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Admin {

	private static ?DBR54_Admin $instance = null;

	public static function instance(): DBR54_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );

		// Per-product art. 59 exclusion checkbox.
		add_action( 'woocommerce_product_options_shipping', array( $this, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_field' ) );
	}

	private function settings(): DBR54_Settings {
		return DBR54_Settings::instance();
	}

	public function menu(): void {
		$count = DBR54_DB::count( 'ricevuto' );
		$badge = $count ? ' <span class="update-plugins count-' . $count . '"><span class="plugin-count">' . $count . '</span></span>' : '';

		add_menu_page(
			__( 'Recessi 54-bis', 'db-recesso-54bis' ),
			__( 'Recessi', 'db-recesso-54bis' ) . $badge,
			'manage_woocommerce',
			'dbr54-list',
			array( $this, 'render_list' ),
			'dashicons-undo',
			56
		);
		add_submenu_page(
			'dbr54-list',
			__( 'Recessi ricevuti', 'db-recesso-54bis' ),
			__( 'Recessi ricevuti', 'db-recesso-54bis' ),
			'manage_woocommerce',
			'dbr54-list',
			array( $this, 'render_list' )
		);
	}

	public function handle_actions(): void {
		// Change record status.
		if ( isset( $_POST['dbr54_update_status'] ) && check_admin_referer( 'dbr54_status' ) ) {
			$id     = absint( $_POST['dbr54_record_id'] ?? 0 );
			$status = sanitize_key( $_POST['dbr54_status'] ?? '' );
			$allowed = array( 'ricevuto', 'evaso', 'rimborsato' );
			if ( $id && in_array( $status, $allowed, true ) ) {
				DBR54_DB::update_fields( $id, array( 'status' => $status ) );
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'dbr54-list',
						'view' => $id,
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	public function render_list(): void {
		if ( isset( $_GET['view'] ) ) {
			$this->render_detail( absint( $_GET['view'] ) );
			return;
		}

		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$records  = DBR54_DB::query(
			array(
				'status'   => $status,
				'per_page' => $per_page,
				'offset'   => ( $paged - 1 ) * $per_page,
			)
		);
		$total = DBR54_DB::count( $status );
		?>
		<div class="wrap db-admin-wrap">
			<h1><?php esc_html_e( 'Recessi ricevuti', 'db-recesso-54bis' ); ?></h1>

			<ul class="subsubsub">
				<?php
				$counts = array(
					''           => __( 'Tutti', 'db-recesso-54bis' ),
					'ricevuto'   => __( 'Ricevuti', 'db-recesso-54bis' ),
					'evaso'      => __( 'Evasi', 'db-recesso-54bis' ),
					'rimborsato' => __( 'Rimborsati', 'db-recesso-54bis' ),
				);
				$parts = array();
				foreach ( $counts as $key => $label ) {
					$url     = add_query_arg(
						array(
							'page' => 'dbr54-list',
							'status' => $key,
						),
						admin_url( 'admin.php' )
					);
					$active  = $status === $key ? ' class="current"' : '';
					$parts[] = sprintf( '<a href="%s"%s>%s (%d)</a>', esc_url( $url ), $active, esc_html( $label ), DBR54_DB::count( $key ) );
				}
				echo wp_kses_post( implode( ' | ', $parts ) );
				?>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Ordine', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Dichiarante', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Ricevuto il', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Stato', 'db-recesso-54bis' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $records ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Nessun recesso registrato.', 'db-recesso-54bis' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $records as $r ) :
							$order = wc_get_order( $r->order_id );
							$view  = add_query_arg(
								array(
									'page' => 'dbr54-list',
									'view' => $r->id,
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td><?php echo esc_html( $r->id ); ?></td>
								<td>
									<?php if ( $order ) : ?>
										<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
									<?php else : ?>
										#<?php echo esc_html( $r->order_id ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( 'guest' === $r->declarant_type ? ( $r->declarant_email ? $r->declarant_email : __( 'Ospite', 'db-recesso-54bis' ) ) : __( 'Account', 'db-recesso-54bis' ) ); ?></td>
								<td><?php echo esc_html( DBR54_Recesso::instance()->format_received( $r ) ); ?></td>
								<td><span class="dbr54-badge dbr54-badge-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( $this->status_label( $r->status ) ); ?></span></td>
								<td><a class="button button-small" href="<?php echo esc_url( $view ); ?>"><?php esc_html_e( 'Dettaglio', 'db-recesso-54bis' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $total_pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private function render_detail( int $id ): void {
		$r = DBR54_DB::get( $id );
		if ( ! $r ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Record non trovato.', 'db-recesso-54bis' ) . '</p></div>';
			return;
		}
		$order = wc_get_order( $r->order_id );
		$back  = admin_url( 'admin.php?page=dbr54-list' );
		?>
		<div class="wrap db-admin-wrap">
			<h1>
			<?php
				/* translators: %d: ID recesso */
				echo esc_html( sprintf( __( 'Recesso #%d', 'db-recesso-54bis' ), $r->id ) );
			?>
			</h1>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Torna alla lista', 'db-recesso-54bis' ); ?></a></p>

			<table class="form-table">
				<tr><th><?php esc_html_e( 'Ordine', 'db-recesso-54bis' ); ?></th>
					<td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html( '#' . $r->order_id ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Dichiarante', 'db-recesso-54bis' ); ?></th>
					<td><?php echo esc_html( 'guest' === $r->declarant_type ? $r->declarant_email : ( $order ? $order->get_formatted_billing_full_name() : __( 'Account', 'db-recesso-54bis' ) ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Ricevuto il', 'db-recesso-54bis' ); ?></th>
					<td><?php echo esc_html( DBR54_Recesso::instance()->format_received( $r ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Motivo', 'db-recesso-54bis' ); ?></th>
					<td><?php echo $r->reason ? esc_html( $r->reason ) : '<em>' . esc_html__( 'non indicato', 'db-recesso-54bis' ) . '</em>'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Hash ordine (SHA-256)', 'db-recesso-54bis' ); ?></th>
					<td><code style="word-break:break-all;"><?php echo esc_html( $r->order_hash ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Marca temporale', 'db-recesso-54bis' ); ?></th>
					<td><?php echo $r->tsa_token ? esc_html__( 'Applicata (RFC 3161)', 'db-recesso-54bis' ) : esc_html__( 'Non applicata', 'db-recesso-54bis' ); ?></td></tr>
			</table>

			<h2><?php esc_html_e( 'Gestione stato', 'db-recesso-54bis' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'dbr54_status' ); ?>
				<input type="hidden" name="dbr54_record_id" value="<?php echo esc_attr( $r->id ); ?>">
				<select name="dbr54_status">
					<?php foreach ( array( 'ricevuto', 'evaso', 'rimborsato' ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $r->status, $s ); ?>><?php echo esc_html( $this->status_label( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" name="dbr54_update_status" value="1" class="button button-primary"><?php esc_html_e( 'Aggiorna stato', 'db-recesso-54bis' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Il rimborso (entro 14 giorni) può essere trattenuto finché non ricevi il bene o la prova di spedizione. Il plugin traccia lo stato ma non forza alcun rimborso automatico.', 'db-recesso-54bis' ); ?></p>
		</div>
		<?php
	}

	private function status_label( string $status ): string {
		$map = array(
			'ricevuto'   => __( 'Ricevuto', 'db-recesso-54bis' ),
			'evaso'      => __( 'Evaso', 'db-recesso-54bis' ),
			'rimborsato' => __( 'Rimborsato', 'db-recesso-54bis' ),
		);
		return $map[ $status ] ?? $status;
	}

	public function product_field(): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => '_dbr54_art59_excluded',
				'label'       => __( 'Escluso dal recesso (art. 59)', 'db-recesso-54bis' ),
				'description' => __( 'Bene sigillato / personalizzato / deperibile ecc. — non soggetto a diritto di recesso.', 'db-recesso-54bis' ),
			)
		);
	}

	public function save_product_field( int $product_id ): void {
		// Nonce già verificato da WooCommerce prima di woocommerce_process_product_meta.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$val = isset( $_POST['_dbr54_art59_excluded'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_dbr54_art59_excluded', $val );
	}
}
