<?php

return [

    'nav' => 'Termine',
    'title' => 'Termine',
    'permission_view' => 'Termine ansehen',
    'permission_manage' => 'Termine und Datumsangaben anlegen, bearbeiten und löschen',
    'permission_manage_settings' => 'Einstellungen der Termine ändern',

    'create_event' => 'Termin anlegen',
    'edit' => 'Bearbeiten',
    'view' => 'Ansehen',
    'delete' => 'Löschen',
    'delete_event' => 'Termin löschen',
    'delete_event_confirm' => 'Mit dem Termin werden alle Datumsangaben gelöscht. Wer den Kalender abonniert hat, behält die bereits importierten Daten.',
    'back_to_events' => 'Alle Termine',

    'setup_required_heading' => 'Diese Seite braucht ihre Datenbanktabellen, und die gibt es noch nicht.',
    'setup_required_description' => 'Führe `php artisan migrate` aus, danach lädt die Seite normal. Der Grund steht auch im Log.',

    'dates' => 'Datumsangaben',
    'no_dates' => 'Für diesen Termin ist noch kein Datum eingetragen.',
    'no_upcoming' => 'Kein kommendes Datum',
    'add_date' => 'Datum zu :event hinzufügen',
    'add_date_short' => 'Datum hinzufügen',
    'all_day' => 'Ganztägig',
    // Die Titel der beiden Listen-Aktionen. Ihre Bestätigungs- und Erfolgstexte
    // stehen weiter unten bei den bulk_*-Schlüsseln, weil dieselbe Aktion eine
    // Zeile und eine Mehrfachauswahl bedient.
    'cancel_date' => 'Datum absagen',
    'delete_date' => 'Datum löschen',
    'download_ics' => 'ICS',
    'calendar_feed' => 'Kalender-Feed',

    'tab_details' => 'Details',
    'tab_settings' => 'Einstellungen',
    'tab_date' => 'Datum',

    'field_title' => 'Titel',
    'field_slug' => 'Slug',
    'field_slug_instructions' => 'Wird in URLs und im ICS-Dateinamen verwendet. Pro Brand eindeutig.',
    'field_description' => 'Beschreibung',
    'field_type' => 'Typ',
    'field_status' => 'Status',
    'field_visibility' => 'Sichtbarkeit',
    'field_visibility_instructions' => 'Öffentliche Termine erscheinen im Kalender-Feed. Nicht gelistete sind nur über ihren Link erreichbar. Private verlassen das Control Panel nie.',
    'field_timezone' => 'Zeitzone',
    'field_timezone_instructions' => 'Die Zone, in der die Datumsangaben dieses Termins gelesen werden. Einzelne Daten können sie überschreiben.',

    'field_starts_at' => 'Beginn',
    'field_ends_at' => 'Ende',
    'field_ends_at_instructions' => 'Optional. Leer lassen für ein offenes Ende.',
    'field_all_day' => 'Ganztägig',
    'field_all_day_instructions' => 'Uhrzeiten werden ignoriert, das Datum wird als ganzer Tag veröffentlicht.',
    'field_occurrence_timezone' => 'Abweichende Zeitzone',
    'field_occurrence_timezone_instructions' => 'Nur wenn dieses Datum in einer anderen Zone als der des Termins stattfindet.',
    'field_occurrence_status' => 'Status',
    'field_occurrence_status_instructions' => 'Wird über das Absagen gesetzt, damit eine Absage immer einen Grund und eine erhöhte Sequenz trägt.',

    'section_location' => 'Ort',
    'section_location_instructions' => 'Ein Veranstaltungsort, eine Online-URL oder beides. Mindestens eines ist erforderlich.',
    'field_venue_name' => 'Veranstaltungsort',
    'field_venue_address' => 'Adresse',
    'field_venue_city' => 'Stadt',
    'field_venue_country' => 'Land',
    'field_online_url' => 'Online-URL',
    'field_online_url_instructions' => 'Der Link, über den teilgenommen wird.',

    'validation_needs_location' => 'Ein Datum braucht einen Veranstaltungsort oder eine Online-URL.',
    'validation_ends_before_starts' => 'Das Ende kann nicht vor dem Beginn liegen.',

    'filter_any' => 'Alle',
    'filter_type' => 'Typ',
    'filter_status' => 'Status',
    'filter_visibility' => 'Sichtbarkeit',

    'col_title' => 'Titel',
    'col_type' => 'Typ',
    'col_next' => 'Nächstes Datum',
    'col_dates' => 'Daten',
    'col_status' => 'Status',
    'col_visibility' => 'Sichtbarkeit',

    // Die Spalten der Datumsangaben-Tabelle auf der Terminseite.
    'col_period' => 'Zeitraum',
    'col_timezone' => 'Zeitzone',
    'col_location' => 'Ort',

    'bulk_cancel_button' => 'Datum absagen|:count Datumsangaben absagen',
    'bulk_cancel_confirm' => 'Das Datum bleibt sichtbar und wird als abgesagt veröffentlicht, damit die Absage alle erreicht, die es bereits im Kalender haben.|Die :count Datumsangaben bleiben sichtbar und werden als abgesagt veröffentlicht, damit die Absage alle erreicht, die sie bereits im Kalender haben.',
    'bulk_cancelled' => 'Datum abgesagt.|:count Datumsangaben abgesagt.',
    'bulk_delete_button' => 'Datum löschen|:count Datumsangaben löschen',
    'bulk_delete_confirm' => 'Beim Löschen verschwindet das Datum vollständig. Abonnenten behalten es im Kalender. Wenn es jemand schon haben könnte, lieber absagen.|Beim Löschen verschwinden die :count Datumsangaben vollständig. Abonnenten behalten sie im Kalender. Wenn jemand sie schon haben könnte, lieber absagen.',
    'bulk_deleted' => 'Datum gelöscht.|:count Datumsangaben gelöscht.',

    'status_draft' => 'Entwurf',
    'status_published' => 'Veröffentlicht',
    'visibility_public' => 'Öffentlich',
    'visibility_unlisted' => 'Nicht gelistet',
    'visibility_private' => 'Privat',
    'occurrence_scheduled' => 'Geplant',
    'occurrence_cancelled' => 'Abgesagt',

    // Die Kennzahlen, die dieses Addon dem Insights-Dashboard anbietet, sofern
    // jenes Addon installiert ist. Siehe src/Integrations/Insights/.
    'metric_group' => 'Termine',
    'metric_published' => 'Veröffentlichte Termine',
    'metric_published_description' => 'Termine, die in diesem Zeitraum sichtbar wurden. Entwürfe zählen nicht, denn ein Termin, den niemand sehen kann, ist noch nichts geschehen.',
    'metric_occurrences' => 'Datumsangaben',
    'metric_occurrences_description' => 'Datumsangaben, die in diesen Zeitraum fallen, abgesagte eingeschlossen. Gezählt danach, wann sie stattfinden. Der Verlauf reicht deshalb in die Zukunft, wenn der Zeitraum es tut.',
    'metric_cancelled' => 'Abgesagte Datumsangaben',
    'metric_cancelled_description' => 'In diesem Zeitraum abgesagte Datumsangaben, gezählt am Tag der Absage und nicht am Tag, an dem sie stattgefunden hätten.',
    'metric_breakdown_type' => 'Typ',
    'metric_breakdown_status' => 'Status',
    'metric_no_type' => 'Ohne Typ',
    'metric_no_status' => 'Ohne Status',

    'empty_heading' => 'Termine, ihre Datumsangaben und ein Kalender-Feed zum Abonnieren.',
    'empty_create_description' => 'Ein Termin, so viele Datumsangaben wie nötig. Die Beschreibung wird einmal geschrieben.',
    'empty_docs_heading' => 'Dokumentation lesen',
    'empty_docs_description' => 'Antlers-Tags, der ICS-Feed und die vier Domain-Events, auf die andere Addons hören können.',
    'empty_dates_description' => 'Beginn, Ende und Ort. Alles Weitere steht schon am Termin.',

];
