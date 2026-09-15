<script setup>
import { computed } from 'vue';
import AlertMessage from './AlertMessage.vue';

const props = defineProps({
    run: { type: Object, default: null },
    organization: { type: Object, required: true },
});

const progress = computed(() => props.run?.progress ?? null);

const isRunning = computed(() => props.run?.is_running ?? false);

const hasError = computed(() => Boolean(props.run?.error));

/**
 * Collection stopped at the source's output limit — this is not an error.
 * It gets its own calm message so the user does not confuse it with a genuine
 * failure.
 */
const isDepthLimited = computed(
    () => props.run?.result?.truncation_reason === 'source_depth_limit',
);
</script>

<template>
    <div class="space-y-3">
        <div
            v-if="isRunning"
            class="rounded-lg border border-sky-200 bg-sky-50 p-4"
        >
            <div class="flex items-center justify-between text-sm text-sky-900">
                <span class="font-medium">Собираем отзывы…</span>
                <span>{{ progress?.percent ?? 0 }}%</span>
            </div>

            <div class="mt-2 h-2 overflow-hidden rounded-full bg-sky-100">
                <div
                    class="h-full rounded-full bg-sky-500 transition-all duration-500"
                    :style="{ width: `${progress?.percent ?? 0}%` }"
                />
            </div>

            <p class="mt-2 text-xs text-sky-700">
                Страниц обработано: {{ progress?.pages_fetched ?? 0 }},
                отзывов получено: {{ progress?.reviews_found ?? 0 }}
                <template v-if="progress?.total_expected">
                    из {{ progress.total_expected }}
                </template>
            </p>
        </div>

        <AlertMessage v-if="hasError" variant="error" :title="run.error.label">
            <p>{{ run.error.message }}</p>

            <p v-if="run.error.code === 'schema_changed'" class="mt-2 text-xs">
                Источник изменил формат данных. Сбор остановлен намеренно, чтобы
                не записать в базу пустые или искажённые записи.
            </p>

            <p v-else-if="run.error.code === 'blocked'" class="mt-2 text-xs">
                Площадка временно ограничила доступ. Повторная попытка будет
                выполнена автоматически с увеличенной паузой.
            </p>
        </AlertMessage>

        <AlertMessage
            v-else-if="isDepthLimited && !isRunning"
            variant="info"
            title="Загружены не все отзывы"
        >
            Яндекс отдаёт не более {{ organization.reviews_stored }} последних отзывов
            по карточке. Компания заявляет {{ organization.reviews_count }} —
            остальные недоступны через публичную выдачу, а не потеряны при сборе.
        </AlertMessage>
    </div>
</template>
