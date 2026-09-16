<script setup>
import { reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import AlertMessage from '../components/AlertMessage.vue';
import { safeRedirect } from '../router/safeRedirect';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = reactive({
    email: '',
    password: '',
});

async function handleSubmit() {
    const success = await auth.login(form);

    if (success) {
        // Back to wherever the user was headed, after checking the value is an
        // internal path: it comes from the address bar and is attacker-controlled.
        // replace, not push, so Back does not return to the sign-in form.
        router.replace(safeRedirect(route.query.redirect));
    }
}
</script>

<template>
    <div class="mx-auto max-w-sm">
        <div class="rounded-xl border border-slate-200 bg-white p-8">
            <h1 class="text-xl font-semibold text-slate-900">Вход</h1>
            <p class="mt-1 text-sm text-slate-500">
                Войдите, чтобы подключить карточку организации.
            </p>

            <form class="mt-6 space-y-4" @submit.prevent="handleSubmit">
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">
                        Email
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900 focus:ring-1 focus:ring-slate-900"
                        :class="{ 'border-red-400': auth.fieldErrors.email }"
                    >
                    <p v-if="auth.fieldErrors.email" class="mt-1 text-xs text-red-600">
                        {{ auth.fieldErrors.email[0] }}
                    </p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">
                        Пароль
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-slate-900 focus:ring-1 focus:ring-slate-900"
                        :class="{ 'border-red-400': auth.fieldErrors.password }"
                    >
                    <p v-if="auth.fieldErrors.password" class="mt-1 text-xs text-red-600">
                        {{ auth.fieldErrors.password[0] }}
                    </p>
                </div>

                <AlertMessage v-if="auth.error && !auth.fieldErrors.email" variant="error">
                    {{ auth.error }}
                </AlertMessage>

                <button
                    type="submit"
                    class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
                    :disabled="auth.isLoading"
                >
                    {{ auth.isLoading ? 'Проверяем…' : 'Войти' }}
                </button>
            </form>
        </div>
    </div>
</template>
