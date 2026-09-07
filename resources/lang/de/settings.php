<?php

/*
 * Beschriftungen des Einstellungs-Formulars unter /cp/brand-settings.
 *
 * Schluesselgleich mit resources/lang/en/settings.php. Die Feldschluessel sind
 * der Config-Pfad mit Unterstrichen statt Punkten (`feeds.past_days` →
 * `feeds_past_days`), weil ein Punkt im Uebersetzungsschluessel als Pfadtrenner
 * gelesen wuerde.
 *
 * Die Beschreibungen sagen, was passiert, wenn man den Wert aendert — nicht,
 * wie das Feld heisst.
 */

return [

    'groups' => [

        'defaults' => [
            'title' => 'Neue Termine',
            'description' => 'Womit ein neu angelegter Termin startet. Bestehende Termine bleiben unberuehrt. Die Liste der Termintypen steht weiterhin in config/events.php: sie ist eine Abbildung von Handle auf Anzeigename, und ein Handle zu aendern wuerde jeden Termin treffen, der darauf steht.',
        ],

        'feeds' => [
            'title' => 'Kalender-Feeds',
            'description' => 'Der oeffentliche ICS-Feed, den Abonnenten in ihren Kalender legen. Aenderungen wirken beim naechsten Abruf, den der Zwischenspeicher unten durchlaesst.',
        ],

        'cp' => [
            'title' => 'Control Panel',
            'description' => 'Der Schalter, der das Control Panel dieses Addons ganz abschaltet (cp.enabled), steht weiterhin in config/events.php: er wird beim Registrieren der Routen und des Navigationseintrags gelesen, also bevor Einstellungen von hier gelten koennen. Aus demselben Grund bleibt bridges.activity dort — die Bruecke zum Aktivitaets-Addon haengt sich beim Booten ein.',
        ],

    ],

    'fields' => [

        'defaults_timezone' => [
            'label' => 'Zeitzone',
            'description' => 'Die Zone, in der die Datumsangaben eines neu angelegten Termins gelesen werden. Leer heisst: die Zeitzone der Anwendung. Als IANA-Kennung, etwa Europe/Berlin.',
        ],

        'defaults_visibility' => [
            'label' => 'Sichtbarkeit',
            'description' => 'Ob ein neu angelegter Termin im Kalender-Feed erscheint. Nicht gelistete sind nur ueber ihren Link erreichbar, private verlassen das Control Panel nie.',
        ],

        'feeds_enabled' => [
            'label' => 'Feed ausliefern',
            'description' => 'Aus heisst: der Feed antwortet nicht mehr. Wer ihn abonniert hat, behaelt die bereits importierten Termine, bekommt aber keine neuen mehr.',
        ],

        'feeds_name' => [
            'label' => 'Name des Kalenders',
            'description' => 'Der Name, den ein Abonnent in seinem Kalenderprogramm sieht. Leer heisst: der Name der Anwendung.',
        ],

        'feeds_past_days' => [
            'label' => 'Vergangene Tage',
            'description' => 'Wie weit der Feed zurueckreicht. Bei 0 verschwindet ein Termin an dem Tag, an dem er stattgefunden hat.',
        ],

        'feeds_max_occurrences' => [
            'label' => 'Datumsangaben je Abruf',
            'description' => 'Der Deckel fuer eine einzelne Feed-Antwort. Der Feed ist oeffentlich und ohne Anmeldung erreichbar; ohne Deckel laesst sich eine Abfrage von aussen beliebig teuer machen.',
        ],

        'feeds_cache_seconds' => [
            'label' => 'Zwischenspeicher',
            'description' => 'Wie lange eine Feed-Antwort wiederverwendet wird, in Sekunden. Solange sieht ein Abonnent eine Aenderung nicht. 0 schaltet den Zwischenspeicher ab.',
        ],

        'cp_per_page' => [
            'label' => 'Zeilen je Seite',
            'description' => 'Wie viele Termine die Liste im Control Panel auf einmal zeigt, solange niemand etwas anderes waehlt.',
        ],

    ],

];
