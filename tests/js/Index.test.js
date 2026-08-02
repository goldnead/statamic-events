import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Events/Index.vue';
import { router } from './stubs/inertia.js';

/*
 * The two Control Panel pages are the only surface no PHP test can reach, so
 * their own decisions are tested here: what the listing is fed, what the empty
 * state offers, and whether a user without `manage events` is shown a create
 * button they cannot use.
 */

const props = {
    initialColumns: [{ field: 'title', label: 'Title' }],
    filters: [{ handle: 'events_type' }],
    hasAny: true,
    listingUrl: '/cp/events',
    createUrl: '/cp/events/create',
    canCreate: true,
    perPage: 50,
};

beforeEach(() => router.reset());

describe('the events index', () => {
    it('feeds the listing the props that decide whether it is a real listing', () => {
        const wrapper = mount(Index, { props });
        const listing = wrapper.findComponent({ name: 'Listing' });

        expect(listing.exists()).toBe(true);
        expect(listing.props('url')).toBe('/cp/events');
        // No preferences-prefix means no saved views and no persisted columns —
        // the difference between a table and the Entries screen.
        expect(listing.props('preferencesPrefix')).toBe('events');
        expect(listing.props('filters')).toHaveLength(1);
        expect(listing.props('perPage')).toBe(50);
        // Server mode. `items` would silently switch it to client mode, which has
        // no pagination at all.
        expect(listing.props('items')).toBeUndefined();
    });

    it('offers no bulk actions, because there is no action controller behind them', () => {
        // actionUrl is the hard gate for checkboxes and the bulk toolbar. Passing
        // one without the two action routes behind it produces a toolbar that
        // does nothing.
        const listing = mount(Index, { props }).findComponent({ name: 'Listing' });

        expect(listing.props('actionUrl')).toBeUndefined();
    });

    it('shows the empty state instead of a listing when there is nothing yet', () => {
        const wrapper = mount(Index, { props: { ...props, hasAny: false } });

        expect(wrapper.findComponent({ name: 'Listing' }).exists()).toBe(false);
        expect(wrapper.find('[data-stub="EmptyStateMenu"]').exists()).toBe(true);
        // Core's empty state is a centred h1, not <Header>.
        expect(wrapper.find('header h1').exists()).toBe(true);
    });

    it('hides the create action from a user who may not manage events', () => {
        const readOnly = mount(Index, { props: { ...props, canCreate: false } });

        expect(readOnly.findComponent({ name: 'CommandPaletteItem' }).exists()).toBe(false);

        const readOnlyEmpty = mount(Index, { props: { ...props, hasAny: false, canCreate: false } });

        expect(readOnlyEmpty.findAll('[data-stub="EmptyStateItem"]').length).toBe(1);
    });

    it('wraps the primary action in a command palette item, the way every core page does', () => {
        const wrapper = mount(Index, { props });
        const palette = wrapper.findComponent({ name: 'CommandPaletteItem' });

        expect(palette.exists()).toBe(true);
        expect(palette.props('url')).toBe('/cp/events/create');
    });

    it('refreshes through the Inertia router, not through axios', () => {
        const wrapper = mount(Index, { props });

        wrapper.findComponent({ name: 'Listing' }).vm.$emit('refreshing');

        expect(router.calls).toEqual([{ method: 'reload', options: {} }]);
    });
});
