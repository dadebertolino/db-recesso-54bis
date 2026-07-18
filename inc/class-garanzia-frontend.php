<?php
/**
 * Frontend for Modulo B (legal guarantee).
 *
 * Deliberately SEPARATE from the withdrawal frontend: distinct endpoint,
 * distinct labelling, distinct form. Keeps the two institutes from being
 * confused by the customer.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Garanzia_Frontend {

	private static ?DBR54_Garanzia_Frontend $instance = null;
	const ENDPOINT = 'garanzia';

	public static function instance(): DBR54_Garanzia_Frontend {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );

		add_action( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_action_button' ), 20, 2 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'maybe_render_result' ), 5 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );

		add_shortcode( 'dbr54_garanzia_guest', array( $this, 'guest_shortcode' ) );

		add_action( 'template_redirect', array( $this, 'handle_submission' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function assets(): void {
		$post          = get_post();
		$has_shortcode = $post instanceof WP_Post && has_shortcode( $post->post_content, 'dbr54_garanzia_guest' );
		if ( ! function_exists( 'is_account_page' ) || ( ! is_account_page() && ! $has_shortcode ) ) {
			return;
		}
		wp_enqueue_style( 'dbr54-frontend', DBR54_URL . 'assets/css/frontend.css', array(), DBR54_VERSION );
	}

	private function garanzia(): DBR54_Garanzia {
		return DBR54_Garanzia::instance();
	}

	private function settings(): DBR54_Settings {
		return DBR54_Settings::instance();
	}

	public function order_action_button( array $actions, WC_Order $order ): array {
		if ( ! $this->garanzia()->is_order_eligible( $order ) ) {
			return $actions;
		}
		$url = wc_get_account_endpoint_url( self::ENDPOINT );
		$url = add_query_arg( 'ordine', $order->get_id(), $url );

		$actions['dbr54_garanzia'] = array(
			'url'  => wp_nonce_url( $url, 'dbr54_gar_open_' . $order->get_id() ),
			'name' => $this->settings()->get( 'garanzia_button_label' ),
		);
		return $actions;
	}

	public function render_endpoint(): void {
		if ( ! empty( $_GET['dbr54_done'] ) ) {
			return; // Result already rendered by maybe_render_result().
		}

		$order_id = isset( $_GET['ordine'] ) ? absint( $_GET['ordine'] ) : 0;
		if ( ! $order_id ) {
			$this->render_order_picker();
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
			$this->notice( __( 'Ordine non trovato o non associato al tuo account.', 'db-recesso-54bis' ), 'error' );
			return;
		}

		$this->render_form( $order, 'user', $order->get_billing_email() );
	}

	private function render_order_picker(): void {
		$orders = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$eligible = array_filter( $orders, fn( $o ) => $this->garanzia()->is_order_eligible( $o ) );

		echo '<h2>' . esc_html__( 'Garanzia legale di conformità', 'db-recesso-54bis' ) . '</h2>';
		echo '<p>' . esc_html__( 'Se un prodotto acquistato è difettoso o non conforme, apri una pratica di garanzia. È un istituto diverso dal recesso: qui segnali un difetto, non un ripensamento.', 'db-recesso-54bis' ) . '</p>';

		if ( empty( $eligible ) ) {
			$this->notice( __( 'Non risultano ordini idonei per una pratica di garanzia.', 'db-recesso-54bis' ), 'info' );
			return;
		}

		echo '<ul class="dbr54-order-list">';
		foreach ( $eligible as $o ) {
			$url = add_query_arg( 'ordine', $o->get_id(), wc_get_account_endpoint_url( self::ENDPOINT ) );
			$url = wp_nonce_url( $url, 'dbr54_gar_open_' . $o->get_id() );
			printf(
				'<li><a href="%s">%s — %s</a></li>',
				esc_url( $url ),
				esc_html(
					sprintf(
					/* translators: %s: numero ordine */
						__( 'Ordine %s', 'db-recesso-54bis' ),
						$o->get_order_number()
					)
				),
				esc_html( wp_strip_all_tags( $o->get_date_created()->date_i18n( wc_date_format() ) ) )
			);
		}
		echo '</ul>';
	}

	public function guest_shortcode(): string {
		ob_start();

		if ( $this->maybe_render_result() ) {
			return ob_get_clean();
		}

		$matched = false;
		if ( isset( $_POST['dbr54_gar_lookup'] ) && check_admin_referer( 'dbr54_gar_lookup', 'dbr54_gar_nonce' ) ) {
			$number = isset( $_POST['dbr54_order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['dbr54_order_number'] ) ) : '';
			$email  = isset( $_POST['dbr54_email'] ) ? sanitize_email( wp_unslash( $_POST['dbr54_email'] ) ) : '';
			$order  = $this->lookup_guest_order( $number, $email );

			if ( $order ) {
				$matched = true;
				$this->render_form( $order, 'guest', $email );
			} else {
				$this->notice( __( 'Nessun ordine corrisponde ai dati inseriti.', 'db-recesso-54bis' ), 'error' );
			}
		}

		if ( ! $matched ) {
			$this->render_guest_lookup_form();
		}

		return ob_get_clean();
	}

	private function lookup_guest_order( string $number, string $email ): ?WC_Order {
		if ( '' === $number || '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$order_id = (int) preg_replace( '/[^0-9]/', '', $number );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
			return null;
		}
		return $order;
	}

	private function render_guest_lookup_form(): void {
		?>
		<form method="post" class="dbr54-form dbr54-guest-lookup">
			<h2><?php esc_html_e( 'Garanzia legale di conformità', 'db-recesso-54bis' ); ?></h2>
			<p><?php esc_html_e( 'Inserisci il numero ordine e l\'email usata per l\'acquisto.', 'db-recesso-54bis' ); ?></p>
			<?php wp_nonce_field( 'dbr54_gar_lookup', 'dbr54_gar_nonce' ); ?>
			<p>
				<label for="dbr54_order_number"><?php esc_html_e( 'Numero ordine', 'db-recesso-54bis' ); ?></label>
				<input type="text" id="dbr54_order_number" name="dbr54_order_number" required>
			</p>
			<p>
				<label for="dbr54_email"><?php esc_html_e( 'Email', 'db-recesso-54bis' ); ?></label>
				<input type="email" id="dbr54_email" name="dbr54_email" required>
			</p>
			<button type="submit" name="dbr54_gar_lookup" value="1" class="button dbr54-button">
				<?php esc_html_e( 'Continua', 'db-recesso-54bis' ); ?>
			</button>
		</form>
		<?php
	}

	private function render_form( WC_Order $order, string $claimant_type, ?string $email ): void {
		$products = $this->garanzia()->order_products( $order );
		$remedies = DBR54_Garanzia::remedies();
		?>
		<div class="dbr54-flow">
			<h2><?php esc_html_e( 'Apri una pratica di garanzia', 'db-recesso-54bis' ); ?> — 
									<?php
									/* translators: %s: numero ordine */
									echo esc_html( sprintf( __( 'Ordine %s', 'db-recesso-54bis' ), $order->get_order_number() ) );
									?>
			</h2>
			<p><?php esc_html_e( 'Garanzia legale di conformità (art. 128-135 Cod. Consumo). Descrivi il difetto: il venditore valuterà la pratica e ti proporrà il rimedio previsto dalla legge.', 'db-recesso-54bis' ); ?></p>

			<form method="post" class="dbr54-form">
				<?php wp_nonce_field( 'dbr54_gar_confirm_' . $order->get_id(), 'dbr54_gar_confirm_nonce' ); ?>
				<input type="hidden" name="dbr54_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
				<input type="hidden" name="dbr54_claimant_type" value="<?php echo esc_attr( $claimant_type ); ?>">
				<?php if ( 'guest' === $claimant_type ) : ?>
					<input type="hidden" name="dbr54_email" value="<?php echo esc_attr( $email ); ?>">
				<?php endif; ?>

				<?php if ( ! empty( $products ) ) : ?>
					<p>
						<label for="dbr54_product_ref"><?php esc_html_e( 'Prodotto interessato', 'db-recesso-54bis' ); ?></label>
						<select id="dbr54_product_ref" name="dbr54_product_ref">
							<option value=""><?php esc_html_e( '— seleziona —', 'db-recesso-54bis' ); ?></option>
							<?php foreach ( $products as $ref => $name ) : ?>
								<option value="<?php echo esc_attr( $ref ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>

				<p>
					<label for="dbr54_defect"><?php esc_html_e( 'Descrizione del difetto o della non conformità', 'db-recesso-54bis' ); ?> <span class="dbr54-required">*</span></label>
					<textarea id="dbr54_defect" name="dbr54_defect" rows="5" required></textarea>
				</p>

				<p>
					<label for="dbr54_remedy"><?php esc_html_e( 'Rimedio preferito', 'db-recesso-54bis' ); ?>
						<span class="dbr54-optional">(<?php esc_html_e( 'indicativo — la scelta finale spetta al venditore secondo legge', 'db-recesso-54bis' ); ?>)</span>
					</label>
					<select id="dbr54_remedy" name="dbr54_remedy">
						<?php foreach ( $remedies as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php $this->micro_privacy_notice(); ?>

				<button type="submit" name="dbr54_gar_confirm" value="1" class="button dbr54-button dbr54-button-primary">
					<?php esc_html_e( 'Apri la pratica', 'db-recesso-54bis' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	public function handle_submission(): void {
		if ( empty( $_POST['dbr54_gar_confirm'] ) ) {
			return;
		}
		$order_id = isset( $_POST['dbr54_order_id'] ) ? absint( $_POST['dbr54_order_id'] ) : 0;
		if ( ! $order_id || ! check_admin_referer( 'dbr54_gar_confirm_' . $order_id, 'dbr54_gar_confirm_nonce' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$claimant_type = isset( $_POST['dbr54_claimant_type'] ) ? sanitize_key( $_POST['dbr54_claimant_type'] ) : 'user';
		$claimant_type = in_array( $claimant_type, array( 'user', 'guest' ), true ) ? $claimant_type : 'user';
		$email         = isset( $_POST['dbr54_email'] ) ? sanitize_email( wp_unslash( $_POST['dbr54_email'] ) ) : null;
		$product_ref   = isset( $_POST['dbr54_product_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['dbr54_product_ref'] ) ) : '';
		$defect        = isset( $_POST['dbr54_defect'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dbr54_defect'] ) ) : '';
		$remedy        = isset( $_POST['dbr54_remedy'] ) ? sanitize_key( $_POST['dbr54_remedy'] ) : 'nessuna';

		if ( 'user' === $claimant_type ) {
			if ( ! is_user_logged_in() || $order->get_customer_id() !== get_current_user_id() ) {
				wp_die( esc_html__( 'Autorizzazione non valida.', 'db-recesso-54bis' ) );
			}
			$email = $order->get_billing_email();
		} elseif ( ! $email || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
				wp_die( esc_html__( 'Autorizzazione non valida.', 'db-recesso-54bis' ) );
		}

		$result = $this->garanzia()->process( $order, $claimant_type, $email, $product_ref, $defect, $remedy );

		$token = wp_generate_password( 20, false, false );
		set_transient( 'dbr54_gar_result_' . $token, $result, 5 * MINUTE_IN_SECONDS );

		$redirect = remove_query_arg( array( 'ordine' ) );
		$redirect = add_query_arg( 'dbr54_done', $token, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function maybe_render_result(): bool {
		if ( empty( $_GET['dbr54_done'] ) ) {
			return false;
		}
		$token  = sanitize_text_field( wp_unslash( $_GET['dbr54_done'] ) );
		$result = get_transient( 'dbr54_gar_result_' . $token );
		if ( false === $result ) {
			return false;
		}
		delete_transient( 'dbr54_gar_result_' . $token );

		if ( ! empty( $result['success'] ) ) {
			echo '<div class="dbr54-notice dbr54-notice-success"><p>';
			echo esc_html__( 'Pratica di garanzia aperta correttamente. Ti abbiamo inviato la ricevuta via email.', 'db-recesso-54bis' );
			if ( ! empty( $result['receipt_url'] ) ) {
				printf(
					' <a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( $result['receipt_url'] ),
					esc_html__( 'Scarica la ricevuta', 'db-recesso-54bis' )
				);
			}
			echo '</p></div>';
		} else {
			$this->notice( $result['error'] ?? __( 'Si è verificato un errore.', 'db-recesso-54bis' ), 'error' );
		}
		return true;
	}

	private function micro_privacy_notice(): void {
		$privacy_id  = (int) $this->settings()->get( 'privacy_page_id' );
		$privacy_url = $privacy_id ? get_permalink( $privacy_id ) : '';
		?>
		<div class="dbr54-privacy-note">
			<p>
				<?php esc_html_e( 'I dati inseriti sono trattati per gestire la tua pratica di garanzia (art. 6.1.b GDPR — esecuzione del contratto). Conserviamo la ricevuta per i termini di legge. Non raccogliamo dati ulteriori né usiamo tracker.', 'db-recesso-54bis' ); ?>
				<?php if ( $privacy_url ) : ?>
					<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Informativa completa', 'db-recesso-54bis' ); ?></a>.
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private function notice( string $message, string $type = 'info' ): void {
		printf( '<div class="dbr54-notice dbr54-notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}
}
