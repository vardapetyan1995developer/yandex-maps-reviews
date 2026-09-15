<script setup>
import { computed } from 'vue';
import StarRating from './StarRating.vue';
import { useFormatters } from '../composables/useFormatters';

const props = defineProps({
    organization: { type: Object, required: true },
});

const { formatNumber, pluralize } = useFormatters();

/**
 * Ratings and reviews are shown separately and never summed: they are different
 * user actions on the platform.
 */
const metrics = computed(() => [
    {
        key: 'ratings',
        value: formatNumber(props.organization.ratings_count),
        label: pluralize(props.organization.ratings_count, ['оценка', 'оценки', 'оценок']),
        hint: 'Поставили звёзды',
    },
    {
        key: 'reviews',
        value: formatNumber(props.organization.reviews_count),
        label: pluralize(props.organization.reviews_count, ['отзыв', 'отзыва', 'отзывов']),
        hint: 'Написали текст',
    },
    {
        key: 'stored',
        value: formatNumber(props.organization.reviews_stored),
        label: 'собрано',
        hint: 'Загружено в систему',
    },
]);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">
                    {{ organization.name || 'Организация' }}
                </h1>

                <p v-if="organization.address" class="mt-1 text-sm text-slate-500">
                    {{ organization.address }}
                </p>

                <div v-if="organization.categories?.length" class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-for="category in organization.categories"
                        :key="category"
                        class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600"
                    >
                        {{ category }}
                    </span>
                </div>
            </div>

            <div class="text-right">
                <div class="flex items-center justify-end gap-2">
                    <span class="text-3xl font-semibold text-slate-900">
                        {{ organization.rating ?? '—' }}
                    </span>
                    <StarRating :value="organization.rating" size="lg" />
                </div>
                <p class="mt-1 text-xs text-slate-500">средний рейтинг</p>
            </div>
        </div>

        <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-slate-100 pt-6 sm:grid-cols-3">
            <div v-for="metric in metrics" :key="metric.key">
                <dt class="text-xs uppercase tracking-wide text-slate-400">
                    {{ metric.hint }}
                </dt>
                <dd class="mt-1">
                    <span class="text-2xl font-semibold text-slate-900">{{ metric.value }}</span>
                    <span class="ml-1 text-sm text-slate-500">{{ metric.label }}</span>
                </dd>
            </div>
        </dl>
    </section>
</template>
