<?php

use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Support\Settings;

/**
 * Die Einstellungs-Schicht, so weit sie diesem Addon gehoert.
 *
 * Der Bildschirm, der Speicher, die Validierung und die Markendimension sind
 * `goldnead/statamic-brand-context` und werden dort getestet. Dieses Addon
 * liefert die Erklaerung: Namensraum, Config-Wurzel, Rechtename und die
 * Feldliste. Was hier steht, faengt genau die Fehler, die brand-contexts eigene
 * Suite nicht sehen kann — einen falschen Namensraum, ein aus Versehen
 * umbenanntes Recht, und vor allem einen Schluessel, der beim Booten gelesen
 * wird und deshalb nicht auf den Bildschirm gehoert.
 */
beforeEach(function () {
    $this->makeBrand('nord');
});

it('meldet sich bei der gemeinsamen Einstellungs-Schicht an', function () {
    $registry = app(SettingsRegistry::class);

    expect($registry->has('events'))->toBeTrue('boot() hat den Einstellungs-Anbieter nicht angemeldet')
        ->and($registry->provider('events'))->toBe(Settings::class)
        ->and($registry->configPath('events'))->toBe('events')
        ->and($registry->permission('events'))->toBe('manage events settings');
});

it('laesst einen geaenderten Wert beim Leser ankommen, nicht nur in der Config', function () {
    // Die Grenze, die dieser Test ueberschreiten muss: nicht "gespeichert",
    // sondern "der Code, der den Wert benutzt, sieht ihn". `defaultTimezone()`
    // ist der einzige Leser von `defaults.timezone`, und ein neu angelegter
    // Termin ohne eigene Zone laeuft durch ihn.
    app(SettingsManager::class)->for('events')->save(['defaults.timezone' => 'Pacific/Auckland']);

    $row = BrandSetting::query()->where('key', 'defaults.timezone')->first();

    expect($row?->value)->toBe('Pacific/Auckland')
        ->and($row?->namespace)->toBe('events')
        ->and($row?->brand_id)->not->toBeNull()
        ->and(config('events.defaults.timezone'))->toBe('Pacific/Auckland')
        ->and(Event::defaultTimezone())->toBe('Pacific/Auckland');

    $event = Event::factory()->create(['timezone' => null]);

    expect($event->fresh()->timezone)->toBe('Pacific/Auckland');
});

it('macht aus einer Zahl aus einem Textfeld eine Zahl', function () {
    // HTML-Felder geben Zeichenketten zurueck, und `feeds.max_occurrences`
    // landet in einem `limit`. Eine "500" ueberlebt bis zum ersten strengen
    // Vergleich und faellt dann an ganz anderer Stelle auf.
    app(SettingsManager::class)->for('events')->save(['feeds.max_occurrences' => '250']);

    expect(config('events.feeds.max_occurrences'))->toBe(250);
});

it('speichert ein leeres nullbares Feld als null, nicht als leere Zeichenkette', function () {
    // Leer heisst bei der Zeitzone "die der Anwendung" und beim Feed-Namen "der
    // Name der Anwendung". Eine leere Zeichenkette waere ein Name, den niemand
    // sehen will.
    app(SettingsManager::class)->for('events')->save(['feeds.name' => '']);

    expect(config('events.feeds.name'))->toBeNull();
});

it('bietet keinen Schluessel an, der beim Booten gelesen wird', function () {
    // Der teuerste Fehler, den diese Seite machen kann: ein Schalter, der erst
    // beim naechsten Deploy wirkt. `SettingsManager::apply()` laeuft aus
    // `app->booted()`; wer vorher liest, sieht den Paketwert.
    //
    // - `cp.enabled`: routes/cp.php:10 und ServiceProvider::bootNavigation()
    // - `bridges.activity`: ActivityBridge::attach() aus bootAddon(), danach
    //   merkt sich die Bruecke statisch, dass sie haengt
    // - `types`: eine Abbildung Handle→Anzeigename, fuer die es keinen Feldtyp
    //   gibt; sie bleibt in der Config und wird in der Gruppenbeschreibung
    //   benannt
    $keys = array_keys(app(SettingsRegistry::class)->fields('events'));

    expect($keys)->not->toContain('cp.enabled')
        ->and($keys)->not->toContain('bridges.activity')
        ->and($keys)->not->toContain('types');
});

it('bietet genau die Schluessel an, die zur Anfragezeit gelesen werden', function () {
    // Die Gegenprobe zum Test darueber: eine Liste, die schrumpft, faellt hier
    // auf, statt still eine Einstellung zu verlieren.
    expect(array_keys(app(SettingsRegistry::class)->fields('events')))->toBe([
        'defaults.timezone',
        'defaults.visibility',
        'feeds.enabled',
        'feeds.name',
        'feeds.past_days',
        'feeds.max_occurrences',
        'feeds.cache_seconds',
        'cp.per_page',
    ]);
});

it('nennt in der Gruppenbeschreibung, was auf der Seite fehlt', function () {
    // Weggelassen ist in Ordnung, verschwiegen nicht: wer den Schalter sucht,
    // muss auf dem Bildschirm erfahren, wo er steht.
    $descriptions = collect(Settings::settingsGroups())->pluck('description')->implode(' ');

    expect($descriptions)->toContain('config/events.php')
        ->and($descriptions)->toContain('cp.enabled')
        ->and($descriptions)->toContain('bridges.activity');
});
