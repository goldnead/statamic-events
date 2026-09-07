<?php

namespace Goldnead\Events\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\Events\Enums\Visibility;

/**
 * Was ein Betreiber an diesem Addon aus dem Control Panel heraus aendern darf.
 *
 * Diese Klasse ist ausschliesslich die Feldliste. Seite, Formular, Validierung,
 * Speicher, Markendimension und Rechtepruefung kommen aus
 * `goldnead/statamic-brand-context` — siehe {@see ProvidesSettings}. Hier steht
 * kein Controller, keine Route, keine Vue-Seite und keine eigene Tabelle.
 *
 * **Ueberschreibungen, keine Kopie.** Gespeichert wird nur, was jemand geaendert
 * hat. Alles andere folgt weiter `config/events.php`, so dass ein Paket-Update
 * die Vorgaben mitbewegt und eine Installation, die diesen Bildschirm nie
 * oeffnet, sich nicht von einer Fassung vor diesem Bildschirm unterscheidet.
 *
 * ## Was hier nicht steht, und warum
 *
 * `SettingsManager::apply()` laeuft aus `app->booted()`. Alles, was beim Booten
 * gelesen wird, sieht zu diesem Zeitpunkt noch den Paketwert. Ein Schalter, der
 * erst beim naechsten Deploy wirkt, ist eine Luege in der Oberflaeche — deshalb
 * fehlen hier drei Schluessel, jeder mit einer nachgesehenen Fundstelle:
 *
 * - `cp.enabled` (`config/events.php:17`). Gelesen in `routes/cp.php:10` beim
 *   Registrieren der Routen und in `ServiceProvider::bootNavigation()` beim
 *   Registrieren des Navigationseintrags. Beides passiert vor `booted`.
 * - `bridges.activity` (:86). Gelesen in `ActivityBridge::attach()`, das aus
 *   `bootAddon()` heraus aufgerufen wird. Danach merkt sich die Bruecke in
 *   einem statischen Feld, dass sie haengt, und liest den Schluessel nie
 *   wieder — ein Abschalten waere also selbst beim naechsten Aufruf wirkungslos.
 * - `types` (:41-48). Eine Abbildung Handle→Anzeigename. Die Einstellungs-
 *   Schicht kennt fuenf Typen und keinen fuer Abbildungen; eine Textarea, die
 *   eine Abbildung ueber irgendein erfundenes Trennzeichen hin- und
 *   zurueckuebersetzt, ist ein schlechterer Editor als keiner. Der Schluessel
 *   bleibt in der Config, und die Gruppenbeschreibung sagt das.
 */
class Settings implements ProvidesSettings
{
    /**
     * Steht in `brand_settings.namespace` auf jeder gespeicherten Zeile. Ein
     * Umbenennen verwaist jede Ueberschreibung, die eine Installation gemacht
     * hat.
     */
    public static function settingsNamespace(): string
    {
        return 'events';
    }

    /** Die Config-Wurzel, der die ungesetzten Werte weiter folgen. */
    public static function settingsConfigPath(): string
    {
        return 'events';
    }

    /**
     * Neu in dieser Fassung, also frei waehlbar — und deshalb genau der Name
     * aus der Konvention: `manage <handle> settings`. Die beiden bestehenden
     * Rechte (`view events`, `manage events`) bleiben unangetastet; sie sind
     * auf Installationen bereits an Gruppen vergeben.
     */
    public static function settingsPermission(): string
    {
        return 'manage events settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('events::settings.groups.defaults.title'),
                'description' => __('events::settings.groups.defaults.description'),
                'fields' => [
                    static::field('defaults.timezone', 'string', ['nullable' => true]),
                    static::field('defaults.visibility', 'select', ['options' => Visibility::options()]),
                ],
            ],
            [
                'title' => __('events::settings.groups.feeds.title'),
                'description' => __('events::settings.groups.feeds.description'),
                'fields' => [
                    static::field('feeds.enabled', 'boolean'),
                    static::field('feeds.name', 'string', ['nullable' => true]),
                    // 0 heisst "nur ab heute" und muss erreichbar bleiben.
                    static::field('feeds.past_days', 'integer', ['min' => 0]),
                    // 1 ist die untere Grenze: ein Feed ohne einen einzigen
                    // Termin ist kein Deckel, sondern ein abgeschalteter Feed —
                    // dafuer gibt es den Schalter darueber.
                    static::field('feeds.max_occurrences', 'integer', ['min' => 1]),
                    // 0 heisst "nicht zwischenspeichern".
                    static::field('feeds.cache_seconds', 'integer', ['min' => 0]),
                ],
            ],
            [
                'title' => __('events::settings.groups.cp.title'),
                'description' => __('events::settings.groups.cp.description'),
                'fields' => [
                    static::field('cp.per_page', 'integer', ['min' => 1]),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Beschreibung aus den Sprachdateien.
     *
     * Der Uebersetzungsschluessel ist der Config-Pfad mit Unterstrichen statt
     * Punkten: ein Punkt im Schluessel waere fuer den Uebersetzer ein
     * Pfadtrenner, und `settings.fields.feeds.name.label` wuerde als vier
     * verschachtelte Arrays gesucht, die es nicht gibt.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("events::settings.fields.{$handle}.label"),
            'description' => __("events::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
