<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schlanker PDF-Writer (PDF 1.4, Helvetica-Kernfonts, WinAnsi) ohne Fremdbibliothek,
 * plus Konverter für die HTML-Teilmenge der Schreiben (p, h1–h3, strong, br, table, ul/li, small, .unterschrift, .umbruch).
 */
class MV_PDF {

	private array $pages = [];
	private string $buf  = '';
	private float $w     = 595.28;  // A4 in pt
	private float $h     = 841.89;
	private float $ml    = 70.9;   // 25 mm
	private float $mr    = 56.7;   // 20 mm
	private float $mt    = 56.7;
	private float $mb    = 56.7;
	private float $y     = 0;
	private float $fs    = 10.5;
	private bool $bold   = false;
	private string $kopf = '';
	private string $fuss = '';

	private static array $widths     = [];
	private static array $widthsBold = [];

	public function __construct() {
		$this->initWidths();
		$this->neueSeite();
	}

	/* ------------------------------------------------------------------ */
	/* Fontmetrik Helvetica (AFM, 1/1000 em)                              */
	/* ------------------------------------------------------------------ */

	private function initWidths(): void {
		if ( self::$widths ) {
			return;
		}
		$w   = array_fill( 0, 256, 556 );
		$map = [
			32  => 278,
			33  => 278,
			34  => 355,
			35  => 556,
			36  => 556,
			37  => 889,
			38  => 667,
			39  => 191,
			40  => 333,
			41  => 333,
			42  => 389,
			43  => 584,
			44  => 278,
			45  => 333,
			46  => 278,
			47  => 278,
			58  => 278,
			59  => 278,
			60  => 584,
			61  => 584,
			62  => 584,
			63  => 556,
			64  => 1015,
			65  => 667,
			66  => 667,
			67  => 722,
			68  => 722,
			69  => 667,
			70  => 611,
			71  => 778,
			72  => 722,
			73  => 278,
			74  => 500,
			75  => 667,
			76  => 556,
			77  => 833,
			78  => 722,
			79  => 778,
			80  => 667,
			81  => 778,
			82  => 722,
			83  => 667,
			84  => 611,
			85  => 722,
			86  => 667,
			87  => 944,
			88  => 667,
			89  => 667,
			90  => 611,
			91  => 278,
			92  => 278,
			93  => 278,
			94  => 469,
			95  => 556,
			96  => 333,
			97  => 556,
			98  => 556,
			99  => 500,
			100 => 556,
			101 => 556,
			102 => 278,
			103 => 556,
			104 => 556,
			105 => 222,
			106 => 222,
			107 => 500,
			108 => 222,
			109 => 833,
			110 => 556,
			111 => 556,
			112 => 556,
			113 => 556,
			114 => 333,
			115 => 500,
			116 => 278,
			117 => 556,
			118 => 500,
			119 => 722,
			120 => 500,
			121 => 500,
			122 => 500,
			123 => 334,
			124 => 260,
			125 => 334,
			126 => 584,
			128 => 556,
			130 => 222,
			132 => 333,
			133 => 1000,
			145 => 222,
			146 => 222,
			147 => 333,
			148 => 333,
			149 => 350,
			150 => 556,
			151 => 1000,
			167 => 556,
			171 => 556,
			176 => 400,
			178 => 333,
			179 => 333,
			187 => 556,
			196 => 667,
			214 => 778,
			220 => 722,
			223 => 611,
			228 => 556,
			246 => 556,
			252 => 556,
			233 => 556,
			224 => 556,
			232 => 556,
		];
		foreach ( $map as $c => $v ) {
			$w[ $c ] = $v;
		}
		self::$widths = $w;
		// Helvetica-Bold (AFM) – abweichende Zeichen
		$b    = $w;
		$bmap = [
			32  => 278,
			33  => 333,
			34  => 474,
			38  => 722,
			39  => 238,
			44  => 278,
			45  => 333,
			46  => 278,
			58  => 333,
			59  => 333,
			63  => 611,
			64  => 975,
			65  => 722,
			66  => 722,
			67  => 722,
			68  => 722,
			69  => 667,
			70  => 611,
			71  => 778,
			72  => 722,
			73  => 278,
			74  => 556,
			75  => 722,
			76  => 611,
			77  => 833,
			78  => 722,
			79  => 778,
			80  => 667,
			81  => 778,
			82  => 722,
			83  => 667,
			84  => 611,
			85  => 722,
			86  => 667,
			87  => 944,
			88  => 667,
			89  => 667,
			90  => 611,
			97  => 556,
			98  => 611,
			99  => 556,
			100 => 611,
			101 => 556,
			102 => 333,
			103 => 611,
			104 => 611,
			105 => 278,
			106 => 278,
			107 => 556,
			108 => 278,
			109 => 889,
			110 => 611,
			111 => 611,
			112 => 611,
			113 => 611,
			114 => 389,
			115 => 556,
			116 => 333,
			117 => 611,
			118 => 556,
			119 => 778,
			120 => 556,
			121 => 556,
			122 => 500,
			196 => 722,
			214 => 778,
			220 => 722,
			223 => 611,
			228 => 556,
			246 => 611,
			252 => 611,
			128 => 556,
			167 => 556,
			150 => 556,
		];
		foreach ( $bmap as $c => $v ) {
			$b[ $c ] = $v;
		}
		self::$widthsBold = $b;
	}

	private function enc( string $s ): string {
		$s   = str_replace( [ '☐', '✓', '→', '←', '·', '⚠', '…' ], [ '[ ]', 'x', '->', '<-', '-', '!', '...' ], $s );
		$out = @iconv( 'UTF-8', 'CP1252', $s );
		if ( $out === false ) {
			$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT', $s );
		}
		return $out === false ? preg_replace( '/[^\x20-\x7E]/', '?', $s ) : $out;
	}

	private function breite( string $cp1252, ?float $fs = null, ?bool $bold = null ): float {
		$fs   = $fs ?? $this->fs;
		$bold = $bold ?? $this->bold;
		$sum  = 0;
		$tab  = $bold ? self::$widthsBold : self::$widths;
		for ( $i = 0, $n = strlen( $cp1252 ); $i < $n; $i++ ) {
			$sum += $tab[ ord( $cp1252[ $i ] ) ];
		}
		return $sum / 1000 * $fs;
	}

	/* ------------------------------------------------------------------ */
	/* Seiten und Primitive                                               */
	/* ------------------------------------------------------------------ */

	public function kopfzeile( string $kopf, string $fuss = '' ): void {
		$this->kopf = $kopf;
		$this->fuss = $fuss;
	}

	private function neueSeite(): void {
		if ( $this->buf !== '' ) {
			$this->pages[] = $this->buf;
		}
		$this->buf = '';
		$this->y   = $this->h - $this->mt;
		if ( $this->kopf !== '' && count( $this->pages ) >= 1 ) {
			$this->text( $this->ml, $this->h - 30, $this->kopf, 8, false, 0.4 );
		}
	}

	private function pruefeUmbruch( float $benoetigt ): void {
		if ( $this->y - $benoetigt < $this->mb ) {
			$this->neueSeite();
		}
	}

	private function text( float $x, float $y, string $utf8, ?float $fs = null, ?bool $bold = null, float $grau = 0 ): void {
		$fs         = $fs ?? $this->fs;
		$bold       = $bold ?? $this->bold;
		$t          = $this->enc( $utf8 );
		$t          = str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $t );
		$this->buf .= sprintf( "BT %s g /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $this->num( $grau ), $bold ? 2 : 1, $fs, $x, $y, $t );
	}

	private function linie( float $x1, float $y1, float $x2, float $y2, float $staerke = 0.5, float $grau = 0 ): void {
		$this->buf .= sprintf( "%s G %.2F w %.2F %.2F m %.2F %.2F l S\n", $this->num( $grau ), $staerke, $x1, $y1, $x2, $y2 );
	}

	private function rechteck( float $x, float $y, float $w, float $h, float $grau ): void {
		$this->buf .= sprintf( "%s g %.2F %.2F %.2F %.2F re f 0 g\n", $this->num( $grau ), $x, $y, $w, $h );
	}

	private function num( float $v ): string {
		return rtrim( rtrim( sprintf( '%.3F', $v ), '0' ), '.' ) ?: '0';
	}

	/** Zeilen umbrechen; Eingabe: Segmente [ [text, bold], ... ] */
	private function umbrechen( array $segs, float $breite, float $fs ): array {
		$zeilen = [];
		$zeile  = [];
		$zb     = 0.0;
		foreach ( $segs as [ $txt, $bold ] ) {
			$woerter = preg_split( '/(\s+)/u', $txt, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			foreach ( $woerter as $w ) {
				if ( $w === "\n" ) {
					$zeilen[] = $zeile;
					$zeile    = [];
					$zb       = 0;
					continue;
				}
				if ( trim( $w ) === '' && $zb == 0 ) {
					continue;
				}
				$wb = $this->breite( $this->enc( $w ), $fs, $bold );
				if ( $zb + $wb > $breite && $zb > 0 ) {
					$zeilen[] = $zeile;
					$zeile    = [];
					$zb       = 0;
					if ( trim( $w ) === '' ) {
						continue;
					}
				}
				$zeile[] = [ $w, $bold ];
				$zb     += $wb;
			}
		}
		if ( $zeile ) {
			$zeilen[] = $zeile;
		}
		return $zeilen ?: [ [] ];
	}

	private function zeileAusgeben( array $zeile, float $x, float $y, float $fs, string $align = 'left', float $breite = 0, float $grau = 0 ): void {
		$gesamt = 0;
		foreach ( $zeile as [ $t, $b ] ) {
			$gesamt += $this->breite( $this->enc( $t ), $fs, $b );
		}
		if ( $align === 'right' ) {
			$x += $breite - $gesamt;
		} elseif ( $align === 'center' ) {
			$x += ( $breite - $gesamt ) / 2;
		}
		foreach ( $zeile as [ $t, $b ] ) {
			$this->text( $x, $y, $t, $fs, $b, $grau );
			$x += $this->breite( $this->enc( $t ), $fs, $b );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Blöcke                                                             */
	/* ------------------------------------------------------------------ */

	public function absatz( array $segs, float $fs = 10.5, float $abstand = 6, string $align = 'left', float $grau = 0, float $einzug = 0 ): void {
		$breite = $this->w - $this->ml - $this->mr - $einzug;
		$zh     = $fs * 1.4;
		$zeilen = $this->umbrechen( $segs, $breite, $fs );
		foreach ( $zeilen as $i => $z ) {
			$this->pruefeUmbruch( $zh );
			$this->y -= $zh;
			$this->zeileAusgeben( $z, $this->ml + $einzug, $this->y, $fs, $align, $breite, $grau );
		}
		$this->y -= $abstand;
	}

	public function ueberschrift( string $t, int $ebene ): void {
		$fs = [
			1 => 15,
			2 => 11.5,
			3 => 10.5,
		][ $ebene ] ?? 10.5;
		$this->pruefeUmbruch( $fs * 3 );
		$this->y -= $ebene === 1 ? 4 : 8;
		$this->absatz( [ [ $t, true ] ], $fs, 2, $ebene === 1 ? 'center' : 'left' );
		if ( $ebene === 2 ) {
			$this->linie( $this->ml, $this->y - 1, $this->w - $this->mr, $this->y - 1, 0.6 );
		}
		$this->y -= 5;
	}

	public function abstand( float $pt ): void {
		$this->y -= $pt;
	}

	public function seitenumbruch(): void {
		$this->neueSeite();
	}

	/**
	 * Tabelle: $zeilen = [ ['cells' => [ [segs], ... ], 'kopf' => bool, 'summe' => bool ], ... ]
	 * Spaltenbreiten werden aus dem Inhalt geschätzt; erste Spalte linksbündig, Rest rechtsbündig (Zahlenlayout) oder alle links.
	 */
	public function tabelle( array $zeilen, float $fs = 9.5, bool $zahlen = true ): void {
		if ( ! $zeilen ) {
			return;
		}
		$n      = max( array_map( fn( $z ) => count( $z['cells'] ), $zeilen ) );
		$gesamt = $this->w - $this->ml - $this->mr;
		// Breitenschätzung
		$maxw = array_fill( 0, $n, 0 );
		$minw = array_fill( 0, $n, 0 ); // längstes einzelnes Wort (darf nicht umbrechen)
		foreach ( $zeilen as $z ) {
			$cells = $z['cells'];
			foreach ( $cells as $i => $segs ) {
				// colspan-Zelle (gefolgt von leeren Zellen) nicht für Breite werten
				if ( isset( $cells[ $i + 1 ] ) && $cells[ $i + 1 ] === [] && $i + 1 < $n ) {
					continue;
				}
				$txt  = implode( '', array_map( fn( $s ) => $s[0], $segs ) );
				$fett = ! empty( $z['kopf'] ) || ! empty( $z['summe'] );
				foreach ( explode( "\n", $txt ) as $part ) {
					$maxw[ $i ] = max( $maxw[ $i ], $this->breite( $this->enc( $part ), $fs, $fett ) + 10 );
					foreach ( preg_split( '/\s+/u', $part ) as $wort ) {
						$minw[ $i ] = max( $minw[ $i ], $this->breite( $this->enc( $wort ), $fs, $fett ) + 10 );
					}
				}
			}
		}
		// Zahlenspalten (rechts) bekommen ihre volle Breite, die erste Spalte den Rest
		$cols = $maxw;
		if ( $zahlen && $n > 1 ) {
			$rest = $gesamt - array_sum( array_slice( $cols, 1 ) );
			if ( $rest >= 90 ) {
				$cols[0] = $rest;
			} else {
				$faktor = ( $gesamt - 90 ) / max( 1, array_sum( array_slice( $cols, 1 ) ) );
				for ( $i = 1; $i < $n; $i++ ) {
					$cols[ $i ] = max( $minw[ $i ], $cols[ $i ] * $faktor );
				}
				$cols[0] = max( 60, $gesamt - array_sum( array_slice( $cols, 1 ) ) );
			}
		} else {
			// Formulartabellen: jede Spalte mindestens 1/(2n) der Breite, Rest nach Inhalt
			$min   = $gesamt / ( 2 * $n );
			$cols  = array_map( fn( $m ) => max( $m, $min ), $cols );
			$summe = array_sum( $cols ) ?: 1;
			$cols  = array_map( fn( $m ) => $m / $summe * $gesamt, $cols );
		}
		$faktor = $gesamt / array_sum( $cols );
		$cols   = array_map( fn( $c ) => $c * $faktor, $cols );

		$zh = $fs * 1.35;
		foreach ( $zeilen as $z ) {
			$cells   = array_pad( $z['cells'], $n, [] );
			$kopf    = ! empty( $z['kopf'] );
			$summe_z = ! empty( $z['summe'] );
			// Zellen umbrechen
			$umbr      = [];
			$maxzeilen = 1;
			foreach ( $cells as $i => $segs ) {
				$segs = $segs ?: [ [ '', false ] ];
				if ( $kopf || $summe_z ) {
					$segs = array_map( fn( $s ) => [ $s[0], true ], $segs );
				}
				$umbr[ $i ] = $this->umbrechen( $segs, $cols[ $i ] - 8, $fs );
				$maxzeilen  = max( $maxzeilen, count( $umbr[ $i ] ) );
			}
			$hoehe = $maxzeilen * $zh + 4;
			$this->pruefeUmbruch( $hoehe );
			if ( $kopf ) {
				$this->rechteck( $this->ml, $this->y - $hoehe, $gesamt, $hoehe, 0.92 );
			}
			if ( $summe_z ) {
				$this->linie( $this->ml, $this->y, $this->w - $this->mr, $this->y, 1.0 );
			}
			$x = $this->ml;
			foreach ( $umbr as $i => $zl ) {
				$align = ( $zahlen && $i > 0 ) ? 'right' : 'left';
				$yy    = $this->y - 2;
				foreach ( $zl as $zeile ) {
					$yy -= $zh;
					$this->zeileAusgeben( $zeile, $x + 4, $yy + $zh * 0.28, $fs, $align, $cols[ $i ] - 8 );
				}
				$x += $cols[ $i ];
			}
			$this->y -= $hoehe;
			if ( ! $summe_z ) {
				$this->linie( $this->ml, $this->y, $this->w - $this->mr, $this->y, 0.3, 0.75 );
			}
		}
		$this->y -= 8;
	}

	public function unterschriften( array $felder ): void {
		$this->pruefeUmbruch( 70 );
		$this->y -= 40;
		$gesamt   = $this->w - $this->ml - $this->mr;
		$n        = count( $felder );
		$bw       = ( $gesamt - ( $n - 1 ) * 30 ) / $n;
		$x        = $this->ml;
		foreach ( $felder as $f ) {
			$this->linie( $x, $this->y, $x + $bw, $this->y, 0.5 );
			$this->text( $x, $this->y - 11, $f, 8.5, false, 0.2 );
			$x += $bw + 30;
		}
		$this->y -= 24;
	}

	/* ------------------------------------------------------------------ */
	/* Ausgabe                                                            */
	/* ------------------------------------------------------------------ */

	public function ausgeben(): string {
		if ( $this->buf !== '' ) {
			$this->pages[] = $this->buf;
			$this->buf     = '';
		}
		$n = count( $this->pages );
		// Fußzeile mit Seitenzahl nachträglich
		foreach ( $this->pages as $i => &$p ) {
			$f  = $this->enc( ( $this->fuss !== '' ? $this->fuss . '  -  ' : '' ) . 'Seite ' . ( $i + 1 ) . ' von ' . $n );
			$f  = str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $f );
			$p .= sprintf( "BT 0.4 g /F1 8 Tf %.2F %.2F Td (%s) Tj ET\n", $this->w - $this->mr - $this->breite( $f, 8, false ), 30, $f );
		}
		unset( $p );

		$objs   = [];
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids   = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 5 + $i * 2 ) . ' 0 R';
		}
		$objs[] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		foreach ( $this->pages as $p ) {
			$objs[] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, count( $objs ) + 2 );
			$objs[] = '<< /Length ' . strlen( $p ) . " >>\nstream\n" . $p . "\nendstream";
		}
		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ( $objs as $i => $o ) {
			$offsets[] = strlen( $out );
			$out      .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= 'xref\n0 ' . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		$out  = str_replace( 'xref\n', "xref\n", $out );
		foreach ( $offsets as $o ) {
			$out .= sprintf( "%010d 00000 n \n", $o );
		}
		$out .= '<< /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R /Info << /Producer (Mietverwaltung) /CreationDate (D:' . date( 'YmdHis' ) . ") >> >>\n";
		$out  = str_replace( '<< /Size', "trailer\n<< /Size", $out );
		$out .= "startxref\n" . $xref . "\n%%EOF\n";
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* HTML-Teilmenge → PDF                                               */
	/* ------------------------------------------------------------------ */

	/** Inline-Segmente aus einem Knoten (strong/b → fett, br → Zeilenumbruch, small → ignoriert Größe). */
	private static function segs( DOMNode $node, bool $bold = false ): array {
		$out = [];
		foreach ( $node->childNodes as $c ) {
			if ( $c->nodeType === XML_TEXT_NODE ) {
				$t = preg_replace( '/\s+/u', ' ', $c->nodeValue );
				if ( $t !== '' && $t !== ' ' ) {
					$out[] = [ $t, $bold ];
				} elseif ( $t === ' ' && $out ) {
					$out[ count( $out ) - 1 ][0] .= ' ';
				}
			} elseif ( $c->nodeType === XML_ELEMENT_NODE ) {
				$n = strtolower( $c->nodeName );
				if ( $n === 'br' ) {
					$out[] = [ "\n", $bold ];
				} elseif ( in_array( $n, [ 'strong', 'b' ], true ) ) {
					$out = array_merge( $out, self::segs( $c, true ) );
				} elseif ( in_array( $n, [ 'span', 'small', 'em', 'i', 'a', 'label' ], true ) ) {
					$cls = $c->getAttribute( 'class' );
					if ( $cls === 'feld' ) {
						$out[] = [ ' ____________ ', $bold ];
					} else {
						$out = array_merge( $out, self::segs( $c, $bold ) );
					}
				} else {
					$out = array_merge( $out, self::segs( $c, $bold ) );
				}
			}
		}
		// führende/abschließende Leerzeichen
		if ( $out ) {
			$out[0][0]                   = ltrim( $out[0][0] );
			$out[ count( $out ) - 1 ][0] = rtrim( $out[ count( $out ) - 1 ][0] );
		}
		return array_values( array_filter( $out, fn( $s ) => $s[0] !== '' ) );
	}

	public function html( string $html ): void {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$root = $doc->getElementsByTagName( 'div' )->item( 0 );
		$this->walk( $root );
	}

	private function walk( DOMNode $node ): void {
		foreach ( $node->childNodes as $c ) {
			if ( $c->nodeType !== XML_ELEMENT_NODE ) {
				if ( $c->nodeType === XML_TEXT_NODE && trim( $c->nodeValue ) !== '' ) {
					$this->absatz( [ [ trim( preg_replace( '/\s+/u', ' ', $c->nodeValue ) ), false ] ] );
				}
				continue;
			}
			$n   = strtolower( $c->nodeName );
			$cls = $c->getAttribute( 'class' );
			if ( $n === 'h1' ) {
				$this->ueberschrift( trim( $c->textContent ), 1 );
			} elseif ( $n === 'h2' ) {
				$this->ueberschrift( trim( $c->textContent ), 2 );
			} elseif ( $n === 'h3' ) {
				$this->ueberschrift( trim( $c->textContent ), 3 );
			} elseif ( $n === 'p' ) {
				$klein = in_array( $cls, [ 'hinweis', 'klein' ], true );
				$segs  = self::segs( $c );
				if ( $segs ) {
					$this->absatz( $segs, $klein ? 8.5 : 10.5, 6, 'left', $klein ? 0.25 : 0 );
				}
			} elseif ( $n === 'table' ) {
				$zeilen = [];
				foreach ( $c->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = [];
					$kopf  = false;
					foreach ( $tr->childNodes as $td ) {
						if ( $td->nodeType !== XML_ELEMENT_NODE ) {
							continue;
						}
						$tn = strtolower( $td->nodeName );
						if ( $tn === 'th' ) {
							$kopf = true;
						}
						if ( $tn === 'th' || $tn === 'td' ) {
							$span    = (int) $td->getAttribute( 'colspan' ) ?: 1;
							$cells[] = self::segs( $td );
							for ( $i = 1; $i < $span; $i++ ) {
								$cells[] = [];
							}
						}
					}
					if ( $cells ) {
						$zeilen[] = [
							'cells' => $cells,
							'kopf'  => $kopf && ! $zeilen,
							'summe' => strpos( $tr->getAttribute( 'class' ), 'summe' ) !== false,
						];
					}
				}
				$this->tabelle( $zeilen, 9.5, strpos( $c->getAttribute( 'class' ), 'liste' ) !== false );
			} elseif ( $n === 'ul' || $n === 'ol' ) {
				$i = 0;
				foreach ( $c->getElementsByTagName( 'li' ) as $li ) {
					++$i;
					$segs = self::segs( $li );
					array_unshift( $segs, [ ( $n === 'ol' ? $i . '. ' : '- ' ), false ] );
					$this->absatz( $segs, 10.5, 2, 'left', 0, 12 );
				}
				$this->y -= 4;
			} elseif ( $n === 'div' && $cls === 'unterschrift' ) {
				$felder = [];
				foreach ( $c->childNodes as $d ) {
					if ( $d->nodeType === XML_ELEMENT_NODE ) {
						$felder[] = trim( preg_replace( '/\s+/u', ' ', str_replace( '<br>', ' ', $d->textContent ) ) );
					}
				}
				$this->unterschriften( $felder ?: [ 'Vermieter', 'Mieter' ] );
			} elseif ( $n === 'div' && $cls === 'umbruch' ) {
				$this->seitenumbruch();
			} elseif ( $n === 'hr' ) {
				$this->y -= 4;
				$this->linie( $this->ml, $this->y, $this->w - $this->mr, $this->y, 0.5 );
				$this->y -= 6;
			} elseif ( in_array( $n, [ 'div', 'section', 'thead', 'tbody' ], true ) ) {
				$this->walk( $c );
			} elseif ( in_array( $n, [ 'script', 'style', 'button' ], true ) ) {
				continue;
			} else {
				$segs = self::segs( $c );
				if ( $segs ) {
					$this->absatz( $segs );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Komfort: Brief → PDF, in Mediathek speichern                       */
	/* ------------------------------------------------------------------ */

	/** Briefkopf (Absender, Empfänger, Datum, Betreff) + Body-HTML → PDF-Bytes */
	public static function brief( string $absender_zeile, string $empfaenger, string $datum_zeile, string $betreff, string $body_html, string $gruss_name ): string {
		$pdf = new self();
		$pdf->kopfzeile( $betreff, $absender_zeile );
		$pdf->y -= 30;
		$pdf->text( $pdf->ml, $pdf->y, $absender_zeile, 7.5, false, 0.3 );
		$pdf->linie( $pdf->ml, $pdf->y - 3, $pdf->ml + 240, $pdf->y - 3, 0.4 );
		$pdf->y -= 18;
		foreach ( explode( "\n", $empfaenger ) as $z ) {
			$pdf->text( $pdf->ml, $pdf->y, $z, 10.5 );
			$pdf->y -= 14;
		}
		$pdf->text( $pdf->w - $pdf->mr - $pdf->breite( $pdf->enc( $datum_zeile ), 10.5, false ), $pdf->h - $pdf->mt - 60, $datum_zeile, 10.5 );
		$pdf->y  = min( $pdf->y, $pdf->h - $pdf->mt - 150 );
		$pdf->y -= 20;
		$pdf->absatz( [ [ $betreff, true ] ], 11, 12 );
		$pdf->html( $body_html );
		$pdf->absatz( [ [ 'Mit freundlichen Grüßen', false ] ], 10.5, 30 );
		$pdf->absatz( [ [ $gruss_name, false ] ] );
		return $pdf->ausgeben();
	}

	/** Dokument ohne Briefkopf (Vertrag, Protokoll) */
	public static function dokument( string $html, string $fuss = '' ): string {
		$pdf = new self();
		$pdf->kopfzeile( '', $fuss );
		$pdf->html( $html );
		return $pdf->ausgeben();
	}

	/** PDF in der Mediathek speichern und als Dokument verknüpfen. Liefert attachment_id. */
	public static function ablegen( string $bytes, string $dateiname, int $objekt_id, ?int $mv_id, string $typ, string $bezeichnung, bool $mieter_sichtbar = false ): int {
		global $wpdb;
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mietverwaltung';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		$dateiname = sanitize_file_name( $dateiname );
		$pfad      = $dir . '/' . wp_unique_filename( $dir, $dateiname );
		file_put_contents( $pfad, $bytes );
		$att = wp_insert_attachment(
			[
				'post_mime_type' => 'application/pdf',
				'post_title'     => $bezeichnung,
				'post_status'    => 'inherit',
			],
			$pfad
		);
		if ( is_wp_error( $att ) || ! $att ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $pfad ) );
		$wpdb->insert(
			mv_table( 'dokumente' ),
			[
				'owner_id'        => MV_OWNER_ID,
				'objekt_id'       => $objekt_id,
				'mv_id'           => $mv_id,
				'typ'             => $typ,
				'bezeichnung'     => $bezeichnung,
				'datum'           => current_time( 'Y-m-d' ),
				'mieter_sichtbar' => $mieter_sichtbar ? 1 : 0,
				'attachment_id'   => (int) $att,
			]
		);
		return (int) $att;
	}

	public static function senden( string $bytes, string $dateiname ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $dateiname ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wird intern erzeugt und ist bereits escaped.
		exit;
	}
}
