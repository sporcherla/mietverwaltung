<?php
/**
 * Kurzhilfe im Backend: eigene Hilfeseite + WordPress-Kontexthilfe (Tab „Hilfe“ oben rechts) auf allen Plugin-Seiten.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mietv_Hilfe {

	public static function init(): void {
		add_action( 'admin_head', [ __CLASS__, 'kontexthilfe' ] );
	}

	/** Schnellstart in 6 Schritten. */
	public static function schnellstart(): array {
		return [
			[ __( 'Einstellungen', 'mietfux' ), __( 'Vermieterdaten, Bankverbindung, Gläubiger-ID, Steuernummer und Absender für Schreiben eintragen.', 'mietfux' ), 'mietv_einstellungen' ],
			[ __( 'Objekt & Einheiten', 'mietfux' ), __( 'Haus/Eigentumswohnung als Objekt anlegen (Baujahr, Heizungsart), darunter die Einheiten (Wohnung, Garage, Stellplatz) mit Fläche und Personen.', 'mietfux' ), 'mietv_objekte' ],
			[ __( 'Mieter', 'mietfux' ), __( 'Mieter erfassen – optional mit Ehe-/Lebenspartner als zweite Vertragspartei. Beide erscheinen automatisch in Vertrag, Schreiben und SEPA-Mandat.', 'mietfux' ), 'mietv_mieter' ],
			[ __( 'Mietverhältnis & Konditionen', 'mietfux' ), __( 'Mietverhältnis = Mieter + Einheit + Beginn. Garage/Stellplatz als Zusatzeinheit anhängen, dann gibt es eine Gesamtmiete. Konditionen (Kaltmiete, Vorauszahlungen) mit Gültig-ab-Datum anlegen.', 'mietfux' ), 'mietv_mietverhaeltnisse' ],
			[ __( 'Anlagegut & Darlehen', 'mietfux' ), __( 'Kaufpreis, Nebenkosten (Notar, Grundbuch, Grunderwerbsteuer, Makler) und Grundanteil erfassen – der Kaufvertrag als PDF kann direkt ausgelesen werden. Darlehen mit Zins, Tilgung und Rate anlegen.', 'mietfux' ), 'mietv_anlagegueter' ],
			[ __( 'Laufender Betrieb', 'mietfux' ), __( 'Mieteingänge in der Übersicht abhaken oder per Bankimport einlesen, Belege fotografieren, Zählerstände erfassen. Am Jahresende: Betriebskostenabrechnung und Anlage V.', 'mietfux' ), 'mietv_uebersicht' ],
		];
	}

	/** Kurzhilfe je Seite (Slug ohne „mietv_“ => [Titel, Absätze]). */
	public static function themen(): array {
		return [
			'uebersicht'        => [
				__( 'Übersicht', 'mietfux' ),
				[
					__( 'Zeigt offene Posten, fällige Aufgaben und Kennzahlen. Offene Mieten lassen sich per Häkchen als bezahlt buchen – die Zahlung wird zum 1. des Monats eingetragen.', 'mietfux' ),
					__( 'Mit „Fokusmodus“ wird das übrige WordPress-Menü ausgeblendet; die Einstellung gilt pro Benutzer.', 'mietfux' ),
				],
			],
			'zahlungen'         => [
				__( 'Zahlungen', 'mietfux' ),
				[
					__( 'Jede Zahlung gehört zu einem Mietverhältnis und einem Monat. Quelle: manuell, Bankimport oder abgehakt. Soll/Ist ergibt sich aus den Konditionen.', 'mietfux' ),
				],
			],
			'belege'            => [
				__( 'Belege', 'mietfux' ),
				[
					__( 'Rechnungen mit Kostenart, Steuerjahr und Datei erfassen (am Handy direkt per Kamera). Die Kostenart entscheidet über Umlagefähigkeit (BetrKV) und die Zeile in Anlage V.', 'mietfux' ),
					__( 'Erhaltungsaufwand kann nach § 82b EStDV auf 2–5 Jahre verteilt werden; Handwerkerlohn (§ 35a) wird gesondert ausgewiesen.', 'mietfux' ),
				],
			],
			'bank'              => [
				__( 'Bankimport', 'mietfux' ),
				[
					__( 'CSV- oder CAMT.053-Export der Bank einlesen. Regeln ordnen Buchungen anhand von IBAN oder Verwendungszweck automatisch einem Mietverhältnis oder einer Kostenart zu; Duplikate werden erkannt.', 'mietfux' ),
				],
			],
			'meldungen'         => [
				__( 'Meldungen', 'mietfux' ),
				[
					__( 'Schadens- und Mängelmeldungen der Mieter aus dem Mieterportal mit Status und Verlauf.', 'mietfux' ),
				],
			],
			'dokumente'         => [
				__( 'Dokumente', 'mietfux' ),
				[
					__( 'Ablage für Verträge, Protokolle, Schreiben und Abrechnungen. Mit „für Mieter sichtbar“ erscheint das Dokument im Mieterportal.', 'mietfux' ),
				],
			],
			'objekte'           => [
				__( 'Objekte', 'mietfux' ),
				[
					__( 'Ein Objekt ist ein Haus oder eine Eigentumswohnung. Baujahr bestimmt den gesetzlichen AfA-Satz; Heizungsart und Verbrauchsanteil steuern die Heizkostenverteilung (HeizkostenV 50–70 %).', 'mietfux' ),
				],
			],
			'einheiten'         => [
				__( 'Einheiten', 'mietfux' ),
				[
					__( 'Wohnung, Garage oder Stellplatz innerhalb eines Objekts. Fläche und Personen werden für Umlageschlüssel verwendet.', 'mietfux' ),
				],
			],
			'mieter'            => [
				__( 'Mieter', 'mietfux' ),
				[
					__( 'Stammdaten des Mieters. Ehe-/Lebenspartner optional als Mitmieter – dann haften beide gesamtschuldnerisch und werden überall automatisch genannt. Ein verknüpfter WordPress-Benutzer erhält Zugang zum Mieterportal.', 'mietfux' ),
				],
			],
			'mietverhaeltnisse' => [
				__( 'Mietverhältnisse', 'mietfux' ),
				[
					__( 'Verbindet Mieter und Einheit mit Beginn, Ende und Kaution. Zusatzeinheiten (Garage, Stellplatz) mit eigenem Mietanteil ergeben eine Gesamtmiete und eine Buchung.', 'mietfux' ),
				],
			],
			'konditionen'       => [
				__( 'Konditionen', 'mietfux' ),
				[
					__( 'Kaltmiete und Vorauszahlungen (Betriebs-/Heizkosten) mit Gültig-ab-Datum. Bei Änderung neue Kondition anlegen, nicht die alte überschreiben – so bleibt die Historie erhalten.', 'mietfux' ),
				],
			],
			'mietvertrag'       => [
				__( 'Mietvertrag & Übergabe', 'mietfux' ),
				[
					__( 'Erzeugt einen vollständigen Wohnraummietvertrag (20 §§) aus den Stammdaten, dazu Hausordnung, Merkblatt, Datenschutzinformation, SEPA-Mandat und Übergabeprotokoll – als PDF oder Druckansicht, auf Wunsch direkt in den Dokumenten abgelegt.', 'mietfux' ),
					__( 'Die Optionen (Befristung, Staffel/Index, Kleinreparaturen, Tiere, Schönheitsreparaturen …) werden je Mietverhältnis gespeichert.', 'mietfux' ),
				],
			],
			'kaution'           => [
				__( 'Kaution', 'mietfux' ),
				[
					__( 'Kautionskonto nach § 551 BGB: Einzahlungen (bis 3 Raten), taggenaue Verzinsung, Abrechnung bei Auszug.', 'mietfux' ),
				],
			],
			'erhoehung'         => [
				__( 'Mieterhöhung', 'mietfux' ),
				[
					__( 'Erhöhung nach § 558 BGB (Vergleichsmiete, Kappungsgrenze 15/20 %, Sperrfrist) oder § 557b (Index). Erzeugt das Schreiben und legt nach Zustimmung die neue Kondition an.', 'mietfux' ),
				],
			],
			'mahnung'           => [
				__( 'Mahnwesen', 'mietfux' ),
				[
					__( 'Drei Mahnstufen mit Fristen und Texten. Schreiben als PDF, optional per E-Mail an Mieter und Mitmieter.', 'mietfux' ),
				],
			],
			'zaehler'           => [
				__( 'Zähler', 'mietfux' ),
				[
					__( 'Strom-, Gas-, Wasser- und Wärmezähler je Einheit oder Objekt (Allgemeinstrom).', 'mietfux' ),
				],
			],
			'zaehlerstaende'    => [
				__( 'Zählerstände', 'mietfux' ),
				[
					__( 'Ablesungen mit Datum. Mieter können Stände selbst im Portal melden (Quelle „Mieter“).', 'mietfux' ),
				],
			],
			'abrechnung'        => [
				__( 'Betriebskostenabrechnung', 'mietfux' ),
				[
					__( 'Jahresabrechnung nach BetrKV: Belege des Jahres werden nach Schlüssel (Fläche, Personen, Einheiten, Verbrauch) verteilt, zeitanteilig bei Mieterwechsel; Leerstand trägt der Vermieter. Vorauszahlungen werden gegengerechnet, § 35a-Anteile ausgewiesen.', 'mietfux' ),
					__( 'Fertige Abrechnung als Schreiben (PDF) erzeugen, in Dokumenten ablegen und im Portal freigeben. Vorauszahlung kann direkt angepasst werden.', 'mietfux' ),
				],
			],
			'kostenarten'       => [
				__( 'Kostenarten', 'mietfux' ),
				[
					__( 'Katalog nach BetrKV § 2 (vorbelegt) plus eigene Arten. Je Kostenart: umlagefähig ja/nein, Umlageschlüssel, Zeile in Anlage V.', 'mietfux' ),
				],
			],
			'anlagegueter'      => [
				__( 'Anlagegüter (AfA)', 'mietfux' ),
				[
					__( 'Gebäude mit Anschaffungskosten, einzelnen Nebenkosten (Grunderwerbsteuer, Notar, Grundbuch, Makler, Sonstiges) und Grundanteil. AfA linear nach § 7 Abs. 4 EStG (2 %, 2,5 % vor 1925, 3 % ab 2023) oder degressiv, zeitanteilig im ersten Jahr.', 'mietfux' ),
					__( 'Der Kaufvertrag kann als PDF hochgeladen werden; Kaufpreis, Datum und Nebenkosten werden daraus vorgeschlagen. Der 15-%-Wächter warnt vor anschaffungsnahem Herstellungsaufwand (§ 6 Abs. 1 Nr. 1a EStG).', 'mietfux' ),
				],
			],
			'darlehen'          => [
				__( 'Darlehen', 'mietfux' ),
				[
					__( 'Finanzierungsdarlehen mit Zinssatz, Tilgung, Rate und Zinsbindung. Daraus entsteht der Tilgungsplan.', 'mietfux' ),
				],
			],
			'darlehenskonto'    => [
				__( 'Darlehenskonto', 'mietfux' ),
				[
					__( 'Tilgungsplan mit Raten zum Abhaken, Sondertilgungen und Restschuld. Abgehakte Raten liefern die Jahreszinsen für Anlage V, sofern keine Zinsbescheinigung eingetragen ist.', 'mietfux' ),
				],
			],
			'darlehen_jahre'    => [
				__( 'Zinsbescheinigung', 'mietfux' ),
				[
					__( 'Jahreswerte laut Bankbescheinigung (Zinsen, Tilgung, Restschuld). Haben Vorrang vor den berechneten Werten.', 'mietfux' ),
				],
			],
			'steuer'            => [
				__( 'Steuer (Anlage V)', 'mietfux' ),
				[
					__( 'Jahresauswertung je Objekt: Einnahmen, Werbungskosten nach Zeilen der Anlage V, AfA, Schuldzinsen, Vorjahresvergleich. Export als CSV; Zeilenzuordnung anpassbar.', 'mietfux' ),
				],
			],
			'bank_regeln'       => [
				__( 'Bankregeln', 'mietfux' ),
				[
					__( 'Zuordnungsregeln für den Bankimport: Suchbegriff oder IBAN → Mietverhältnis oder Kostenart.', 'mietfux' ),
				],
			],
			'datenschutz'       => [
				__( 'Datenschutz', 'mietfux' ),
				[
					__( 'Beendete Mietverhältnisse nach Ablauf der Aufbewahrungsfristen anonymisieren, Auskunft nach Art. 15 DSGVO exportieren. Löschkonzept und Fristen im Überblick.', 'mietfux' ),
				],
			],
			'assistent'         => [
				__( 'Einrichtungsassistent', 'mietfux' ),
				[
					__( 'Legt in fünf Schritten Vermieter, Objekt, Einheiten (Wohnung + Garage), Mieter und Mietverhältnis mit Konditionen an. Jeder Schritt speichert sofort; der Assistent kann jederzeit erneut aufgerufen werden, um die Angaben zu ergänzen.', 'mietfux' ),
				],
			],
			'einstellungen'     => [
				__( 'Einstellungen & Backup', 'mietfux' ),
				[
					__( 'Vermieterdaten, Bankverbindung, Gläubiger-ID, Steuernummer, Mahn- und Portaloptionen. Backup aller Plugin-Tabellen als JSON, Wiederherstellung, Option „Daten bei Deinstallation löschen“.', 'mietfux' ),
				],
			],
			'portal'            => [
				__( 'Mieterportal', 'mietfux' ),
				[
					__( 'Shortcode [mietfux_portal] auf einer Seite einfügen. Angemeldete Mieter (verknüpfter WordPress-Benutzer) sehen Abrechnungen und freigegebene Dokumente, melden Schäden und Zählerstände.', 'mietfux' ),
				],
			],
		];
	}

	/** Eigene Hilfeseite. */
	public static function page(): void {
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Hilfe', 'mietfux' ) . '</h1>';
		echo '<p>' . esc_html__( 'Mietverwaltung für private Vermieter: Stammdaten, Zahlungen, Belege, Betriebskostenabrechnung, Steuer (Anlage V), Mietvertrag und Mieterportal in einem Plugin. Auf jeder Seite finden Sie oben rechts unter „Hilfe“ eine Kurzbeschreibung.', 'mietfux' ) . '</p>';

		printf( '<h2>%s</h2><p><a class="button button-primary" href="%s">%s</a> %s</p><ol class="mv-hilfe-schritte">', esc_html__( 'Schnellstart', 'mietfux' ), esc_url( admin_url( 'admin.php?page=mietv_assistent' ) ), esc_html__( 'Einrichtungsassistent starten', 'mietfux' ), esc_html__( '– führt durch die Schritte 1 bis 4 und legt das erste Mietverhältnis an.', 'mietfux' ) );
		foreach ( self::schnellstart() as [ $titel, $text, $slug ] ) {
			printf( '<li><strong><a href="%s">%s</a></strong><br>%s</li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $titel ), esc_html( $text ) );
		}
		echo '</ol>';

		echo '<h2>' . esc_html__( 'Module', 'mietfux' ) . '</h2><div class="mv-hilfe-grid">';
		foreach ( self::themen() as $slug => [ $titel, $abs ] ) {
			$link = $slug === 'portal' ? '' : admin_url( 'admin.php?page=mietv_' . $slug );
			echo '<div class="mv-hilfe-karte"><h3>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $titel ) . '</a>' : esc_html( $titel ) ) . '</h3>';
			foreach ( $abs as $a ) {
				echo '<p>' . esc_html( $a ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Rechtliche Hinweise', 'mietfux' ) . '</h2>';
		echo '<p>' . esc_html__( 'Das Plugin ersetzt keine Rechts- oder Steuerberatung. Vertragsmuster, Berechnungen und Fristen beruhen auf der Rechtslage in Deutschland (BGB, BetrKV, HeizkostenV, EStG) und sind vor Verwendung zu prüfen. Alle Daten bleiben in Ihrer WordPress-Datenbank; es werden keine Daten an Dritte übertragen.', 'mietfux' ) . '</p>';
		printf( '<p class="description">%s %s · <a href="https://github.com/sporcherla/mietverwaltung" target="_blank" rel="noopener">GitHub</a></p>', esc_html__( 'Version', 'mietfux' ), esc_html( MIETV_VERSION ) );
		echo '</div>';
	}

	/** Kontexthilfe-Tab oben rechts auf allen Plugin-Seiten. */
	public static function kontexthilfe(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( $screen->id, 'mietv_' ) === false ) {
			return;
		}
		if ( ! preg_match( '/mietv_([a-z_]+)$/', $screen->id, $m ) ) {
			return;
		}
		$slug   = $m[1];
		$themen = self::themen();
		if ( ! isset( $themen[ $slug ] ) ) {
			return;
		}
		[ $titel, $abs ] = $themen[ $slug ];
		$html            = '';
		foreach ( $abs as $a ) {
			$html .= '<p>' . esc_html( $a ) . '</p>';
		}
		$html .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=mietv_hilfe' ) ) . '">' . esc_html__( 'Alle Hilfethemen', 'mietfux' ) . ' →</a></p>';
		$screen->add_help_tab(
			[
				'id'      => 'mietv_hilfe_' . $slug,
				'title'   => $titel,
				'content' => $html,
			]
		);
	}
}
