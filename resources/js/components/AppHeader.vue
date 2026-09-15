<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

async function handleLogout() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
            <RouterLink
                :to="{ name: 'settings' }"
                class="text-lg font-semibold text-slate-900 hover:text-slate-700"
            >
                Отзывы с карт
            </RouterLink>

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
