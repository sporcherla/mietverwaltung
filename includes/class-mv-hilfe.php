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
			[ __( 'Settings', 'mietfux' ), __( 'Enter landlord details, bank account, creditor ID, tax number and sender for letters.', 'mietfux' ), 'mietv_einstellungen' ],
			[ __( 'Property & units', 'mietfux' ), __( 'Create the house/condominium as a property (year built, heating type), then the units beneath it (flat, garage, parking space) with area and persons.', 'mietfux' ), 'mietv_objekte' ],
			[ __( 'Tenants', 'mietfux' ), __( 'Enter tenants – optionally with spouse/partner as second contracting party. Both appear automatically in the agreement, letters and SEPA mandate.', 'mietfux' ), 'mietv_mieter' ],
			[ __( 'Tenancy & rent conditions', 'mietfux' ), __( 'Tenancy = tenant + unit + start date. Attach garage/parking as an additional unit to get one total rent. Create rent conditions (base rent, advance payments) with a valid-from date.', 'mietfux' ), 'mietv_mietverhaeltnisse' ],
			[ __( 'Asset & loan', 'mietfux' ), __( 'Enter purchase price, incidental costs (notary, land registry, transfer tax, agent) and land share – the purchase contract PDF can be parsed directly. Create loans with interest, repayment and instalment.', 'mietfux' ), 'mietv_anlagegueter' ],
			[ __( 'Day-to-day', 'mietfux' ), __( 'Tick rent payments in the overview or import them from the bank, photograph receipts, record meter readings. At year end: service charge statement and Anlage V.', 'mietfux' ), 'mietv_uebersicht' ],
		];
	}

	/** Kurzhilfe je Seite (Slug ohne „mietv_“ => [Titel, Absätze]). */
	public static function themen(): array {
		return [
			'uebersicht'        => [
				__( 'Overview', 'mietfux' ),
				[
					__( 'Shows open items, due tasks and key figures. Open rents can be booked as paid by ticking – the payment is recorded on the 1st of the month.', 'mietfux' ),
					__( '“Focus mode” hides the rest of the WordPress menu; the setting is per user.', 'mietfux' ),
				],
			],
			'zahlungen'         => [
				__( 'Payments', 'mietfux' ),
				[
					__( 'Each payment belongs to a tenancy and a month. Source: manual, bank import or ticked. Due/paid is derived from the rent conditions.', 'mietfux' ),
				],
			],
			'belege'            => [
				__( 'Receipts', 'mietfux' ),
				[
					__( 'Record invoices with cost type, tax year and file (directly via camera on your phone). The cost type determines recoverability (BetrKV) and the line in Anlage V.', 'mietfux' ),
					__( 'Maintenance expenses can be spread over 2–5 years under § 82b EStDV; tradesmen\'s labour (§ 35a) is shown separately.', 'mietfux' ),
				],
			],
			'bank'              => [
				__( 'Bank import', 'mietfux' ),
				[
					__( 'Import the bank\'s CSV or CAMT.053 export. Rules automatically assign transactions to a tenancy or cost type by IBAN or reference; duplicates are detected.', 'mietfux' ),
				],
			],
			'meldungen'         => [
				__( 'Reports', 'mietfux' ),
				[
					__( 'Damage and defect reports from tenants via the tenant portal, with status and history.', 'mietfux' ),
				],
			],
			'dokumente'         => [
				__( 'Documents', 'mietfux' ),
				[
					__( 'Storage for agreements, protocols, letters and statements. With “visible to tenant” the document appears in the tenant portal.', 'mietfux' ),
				],
			],
			'objekte'           => [
				__( 'Properties', 'mietfux' ),
				[
					__( 'A property is a house or a condominium. Year built determines the statutory depreciation rate; heating type and consumption share control heating cost allocation (HeizkostenV 50–70 %).', 'mietfux' ),
				],
			],
			'einheiten'         => [
				__( 'Units', 'mietfux' ),
				[
					__( 'Flat, garage or parking space within a property. Area and persons are used for allocation keys.', 'mietfux' ),
				],
			],
			'mieter'            => [
				__( 'Tenants', 'mietfux' ),
				[
					__( 'Tenant master data. Spouse/partner optionally as co-tenant – both are then jointly and severally liable and named automatically everywhere. A linked WordPress user gets access to the tenant portal.', 'mietfux' ),
				],
			],
			'mietverhaeltnisse' => [
				__( 'Tenancies', 'mietfux' ),
				[
					__( 'Links tenant and unit with start, end and deposit. Additional units (garage, parking) with their own rent share result in one total rent and one booking.', 'mietfux' ),
				],
			],
			'konditionen'       => [
				__( 'Rent conditions', 'mietfux' ),
				[
					__( 'Base rent and advance payments (service/heating) with a valid-from date. For changes create a new condition rather than overwriting the old one – this preserves the history.', 'mietfux' ),
				],
			],
			'mietvertrag'       => [
				__( 'Agreement & handover', 'mietfux' ),
				[
					__( 'Generates a complete residential tenancy agreement (20 sections) from the master data, plus house rules, information sheet, privacy notice, SEPA mandate and handover protocol – as PDF or print view, optionally filed directly under Documents.', 'mietfux' ),
					__( 'The options (fixed term, stepped/index rent, minor repairs, pets, decorative repairs …) are saved per tenancy.', 'mietfux' ),
				],
			],
			'kaution'           => [
				__( 'Deposit', 'mietfux' ),
				[
					__( 'Deposit account under § 551 BGB: payments (up to 3 instalments), daily interest, settlement on move-out.', 'mietfux' ),
				],
			],
			'erhoehung'         => [
				__( 'Rent increase', 'mietfux' ),
				[
					__( 'Increase under § 558 BGB (reference rent, cap 15/20 %, waiting period) or § 557b (index). Generates the letter and creates the new rent condition after consent.', 'mietfux' ),
				],
			],
			'mahnung'           => [
				__( 'Dunning', 'mietfux' ),
				[
					__( 'Three dunning levels with deadlines and texts. Letters as PDF, optionally by email to tenant and co-tenant.', 'mietfux' ),
				],
			],
			'zaehler'           => [
				__( 'Meters', 'mietfux' ),
				[
					__( 'Electricity, gas, water and heat meters per unit or property (communal electricity).', 'mietfux' ),
				],
			],
			'zaehlerstaende'    => [
				__( 'Meter readings', 'mietfux' ),
				[
					__( 'Readings with date. Tenants can submit readings themselves in the portal (source “Tenant”).', 'mietfux' ),
				],
			],
			'abrechnung'        => [
				__( 'Service charge statement', 'mietfux' ),
				[
					__( 'Annual statement under BetrKV: the year\'s receipts are allocated by key (area, persons, units, consumption), pro rata on tenant change; vacancy is borne by the landlord. Advance payments are offset, § 35a shares are shown.', 'mietfux' ),
					__( 'Generate the finished statement as a letter (PDF), file it under Documents and release it in the portal. The advance payment can be adjusted directly.', 'mietfux' ),
				],
			],
			'kostenarten'       => [
				__( 'Cost types', 'mietfux' ),
				[
					__( 'Catalogue per BetrKV § 2 (pre-filled) plus your own types. Per cost type: recoverable yes/no, allocation key, line in Anlage V.', 'mietfux' ),
				],
			],
			'anlagegueter'      => [
				__( 'Assets (depreciation)', 'mietfux' ),
				[
					__( 'Building with acquisition costs, itemised incidental costs (transfer tax, notary, land registry, agent, other) and land share. Depreciation straight-line under § 7 (4) EStG (2 %, 2.5 % before 1925, 3 % from 2023) or declining balance, pro rata in the first year.', 'mietfux' ),
					__( 'The purchase contract can be uploaded as PDF; purchase price, date and incidental costs are suggested from it. The 15 % guard warns of acquisition-related construction costs (§ 6 (1) no. 1a EStG).', 'mietfux' ),
				],
			],
			'darlehen'          => [
				__( 'Loans', 'mietfux' ),
				[
					__( 'Financing loans with interest rate, repayment, instalment and fixed-rate period. The repayment schedule is derived from these.', 'mietfux' ),
				],
			],
			'darlehenskonto'    => [
				__( 'Loan account', 'mietfux' ),
				[
					__( 'Repayment schedule with instalments to tick off, extra repayments and outstanding balance. Ticked instalments provide the annual interest for Anlage V unless an interest certificate is entered.', 'mietfux' ),
				],
			],
			'darlehen_jahre'    => [
				__( 'Interest certificate', 'mietfux' ),
				[
					__( 'Annual figures per bank certificate (interest, repayment, balance). Take precedence over calculated values.', 'mietfux' ),
				],
			],
			'steuer'            => [
				__( 'Tax (Anlage V)', 'mietfux' ),
				[
					__( 'Annual report per property: income, expenses by Anlage V line, depreciation, loan interest, prior-year comparison. CSV export; line mapping adjustable.', 'mietfux' ),
				],
			],
			'bank_regeln'       => [
				__( 'Bank rules', 'mietfux' ),
				[
					__( 'Matching rules for the bank import: search term or IBAN → tenancy or cost type.', 'mietfux' ),
				],
			],
			'datenschutz'       => [
				__( 'Data protection', 'mietfux' ),
				[
					__( 'Anonymise ended tenancies after the retention periods expire, export access reports under Art. 15 GDPR. Overview of deletion concept and deadlines.', 'mietfux' ),
				],
			],
			'assistent'         => [
				__( 'Setup wizard', 'mietfux' ),
				[
					__( 'Creates landlord, property, units (flat + garage), tenant and tenancy with rent conditions in five steps. Each step saves immediately; the wizard can be reopened at any time to complete the entries.', 'mietfux' ),
				],
			],
			'einstellungen'     => [
				__( 'Settings & backup', 'mietfux' ),
				[
					__( 'Landlord details, bank account, creditor ID, tax number, dunning and portal options. Backup of all plugin tables as JSON, restore, option “delete data on uninstall”.', 'mietfux' ),
				],
			],
			'portal'            => [
				__( 'Tenant portal', 'mietfux' ),
				[
					__( 'Insert the shortcode [mietfux_portal] on a page. Logged-in tenants (linked WordPress user) see statements and released documents, and report damage and meter readings.', 'mietfux' ),
				],
			],
		];
	}

	/** Eigene Hilfeseite. */
	public static function page(): void {
		echo '<div class="wrap mv-wrap"><h1>' . esc_html__( 'Help', 'mietfux' ) . '</h1>';
		echo '<p>' . esc_html__( 'Rental management for private landlords: master data, payments, receipts, service charge statements, tax (Anlage V), tenancy agreement and tenant portal in one plugin. On every page you will find a short description under “Help” at the top right.', 'mietfux' ) . '</p>';

		printf( '<h2>%s</h2><p><a class="button button-primary" href="%s">%s</a> %s</p><ol class="mv-hilfe-schritte">', esc_html__( 'Quick start', 'mietfux' ), esc_url( admin_url( 'admin.php?page=mietv_assistent' ) ), esc_html__( 'Start setup wizard', 'mietfux' ), esc_html__( '– guides through steps 1 to 4 and creates the first tenancy.', 'mietfux' ) );
		foreach ( self::schnellstart() as [ $titel, $text, $slug ] ) {
			printf( '<li><strong><a href="%s">%s</a></strong><br>%s</li>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $titel ), esc_html( $text ) );
		}
		echo '</ol>';

		echo '<h2>' . esc_html__( 'Modules', 'mietfux' ) . '</h2><div class="mv-hilfe-grid">';
		foreach ( self::themen() as $slug => [ $titel, $abs ] ) {
			$link = $slug === 'portal' ? '' : admin_url( 'admin.php?page=mietv_' . $slug );
			echo '<div class="mv-hilfe-karte"><h3>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $titel ) . '</a>' : esc_html( $titel ) ) . '</h3>';
			foreach ( $abs as $a ) {
				echo '<p>' . esc_html( $a ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Legal notes', 'mietfux' ) . '</h2>';
		echo '<p>' . esc_html__( 'The plugin does not replace legal or tax advice. Contract templates, calculations and deadlines are based on German law (BGB, BetrKV, HeizkostenV, EStG) and must be checked before use. All data stays in your WordPress database; no data is transmitted to third parties.', 'mietfux' ) . '</p>';
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
		$html .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=mietv_hilfe' ) ) . '">' . esc_html__( 'All help topics', 'mietfux' ) . ' →</a></p>';
		$screen->add_help_tab(
			[
				'id'      => 'mietv_hilfe_' . $slug,
				'title'   => $titel,
				'content' => $html,
			]
		);
	}
}
