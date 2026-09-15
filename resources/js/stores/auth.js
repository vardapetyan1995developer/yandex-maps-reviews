import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { authApi } from '../api';
import http, { normaliseError } from '../api/http';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const isLoading = ref(false);
    const error = ref(null);
    const fieldErrors = ref({});

    // Whether the session has been checked on boot. Without this flag the
    // router would bounce an already-signed-in user to the login page on reload
    const isReady = ref(false);

    const isAuthenticated = computed(() => user.value !== null);

    /**
     * Sanctum requires the CSRF cookie to be fetched first: without it any POST
     * is rejected as a cross-site request.
     */
    async function ensureCsrfCookie() {
        await http.get('/sanctum/csrf-cookie', { baseURL: '' });
    }

    async function login(credentials) {
        isLoading.value = true;
        error.value = null;
        fieldErrors.value = {};

        try {
            await ensureCsrfCookie();
            const { data } = await authApi.login(credentials);
            user.value = data.data;

            return true;
        } catch (e) {
            const normalised = normaliseError(e);
            error.value = normalised.message;
            fieldErrors.value = normalised.errors;

            return false;
        } finally {
            isLoading.value = false;
        }
    }

    async function logout() {
        try {
            await authApi.logout();
        } finally {
            // Local state is cleared regardless: if the server session has
            // already expired the request fails, but the user must still be
            // signed out
            user.value = null;
        }
    }

    /**
     * Restores the session when the application boots.
     * A 401 here means "not signed in", which is normal rather than an error.
     */
    async function fetchUser() {
        try {
            const { data } = await authApi.me();
            user.value = data.data;
        } catch {
            user.value = null;
        } finally {
            isReady.value = true;
        }
    }

    return {
        user,
        isLoading,
        isReady,
        error,
        fieldErrors,
        isAuthenticated,
        login,
        logout,
        fetchUser,
    };
});
