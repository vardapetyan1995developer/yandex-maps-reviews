import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { organizationsApi } from '../api';
import { normaliseError } from '../api/http';

export const useOrganizationsStore = defineStore('organizations', () => {
    const items = ref([]);
    const current = ref(null);

    const reviews = ref([]);
    const pagination = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null });

    const isLoading = ref(false);
    const isLoadingReviews = ref(false);
    const isSubmitting = ref(false);
    const error = ref(null);
    const fieldErrors = ref({});

    const hasOrganizations = computed(() => items.value.length > 0);

    // While background collection runs the interface must show progress rather
    // than an empty screen — hence a dedicated flag
    const isParsing = computed(
        () => current.value?.latest_parse_run?.is_running ?? false,
    );

    function reset() {
        error.value = null;
        fieldErrors.value = {};
    }

    async function fetchAll() {
        isLoading.value = true;
        reset();

        try {
            const { data } = await organizationsApi.list();
            items.value = data.data;
        } catch (e) {
            error.value = normaliseError(e).message;
        } finally {
            isLoading.value = false;
        }
    }

    async function connect(url) {
        isSubmitting.value = true;
        reset();

        try {
            const { data } = await organizationsApi.store(url);
            current.value = data.data;

            const index = items.value.findIndex((item) => item.id === data.data.id);
            if (index === -1) {
                items.value.unshift(data.data);
            } else {
                items.value[index] = data.data;
            }

            return data.data;
        } catch (e) {
            const normalised = normaliseError(e);
            error.value = normalised.message;
            fieldErrors.value = normalised.errors;

            return null;
        } finally {
            isSubmitting.value = false;
        }
    }

    async function fetchOne(id) {
        isLoading.value = true;
        reset();

        try {
            const { data } = await organizationsApi.show(id);
            current.value = data.data;
        } catch (e) {
            error.value = normaliseError(e).message;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Quiet refresh used while polling for progress.
     * It leaves the loading flag alone; otherwise the interface would flicker
     * every couple of seconds.
     */
    async function refreshStatus(id) {
        try {
            const { data } = await organizationsApi.show(id);
            current.value = data.data;

            return data.data;
        } catch {
            return null;
        }
    }

    async function startRefresh(id) {
        isSubmitting.value = true;
        reset();

        try {
            const { data } = await organizationsApi.refresh(id);
            current.value = data.data;
        } catch (e) {
            error.value = normaliseError(e).message;
        } finally {
            isSubmitting.value = false;
        }
    }

    async function fetchReviews(id, params = {}) {
        isLoadingReviews.value = true;

        try {
            const { data } = await organizationsApi.reviews(id, { per_page: 50, ...params });
            reviews.value = data.data;
            pagination.value = data.meta;
        } catch (e) {
            error.value = normaliseError(e).message;
            reviews.value = [];
        } finally {
            isLoadingReviews.value = false;
        }
    }

    async function remove(id) {
        await organizationsApi.destroy(id);
        items.value = items.value.filter((item) => item.id !== id);

        if (current.value?.id === id) {
            current.value = null;
            reviews.value = [];
        }
    }

    return {
        items,
        current,
        reviews,
        pagination,
        isLoading,
        isLoadingReviews,
        isSubmitting,
        isParsing,
        error,
        fieldErrors,
        hasOrganizations,
        fetchAll,
        fetchOne,
        refreshStatus,
        connect,
        startRefresh,
        fetchReviews,
        remove,
    };
});
