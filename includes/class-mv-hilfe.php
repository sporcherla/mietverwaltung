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
			[ __( 'Einstellungen', 'mietverwaltung' ), __( 'Vermieterdaten, Bankverbindung, Gläubiger-ID, Steuernummer und Absender für Schreiben eintragen.', 'mietverwaltung' ), 'mietv_einstellungen' ],
			[ __( 'Objekt & Einheiten', 'mietverwaltung' ), __( 'Haus/Eigentumswohnung als Objekt anlegen (Baujahr, Heizungsart), darunter die Einheiten (Wohnung, Garage, Stellplatz) mit Fläche und Personen.', 'mietverwaltung' ), 'mietv_objekte' ],
			[ __( 'Mieter', 'mietverwaltung' ), __( 'Mieter erfassen – optional mit Ehe-/Lebenspartner als zweite Vertragspartei. Beide erscheinen automatisch in Vertrag, Schreiben und SEPA-Mandat.', 'mietverwaltung' ), 'mietv_mieter' ],
			[ __( 'Mietverhältnis & Konditionen', 'mietverwaltung' ), __( 'Mietverhältnis = Mieter + Einheit + Beginn. Garage/Stellplatz als Zusatzeinheit anhängen, dann gibt es eine Gesamtmiete. Konditionen (Kaltmiete, Vorauszahlungen) mit Gültig-ab-Datum anlegen.', 'mietverwaltung' ), 'mietv_mietverhaeltnisse' ],
			[ __( 'Anlagegut & Darlehen', 'mietverwaltung' ), __( 'Kaufpreis, Nebenkosten (Notar, Grundbuch, Grunderwerbsteuer, Makler) und Grundanteil erfassen – der Kaufvertrag als PDF kann direkt ausgelesen werden. Darlehen mit Zins, Tilgung und Rate anlegen.', 'mietverwaltung' ), 'mietv_anlagegueter' ],
			[ __( 'Laufender Betrieb', 'mietverwaltung' ), __( 'Mieteingänge in der Übersicht abhaken oder per Bankimport einlesen, Belege fotografieren, Zählerstände erfassen. Am Jahresende: Betriebskostenabrechnung und Anlage V.', 'mietverwaltung' ), 'mietv_uebersicht' ],
		];
	}

	/** Kurzhilfe je Seite (Slug ohne „mietv_“ => [Titel, Absätze]). */
	public static function themen(): array {
		return [
			'uebersicht'        => [
				__( 'Übersicht', 'mietverwaltung' ),
				[
					__( 'Zeigt offene Posten, fällige Aufgaben und Kennzahlen. Offene Mieten lassen sich per Häkchen als bezahlt buchen – die Zahlung wird zum 1. des Monats eingetragen.', 'mietverwaltung' ),
					__( 'Mit „Fokusmodus“ wird das übrige WordPress-Menü ausgeblendet; die Einstellung gilt pro Benutzer.', 'mietverwaltung' ),
				],
			],
			'zahlungen'         => [
				__( 'Zahlungen', 'mietverwaltung' ),
				[
					__( 'Jede Zahlung gehört zu einem Mietverhältnis und einem Monat. Quelle: manuell, Bankimport oder abgehakt. Soll/Ist ergibt sich aus den Konditionen.', 'mietverwaltung' ),
				],
			],
			'belege'            => [
				__( 'Belege', 'mietverwaltung' ),
				[
					__( 'Rechnungen mit Kostenart, Steuerjahr und Datei erfassen (am Handy direkt per Kamera). Die Kostenart entscheidet über Umlagefähigkeit (BetrKV) und die Zeile in Anlage V.', 'mietverwaltung' ),
					__( 'Erhaltungsaufwand kann nach § 82b EStDV auf 2–5 Jahre verteilt werden; Handwerkerlohn (§ 35a) wird gesondert ausgewiesen.', 'mietverwaltung' ),
				],
			],
			'bank'              => [
				__( 'Bankimport', 'mietverwaltung' ),
				[
					__( 'CSV- oder CAMT.053-Export der Bank einlesen. Regeln ordnen Buchungen anhand von IBAN oder Verwendungszweck automatisch einem Mietverhältnis oder einer Kostenart zu; Duplikate werden erkannt.', 'mietverwaltung' ),
				],
			],
			'meldungen'         => [
				__( 'Meldungen', 'mietverwaltung' ),
				[
					__( 'Schadens- und Mängelmeldungen der Mieter aus dem Mieterportal mit Status und Verlauf.', 'mietverwaltung' ),
				],
			],
			'dokumente'         => [
				__( 'Dokumente', 'mietverwaltung' ),
				[
					__( 'Ablage für Verträge, Protokolle, Schreiben und Abrechnungen. Mit „für Mieter sichtbar“ erscheint das Dokument im Mieterportal.', 'mietverwaltung' ),
				],
			],
			'objekte'           => [
				__( 'Objekte', 'mietverwaltung' ),
				[
					__( 'Ein Objekt ist ein Haus oder eine Eigentumswohnung. Baujahr bestimmt den gesetzlichen AfA-Satz; Heizungsart und Verbrauchsanteil steuern die Heizkostenverteilung (HeizkostenV 50–70 %).', 'mietverwaltung' ),
				],
			],
			'einheiten'         => [
				__( 'Einheiten', 'mietverwaltung' ),
				[
					__( 'Wohnung, Garage oder Stellplatz innerhalb eines Objekts. Fläche und Personen werden für Umlageschlüssel verwendet.', 'mietverwaltung' ),
				],
			],
			'mieter'            => [
				__( 'Mieter', 'mietverwaltung' ),
				[
					__( 'Stammdaten des Mieters. Ehe-/Lebenspartner optional als Mitmieter – dann haften beide gesamtschuldnerisch und werden überall automatisch genannt. Ein verknüpfter WordPress-Benutzer erhält Zugang zum Mieterportal.', 'mietverwaltung' ),
				],
			],
			'mietverhaeltnisse' => [
				__( 'Mietverhältnisse', 'mietverwaltung' ),
				[
					__( 'Verbindet Mieter und Einheit mit Beginn, Ende und Kaution. Zusatzeinheiten (Garage, Stellplatz) mit eigenem Mietanteil ergeben eine Gesamtmiete und eine Buchung.', 'mietverwaltung' ),
				],
			],
			'konditionen'       => [
				__( 'Konditionen', 'mietverwaltung' ),
				[
					__( 'Kaltmiete und Vorauszahlungen (Betriebs-/Heizkosten) mit Gültig-ab-Datum. Bei Änderung neue Kondition anlegen, nicht die alte überschreiben – so bleibt die Historie erhalten.', 'mietverwaltung' ),
				],
			],
			'mietvertrag'       => [
				__( 'Mietvertrag & Übergabe', 'mietverwaltung' ),
				[
					__( 'Erzeugt einen vollständigen Wohnraummietvertrag (20 §§) aus den Stammdaten, dazu Hausordnung, Merkblatt, Datenschutzinformation, SEPA-Mandat und Übergabeprotokoll – als PDF oder Druckansicht, auf Wunsch direkt in den Dokumenten abgelegt.', 'mietverwaltung' ),
					__( 'Die Optionen (Befristung, Staffel/Index, Kleinreparaturen, Tiere, Schönheitsreparaturen …) werden je Mietverhältnis gespeichert.', 'mietverwaltung' ),
				],
			],
			'kaution'           => [
				__( 'Kaution', 'mietverwaltung' ),
				[
					__( 'Kautionskonto nach § 551 BGB: Einzahlungen (bis 3 Raten), taggenaue Verzinsung, Abrechnung bei Auszug.', 'mietverwaltung' ),
				],
			],
			'erhoehung'         => [
				__( 'Mieterhöhung', 'mietverwaltung' ),
				[
					__( 'Erhöhung nach § 558 BGB (Vergleichsmiete, Kappungsgrenze 15/20 %, Sperrfrist) oder § 557b (Index). Erzeugt das Schreiben und legt nach Zustimmung die neue Kondition an.', 'mietverwaltung' ),
				],
			],
			'mahnung'           => [
				__( 'Mahnwesen', 'mietverwaltung' ),
				[
					__( 'Drei Mahnstufen mit Fristen und Texten. Schreiben als PDF, optional per E-Mail an Mieter und Mitmieter.', 'mietverwaltung' ),
				],
			],
			'zaehler'           => [
				__( 'Zähler', 'mietverwaltung' ),
				[
					__( 'Strom-, Gas-, Wasser- und Wärmezähler je Einheit oder Objekt (Allgemeinstrom).', 'mietverwaltung' ),
				],
			],
			'zaehlerstaende'    => [
				__( 'Zählerstände', 'mietverwaltung' ),
				[
					__( 'Ablesungen mit Datum. Mieter können Stände selbst im Portal melden (Quelle „Mieter“).', 'mietverwaltung' ),
				],
			],
			'abrechnung'        => [
				__( 'Betriebskostenabrechnung', 'mietverwaltung' ),
				[
					__( 'Jahresabrechnung nach BetrKV: Belege des Jahres werden nach Schlüssel (Fläche, Personen, Einheiten, Verbrauch) verteilt, zeitanteilig bei Mieterwechsel; Leerstand trägt der Vermieter. Vorauszahlungen werden gegengerechnet, § 35a-Anteile ausgewiesen.', 'mietverwaltung' ),
					__( 'Fertige Abrechnung als Schreiben (PDF) erzeugen, in Dokumenten ablegen und im Portal freigeben. Vorauszahlung kann direkt angepasst werden.', 'mietverwaltung' ),
				],
			],
			'kostenarten'       => [
				__( 'Kostenarten', 'mietverwaltung' ),
				[
					__( 'Katalog nach BetrKV § 2 (vorbelegt) plus eigene Arten. Je Kostenart: umlagefähig ja/nein, Umlageschlüssel, Zeile in Anlage V.', 'mietverwaltung' ),
				],
			],
			'anlagegueter'      => [
				__( 'Anlagegüter (AfA)', 'mietverwaltung' ),
				[
					__( 'Gebäude mit Anschaffungskosten, einzelnen Nebenkosten (Grunderwerbsteuer, Notar, Grundbuch, Makler, Sonstiges) und Grundanteil. AfA linear nach § 7 Abs. 4 EStG (2 %, 2,5 % vor 1925, 3 % ab 2023) oder degressiv, zeitanteilig im ersten Jahr.', 'mietverwaltung' ),
					__( 'Der Kaufvertrag kann als PDF hochgeladen werden; Kaufpreis, Datum und Nebenkosten werden daraus vorgeschlagen. Der 15-%-Wächter warnt vor anschaffungsnahem Herstellungsaufwand (§ 6 Abs. 1 Nr. 1a EStG).', 'mietverwaltung' ),
				],
			],
			'darlehen'          => [
				__( 'Darlehen', 'mietverwaltung' ),
				[
					__( 'Finanzierungsdarlehen mit Zinssatz, Tilgung, Rate und Zinsbindung. Daraus entsteht der Tilgungsplan.', 'mietverwaltung' ),
				],
			],
			'darlehenskonto'    => [
				__( 'Darlehenskonto', 'mietverwaltung' ),
				[
					__( 'Tilgungsplan mit Raten zum Abhaken, Sondertilgungen und Restschuld. Abgehakte Raten liefern die Jahreszinsen für Anlage V, sofern keine Zinsbescheinigung eingetragen ist.', 'mietverwaltung' ),
				],
			],
			'darlehen_jahre'    => [
				__( 'Zinsbescheinigung', 'mietverwaltung' ),
				[
					__( 'Jahreswerte laut Bankbescheinigung (Zinsen, Tilgung, Restschuld). Haben Vorrang vor den berechneten Werten.', 'mietverwaltung' ),
				],
			],
			'steuer'            => [
				__( 'Steuer (Anlage V)', 'mietverwaltung' ),
				[
					__( 'Jahresauswertung je Objekt: Einnahmen, Werbungskosten nach Zeilen der Anlage V, AfA, Schuldzinsen, Vorjahresvergleich. Export als CSV; Zeilenzuordnung anpassbar.', 'mietverwaltung' ),
				],
			],
			'bank_regeln'       => [
				__( 'Bankregeln', 'mietverwaltung' ),
				[
					__( 'Zuordnungsregeln für den Bankimport: Suchbegriff oder IBAN → Mietverhältnis oder Kostenart.', 'mietverwaltung' ),
				],
			],
			'datenschutz'       => [
				__( 'Datenschutz', 'mietverwaltung' ),
				[
					__( 'Beendete Mietverhältnisse nach Ablauf der Aufbewahrungsfristen anonymisieren, Auskunft nach Art. 15 DSGVO exportieren. Löschkonzept und Fristen im Überblick.', 'mietverwaltung' ),
				],
			],
			'assistent'         => [
				__( 'Einrichtungsassistent', 'mietverwaltung' ),
				[
					__( 'Legt in fünf Schritten Vermieter, Objekt, Einheiten (Wohnung + Garage), Mieter und Mietverhältnis mit Konditionen an. Jeder Schritt speichert sofort; der Assistent kann jederzeit erneut aufgerufen werden, um die Angaben zu ergänzen.', 'mietverwaltung' ),
				],
			],
			'einstellungen'     => [
				__( 'Einstellungen & Backup', 'mietverwaltung' ),
				[
					__( 'Vermieterdaten, Bankverbindung, Gläubiger-ID, Steuernummer, Mahn- und Portaloptionen. Backup aller Plugin-Tabellen als JSON, Wiederherstellung, Option „Daten bei Deinstallation löschen“.', 'mietverwaltung' ),
				],
			],
			'portal'            => [
				__( 'Mieterportal', 'mietverwaltung' ),
				[
					__( 'Shortcode [mietverwaltung_portal] auf einer Seite einfügen. Angemeldete Mieter (verknüpfter WordPress-Benutzer) sehen Abrechnungen und freigegebene Dokumente, melden Schäden und Zählerstände.', 'mietverwaltung' ),
				],
			],
		];
	}

	/** Eigene Hilfeseite. */
	public static function page(): void {
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Hilfe', 'mietverwaltung' ) . '</h1>';
		echo '<p>' . esc_html__( 'Mietverwaltung für private Vermieter: Stammdaten, Zahlungen, Belege, Betriebskostenabrechnung, Steuer (Anlage V), Mietvertrag und Mieterportal in einem Plugin. Auf jeder Seite finden Sie oben rechts unter „Hilfe“ eine Kurzbeschreibung.', 'mietverwaltung' ) . '</p>';

		printf( '<h2>%s</h2><p><a class="button button-primary" href="%s">%s</a> %s</p><ol class="mv-hilfe-schritte">', esc_html__( 'Schnellstart', 'mietverwaltung' ), esc_url( admin_url( 'admin.php?page=mietv_assistent' ) ), esc_html__( 'Einrichtungsassistent starten', 'mietverwaltung' ), esc_html__( '– führt durch die Schritte 1 bis 4 und legt das erste Mietverhältnis an.', 'mietverwaltung' ) );
		foreach ( self::schnellstart() as [ $titel, $text, $slug ] ) {
			printf( '<li><strong><a href="%s">%s</a></strong><br>%s</li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $titel ), esc_html( $text ) );
		}
		echo '</ol>';

		echo '<h2>' . esc_html__( 'Module', 'mietverwaltung' ) . '</h2><div class="mv-hilfe-grid">';
		foreach ( self::themen() as $slug => [ $titel, $abs ] ) {
			$link = $slug === 'portal' ? '' : admin_url( 'admin.php?page=mietv_' . $slug );
			echo '<div class="mv-hilfe-karte"><h3>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $titel ) . '</a>' : esc_html( $titel ) ) . '</h3>';
			foreach ( $abs as $a ) {
				echo '<p>' . esc_html( $a ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Rechtliche Hinweise', 'mietverwaltung' ) . '</h2>';
		echo '<p>' . esc_html__( 'Das Plugin ersetzt keine Rechts- oder Steuerberatung. Vertragsmuster, Berechnungen und Fristen beruhen auf der Rechtslage in Deutschland (BGB, BetrKV, HeizkostenV, EStG) und sind vor Verwendung zu prüfen. Alle Daten bleiben in Ihrer WordPress-Datenbank; es werden keine Daten an Dritte übertragen.', 'mietverwaltung' ) . '</p>';
		printf( '<p class="description">%s %s · <a href="https://github.com/sporcherla/mietverwaltung" target="_blank" rel="noopener">GitHub</a></p>', esc_html__( 'Version', 'mietverwaltung' ), esc_html( MIETV_VERSION ) );
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
		$html .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=mietv_hilfe' ) ) . '">' . esc_html__( 'Alle Hilfethemen', 'mietverwaltung' ) . ' →</a></p>';
		$screen->add_help_tab(
			[
				'id'      => 'mietv_hilfe_' . $slug,
				'title'   => $titel,
				'content' => $html,
			]
		);
	}
}
