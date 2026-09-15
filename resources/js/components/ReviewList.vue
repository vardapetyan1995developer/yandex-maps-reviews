<script setup>
import ReviewCard from './ReviewCard.vue';
import LoadingSpinner from './LoadingSpinner.vue';

defineProps({
    reviews: { type: Array, required: true },
    isLoading: { type: Boolean, default: false },
});
</script>

<template>
    <div>
        <LoadingSpinner v-if="isLoading" label="Загружаем отзывы…" />

        <p
            v-else-if="reviews.length === 0"
            class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500"
        >
            Отзывов пока нет.
        </p>

        <div v-else class="space-y-3">
            <ReviewCard
                v-for="review in reviews"
                :key="review.id"
                :review="review"
            />
        </div>
    </div>
</template>
