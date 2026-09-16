<script setup>
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

async function handleLogout() {
    await auth.logout();

    // replace, not push: the page just left sits behind a guard, and leaving it
    // in history means Back lands on a screen that bounces the user straight
    // out again — which is how `?redirect=/` ends up in the address bar
    router.replace({ name: 'login' });
}
</script>

<template>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
            <nav class="flex items-center gap-6" aria-label="Основная навигация">
                <RouterLink
                    :to="{ name: 'settings' }"
                    class="text-lg font-semibold text-slate-900 hover:text-slate-700"
                >
                    Отзывы с карт
                </RouterLink>

                <!-- An explicit menu item: the brand link above leads to the same
                     place, but nothing tells the user that. Highlighted on both
                     the list itself and a card, since a card belongs to it. -->
                <RouterLink
                    :to="{ name: 'settings' }"
                    class="text-sm font-medium transition"
                    :class="['settings', 'organization'].includes(route.name)
                        ? 'text-slate-900 underline underline-offset-8 decoration-2'
                        : 'text-slate-500 hover:text-slate-900'"
                >
                    Организации
                </RouterLink>
            </nav>

            <div class="flex items-center gap-4">
                <span class="hidden text-sm text-slate-500 sm:inline">
                    {{ auth.user?.email }}
                </span>

                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                    @click="handleLogout"
                >
                    Выйти
                </button>
            </div>
        </div>
    </header>
</template>
