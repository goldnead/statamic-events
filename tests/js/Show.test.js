import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Show from '../../resources/js/pages/Events/Show.vue';
import { router } from './stubs/inertia.js';
import { requests } from './stubs/save-pipeline.js';
import { dirty } from './stubs/api.js';

const occurrence = {
    id: 1,
    // The sortable value, not the readable one. Client-side sorting compares the
    // raw field, so "Wed, 15 Jul 2026" would sort by weekday.
    starts_at: '2026-07-15 19:00',
    // No `ends_at`: the end lives in the label, and no column reads it alone.
    period_label: 'Wed, 15 Jul 2026 19:00 – 21:00',
    timezone: 'Europe/Berlin',
    all_day: false,
    status: 'scheduled',
    status_label: 'Scheduled',
    cancelled: false,
    location: 'Alte Oper, Frankfurt',
    online: false,
    ics_url: '/!/events/occurrences/abc.ics',
    edit_url: '/cp/events/occurrences/1/edit',
};

// Dieselbe Nutzlast, die core's eigene PublishForm-Seite bekommt — der Server
// baut sie in EventController::formPayload().
const form = {
    blueprint: {
        tabs: [
            { handle: 'main', display: 'Details', sections: [{ fields: [{ handle: 'title' }] }] },
            { handle: 'sidebar', display: 'Settings', sections: [{ fields: [{ handle: 'status' }] }] },
        ],
    },
    values: { title: 'Chorworkshop', slug: 'chorworkshop', status: 'published' },
    meta: { title: {}, slug: {}, status: {} },
    submitUrl: '/cp/events/1',
};

const props = {
    event: {
        id: 1,
        title: 'Chorworkshop',
    },
    form,
    occurrences: [occurrence],
    occurrenceColumns: [
        { field: 'starts_at', label: 'When', sortable: true, visible: true },
        { field: 'timezone', label: 'Timezone', sortable: true, visible: true },
        { field: 'location', label: 'Location', sortable: true, visible: true },
        { field: 'status', label: 'Status', sortable: true, visible: true },
    ],
    occurrenceActionUrl: '/cp/events/occurrences/actions',
    deleteUrl: '/cp/events/1',
    indexUrl: '/cp/events',
    addOccurrenceUrl: '/cp/events/1/occurrences/create',
    feedUrl: '/!/events/calendar.ics',
    canManage: true,
};

const listing = (wrapper) => wrapper.findComponent({ name: 'Listing' });

// Der Datums-Stack holt sein Formular ueber `fetch`. Ohne Antwort bleibt der
// Stack leer, und jeder Test darueber wuerde das Falsche belegen.
const occurrenceForm = {
    title: 'Datum hinzufuegen',
    blueprint: { tabs: [{ handle: 'main', display: 'Date', sections: [{ fields: [{ handle: 'starts_at' }] }] }] },
    values: { starts_at: null },
    meta: { starts_at: {} },
    submitUrl: '/cp/events/1/occurrences',
    submitMethod: 'POST',
};

beforeEach(() => {
    router.reset();
    requests.reset();
    dirty.reset();

    globalThis.fetch = vi.fn(() =>
        Promise.resolve({ ok: true, json: () => Promise.resolve(occurrenceForm) })
    );
});

describe('the event detail page', () => {
    it('shows the dates in core listing rather than a stack of cards', () => {
        const table = listing(mount(Show, { props }));

        expect(table.exists()).toBe(true);
        // Client mode: the dates arrive complete as a prop. A `url` here would
        // mean a second route that does not exist.
        expect(table.props('items')).toHaveLength(1);
        expect(table.props('url')).toBeUndefined();
        expect(table.props('columns')).toHaveLength(4);
    });

    it('sorts the window by a value that is actually chronological', () => {
        // The column sorts on `starts_at`, so that field has to carry the ISO
        // value and the readable one has to travel beside it.
        const table = listing(mount(Show, { props }));
        const [first] = table.props('items');

        expect(table.props('sortColumn')).toBe('starts_at');
        expect(first.starts_at).toMatch(/^\d{4}-\d{2}-\d{2}/);
        expect(first.period_label).toContain('Wed, 15 Jul 2026');
    });

    it('prints the timezone next to every date', () => {
        // A date rendered in its own zone without saying which one is a date the
        // reader has to guess at.
        const wrapper = mount(Show, { props });
        const cells = wrapper.findAll('[data-cell]').map((cell) => cell.text());

        expect(cells.join(' ')).toContain('Wed, 15 Jul 2026 19:00 – 21:00');
        expect(cells.join(' ')).toContain('Europe/Berlin');
        expect(cells.join(' ')).toContain('Alte Oper, Frankfurt');
    });

    it('wires the row menu to the two navigations and leaves the rest to core', () => {
        // Cancelling and deleting a date are registered actions now. Repeating
        // them by hand here would print each one twice, because core appends the
        // server actions after this slot.
        const wrapper = mount(Show, { props });
        const items = wrapper.findAll('[data-stub="DropdownItem"]').map((item) => item.text());

        expect(items).toContain('events::cp.download_ics');
        expect(items).toContain('events::cp.edit');
        expect(items).not.toContain('events::cp.cancel_date');
        expect(items).not.toContain('events::cp.delete_date');
    });

    it('feeds the listing the action url, which is what earns the checkboxes', () => {
        // No action-url means no checkbox column and no bulk bar, whatever
        // allow-bulk-actions says.
        const table = listing(mount(Show, { props }));

        expect(table.props('actionUrl')).toBe('/cp/events/occurrences/actions');
        expect(table.props('allowBulkActions')).toBe(true);
    });

    it('reloads through the Inertia router when an action finishes', () => {
        // A client-side listing has no URL to re-fetch from, so its own refresh
        // does nothing. This is what actually puts a cancelled date on screen —
        // and the reason neither action declares a redirect, which would cost
        // the success toast.
        const wrapper = mount(Show, { props });

        listing(wrapper).vm.$emit('refreshing');

        expect(router.calls).toEqual([{ method: 'reload', options: { preserveScroll: true } }]);
    });

    it('offers a read-only user neither the checkboxes nor the write actions', () => {
        const wrapper = mount(Show, { props: { ...props, canManage: false } });
        const table = listing(wrapper);
        const text = wrapper.text();

        expect(table.props('actionUrl')).toBeUndefined();
        expect(table.props('allowBulkActions')).toBe(false);
        expect(text).not.toContain('events::cp.delete_event');
        // …but the read-only affordances stay.
        expect(text).toContain('events::cp.download_ics');
    });

    it('keeps deleting the event behind its own confirmation', async () => {
        // Deleting the event lives in the header's "…" menu, apart from anything
        // that acts on a single date.
        const wrapper = mount(Show, { props });

        await wrapper
            .findAll('[data-stub="DropdownItem"]')
            .find((item) => item.text().includes('events::cp.delete_event'))
            .trigger('click');

        const modals = wrapper.findAll('[data-stub="ConfirmationModal"]');

        expect(modals).toHaveLength(1);
        expect(modals[0].attributes('data-title')).toBe('events::cp.delete_event');

        await modals[0].find('[data-role="confirm"]').trigger('click');

        expect(router.calls).toEqual([{ method: 'delete', url: '/cp/events/1', options: {} }]);
    });

    it('offers a way forward rather than an empty table when an event has no dates', () => {
        const wrapper = mount(Show, { props: { ...props, occurrences: [] } });

        expect(listing(wrapper).exists()).toBe(false);
        expect(wrapper.find('[data-stub="EmptyStateMenu"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('events::cp.no_dates');
        // Kein `href` mehr: der Einstieg oeffnet den Stack, statt auf eine
        // eigene Seite zu navigieren.
        expect(wrapper.find('[data-stub="EmptyStateItem"]').attributes('href')).toBeUndefined();
    });

    it('shows the subscribable feed URL, because that is what people actually copy', () => {
        expect(mount(Show, { props }).text()).toContain('/!/events/calendar.ics');
    });

    it('is the form itself rather than a read-only card with an edit button', () => {
        // F02: vorher standen hier Schluessel-Wert-Paare und ein Knopf auf eine
        // zweite Seite. Jetzt traegt die Seite den Blueprint, den auch der
        // Speichern-Weg benutzt.
        const wrapper = mount(Show, { props });
        const container = wrapper.findComponent({ name: 'PublishContainer' });

        expect(container.exists()).toBe(true);
        expect(container.props('blueprint')).toStrictEqual(form.blueprint);
        expect(container.props('meta')).toStrictEqual(form.meta);
        expect(container.props('readOnly')).toBe(false);
        expect(wrapper.findComponent({ name: 'PublishTabs' }).exists()).toBe(true);
    });

    it('saves the event through core save pipeline, to the URL the server named', async () => {
        // Nicht ueber einen eigenen axios-Aufruf: die Pipeline besitzt den
        // Fehler-Toast, die 422-Feldfehler und den Dirty-Zustand.
        const wrapper = mount(Show, { props });

        await wrapper
            .findAll('[data-stub="Button"]')
            .find((button) => button.text() === 'Save')
            .trigger('click');

        expect(requests.calls).toEqual([{ url: '/cp/events/1', method: 'patch' }]);
    });

    it('shows a read-only user the same fields, without the way to save them', () => {
        // Eine zweite, lesende Darstellung derselben Werte waere eine zweite
        // Wahrheit — es sind dieselben Felder, nur gesperrt.
        const wrapper = mount(Show, { props: { ...props, canManage: false } });

        expect(wrapper.findComponent({ name: 'PublishContainer' }).props('readOnly')).toBe(true);
        expect(wrapper.findAll('[data-stub="Button"]').some((b) => b.text() === 'Save')).toBe(false);
    });

    it('opens a date in a stack instead of navigating to a page of its own', async () => {
        // F05: "Datum hinzufuegen" fuehrte auf eine eigene Seite. Adrians
        // Rangfolge: ein Stack ist der Kompromiss, eine Extraseite ist es nie.
        const wrapper = mount(Show, { props });

        expect(wrapper.find('[data-stub="Stack"]').exists()).toBe(false);

        await wrapper
            .findAll('[data-stub="Button"]')
            .find((button) => button.text() === 'events::cp.add_date_short')
            .trigger('click');

        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledWith(
            '/cp/events/1/occurrences/create',
            expect.objectContaining({ headers: expect.objectContaining({ Accept: 'application/json' }) })
        );
        expect(wrapper.find('[data-stub="Stack"]').attributes('data-title')).toBe('Datum hinzufuegen');
        expect(router.calls).toEqual([]);
    });

    it('edits a date in the same stack, from the URL the row carries', async () => {
        const wrapper = mount(Show, { props });

        await wrapper
            .findAll('[data-stub="DropdownItem"]')
            .find((item) => item.text() === 'events::cp.edit')
            .trigger('click');

        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledWith(
            '/cp/events/occurrences/1/edit',
            expect.anything()
        );
        expect(wrapper.find('[data-stub="Stack"]').exists()).toBe(true);
    });

    it('reloads the dates after the stack saved, so the table shows what was written', async () => {
        const wrapper = mount(Show, { props });

        await wrapper
            .findAll('[data-stub="Button"]')
            .find((button) => button.text() === 'events::cp.add_date_short')
            .trigger('click');

        await flushPromises();

        await wrapper
            .find('[data-stub="Stack"]')
            .findAll('button')
            .find((button) => button.text() === 'Save')
            .trigger('click');

        await flushPromises();

        expect(requests.calls).toEqual([{ url: '/cp/events/1/occurrences', method: 'POST' }]);
        expect(router.calls).toEqual([{ method: 'reload', options: { preserveScroll: true } }]);
        expect(wrapper.find('[data-stub="Stack"]').exists()).toBe(false);
    });
});
