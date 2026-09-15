<script setup>
defineProps({
    label: { type: String, default: 'Загрузка…' },
});
</script>

<template>
    <div class="flex items-center justify-center gap-3 py-10 text-sm text-slate-500">
        <span
            class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-600"
            aria-hidden="true"
        />
        <span>{{ label }}</span>
    </div>
</template>
