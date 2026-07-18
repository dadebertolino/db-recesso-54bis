<?php
/**
 * Always-on admin core: shared assets, plugin action links, and the unified
 * settings page (both modules). Runs regardless of which modules are enabled,
 * so the settings page is always reachable and module toggles live in one place.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Admin_Core {

	private static ?DBR54_Admin_Core $instance = null;

	public static function instance(): DBR54_Admin_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . DBR54_BASENAME, array( $this, 'action_links' ) );
	}

	private function settings(): DBR54_Settings {
		return DBR54_Settings::instance();
	}

	public function action_links( array $links ): array {
		$url  = admin_url( 'admin.php?page=dbr54-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Impostazioni', 'db-recesso-54bis' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'dbr54' ) ) {
			return;
		}
		wp_enqueue_style( 'db-admin-ui', DBR54_URL . 'assets/css/db-admin-ui.css', array(), DBR54_VERSION );
		wp_enqueue_style( 'dbr54-admin', DBR54_URL . 'assets/css/admin.css', array( 'db-admin-ui' ), DBR54_VERSION );
	}

	/**
	 * Register the settings page. If Modulo A owns the top-level "Recessi" menu,
	 * attach there; if only Modulo B is on, its admin attaches settings itself;
	 * if neither module is on, create a minimal top-level so settings stay reachable.
	 */
	public function menu(): void {
		$recesso_on  = $this->settings()->get( 'module_recesso_enabled', true );
		$garanzia_on = $this->settings()->get( 'module_garanzia_enabled', false );

		if ( $recesso_on ) {
			add_submenu_page(
				'dbr54-list',
				__( 'Impostazioni', 'db-recesso-54bis' ),
				__( 'Impostazioni', 'db-recesso-54bis' ),
				'manage_woocommerce',
				'dbr54-settings',
				array( $this, 'render_settings' )
			);
			return;
		}

		if ( $garanzia_on ) {
			// Garanzia admin registers the settings submenu under its own top-level.
			return;
		}

		// Neither module enabled: standalone settings menu.
		add_menu_page(
			__( 'DB Recesso 54-bis', 'db-recesso-54bis' ),
			__( 'DB Recesso', 'db-recesso-54bis' ),
			'manage_woocommerce',
			'dbr54-settings',
			array( $this, 'render_settings' ),
			'dashicons-undo',
			56
		);
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['dbr54_save_settings'] ) || ! check_admin_referer( 'dbr54_settings' ) ) {
			return;
		}
		$in = wp_unslash( $_POST );

		$eligible = isset( $in['eligible_statuses'] ) && is_array( $in['eligible_statuses'] )
			? array_map( 'sanitize_key', $in['eligible_statuses'] )
			: array();

		$garanzia_eligible = isset( $in['garanzia_eligible_statuses'] ) && is_array( $in['garanzia_eligible_statuses'] )
			? array_map( 'sanitize_key', $in['garanzia_eligible_statuses'] )
			: array();

		$excluded_cats = isset( $in['excluded_cats'] ) && is_array( $in['excluded_cats'] )
			? array_map( 'intval', $in['excluded_cats'] )
			: array();

		$timestamp_mode = ( isset( $in['timestamp_mode'] ) && 'tsa' === $in['timestamp_mode'] ) ? 'tsa' : 'base';

		$this->settings()->update(
			array(
				'module_recesso_enabled'     => ! empty( $in['module_recesso_enabled'] ),
				'module_garanzia_enabled'    => ! empty( $in['module_garanzia_enabled'] ),
				'button_label'               => sanitize_text_field( $in['button_label'] ?? '' ),
				'garanzia_button_label'      => sanitize_text_field( $in['garanzia_button_label'] ?? '' ),
				'withdrawal_days'            => max( 1, absint( $in['withdrawal_days'] ?? 14 ) ),
				'eligible_statuses'          => $eligible,
				'garanzia_eligible_statuses' => $garanzia_eligible,
				'excluded_cats'              => $excluded_cats,
				'admin_email'                => sanitize_email( $in['admin_email'] ?? get_option( 'admin_email' ) ),
				'timestamp_mode'             => $timestamp_mode,
				'tsa_url'                    => esc_url_raw( $in['tsa_url'] ?? '' ),
				'privacy_page_id'            => absint( $in['privacy_page_id'] ?? 0 ),
				'retention_years'            => max( 1, absint( $in['retention_years'] ?? 10 ) ),
			)
		);

		// Module toggles change registered endpoints; flush on next load.
		update_option( 'dbr54_flush_needed', 1 );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=dbr54-settings' ) ) );
		exit;
	}

	public function render_settings(): void {
		$s            = $this->settings();
		$wc_statuses  = wc_get_order_statuses();
		$product_cats = get_terms(
			array(
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<div class="wrap db-admin-wrap">
			<h1><?php esc_html_e( 'DB Recesso 54-bis — Impostazioni', 'db-recesso-54bis' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni salvate.', 'db-recesso-54bis' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Nota legale:', 'db-recesso-54bis' ); ?></strong>
				<?php esc_html_e( 'Il plugin fornisce le funzioni digitali. Non sostituisce l\'informativa precontrattuale (art. 49), il modulo tipo Allegato I-B né le condizioni generali di vendita, che vanno aggiornati separatamente.', 'db-recesso-54bis' ); ?></p>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'dbr54_settings' ); ?>

				<h2><?php esc_html_e( 'Moduli', 'db-recesso-54bis' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Moduli attivi', 'db-recesso-54bis' ); ?></th>
						<td>
							<label style="display:block;">
								<input type="checkbox" name="module_recesso_enabled" value="1" <?php checked( $s->get( 'module_recesso_enabled', true ) ); ?>>
								<strong><?php esc_html_e( 'Modulo A — Recesso (art. 54-bis)', 'db-recesso-54bis' ); ?></strong> — <?php esc_html_e( 'ripensamento del consumatore, 14 giorni.', 'db-recesso-54bis' ); ?>
							</label>
							<label style="display:block; margin-top:6px;">
								<input type="checkbox" name="module_garanzia_enabled" value="1" <?php checked( $s->get( 'module_garanzia_enabled', false ) ); ?>>
								<strong><?php esc_html_e( 'Modulo B — Garanzia legale (art. 128-135)', 'db-recesso-54bis' ); ?></strong> — <?php esc_html_e( 'prodotto difettoso o non conforme.', 'db-recesso-54bis' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'I due istituti sono giuridicamente distinti e restano separati nel flusso e nell\'interfaccia.', 'db-recesso-54bis' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Modulo A — Recesso', 'db-recesso-54bis' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="button_label"><?php esc_html_e( 'Etichetta pulsante', 'db-recesso-54bis' ); ?></label></th>
						<td><input type="text" id="button_label" name="button_label" class="regular-text" value="<?php echo esc_attr( $s->get( 'button_label' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="withdrawal_days"><?php esc_html_e( 'Giorni finestra recesso', 'db-recesso-54bis' ); ?></label></th>
						<td><input type="number" id="withdrawal_days" name="withdrawal_days" min="1" value="<?php echo esc_attr( $s->get( 'withdrawal_days' ) ); ?>"> <span class="description"><?php esc_html_e( 'Predefinito di legge: 14', 'db-recesso-54bis' ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Stati ordine idonei', 'db-recesso-54bis' ); ?></th>
						<td>
							<?php
							$eligible = (array) $s->get( 'eligible_statuses' );
							foreach ( $wc_statuses as $key => $label ) :
								$slug = str_replace( 'wc-', '', $key );
								?>
								<label style="display:block;">
									<input type="checkbox" name="eligible_statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $eligible, true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Categorie escluse (art. 59)', 'db-recesso-54bis' ); ?></th>
						<td>
							<?php
							$excluded = array_map( 'intval', (array) $s->get( 'excluded_cats' ) );
							if ( ! is_wp_error( $product_cats ) && $product_cats ) :
								foreach ( $product_cats as $cat ) :
									?>
									<label style="display:block;">
										<input type="checkbox" name="excluded_cats[]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( $cat->term_id, $excluded, true ) ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</label>
									<?php
								endforeach;
							endif;
							?>
							<p class="description"><?php esc_html_e( 'Puoi anche escludere singoli prodotti dalla loro scheda.', 'db-recesso-54bis' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Timestamp probatorio (Modulo A)', 'db-recesso-54bis' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Livello', 'db-recesso-54bis' ); ?></th>
						<td>
							<label style="display:block;">
								<input type="radio" name="timestamp_mode" value="base" <?php checked( $s->get( 'timestamp_mode' ), 'base' ); ?>>
								<?php esc_html_e( 'Base — 100% self-contained (consigliato): ora server + hash SHA-256.', 'db-recesso-54bis' ); ?>
							</label>
							<label style="display:block; margin-top:6px;">
								<input type="radio" name="timestamp_mode" value="tsa" <?php checked( $s->get( 'timestamp_mode' ), 'tsa' ); ?>>
								<?php esc_html_e( 'Rafforzato — marca temporale qualificata (RFC 3161).', 'db-recesso-54bis' ); ?>
							</label>
							<p class="description" style="color:#7a5d00;"><?php esc_html_e( 'Il livello rafforzato introduce una dipendenza esterna (server TSA). Il recesso funziona comunque senza; la marca è additiva.', 'db-recesso-54bis' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tsa_url"><?php esc_html_e( 'URL server TSA', 'db-recesso-54bis' ); ?></label></th>
						<td><input type="url" id="tsa_url" name="tsa_url" class="regular-text" value="<?php echo esc_attr( $s->get( 'tsa_url' ) ); ?>" placeholder="https://..."></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Modulo B — Garanzia legale', 'db-recesso-54bis' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="garanzia_button_label"><?php esc_html_e( 'Etichetta pulsante', 'db-recesso-54bis' ); ?></label></th>
						<td><input type="text" id="garanzia_button_label" name="garanzia_button_label" class="regular-text" value="<?php echo esc_attr( $s->get( 'garanzia_button_label' ) ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Stati ordine idonei', 'db-recesso-54bis' ); ?></th>
						<td>
							<?php
							$gar_eligible = (array) $s->get( 'garanzia_eligible_statuses' );
							foreach ( $wc_statuses as $key => $label ) :
								$slug = str_replace( 'wc-', '', $key );
								?>
								<label style="display:block;">
									<input type="checkbox" name="garanzia_eligible_statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $gar_eligible, true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Nessun limite temporale automatico: il venditore valuta se la pratica rientra nella garanzia legale (art. 133).', 'db-recesso-54bis' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Generali', 'db-recesso-54bis' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="admin_email"><?php esc_html_e( 'Email notifiche admin', 'db-recesso-54bis' ); ?></label></th>
						<td><input type="email" id="admin_email" name="admin_email" class="regular-text" value="<?php echo esc_attr( $s->get( 'admin_email' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="privacy_page_id"><?php esc_html_e( 'Pagina informativa privacy', 'db-recesso-54bis' ); ?></label></th>
						<td>
						<?php
						// wp_dropdown_pages() esegue il proprio escaping dell'output.
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
						wp_dropdown_pages(
							array(
								'name'              => 'privacy_page_id',
								'id'                => 'privacy_page_id',
								'selected'          => $s->get( 'privacy_page_id' ),
								'show_option_none'  => __( '— nessuna —', 'db-recesso-54bis' ),
								'option_none_value' => 0,
							)
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
							</td>
					</tr>
					<tr>
						<th><label for="retention_years"><?php esc_html_e( 'Conservazione ricevute (anni)', 'db-recesso-54bis' ); ?></label></th>
						<td><input type="number" id="retention_years" name="retention_years" min="1" value="<?php echo esc_attr( $s->get( 'retention_years' ) ); ?>"> <span class="description"><?php esc_html_e( 'Allineare ai termini di prescrizione. Documentato nel registro dei trattamenti.', 'db-recesso-54bis' ); ?></span></td>
					</tr>
				</table>

				<p><button type="submit" name="dbr54_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'db-recesso-54bis' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
