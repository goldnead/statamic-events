<script setup>
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    CommandPaletteItem,
    DocsCallout,
    DropdownItem,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
} from '@statamic/cms/ui';

const props = defineProps({
    initialColumns: { type: Array, required: true },
    filters: { type: Array, default: () => [] },
    hasAny: { type: Boolean, default: false },
    listingUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    canCreate: { type: Boolean, default: false },
    perPage: { type: Number, default: 50 },
});

// Nothing here is a config value. The page receives urls, labels and booleans
// that the server already decided on — handing a config array to the browser is
// how a token ends up in a page's props.
function reload() {
    router.reload();
}
</script>

<template>
    <Head :title="__('events::cp.title')" />

    <div class="max-w-page mx-auto">
        <template v-if="!hasAny">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1
                    class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3"
                >
                    <Icon name="calendar" class="size-5 text-gray-500" />{{ __('events::cp.title') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="__('events::cp.empty_heading')">
                <EmptyStateItem
                    v-if="canCreate"
                    :href="createUrl"
                    icon="calendar"
                    :heading="__('events::cp.create_event')"
                    :description="__('events::cp.empty_create_description')"
                />
                <EmptyStateItem
                    icon="book-open-cover"
                    :heading="__('events::cp.empty_docs_heading')"
                    :description="__('events::cp.empty_docs_description')"
                    href="https://github.com/goldnead/statamic-events#readme"
                    target="_blank"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('events::cp.title')" icon="calendar">
                <CommandPaletteItem
                    v-if="canCreate"
                    category="Actions"
                    :text="__('events::cp.create_event')"
                    icon="calendar"
                    :url="createUrl"
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </Header>

            <!--
                Server mode: the listing re-requests the same route as JSON on
                every search, sort, filter and page change.

                No action-url is passed. Bulk actions would need an ActionController
                and a set of Actions, and the destructive one of those — deleting an
                event with its dates — is not something to offer behind a checkbox
                before it can be offered behind a confirmation.
            -->
            <Listing
                :url="listingUrl"
                :columns="initialColumns"
                :filters="filters"
                :per-page="perPage"
                preferences-prefix="events"
                sort-column="title"
                sort-direction="asc"
                push-query
                @refreshing="reload"
            >
                <template #cell-title="{ row }">
                    <Link :href="row.show_url">{{ row.title }}</Link>
                </template>

                <template #cell-next_occurrence="{ row }">
                    <span v-if="row.next_occurrence" class="whitespace-nowrap">
                        {{ row.next_occurrence }}
                        <span class="text-gray-500">{{ row.next_timezone }}</span>
                    </span>
                    <span v-else class="text-gray-500">{{ __('events::cp.no_upcoming') }}</span>
                </template>

                <template #cell-status="{ row }">
                    <Badge
                        size="sm"
                        pill
                        :color="row.status === 'published' ? 'green' : 'gray'"
                        :text="row.status_label"
                    />
                </template>

                <template #cell-visibility="{ row }">
                    <Badge
                        size="sm"
                        pill
                        :color="row.visibility === 'public' ? 'blue' : 'amber'"
                        :text="row.visibility_label"
                    />
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('events::cp.view')" icon="eye" :href="row.show_url" />
                    <DropdownItem
                        v-if="canCreate"
                        :text="__('events::cp.edit')"
                        icon="edit"
                        :href="row.edit_url"
                    />
                </template>
            </Listing>
        </template>

        <DocsCallout
            :topic="__('events::cp.title')"
            url="https://github.com/goldnead/statamic-events#readme"
        />
    </div>
</template>
