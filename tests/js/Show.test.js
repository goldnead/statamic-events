import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../../resources/js/pages/Events/Show.vue';
import { router } from './stubs/inertia.js';

const occurrence = {
    id: 1,
    starts_at: 'Wed, 15 Jul 2026 19:00',
    ends_at: 'Wed, 15 Jul 2026 21:00',
    timezone: 'Europe/Berlin',
    all_day: false,
    status: 'scheduled',
    cancelled: false,
    location: 'Alte Oper, Frankfurt',
    online: false,
    ics_url: '/!/events/occurrences/abc.ics',
    edit_url: '/cp/events/occurrences/1/edit',
    cancel_url: '/cp/events/occurrences/1/cancel',
    delete_url: '/cp/events/occurrences/1',
};

const props = {
    event: {
        id: 1,
        title: 'Chorworkshop',
        slug: 'chorworkshop',
        type: 'Workshop',
        status: 'published',
        statusLabel: 'Published',
        visibility: 'public',
        visibilityLabel: 'Public',
        timezone: 'Europe/Berlin',
        description: 'Two days on vowel shaping.',
    },
    occurrences: [occurrence],
    editUrl: '/cp/events/1/edit',
    deleteUrl: '/cp/events/1',
    indexUrl: '/cp/events',
    addOccurrenceUrl: '/cp/events/1/occurrences/create',
    feedUrl: '/!/events/calendar.ics',
    canManage: true,
};

beforeEach(() => router.reset());

describe('the event detail page', () => {
    it('prints the timezone next to every date', () => {
        // A date rendered in its own zone without saying which one is a date the
        // reader has to guess at.
        const wrapper = mount(Show, { props });

        expect(wrapper.text()).toContain('Wed, 15 Jul 2026 19:00');
        expect(wrapper.text()).toContain('Europe/Berlin');
    });

    it('hides every write control from a user who may not manage events', () => {
        const wrapper = mount(Show, { props: { ...props, canManage: false } });
        const text = wrapper.text();

        expect(text).not.toContain('events::cp.cancel_date');
        expect(text).not.toContain('events::cp.delete_event');
        // …but the read-only affordances stay.
        expect(text).toContain('events::cp.download_ics');
    });

    it('asks before cancelling and posts through the Inertia router', async () => {
        const wrapper = mount(Show, { props });

        await wrapper
            .findAll('[data-stub="Button"]')
            .find((button) => button.text().includes('events::cp.cancel_date'))
            .trigger('click');

        const modal = wrapper.find('[data-stub="ConfirmationModal"]');

        // Confirming a destructive action is not optional, and the modal is core's
        // so it joins the portal and esc-key stacks.
        expect(modal.exists()).toBe(true);
        expect(router.calls).toHaveLength(0);

        await modal.find('[data-role="confirm"]').trigger('click');

        expect(router.calls).toEqual([
            { method: 'post', url: '/cp/events/occurrences/1/cancel', data: {}, options: { preserveScroll: true } },
        ]);
    });

    it('keeps cancelling a date and deleting the event behind separate confirmations', async () => {
        // One shared "pending action" modal is a modal that will eventually mean
        // the wrong one.
        const wrapper = mount(Show, { props });

        await wrapper
            .findAll('[data-stub="Button"]')
            .find((button) => button.text().includes('events::cp.delete_event'))
            .trigger('click');

        const modals = wrapper.findAll('[data-stub="ConfirmationModal"]');

        expect(modals).toHaveLength(1);
        expect(modals[0].attributes('data-title')).toBe('events::cp.delete_event');

        await modals[0].find('[data-role="confirm"]').trigger('click');

        expect(router.calls).toEqual([{ method: 'delete', url: '/cp/events/1', options: {} }]);
    });

    it('says so rather than showing an empty list when an event has no dates', () => {
        const wrapper = mount(Show, { props: { ...props, occurrences: [] } });

        expect(wrapper.text()).toContain('events::cp.no_dates');
    });

    it('shows the subscribable feed URL, because that is what people actually copy', () => {
        expect(mount(Show, { props }).text()).toContain('/!/events/calendar.ics');
    });
});
