<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'error' },
    title: { type: String, default: null },
});

const classes = computed(() => ({
    error: 'border-red-200 bg-red-50 text-red-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
}[props.variant]));
</script>

<template>
    <div class="rounded-lg border px-4 py-3 text-sm" :class="classes" role="alert">
        <p v-if="title" class="mb-1 font-semibold">{{ title }}</p>
        <slot />
    </div>
</template>
