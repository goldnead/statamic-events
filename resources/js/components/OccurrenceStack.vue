<script setup>
import { computed, ref, useTemplateRef, watch } from 'vue';
import { Button, PublishContainer, PublishTabs, Stack } from '@statamic/cms/ui';
import { Pipeline, Request } from '@statamic/cms/save-pipeline';
import { dirty } from '@statamic/cms/api';

/**
 * Ein Datum anlegen oder bearbeiten, ohne die Terminseite zu verlassen.
 *
 * Vorher fuehrten beide Wege auf eine eigene Seite. Adrians Rangfolge: die
 * editierbare Detailseite ist erste Wahl, ein Stack ist der Kompromiss, eine
 * Extraseite ist es nie. Fuer den Termin selbst ist die erste Wahl moeglich
 * (siehe Show.vue); ein Datum ist ein eigener Datensatz mit eigenem Blueprint
 * und bekommt deshalb den Stack.
 *
 * Das Formular wird nicht nachgebaut. `Statamic\CP\PublishForm` liefert auf eine
 * JSON-Anfrage genau die Nutzlast, die es sonst seiner eigenen Vue-Seite gibt —
 * Blueprint, Werte, Metadaten, Ziel-URL. Der Stack holt sie beim Oeffnen und
 * uebergibt sie an core's `PublishContainer`. Die Route bleibt unveraendert und
 * beantwortet weiterhin auch eine gewoehnliche Anfrage; die Oberflaeche
 * navigiert nur nicht mehr dorthin.
 *
 * Warum `fetch` und nicht axios: dieses Addon fuehrt axios nicht als
 * Abhaengigkeit, und ein mitgebuendeltes zweites axios haette die
 * Interceptoren des Control Panels nicht. Es ist ein GET ohne CSRF-Bedarf.
 * Das Speichern laeuft danach ueber core's Save-Pipeline, die die CP-eigene
 * axios-Instanz benutzt — samt Fehler-Toast und 422-Feldfehlern.
 */
const props = defineProps({
    // Solange null, ist der Stack zu. Ein Wechsel der URL bei offenem Stack
    // laedt das andere Datum nach.
    url: { type: String, default: null },
});

const emit = defineEmits(['closed', 'saved']);

const form = ref(null);
const values = ref({});
const errors = ref({});
const saving = ref(false);
const loading = ref(false);
const container = useTemplateRef('container');

const open = computed(() => props.url !== null);

// Ein fester Name reicht: es ist immer hoechstens ein Datums-Stack offen, und
// core's Dirty-Registry wird beim Schliessen wieder freigegeben.
const containerName = 'events-occurrence';

watch(() => props.url, (url) => (url ? load(url) : reset()), { immediate: true });

async function load(url) {
    loading.value = true;
    form.value = null;
    errors.value = {};

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (! response.ok) throw new Error(response.statusText);

        form.value = await response.json();
        values.value = { ...form.value.values };
    } catch (e) {
        // Ein Stack, der nichts anzeigen kann, bleibt nicht leer stehen.
        window.Statamic?.$toast?.error?.(__('Something went wrong'));
        emit('closed');
    } finally {
        loading.value = false;
    }
}

function reset() {
    form.value = null;
    values.value = {};
    errors.value = {};
    dirty.remove(containerName);
}

/**
 * Ungespeichertes geht nicht still verloren.
 *
 * Core's eigener Inline-Publish-Stack fragt an dieser Stelle nach; hier reicht
 * die Browser-Rueckfrage, weil der Stack sonst keinen eigenen Zustand haelt.
 */
function shouldClose() {
    if (! dirty.has(containerName)) return true;

    return window.confirm(__('Are you sure? Unsaved changes will be lost.'));
}

function save() {
    new Pipeline()
        .provide({ container, errors, saving })
        .through([new Request(form.value.submitUrl, form.value.submitMethod)])
        .then(() => {
            window.Statamic?.$toast?.success?.(__('Saved'));
            emit('saved');
        })
        // Die Pipeline meldet einen Fehlschlag schon selbst (Toast und
        // Feldfehler). Ohne diesen Zweig bliebe eine unbehandelte Promise
        // uebrig, die in der Konsole als Fehler steht, den niemand ausloesen
        // kann.
        .catch(() => {});
}
</script>

<template>
    <Stack
        :open="open"
        :title="form?.title ?? ''"
        icon="calendar"
        size="half"
        :before-close="shouldClose"
        @closed="emit('closed')"
    >
        <template #header-actions>
            <Button variant="primary" :text="__('Save')" :disabled="saving || ! form" @click="save" />
        </template>

        <PublishContainer
            v-if="form"
            ref="container"
            :name="containerName"
            :blueprint="form.blueprint"
            :meta="form.meta"
            :errors="errors"
            v-model="values"
        >
            <PublishTabs />
        </PublishContainer>
    </Stack>
</template>
