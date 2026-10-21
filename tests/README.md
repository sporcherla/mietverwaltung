# Tests (ohne WordPress, mit SQLite-Stub)

Voraussetzung: PHP ≥ 8.0 mit pdo_sqlite. Aufruf aus beliebigem Verzeichnis:

    php tests/save_all_test.php      # Insert/Update aller Entitäten über das generische Formular
    php tests/special_save_test.php  # Einstellungen, Kaution, Abrechnung, Bankimport, Erhöhung, Vertrag, Portal, Anlage-V-Zeilen
    php tests/abr_test.php           # Betriebskostenabrechnung Rechenbeispiel + Bank-CSV-Parser
    php tests/afa_test.php           # AfA linear/degressiv/zeitanteilig

`stub.php` ersetzt `$wpdb` durch SQLite (Tabellen aus `class-mv-db.php` abgeleitet) und die nötigsten WordPress-Funktionen.
Nicht abgedeckt: Nonces, Media-Upload, WP-Cron, Rollen, Mailversand – das prüft nur eine echte WordPress-Installation.
