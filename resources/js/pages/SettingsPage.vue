<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useOrganizationsStore } from '../stores/organizations';
import { useFormatters } from '../composables/useFormatters';
import AlertMessage from '../components/AlertMessage.vue';
import LoadingSpinner from '../components/LoadingSpinner.vue';

const store = useOrganizationsStore();
const router = useRouter();
const { formatDate, formatNumber } = useFormatters();

const url = ref('');

const statusStyles = {
    success: 'bg-emerald-50 text-emerald-700',
    partial: 'bg-amber-50 text-amber-700',
    failed: 'bg-red-50 text-red-700',
    running: 'bg-sky-50 text-sky-700',
    queued: 'bg-sky-50 text-sky-700',
    pending: 'bg-slate-100 text-slate-600',
};

onMounted(() => store.fetchAll());

async function handleSubmit() {
    const organization = await store.connect(url.value);

    if (organization) {
        url.value = '';
        // Go straight to the card: collection runs in the background, and
        // progress belongs there rather than on the settings page
        router.push({ name: 'organization', params: { id: organization.id } });
    }
}

async function handleRemove(organization) {
    await store.remove(organization.id);
}
</script>

<template>
    <div class="space-y-8">
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h1 class="text-lg font-semibold text-slate-900">Подключение организации</h1>
            <p class="mt-1 text-sm text-slate-500">
                Вставьте ссылку на карточку организации в Яндекс.Картах — например,
                <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">
                    https://yandex.ru/maps/org/название/1234567890/
                </code>
            </p>

            <form class="mt-5 space-y-3" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <input
                        v-model="url"
                        type="url"
                        required
                        placeholder="https://yandex.ru/maps/org/…"
                        class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900 focus:ring-1 focus:ring-slate-900"
                        :class="{ 'border-red-400': store.fieldErrors.url }"
                    >

                    <button
                        type="submit"
                        class="rounded-md bg-slate-900 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="store.isSubmitting"
                    >
                        {{ store.isSubmitting ? 'Сохраняем…' : 'Сохранить' }}
                    </button>
                </div>

                <p v-if="store.fieldErrors.url" class="text-xs text-red-600">
                    {{ store.fieldErrors.url[0] }}
                </p>

                <AlertMessage v-else-if="store.error" variant="error">
                    {{ store.error }}
                </AlertMessage>
            </form>
        </section>

        <section>
            <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500">
                Подключённые карточки
            </h2>

            <LoadingSpinner v-if="store.isLoading && !store.hasOrganizations" />

            <p
                v-else-if="!store.hasOrganizations"
                class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500"
            >
                Пока ничего не подключено.
            </p>

            <ul v-else class="space-y-3">
                <li
                    v-for="organization in store.items"
                    :key="organization.id"
                    class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4"
                >
                    <div class="min-w-0">
                        <RouterLink
                            :to="{ name: 'organization', params: { id: organization.id } }"
                            class="text-sm font-medium text-slate-900 hover:underline"
                        >
                            {{ organization.name || 'Загружается…' }}
                        </RouterLink>

                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ organization.address || organization.url }}
                        </p>

                        <p class="mt-1 text-xs text-slate-400">
                            <template v-if="organization.last_parsed_at">
                                Обновлено {{ formatDate(organization.last_parsed_at) }} ·
                                {{ formatNumber(organization.reviews_stored) }} отзывов
                            </template>
                            <template v-else>
                                Данные ещё не собраны
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="statusStyles[organization.parse_status]"
                        >
                            {{ organization.parse_status_label }}
                        </span>

                        <button
                            type="button"
                            class="text-xs text-slate-400 transition hover:text-red-600"
                            @click="handleRemove(organization)"
                        >
                            Удалить
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
