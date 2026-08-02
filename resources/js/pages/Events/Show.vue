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
    Header,
    Heading,
    Icon,
    Panel,
    PanelHeader,
    Subheading,
} from '@statamic/cms/ui';

const props = defineProps({
    event: { type: Object, required: true },
    occurrences: { type: Array, default: () => [] },
    editUrl: { type: String, required: true },
    deleteUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    addOccurrenceUrl: { type: String, required: true },
    feedUrl: { type: String, required: true },
    canManage: { type: Boolean, default: false },
});

// Two separate confirmations rather than one shared "pending action": a modal
// that can mean either "delete this date" or "delete the whole event" is a modal
// that will eventually mean the wrong one.
const cancelling = ref(null);
const deletingOccurrence = ref(null);
const deletingEvent = ref(false);

// Every mutation goes through the Inertia router, never axios: the router owns
// the progress bar, the flash toast, the dirty-state guard and back-button
// behaviour, and a bare axios call silently opts out of all four.
function confirmCancel() {
    if (!cancelling.value) return;
    router.post(cancelling.value.cancel_url, {}, { preserveScroll: true });
    cancelling.value = null;
}

function confirmDeleteOccurrence() {
    if (!deletingOccurrence.value) return;
    router.delete(deletingOccurrence.value.delete_url, { preserveScroll: true });
    deletingOccurrence.value = null;
}

function confirmDeleteEvent() {
    router.delete(props.deleteUrl);
    deletingEvent.value = false;
}
</script>

<template>
    <Head :title="[event.title, __('events::cp.title')]" />

    <!-- Core's narrow variant for detail screens. data-max-width-wrapper keeps the
         header's own full-width toggle working; a bare max-w-* ignores it. -->
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="event.title" icon="calendar">
            <ButtonGroup role="group" :aria-label="__('events::cp.event_actions')">
                <Button :href="indexUrl" :text="__('events::cp.back_to_events')" variant="ghost" />
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Panel class="lg:col-span-2 h-full flex flex-col">
                <PanelHeader class="flex items-center justify-between min-h-10">
                    <Heading>{{ __('events::cp.dates') }}</Heading>
                    <Button
                        v-if="canManage"
                        :href="addOccurrenceUrl"
                        :text="__('events::cp.add_date_short')"
                        size="sm"
                    />
                </PanelHeader>

                <Card class="flex-1">
                    <Description v-if="occurrences.length === 0">
                        {{ __('events::cp.no_dates') }}
                    </Description>

                    <ul v-else class="divide-y divide-content-border">
                        <li
                            v-for="occurrence in occurrences"
                            :key="occurrence.id"
                            class="py-3 flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span
                                        class="font-medium"
                                        :class="occurrence.cancelled ? 'line-through text-gray-500' : ''"
                                    >
                                        {{ occurrence.starts_at }}
                                        <template v-if="occurrence.ends_at">
                                            – {{ occurrence.ends_at }}
                                        </template>
                                    </span>
                                    <!-- The zone is always shown next to the time. A date
                                         rendered in its own zone without saying which one
                                         is a date the reader has to guess at. -->
                                    <Badge size="sm" pill color="gray" :text="occurrence.timezone" />
                                    <Badge
                                        v-if="occurrence.all_day"
                                        size="sm"
                                        pill
                                        color="gray"
                                        :text="__('events::cp.all_day')"
                                    />
                                    <Badge
                                        v-if="occurrence.cancelled"
                                        size="sm"
                                        pill
                                        color="red"
                                        :text="__('events::cp.cancelled')"
                                    />
                                </div>
                                <Description v-if="occurrence.location" class="mt-1">
                                    <Icon
                                        :name="occurrence.online ? 'globe' : 'map-pin'"
                                        class="size-3.5 inline"
                                    />
                                    {{ occurrence.location }}
                                </Description>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <Button
                                    :href="occurrence.ics_url"
                                    :text="__('events::cp.download_ics')"
                                    size="sm"
                                    variant="ghost"
                                    icon="download"
                                />
                                <Button
                                    v-if="canManage"
                                    :href="occurrence.edit_url"
                                    :text="__('events::cp.edit')"
                                    size="sm"
                                    variant="ghost"
                                />
                                <Button
                                    v-if="canManage && !occurrence.cancelled"
                                    :text="__('events::cp.cancel_date')"
                                    size="sm"
                                    variant="ghost"
                                    @click="cancelling = occurrence"
                                />
                                <Button
                                    v-if="canManage"
                                    :text="__('events::cp.delete')"
                                    size="sm"
                                    variant="ghost"
                                    @click="deletingOccurrence = occurrence"
                                />
                            </div>
                        </li>
                    </ul>
                </Card>
            </Panel>

            <Panel class="h-full flex flex-col">
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

                    <div v-if="canManage" class="mt-6">
                        <Button
                            variant="danger"
                            size="sm"
                            :text="__('events::cp.delete_event')"
                            @click="deletingEvent = true"
                        />
                    </div>
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
            :open="cancelling !== null"
            :title="__('events::cp.cancel_date')"
            :body-text="__('events::cp.cancel_date_confirm')"
            :button-text="__('events::cp.cancel_date')"
            danger
            @update:open="(open) => (open ? null : (cancelling = null))"
            @confirm="confirmCancel"
        />

        <ConfirmationModal
            :open="deletingOccurrence !== null"
            :title="__('events::cp.delete_date')"
            :body-text="__('events::cp.delete_date_confirm')"
            :button-text="__('events::cp.delete')"
            danger
            @update:open="(open) => (open ? null : (deletingOccurrence = null))"
            @confirm="confirmDeleteOccurrence"
        />

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
