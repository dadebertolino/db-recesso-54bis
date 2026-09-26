<?php
/**
 * Frontend: My Account endpoint, order button, two-step form, guest access.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Frontend {

	private static ?DBR54_Frontend $instance = null;
	const ENDPOINT = 'recesso';

	public static function instance(): DBR54_Frontend {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );

		// Button next to each eligible order in My Account > Orders.
		add_action( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_action_button' ), 10, 2 );

		// Endpoint content.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );

		// Guest access shortcode: [dbr54_recesso_guest].
		add_shortcode( 'dbr54_recesso_guest', array( $this, 'guest_shortcode' ) );

		// Keep page caches away from the page carrying the guest form.
		add_action(
			'template_redirect',
			static function () {
				DBR54_Guest_Guard::no_cache_if_shortcode( 'dbr54_recesso_guest' );
			},
			1
		);

		// Handle form submissions early.
		add_action( 'template_redirect', array( $this, 'handle_submission' ) );

		// Show post-redirect outcome above account/guest content.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'maybe_render_result' ), 5 );

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Render the outcome of a completed submission (PRG pattern).
	 * Returns true if an outcome was rendered.
	 */
	public function maybe_render_result(): bool {
		if ( empty( $_GET['dbr54_done'] ) ) {
			return false;
		}
		$token  = sanitize_text_field( wp_unslash( $_GET['dbr54_done'] ) );
		$result = get_transient( 'dbr54_result_' . $token );
		if ( false === $result ) {
			return false;
		}
		delete_transient( 'dbr54_result_' . $token );

		if ( ! empty( $result['success'] ) ) {
			echo '<div class="dbr54-notice dbr54-notice-success"><p>';
			echo esc_html__( 'Recesso registrato correttamente. Ti abbiamo inviato la ricevuta via email.', 'db-recesso-54bis' );
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

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function assets(): void {
		$post = get_post();
		$has_shortcode = $post instanceof WP_Post && has_shortcode( $post->post_content, 'dbr54_recesso_guest' );

		if ( ! function_exists( 'is_account_page' ) || ( ! is_account_page() && ! $has_shortcode ) ) {
			return;
		}
		wp_enqueue_style( 'dbr54-frontend', DBR54_URL . 'assets/css/frontend.css', array(), DBR54_VERSION );
	}

	private function recesso(): DBR54_Recesso {
		return DBR54_Recesso::instance();
	}

	private function settings(): DBR54_Settings {
		return DBR54_Settings::instance();
	}

	/**
	 * Add a "Recedere dal contratto qui" action to eligible orders.
	 */
	public function order_action_button( array $actions, WC_Order $order ): array {
		if ( ! $this->recesso()->is_order_eligible( $order ) ) {
			return $actions;
		}

		$url = wc_get_account_endpoint_url( self::ENDPOINT );
		$url = add_query_arg( 'ordine', $order->get_id(), $url );

		$actions['dbr54_recesso'] = array(
			'url'  => wp_nonce_url( $url, 'dbr54_open_' . $order->get_id() ),
			'name' => $this->settings()->get( 'button_label' ),
		);
		return $actions;
	}

	/**
	 * Render the /my-account/recesso/ endpoint (logged-in users).
	 */
	public function render_endpoint(): void {
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

		$this->render_flow( $order, 'user', $order->get_billing_email() );
	}

	private function render_order_picker(): void {
		$customer_orders = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$eligible = array_filter(
			$customer_orders,
			fn( $o ) => $this->recesso()->is_order_eligible( $o )
		);

		echo '<h2>' . esc_html__( 'Recesso dal contratto', 'db-recesso-54bis' ) . '</h2>';
		if ( empty( $eligible ) ) {
			$this->notice( __( 'Non risultano ordini per cui è attualmente possibile esercitare il recesso.', 'db-recesso-54bis' ), 'info' );
			return;
		}

		echo '<p>' . esc_html__( 'Seleziona l\'ordine per cui vuoi esercitare il diritto di recesso:', 'db-recesso-54bis' ) . '</p>';
		echo '<ul class="dbr54-order-list">';
		foreach ( $eligible as $o ) {
			$url = add_query_arg( 'ordine', $o->get_id(), wc_get_account_endpoint_url( self::ENDPOINT ) );
			$url = wp_nonce_url( $url, 'dbr54_open_' . $o->get_id() );
			printf(
				'<li><a href="%s">%s — %s (%s)</a></li>',
				esc_url( $url ),
				esc_html(
					sprintf(
					/* translators: %s: numero ordine */
						__( 'Ordine %s', 'db-recesso-54bis' ),
						$o->get_order_number()
					)
				),
				esc_html( wp_strip_all_tags( $o->get_date_created()->date_i18n( wc_date_format() ) ) ),
				esc_html(
					sprintf(
					/* translators: %d: giorni residui */
						_n( '%d giorno residuo', '%d giorni residui', $this->recesso()->days_remaining( $o ), 'db-recesso-54bis' ),
						$this->recesso()->days_remaining( $o )
					)
				)
			);
		}
		echo '</ul>';
	}

	/**
	 * Guest access via shortcode: order number + email lookup.
	 *
	 * Anonymous visitors are verified by DBR54_Guest_Guard (same-site origin +
	 * per-IP rate limit, no nonce in cached HTML); logged-in users by nonce.
	 * Handles both the lookup POST and the step 1 → step 2 POST of the guest
	 * flow (the latter carries order id + email instead of the lookup fields).
	 */
	public function guest_shortcode(): string {
		DBR54_Guest_Guard::no_cache();
		ob_start();

		if ( $this->maybe_render_result() ) {
			return ob_get_clean();
		}

		$matched = false;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificati in DBR54_Guest_Guard::verify() (nonce per i loggati, origin + rate limit per gli anonimi).
		$is_lookup = isset( $_POST['dbr54_guest_lookup'] );
		$is_step   = ! $is_lookup
			&& isset( $_POST['dbr54_step'], $_POST['dbr54_order_id'], $_POST['dbr54_declarant_type'] )
			&& 'guest' === sanitize_key( $_POST['dbr54_declarant_type'] );

		if ( $is_lookup || $is_step ) {
			$order_id = $is_step ? absint( $_POST['dbr54_order_id'] ) : 0;
			$error    = $is_lookup
				? DBR54_Guest_Guard::verify( 'dbr54_guest_lookup', 'dbr54_guest_nonce' )
				: DBR54_Guest_Guard::verify( 'dbr54_declare_' . $order_id, 'dbr54_nonce' );

			if ( '' !== $error ) {
				$this->notice( $error, 'error' );
			} else {
				if ( $is_lookup ) {
					$number = isset( $_POST['dbr54_order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['dbr54_order_number'] ) ) : '';
				} else {
					$number = (string) $order_id;
				}
				$email = isset( $_POST['dbr54_email'] ) ? sanitize_email( wp_unslash( $_POST['dbr54_email'] ) ) : '';
				$order = $this->lookup_guest_order( $number, $email );

				if ( $order ) {
					$matched = true;
					$this->render_flow( $order, 'guest', $email );
				} else {
					$this->notice( __( 'Nessun ordine corrisponde ai dati inseriti.', 'db-recesso-54bis' ), 'error' );
				}
			}
		}
		// phpcs:enable

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

		if ( ! $order ) {
			return null;
		}
		if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
			return null;
		}
		return $order;
	}

	private function render_guest_lookup_form(): void {
		?>
		<form method="post" class="dbr54-form dbr54-guest-lookup">
			<h2><?php esc_html_e( 'Recesso dal contratto', 'db-recesso-54bis' ); ?></h2>
			<p><?php esc_html_e( 'Inserisci il numero ordine e l\'email usata per l\'acquisto.', 'db-recesso-54bis' ); ?></p>
			<?php DBR54_Guest_Guard::nonce_field( 'dbr54_guest_lookup', 'dbr54_guest_nonce' ); ?>
			<p>
				<label for="dbr54_order_number"><?php esc_html_e( 'Numero ordine', 'db-recesso-54bis' ); ?></label>
				<input type="text" id="dbr54_order_number" name="dbr54_order_number" required>
			</p>
			<p>
				<label for="dbr54_email"><?php esc_html_e( 'Email', 'db-recesso-54bis' ); ?></label>
				<input type="email" id="dbr54_email" name="dbr54_email" required>
			</p>
			<button type="submit" name="dbr54_guest_lookup" value="1" class="button dbr54-button">
				<?php esc_html_e( 'Continua', 'db-recesso-54bis' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Render step 1 (declaration) or step 2 (summary) depending on state.
	 */
	private function render_flow( WC_Order $order, string $declarant_type, ?string $email ): void {
		if ( DBR54_DB::has_for_order( $order->get_id() ) ) {
			$this->notice( __( 'Per questo ordine è già stato registrato un recesso. Controlla la tua email per la ricevuta.', 'db-recesso-54bis' ), 'info' );
			return;
		}

		if ( ! $this->recesso()->is_order_eligible( $order ) ) {
			$this->notice( __( 'Per questo ordine non è (più) possibile esercitare il recesso.', 'db-recesso-54bis' ), 'error' );
			return;
		}

		$step   = isset( $_REQUEST['dbr54_step'] ) ? absint( $_REQUEST['dbr54_step'] ) : 1;
		$reason = isset( $_REQUEST['dbr54_reason'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['dbr54_reason'] ) ) : '';

		if ( 2 === $step ) {
			$this->render_step2( $order, $declarant_type, $email, $reason );
		} else {
			$this->render_step1( $order, $declarant_type, $email, $reason );
		}
	}

	private function render_step1( WC_Order $order, string $declarant_type, ?string $email, string $reason ): void {
		$days = $this->recesso()->days_remaining( $order );
		?>
		<div class="dbr54-flow">
			<h2><?php esc_html_e( 'Dichiarazione di recesso', 'db-recesso-54bis' ); ?> — 
									<?php
									/* translators: %s: numero ordine */
									echo esc_html( sprintf( __( 'Ordine %s', 'db-recesso-54bis' ), $order->get_order_number() ) );
									?>
			</h2>
			<p class="dbr54-window">
			<?php
				/* translators: %d: giorni */
				echo esc_html( sprintf( _n( 'Hai %d giorno per esercitare il recesso.', 'Hai %d giorni per esercitare il recesso.', $days, 'db-recesso-54bis' ), $days ) );
			?>
			</p>

			<form method="post" class="dbr54-form">
				<?php DBR54_Guest_Guard::nonce_field( 'dbr54_declare_' . $order->get_id(), 'dbr54_nonce' ); ?>
				<input type="hidden" name="dbr54_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
				<input type="hidden" name="dbr54_declarant_type" value="<?php echo esc_attr( $declarant_type ); ?>">
				<?php if ( 'guest' === $declarant_type ) : ?>
					<input type="hidden" name="dbr54_email" value="<?php echo esc_attr( $email ); ?>">
				<?php endif; ?>
				<input type="hidden" name="dbr54_step" value="2">

				<p>
					<label for="dbr54_reason">
						<?php esc_html_e( 'Motivo del recesso', 'db-recesso-54bis' ); ?>
						<span class="dbr54-optional">(<?php esc_html_e( 'facoltativo — non sei obbligato a indicarlo', 'db-recesso-54bis' ); ?>)</span>
					</label>
					<textarea id="dbr54_reason" name="dbr54_reason" rows="3"><?php echo esc_textarea( $reason ); ?></textarea>
				</p>

				<?php $this->micro_privacy_notice(); ?>

				<button type="submit" class="button dbr54-button"><?php esc_html_e( 'Continua al riepilogo', 'db-recesso-54bis' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_step2( WC_Order $order, string $declarant_type, ?string $email, string $reason ): void {
		$exceptions = $this->recesso()->get_art59_exceptions( $order );
		?>
		<div class="dbr54-flow">
			<h2><?php esc_html_e( 'Riepilogo e conferma', 'db-recesso-54bis' ); ?></h2>

			<table class="dbr54-summary">
				<tr><th><?php esc_html_e( 'Ordine', 'db-recesso-54bis' ); ?></th><td><?php echo esc_html( $order->get_order_number() ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Totale', 'db-recesso-54bis' ); ?></th><td><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
				<?php if ( '' !== $reason ) : ?>
					<tr><th><?php esc_html_e( 'Motivo', 'db-recesso-54bis' ); ?></th><td><?php echo esc_html( $reason ); ?></td></tr>
				<?php endif; ?>
			</table>

			<?php if ( ! empty( $exceptions ) ) : ?>
				<div class="dbr54-notice dbr54-notice-warning">
					<p><strong><?php esc_html_e( 'Attenzione — alcuni prodotti potrebbero essere esclusi dal recesso (art. 59):', 'db-recesso-54bis' ); ?></strong></p>
					<ul>
						<?php foreach ( $exceptions as $ex ) : ?>
							<li><?php echo esc_html( $ex['name'] ); ?> — <?php echo esc_html( $ex['reason'] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p><?php esc_html_e( 'Puoi comunque procedere: il venditore valuterà le esclusioni secondo legge.', 'db-recesso-54bis' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" class="dbr54-form">
				<?php DBR54_Guest_Guard::nonce_field( 'dbr54_confirm_' . $order->get_id(), 'dbr54_nonce' ); ?>
				<input type="hidden" name="dbr54_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
				<input type="hidden" name="dbr54_declarant_type" value="<?php echo esc_attr( $declarant_type ); ?>">
				<?php if ( 'guest' === $declarant_type ) : ?>
					<input type="hidden" name="dbr54_email" value="<?php echo esc_attr( $email ); ?>">
				<?php endif; ?>
				<input type="hidden" name="dbr54_reason" value="<?php echo esc_attr( $reason ); ?>">
				<input type="hidden" name="dbr54_confirm" value="1">

				<div class="dbr54-actions">
					<button type="submit" class="button dbr54-button dbr54-button-primary"><?php esc_html_e( 'Confermo il recesso', 'db-recesso-54bis' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the final confirmation POST.
	 */
	public function handle_submission(): void {
		if ( empty( $_POST['dbr54_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- solo rilevamento del submit; verifica in DBR54_Guest_Guard::verify().
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificati in DBR54_Guest_Guard::verify() (nonce per i loggati, origin + rate limit per gli anonimi).
		$order_id = isset( $_POST['dbr54_order_id'] ) ? absint( $_POST['dbr54_order_id'] ) : 0;
		if ( ! $order_id ) {
			return;
		}

		// Errors are shown to the user via the PRG outcome, never a bare wp_die().
		$error = DBR54_Guest_Guard::verify( 'dbr54_confirm_' . $order_id, 'dbr54_nonce' );
		if ( '' !== $error ) {
			$this->redirect_with_result(
				array(
					'success' => false,
					'error'   => $error,
				)
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect_with_result(
				array(
					'success' => false,
					'error'   => __( 'Ordine non trovato.', 'db-recesso-54bis' ),
				)
			);
		}

		$declarant_type = isset( $_POST['dbr54_declarant_type'] ) ? sanitize_key( $_POST['dbr54_declarant_type'] ) : 'user';
		$declarant_type = in_array( $declarant_type, array( 'user', 'guest' ), true ) ? $declarant_type : 'user';
		$email          = isset( $_POST['dbr54_email'] ) ? sanitize_email( wp_unslash( $_POST['dbr54_email'] ) ) : null;
		$reason         = isset( $_POST['dbr54_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dbr54_reason'] ) ) : '';

		// phpcs:enable

		// Authorisation re-check.
		$unauthorised = array(
			'success' => false,
			'error'   => __( 'Autorizzazione non valida.', 'db-recesso-54bis' ),
		);
		if ( 'user' === $declarant_type ) {
			if ( ! is_user_logged_in() || $order->get_customer_id() !== get_current_user_id() ) {
				$this->redirect_with_result( $unauthorised );
			}
			$email = $order->get_billing_email();
		} elseif ( ! $email || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
			$this->redirect_with_result( $unauthorised );
		}

		$this->redirect_with_result( $this->recesso()->process( $order, $declarant_type, $email, $reason ) );
	}

	/**
	 * PRG: store the outcome in a short-lived transient and redirect.
	 *
	 * @return never
	 */
	private function redirect_with_result( array $result ): void {
		$token = wp_generate_password( 20, false, false );
		set_transient( 'dbr54_result_' . $token, $result, 5 * MINUTE_IN_SECONDS );

		$redirect = remove_query_arg( array( 'dbr54_step', 'ordine' ) );
		$redirect = add_query_arg( 'dbr54_done', $token, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	private function micro_privacy_notice(): void {
		$privacy_id  = (int) $this->settings()->get( 'privacy_page_id' );
		$privacy_url = $privacy_id ? get_permalink( $privacy_id ) : '';
		?>
		<div class="dbr54-privacy-note">
			<p>
				<?php esc_html_e( 'I dati inseriti sono trattati esclusivamente per gestire il tuo recesso (art. 6.1.b e 6.1.c GDPR — esecuzione del contratto e obbligo legale ex art. 54-bis). Conserviamo la ricevuta per i termini di legge. Non raccogliamo dati ulteriori né usiamo tracker.', 'db-recesso-54bis' ); ?>
				<?php if ( $privacy_url ) : ?>
					<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Informativa completa', 'db-recesso-54bis' ); ?></a>.
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private function notice( string $message, string $type = 'info' ): void {
		printf(
			'<div class="dbr54-notice dbr54-notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
