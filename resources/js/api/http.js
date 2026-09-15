import axios from 'axios';

/**
 * HTTP client for talking to the API.
 *
 * withCredentials is mandatory: Sanctum in SPA mode authenticates requests with
 * a session cookie, and without sending cookies every request would look
 * anonymous.
 */
const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Normalises any error into a single shape.
 *
 * Components do not need to know what happened at the transport layer — they
 * need text for the user and, for forms, per-field errors.
 */
export function normaliseError(error) {
    if (error.response) {
        const { status, data } = error.response;

        return {
            status,
            message: data?.message ?? 'Запрос не удалось выполнить.',
            errors: data?.errors ?? {},
            code: data?.error?.code ?? null,
        };
    }

    if (error.request) {
        return {
            status: 0,
            message: 'Сервер не отвечает. Проверьте соединение.',
            errors: {},
            code: 'network',
        };
    }

    return { status: 0, message: error.message, errors: {}, code: null };
}

export default http;
