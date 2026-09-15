<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useOrganizationsStore } from '../stores/organizations';
import { usePolling } from '../composables/usePolling';
import { useFormatters } from '../composables/useFormatters';
import RatingSummary from '../components/RatingSummary.vue';
import ReviewList from '../components/ReviewList.vue';
import PaginationNav from '../components/PaginationNav.vue';
import ParseProgress from '../components/ParseProgress.vue';
import AlertMessage from '../components/AlertMessage.vue';
import LoadingSpinner from '../components/LoadingSpinner.vue';

const props = defineProps({
    id: { type: [String, Number], required: true },
});

const store = useOrganizationsStore();
const { formatDate } = useFormatters();

const sort = ref('date_desc');
const ratingFilter = ref('');

const organization = computed(() => store.current);
const latestRun = computed(() => organization.value?.latest_parse_run ?? null);

/**
 * While the job runs, status is polled every two seconds.
 * The callback returns false once collection finishes, which stops the polling
 * so the page does not keep firing requests for nothing.
 */
const { start: startPolling, stop: stopPolling } = usePolling(async () => {
    const updated = await store.refreshStatus(props.id);

    if (!updated?.latest_parse_run?.is_running) {
        await loadReviews(1);

        return false;
    }

    return true;
}, 2000);

async function loadReviews(page = 1) {
    await store.fetchReviews(props.id, {
        page,
        sort: sort.value,
        ...(ratingFilter.value ? { rating: Number(ratingFilter.value) } : {}),
    });
}

async function handlePageChange(page) {
    await loadReviews(page);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function handleRefresh() {
    await store.startRefresh(props.id);
    startPolling();
}

// Changing the sort or filter always returns to page one: otherwise you could
// end up on page ten of a three-page result set
watch([sort, ratingFilter], () => loadReviews(1));

onMounted(async () => {
    await store.fetchOne(props.id);
    await loadReviews(1);

    if (latestRun.value?.is_running) {
        startPolling();
    }
});
</script>

<template>
    <div class="space-y-6">
        <RouterLink
            :to="{ name: 'settings' }"
            class="inline-flex items-center text-sm text-slate-500 transition hover:text-slate-900"
        >
            ← К списку организаций
        </RouterLink>

        <LoadingSpinner v-if="store.isLoading && !organization" />

        <AlertMessage v-else-if="!organization" variant="error">
            Организация не найдена.
        </AlertMessage>

        <template v-else>
            <RatingSummary :organization="organization" />

            <ParseProgress :run="latestRun" :organization="organization" />

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <select
                        v-model="sort"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-slate-900"
                        aria-label="Сортировка отзывов"
                    >
                        <option value="date_desc">Сначала новые</option>
                        <option value="date_asc">Сначала старые</option>
                        <option value="rating_desc">Высокая оценка</option>
                        <option value="rating_asc">Низкая оценка</option>
                    </select>

                    <select
                        v-model="ratingFilter"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-slate-900"
                        aria-label="Фильтр по оценке"
                    >
                        <option value="">Все оценки</option>
                        <option v-for="value in 5" :key="value" :value="value">
                            {{ value }} ★
                        </option>
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <span v-if="organization.last_parsed_at" class="text-xs text-slate-400">
                        Обновлено {{ formatDate(organization.last_parsed_at) }}
                    </span>

                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                        :disabled="store.isSubmitting || latestRun?.is_running"
                        @click="handleRefresh"
                    >
                        Обновить данные
                    </button>
                </div>
            </div>

            <ReviewList :reviews="store.reviews" :is-loading="store.isLoadingReviews" />

            <PaginationNav
                :meta="store.pagination"
                :disabled="store.isLoadingReviews"
                @change="handlePageChange"
            />
        </template>
    </div>
</template>
