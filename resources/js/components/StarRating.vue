<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: null },
    size: { type: String, default: 'sm' },
});

const stars = computed(() => {
    const rating = Math.round(props.value ?? 0);

    return Array.from({ length: 5 }, (_, index) => index < rating);
});

const sizeClass = computed(() => (props.size === 'lg' ? 'h-5 w-5' : 'h-4 w-4'));
</script>

<template>
    <div
        class="flex items-center gap-0.5"
        :aria-label="value ? `Оценка ${value} из 5` : 'Без оценки'"
    >
        <svg
            v-for="(filled, index) in stars"
            :key="index"
            :class="[sizeClass, filled ? 'text-amber-400' : 'text-slate-300']"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M10 1.5l2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8L1.5 7.7l5.9-.9L10 1.5z" />
        </svg>
    </div>
</template>
