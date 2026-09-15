<script setup>
import StarRating from './StarRating.vue';
import { useFormatters } from '../composables/useFormatters';

defineProps({
    review: { type: Object, required: true },
});

const { formatDate } = useFormatters();
</script>

<template>
    <article class="rounded-lg border border-slate-200 bg-white p-5">
        <header class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <img
                    v-if="review.author.avatar"
                    :src="review.author.avatar"
                    :alt="review.author.name"
                    class="h-9 w-9 rounded-full bg-slate-100 object-cover"
                    loading="lazy"
                >
                <span
                    v-else
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-sm font-medium text-slate-500"
                    aria-hidden="true"
                >
                    {{ review.author.name.charAt(0) }}
                </span>

                <div>
                    <p class="text-sm font-medium text-slate-900">{{ review.author.name }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(review.published_at) }}</p>
                </div>
            </div>

            <div class="flex flex-col items-end gap-1">
                <StarRating :value="review.rating" />
                <span
                    v-if="review.is_edited"
                    class="text-xs text-slate-400"
                    title="Текст или оценка менялись между парсингами"
                >
                    изменён
                </span>
            </div>
        </header>

        <p v-if="review.text" class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">
            {{ review.text }}
        </p>

        <p v-else class="mt-3 text-sm italic text-slate-400">
            Оценка без текста
        </p>
    </article>
</template>
