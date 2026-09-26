<?php
/**
 * Durable-medium receipt generator.
 *
 * Produces a self-contained PDF (no external library) containing the minimal
 * evidential data: order number, declarant, certified received datetime with
 * explicit timezone, and the SHA-256 order hash. Personal data is NOT frozen
 * beyond the minimum: the hash proves integrity; full data stays in the order.
 *
 * The PDF is a hand-built single-page document using core PDF fonts. This keeps
 * the plugin dependency-free per the suite philosophy.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBR54_Receipt {

	private function upload_dir(): array {
		$base = wp_upload_dir();
		$dir  = trailingslashit( $base['basedir'] ) . 'dbr54-receipts';
		$url  = trailingslashit( $base['baseurl'] ) . 'dbr54-receipts';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Protect the directory: no listing, receipts fetched via authorised route only.
			@file_put_contents( $dir . '/index.html', '' );
			@file_put_contents( $dir . '/.htaccess', "Options -Indexes\n" );
		}
		return array(
			'dir' => $dir,
			'url' => $url,
		);
	}

	/**
	 * Remove the PDF of a receipt (retention cleanup). Best-effort: a missing
	 * file is not an error. The ref is reduced to a safe basename so a
	 * tampered DB value can never escape the receipts directory.
	 */
	public static function delete_file( string $ref ): void {
		$ref = sanitize_file_name( basename( $ref ) );
		if ( '' === $ref ) {
			return;
		}
		$base = wp_upload_dir();
		$path = trailingslashit( $base['basedir'] ) . 'dbr54-receipts/' . $ref . '.pdf';
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}

	/**
	 * @return array{ref:string, path:string, url:string}
	 */
	public function generate( WC_Order $order, object $record ): array {
		$lines = $this->build_lines( $order, $record );
		return $this->write( $order, 'recesso', $record->id, $lines );
	}

	/**
	 * Receipt for a Modulo B legal-guarantee claim.
	 *
	 * @return array{ref:string, path:string, url:string}
	 */
	public function generate_garanzia( WC_Order $order, object $claim ): array {
		$lines = $this->build_garanzia_lines( $order, $claim );
		return $this->write( $order, 'garanzia', $claim->id, $lines );
	}

	/**
	 * Build the PDF from lines and persist it. Shared by both modules.
	 *
	 * @return array{ref:string, path:string, url:string}
	 */
	private function write( WC_Order $order, string $prefix, int $record_id, array $lines ): array {
		$paths = $this->upload_dir();

		$ref      = $prefix . '-' . $order->get_id() . '-' . $record_id . '-' . wp_generate_password( 8, false, false );
		$filename = $ref . '.pdf';
		$path     = trailingslashit( $paths['dir'] ) . $filename;
		$url      = trailingslashit( $paths['url'] ) . $filename;

		$pdf_bin = $this->build_pdf( $lines );
		file_put_contents( $path, $pdf_bin );

		return array(
			'ref'  => $ref,
			'path' => $path,
			'url'  => $url,
		);
	}

	private function build_garanzia_lines( WC_Order $order, object $claim ): array {
		$garanzia = DBR54_Garanzia::instance();
		$when     = $garanzia->format_received( $claim );

		$claimant = 'guest' === $claim->claimant_type
			? ( $claim->claimant_email ? $claim->claimant_email : __( 'Ospite', 'db-recesso-54bis' ) )
			: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$lines = array(
			array( 'h', __( 'RICEVUTA PRATICA DI GARANZIA', 'db-recesso-54bis' ) ),
			array( 's', __( 'Garanzia legale di conformita - art. 128-135 Codice del Consumo', 'db-recesso-54bis' ) ),
			array( 'sp', '' ),
			array( 'p', get_bloginfo( 'name' ) ),
			array( 'sp', '' ),
			array( 'l', __( 'Pratica n.', 'db-recesso-54bis' ), (string) $claim->id ),
			array( 'l', __( 'Ordine n.', 'db-recesso-54bis' ), (string) $order->get_order_number() ),
			array( 'l', __( 'Richiedente', 'db-recesso-54bis' ), $claimant ? $claimant : '-' ),
			array( 'l', __( 'Data e ora apertura', 'db-recesso-54bis' ), $when ),
			array( 'l', __( 'Prodotto', 'db-recesso-54bis' ), $claim->product_ref ? $claim->product_ref : __( 'non specificato', 'db-recesso-54bis' ) ),
			array( 'l', __( 'Rimedio preferito', 'db-recesso-54bis' ), DBR54_Garanzia::remedy_label( $claim->preferred_remedy ) ),
			array( 'sp', '' ),
			array( 'p', __( 'Difetto / non conformita segnalata:', 'db-recesso-54bis' ) ),
		);

		// Wrap the free-text defect description across lines.
		foreach ( $this->wrap( $claim->defect_description, 80 ) as $chunk ) {
			$lines[] = array( 's', $chunk );
		}

		$lines[] = array( 'sp', '' );
		$lines[] = array( 'p', __( 'Prova di integrita (SHA-256 dello stato ordine):', 'db-recesso-54bis' ) );
		$lines[] = array( 'mono', substr( $claim->order_hash, 0, 32 ) );
		$lines[] = array( 'mono', substr( $claim->order_hash, 32 ) );
		$lines[] = array( 'sp', '' );
		$lines[] = array( 's', __( 'Il rimedio (riparazione, sostituzione, riduzione del prezzo o risoluzione) e valutato dal venditore secondo la legge.', 'db-recesso-54bis' ) );
		$lines[] = array( 's', __( 'Documento generato automaticamente su supporto durevole. Conservare copia.', 'db-recesso-54bis' ) );

		return $lines;
	}

	/**
	 * Naive word wrap for the PDF (ASCII width approximation).
	 *
	 * @return string[]
	 */
	private function wrap( string $text, int $width ): array {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( '' === $text ) {
			return array();
		}
		return explode( "\n", wordwrap( $text, $width, "\n", true ) );
	}

	private function build_lines( WC_Order $order, object $record ): array {
		$recesso = DBR54_Recesso::instance();
		$when    = $recesso->format_received( $record );

		$declarant = 'guest' === $record->declarant_type
			? ( $record->declarant_email ? $record->declarant_email : __( 'Ospite', 'db-recesso-54bis' ) )
			: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$lines = array(
			array( 'h', __( 'RICEVUTA DI RECESSO', 'db-recesso-54bis' ) ),
			array( 's', __( 'Art. 54-bis Codice del Consumo (D.Lgs. 209/2025)', 'db-recesso-54bis' ) ),
			array( 'sp', '' ),
			array( 'p', get_bloginfo( 'name' ) ),
			array( 'sp', '' ),
			array( 'l', __( 'Ordine n.', 'db-recesso-54bis' ), (string) $order->get_order_number() ),
			array( 'l', __( 'Dichiarante', 'db-recesso-54bis' ), $declarant ? $declarant : '—' ),
			array( 'l', __( 'Data e ora di ricezione', 'db-recesso-54bis' ), $when ),
			array( 'l', __( 'Totale ordine', 'db-recesso-54bis' ), html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES ) ),
		);

		if ( ! empty( $record->reason ) ) {
			$lines[] = array( 'l', __( 'Motivo (facoltativo)', 'db-recesso-54bis' ), $record->reason );
		}

		$lines[] = array( 'sp', '' );
		$lines[] = array( 'p', __( 'Prova di integrita (SHA-256 dello stato ordine):', 'db-recesso-54bis' ) );
		// Split the 64-char hash into two lines for width.
		$lines[] = array( 'mono', substr( $record->order_hash, 0, 32 ) );
		$lines[] = array( 'mono', substr( $record->order_hash, 32 ) );

		if ( ! empty( $record->tsa_token ) ) {
			$lines[] = array( 'sp', '' );
			$lines[] = array( 's', __( 'Marca temporale qualificata (RFC 3161) applicata all\'hash.', 'db-recesso-54bis' ) );
		}

		$lines[] = array( 'sp', '' );
		$lines[] = array( 's', __( 'Documento generato automaticamente su supporto durevole. Conservare copia.', 'db-recesso-54bis' ) );

		return $lines;
	}

	/**
	 * Minimal single-page PDF builder using core Helvetica fonts.
	 * Escapes text for PDF string literals; ASCII-only (latin1) content.
	 */
	private function build_pdf( array $lines ): string {
		$content = "BT\n";
		$y       = 780;
		$left    = 60;

		foreach ( $lines as $line ) {
			$type = $line[0];
			switch ( $type ) {
				case 'h':
					$content .= $this->text_op( $left, $y, $this->esc( $line[1] ), 'F1', 18 );
					$y       -= 28;
					break;
				case 's':
					$content .= $this->text_op( $left, $y, $this->esc( $line[1] ), 'F2', 9 );
					$y       -= 16;
					break;
				case 'p':
					$content .= $this->text_op( $left, $y, $this->esc( $line[1] ), 'F1', 11 );
					$y       -= 18;
					break;
				case 'l':
					$content .= $this->text_op( $left, $y, $this->esc( $line[1] . ':' ), 'F1', 11 );
					$content .= $this->text_op( $left + 170, $y, $this->esc( $line[2] ), 'F2', 11 );
					$y       -= 18;
					break;
				case 'mono':
					$content .= $this->text_op( $left, $y, $this->esc( $line[1] ), 'F3', 11 );
					$y       -= 15;
					break;
				case 'sp':
					$y -= 10;
					break;
			}
		}
		$content .= 'ET';

		$objects   = array();
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
			. '/Resources << /Font << /F1 5 0 R /F2 6 0 R /F3 7 0 R >> >> '
			. '/Contents 4 0 R >>';
		$objects[] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $obj ) {
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= ( $i + 1 ) . " 0 obj\n" . $obj . "\nendobj\n";
		}

		$xref_pos = strlen( $pdf );
		$count    = count( $objects ) + 1;
		$pdf     .= "xref\n0 {$count}\n";
		$pdf     .= "0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}
		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
		$pdf .= "startxref\n{$xref_pos}\n%%EOF";

		return $pdf;
	}

	private function text_op( int $x, int $y, string $text, string $font, int $size ): string {
		return "/{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$text}) Tj\n";
	}

	/**
	 * Escape a string for a PDF literal and reduce to latin1 (core fonts).
	 */
	private function esc( string $text ): string {
		$text = remove_accents( $text );
		$text = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
		$text = (string) $text;
		$text = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
		return $text;
	}
}
