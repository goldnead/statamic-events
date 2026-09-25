<?php

use Goldnead\Events\Support\Settings;

// The settings entry read "Ereignisse" only because two other addons
// translated the name "Events" globally (fixed 25.09.2026). It names itself
// now, with the word of its own sidebar entry.
it('names its settings entry like its sidebar entry', function (): void {
    app()->setLocale('de');

    expect(Settings::settingsTitle())->toBe('Termine');
});
