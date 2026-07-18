<?php
/**
 * Admin for Modulo B (legal guarantee): claims list, detail, status + note.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Garanzia_Admin {

	private static ?DBR54_Garanzia_Admin $instance = null;

	public static function instance(): DBR54_Garanzia_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function menu(): void {
		$count = DBR54_Garanzia_DB::count( 'aperta' );
		$badge = $count ? ' <span class="update-plugins count-' . $count . '"><span class="plugin-count">' . $count . '</span></span>' : '';

		$recesso_on = DBR54_Settings::instance()->get( 'module_recesso_enabled', true );

		if ( $recesso_on ) {
			// Attach under the existing "Recessi" top-level menu.
			add_submenu_page(
				'dbr54-list',
				__( 'Garanzia legale', 'db-recesso-54bis' ),
				__( 'Garanzia legale', 'db-recesso-54bis' ) . $badge,
				'manage_woocommerce',
				'dbr54-garanzia',
				array( $this, 'render_list' )
			);
			return;
		}

		// Standalone top-level menu when Modulo A is off.
		add_menu_page(
			__( 'Garanzia legale', 'db-recesso-54bis' ),
			__( 'Garanzia legale', 'db-recesso-54bis' ) . $badge,
			'manage_woocommerce',
			'dbr54-garanzia',
			array( $this, 'render_list' ),
			'dashicons-shield',
			56
		);
		add_submenu_page(
			'dbr54-garanzia',
			__( 'Pratiche', 'db-recesso-54bis' ),
			__( 'Pratiche', 'db-recesso-54bis' ),
			'manage_woocommerce',
			'dbr54-garanzia',
			array( $this, 'render_list' )
		);
		add_submenu_page(
			'dbr54-garanzia',
			__( 'Impostazioni', 'db-recesso-54bis' ),
			__( 'Impostazioni', 'db-recesso-54bis' ),
			'manage_woocommerce',
			'dbr54-settings',
			array( DBR54_Admin_Core::instance(), 'render_settings' )
		);
	}

	public function handle_actions(): void {
		if ( isset( $_POST['dbr54_gar_update'] ) && check_admin_referer( 'dbr54_gar_status' ) ) {
			$id      = absint( $_POST['dbr54_claim_id'] ?? 0 );
			$status  = sanitize_key( $_POST['dbr54_status'] ?? '' );
			$note    = isset( $_POST['dbr54_merchant_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dbr54_merchant_note'] ) ) : '';
			$allowed = array( 'aperta', 'in_lavorazione', 'accolta', 'respinta', 'chiusa' );
			if ( $id && in_array( $status, $allowed, true ) ) {
				DBR54_Garanzia_DB::update_fields(
					$id,
					array(
						'status' => $status,
						'merchant_note' => $note,
					)
				);
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'dbr54-garanzia',
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
		$claims   = DBR54_Garanzia_DB::query(
			array(
				'status' => $status,
				'per_page' => $per_page,
				'offset' => ( $paged - 1 ) * $per_page,
			)
		);
		$total = DBR54_Garanzia_DB::count( $status );
		?>
		<div class="wrap db-admin-wrap">
			<h1><?php esc_html_e( 'Pratiche di garanzia legale', 'db-recesso-54bis' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Garanzia legale di conformità (art. 128-135). Istituto distinto dal recesso. Il rimedio è valutato dal venditore secondo legge.', 'db-recesso-54bis' ); ?></p>

			<ul class="subsubsub">
				<?php
				$filters = array(
					''               => __( 'Tutte', 'db-recesso-54bis' ),
					'aperta'         => __( 'Aperte', 'db-recesso-54bis' ),
					'in_lavorazione' => __( 'In lavorazione', 'db-recesso-54bis' ),
					'accolta'        => __( 'Accolte', 'db-recesso-54bis' ),
					'respinta'       => __( 'Respinte', 'db-recesso-54bis' ),
					'chiusa'         => __( 'Chiuse', 'db-recesso-54bis' ),
				);
				$parts = array();
				foreach ( $filters as $key => $label ) {
					$url     = add_query_arg(
						array(
							'page' => 'dbr54-garanzia',
							'status' => $key,
						),
						admin_url( 'admin.php' )
					);
					$active  = $status === $key ? ' class="current"' : '';
					$parts[] = sprintf( '<a href="%s"%s>%s (%d)</a>', esc_url( $url ), $active, esc_html( $label ), DBR54_Garanzia_DB::count( $key ) );
				}
				echo wp_kses_post( implode( ' | ', $parts ) );
				?>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Ordine', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Prodotto', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Aperta il', 'db-recesso-54bis' ); ?></th>
						<th><?php esc_html_e( 'Stato', 'db-recesso-54bis' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $claims ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Nessuna pratica registrata.', 'db-recesso-54bis' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $claims as $c ) :
							$order = wc_get_order( $c->order_id );
							$view  = add_query_arg(
								array(
									'page' => 'dbr54-garanzia',
									'view' => $c->id,
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td><?php echo esc_html( $c->id ); ?></td>
								<td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html( '#' . $c->order_id ); ?></td>
								<td><?php echo esc_html( $c->product_ref ? $c->product_ref : '—' ); ?></td>
								<td><?php echo esc_html( DBR54_Garanzia::instance()->format_received( $c ) ); ?></td>
								<td><span class="dbr54-badge dbr54-badge-gar-<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( $this->status_label( $c->status ) ); ?></span></td>
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
							'base' => add_query_arg( 'paged', '%#%' ),
							'format' => '',
							'current' => $paged,
							'total' => $total_pages,
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
		$c = DBR54_Garanzia_DB::get( $id );
		if ( ! $c ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Pratica non trovata.', 'db-recesso-54bis' ) . '</p></div>';
			return;
		}
		$order = wc_get_order( $c->order_id );
		$back  = admin_url( 'admin.php?page=dbr54-garanzia' );
		?>
		<div class="wrap db-admin-wrap">
			<h1>
			<?php
				/* translators: %d: ID pratica */
				echo esc_html( sprintf( __( 'Pratica di garanzia #%d', 'db-recesso-54bis' ), $c->id ) );
			?>
			</h1>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Torna alla lista', 'db-recesso-54bis' ); ?></a></p>

			<table class="form-table">
				<tr><th><?php esc_html_e( 'Ordine', 'db-recesso-54bis' ); ?></th>
					<td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html( '#' . $c->order_id ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Richiedente', 'db-recesso-54bis' ); ?></th>
					<td><?php echo esc_html( 'guest' === $c->claimant_type ? $c->claimant_email : ( $order ? $order->get_formatted_billing_full_name() : __( 'Account', 'db-recesso-54bis' ) ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Prodotto', 'db-recesso-54bis' ); ?></th><td><?php echo esc_html( $c->product_ref ? $c->product_ref : '—' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Difetto segnalato', 'db-recesso-54bis' ); ?></th><td><?php echo nl2br( esc_html( $c->defect_description ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Rimedio preferito', 'db-recesso-54bis' ); ?></th><td><?php echo esc_html( DBR54_Garanzia::remedy_label( $c->preferred_remedy ) ); ?> <em>(<?php esc_html_e( 'informativo', 'db-recesso-54bis' ); ?>)</em></td></tr>
				<tr><th><?php esc_html_e( 'Aperta il', 'db-recesso-54bis' ); ?></th><td><?php echo esc_html( DBR54_Garanzia::instance()->format_received( $c ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Hash ordine (SHA-256)', 'db-recesso-54bis' ); ?></th><td><code style="word-break:break-all;"><?php echo esc_html( $c->order_hash ); ?></code></td></tr>
			</table>

			<h2><?php esc_html_e( 'Gestione pratica', 'db-recesso-54bis' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'dbr54_gar_status' ); ?>
				<input type="hidden" name="dbr54_claim_id" value="<?php echo esc_attr( $c->id ); ?>">
				<table class="form-table">
					<tr>
						<th><label for="dbr54_status"><?php esc_html_e( 'Stato', 'db-recesso-54bis' ); ?></label></th>
						<td>
							<select id="dbr54_status" name="dbr54_status">
								<?php foreach ( array( 'aperta', 'in_lavorazione', 'accolta', 'respinta', 'chiusa' ) as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $c->status, $s ); ?>><?php echo esc_html( $this->status_label( $s ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="dbr54_merchant_note"><?php esc_html_e( 'Nota interna / rimedio deciso', 'db-recesso-54bis' ); ?></label></th>
						<td><textarea id="dbr54_merchant_note" name="dbr54_merchant_note" rows="4" class="large-text"><?php echo esc_textarea( $c->merchant_note ?? '' ); ?></textarea></td>
					</tr>
				</table>
				<p><button type="submit" name="dbr54_gar_update" value="1" class="button button-primary"><?php esc_html_e( 'Aggiorna pratica', 'db-recesso-54bis' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private function status_label( string $status ): string {
		$map = array(
			'aperta'         => __( 'Aperta', 'db-recesso-54bis' ),
			'in_lavorazione' => __( 'In lavorazione', 'db-recesso-54bis' ),
			'accolta'        => __( 'Accolta', 'db-recesso-54bis' ),
			'respinta'       => __( 'Respinta', 'db-recesso-54bis' ),
			'chiusa'         => __( 'Chiusa', 'db-recesso-54bis' ),
		);
		return $map[ $status ] ?? $status;
	}
}
