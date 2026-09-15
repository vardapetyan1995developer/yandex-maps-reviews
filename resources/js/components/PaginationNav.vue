<script setup>
import { computed } from 'vue';

const props = defineProps({
    meta: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

/**
 * A window of pages around the current one, with ellipses.
 * With 12 pages we could render them all, but the review list is also meant for
 * cards that have more.
 */
const pages = computed(() => {
    const total = props.meta.last_page;
    const current = props.meta.current_page;

    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const result = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    if (start > 2) {
        result.push('…');
    }

    for (let page = start; page <= end; page += 1) {
        result.push(page);
    }

    if (end < total - 1) {
        result.push('…');
    }

    result.push(total);

    return result;
});

function select(page) {
    if (page === '…' || page === props.meta.current_page || props.disabled) {
        return;
    }

    emit('change', page);
}
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex flex-wrap items-center justify-between gap-4"
        aria-label="Навигация по страницам отзывов"
    >
        <p class="text-sm text-slate-500">
            Показаны {{ meta.from }}–{{ meta.to }} из {{ meta.total }}
        </p>

        <div class="flex items-center gap-1">
            <button
                type="button"
                class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition enabled:hover:bg-slate-50 disabled:opacity-40"
                :disabled="meta.current_page === 1 || disabled"
                @click="select(meta.current_page - 1)"
            >
                Назад
            </button>

            <button
                v-for="(page, index) in pages"
                :key="`${page}-${index}`"
                type="button"
                class="min-w-9 rounded-md px-3 py-1.5 text-sm transition"
                :class="page === meta.current_page
                    ? 'bg-slate-900 font-medium text-white'
                    : 'border border-slate-200 text-slate-600 enabled:hover:bg-slate-50'"
                :disabled="page === '…' || disabled"
                :aria-current="page === meta.current_page ? 'page' : undefined"
                @click="select(page)"
            >
                {{ page }}
            </button>

            <button
                type="button"
                class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition enabled:hover:bg-slate-50 disabled:opacity-40"
                :disabled="meta.current_page === meta.last_page || disabled"
                @click="select(meta.current_page + 1)"
            >
                Вперёд
            </button>
        </div>
    </nav>
</template>
