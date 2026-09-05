<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    ButtonGroup,
    Card,
    CommandPaletteItem,
    ConfirmationModal,
    Description,
    DocsCallout,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Heading,
    Icon,
    Listing,
    Panel,
    PanelHeader,
    Subheading,
} from '@statamic/cms/ui';

const props = defineProps({
    event: { type: Object, required: true },
    occurrences: { type: Array, default: () => [] },
    occurrenceColumns: { type: Array, default: () => [] },
    occurrenceActionUrl: { type: String, default: null },
    editUrl: { type: String, required: true },
    deleteUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    addOccurrenceUrl: { type: String, required: true },
    feedUrl: { type: String, required: true },
    canManage: { type: Boolean, default: false },
});

// Only the event itself still confirms here. Cancelling and deleting a single
// date used to have a hand-built modal each; they are core actions now
// (Goldnead\Events\Actions\*), so their confirmation, their button text and
// their toast come from the same place whether the editor picked one row or
// checked five.
const deletingEvent = ref(false);

// Every mutation goes through the Inertia router, never axios: the router owns
// the progress bar, the flash toast, the dirty-state guard and back-button
// behaviour, and a bare axios call silently opts out of all four.
function confirmDeleteEvent() {
    router.delete(props.deleteUrl);
    deletingEvent.value = false;
}
</script>

<template>
    <Head :title="[event.title, __('events::cp.title')]" />

    <!--
        `max-w-page`, not core's narrower publish-form width. This screen carries
        a table now, and a table is what decides the width it needs: measured in
        the playground, the four columns plus the checkbox and the "…" menu want
        729px and the narrow variant left 661 — the actions column was scrolled
        out of sight, which is a "…" menu nobody can reach.
        data-max-width-wrapper keeps the header's own full-width toggle working;
        a bare max-w-* ignores it.
    -->
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="event.title" icon="calendar">
            <ButtonGroup role="group" :aria-label="__('events::cp.event_actions')">
                <Button :href="indexUrl" :text="__('events::cp.back_to_events')" variant="ghost" />
                <!-- Deleting the event used to be a `Button variant="danger"`
                     halfway down the page, under the calendar feed. A
                     destructive page action belongs in the header's "…" menu:
                     core reserves `danger` for the confirm button inside a
                     modal, and a delete buried in body copy is both hard to
                     find and easy to hit by accident. -->
                <Dropdown v-if="canManage">
                    <DropdownMenu>
                        <DropdownItem
                            :text="__('events::cp.delete_event')"
                            icon="trash"
                            variant="destructive"
                            @click="deletingEvent = true"
                        />
                    </DropdownMenu>
                </Dropdown>
                <CommandPaletteItem
                    v-if="canManage"
                    category="Actions"
                    :text="__('events::cp.add_date_short')"
                    icon="calendar"
                    :url="addOccurrenceUrl"
                    prioritize
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </ButtonGroup>
        </Header>

        <!--
            Stacked, not side by side, and that is a correction rather than a
            preference. This addon ships no stylesheet of its own, so the only
            utilities that exist are the ones Statamic core already emits — and
            core emits neither of the two responsive grid classes the earlier
            two-column layout was built on. Grepping the playground's built CSS
            finds them in `statamic-marketing` and `statamic-clientrooms`: the
            side-by-side arrangement only ever appeared because a sibling addon
            happened to be installed, and a customer running this addon alone got
            the stacked fallback anyway.

            Stacking on purpose costs nothing here and buys the table the full
            width, which is what the dates actually need: four columns plus the
            checkbox and the "…" menu want 729px, and two thirds of a detail
            screen was 661.
        -->
        <div>
            <Panel class="min-w-0 flex flex-col">
                <PanelHeader class="flex items-center justify-between min-h-10">
                    <Heading>{{ __('events::cp.dates') }}</Heading>
                    <Button
                        v-if="canManage"
                        :href="addOccurrenceUrl"
                        :text="__('events::cp.add_date_short')"
                        size="sm"
                    />
                </PanelHeader>

                <EmptyStateMenu
                    v-if="occurrences.length === 0"
                    :heading="__('events::cp.no_dates')"
                    class="flex-1"
                >
                    <EmptyStateItem
                        v-if="canManage"
                        :href="addOccurrenceUrl"
                        icon="calendar"
                        :heading="__('events::cp.add_date_short')"
                        :description="__('events::cp.empty_dates_description')"
                    />
                </EmptyStateMenu>

                <!--
                    Client-side mode. The dates arrive complete as an Inertia prop,
                    so there is nothing to page through and no second route to
                    fetch from — `:items` is the mode core built for exactly that.
                    Search, filters, presets and the column picker are off because
                    an embedded panel with its own toolbar reads as a second
                    screen; the column headers still sort, which is what a table
                    of dates is actually asked to do.

                    `action-url` is the hard gate for both the checkbox column and
                    the "…" menu's server actions, so a read-only user gets
                    neither. Cancelling and deleting arrive through it; only the
                    two navigations are prepended by hand below.
                -->
                <Listing
                    v-else
                    :items="occurrences"
                    :columns="occurrenceColumns"
                    :action-url="canManage ? occurrenceActionUrl : undefined"
                    :allow-bulk-actions="canManage"
                    :allow-search="false"
                    :allow-presets="false"
                    :allow-customizing-columns="false"
                    sort-column="starts_at"
                    sort-direction="asc"
                >
                    <template #cell-starts_at="{ row }">
                        <span class="whitespace-nowrap">{{ row.period_label }}</span>
                        <Badge
                            v-if="row.all_day"
                            size="sm"
                            pill
                            color="gray"
                            class="ms-2"
                            :text="__('events::cp.all_day')"
                        />
                    </template>

                    <!-- The zone is a column of its own. A date rendered in its own
                         zone without saying which one is a date the reader has to
                         guess at. -->
                    <template #cell-timezone="{ value }">
                        <Badge size="sm" pill color="gray" :text="value" />
                    </template>

                    <!-- Plain text rather than MiddleEllipsis: that component
                         measures its container, and in a table cell with no
                         width of its own it measures zero and renders the whole
                         address as a single "…". An address is allowed to wrap. -->
                    <template #cell-location="{ row, value }">
                        <span v-if="value" class="flex items-start gap-1.5">
                            <Icon
                                :name="row.online ? 'earth' : 'pin'"
                                class="size-3.5 shrink-0 mt-0.5"
                            />
                            <span>{{ value }}</span>
                        </span>
                    </template>

                    <template #cell-status="{ row }">
                        <Badge
                            size="sm"
                            pill
                            :color="row.cancelled ? 'red' : 'gray'"
                            :text="row.status_label"
                        />
                    </template>

                    <!-- Prepended, not replacing: core appends the registered
                         actions after these, which is where cancelling and
                         deleting a date come from. -->
                    <template #prepended-row-actions="{ row }">
                        <DropdownItem
                            :text="__('events::cp.download_ics')"
                            icon="download"
                            :href="row.ics_url"
                        />
                        <DropdownItem
                            v-if="canManage"
                            :text="__('events::cp.edit')"
                            icon="edit"
                            :href="row.edit_url"
                        />
                    </template>
                </Listing>
            </Panel>

            <Panel class="min-w-0 mt-6 flex flex-col">
                <PanelHeader class="flex items-center justify-between min-h-10">
                    <Heading>{{ __('events::cp.tab_settings') }}</Heading>
                    <Button
                        v-if="canManage"
                        :href="editUrl"
                        :text="__('events::cp.edit')"
                        size="sm"
                    />
                </PanelHeader>

                <Card class="flex-1">
                    <div class="flex flex-wrap gap-2">
                        <Badge :prepend="__('events::cp.field_type')" :text="event.type" />
                        <Badge :prepend="__('events::cp.field_status')" :text="event.statusLabel" />
                        <Badge
                            :prepend="__('events::cp.field_visibility')"
                            :text="event.visibilityLabel"
                        />
                        <Badge :prepend="__('events::cp.field_timezone')" :text="event.timezone" />
                    </div>

                    <Subheading class="mt-4">{{ __('events::cp.field_slug') }}</Subheading>
                    <Description><code class="text-xs">{{ event.slug }}</code></Description>

                    <template v-if="event.description">
                        <Subheading class="mt-4">{{ __('events::cp.field_description') }}</Subheading>
                        <Description class="whitespace-pre-line">{{ event.description }}</Description>
                    </template>

                    <Subheading class="mt-4">{{ __('events::cp.calendar_feed') }}</Subheading>
                    <Description>
                        <a :href="feedUrl" class="break-all">{{ feedUrl }}</a>
                    </Description>

                </Card>
            </Panel>
        </div>

        <DocsCallout
            :topic="__('events::cp.title')"
            url="https://github.com/goldnead/statamic-events#readme"
        />

        <!-- Core's overlay, not a bespoke one: core modals join the portal stack,
             the esc-key stack and FocusScope trapping. A hand-built fixed inset-0
             steals esc from its parent and z-fights with everything above it. -->
        <ConfirmationModal
            :open="deletingEvent"
            :title="__('events::cp.delete_event')"
            :body-text="__('events::cp.delete_event_confirm')"
            :button-text="__('events::cp.delete')"
            danger
            @update:open="(open) => (deletingEvent = open)"
            @confirm="confirmDeleteEvent"
        />
    </div>
</template>
