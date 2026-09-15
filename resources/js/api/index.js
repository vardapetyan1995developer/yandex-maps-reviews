import http from './http';

/**
 * The single place where API addresses are declared.
 *
 * Components call named methods and know nothing about routes or response
 * shapes — a contract change is made here and nowhere else.
 */
export const authApi = {
    login: (credentials) => http.post('/login', credentials),
    logout: () => http.post('/logout'),
    me: () => http.get('/me'),
};

export const organizationsApi = {
    list: () => http.get('/organizations'),
    store: (url) => http.post('/organizations', { url }),
    show: (id) => http.get(`/organizations/${id}`),
    refresh: (id) => http.post(`/organizations/${id}/refresh`),
    destroy: (id) => http.delete(`/organizations/${id}`),
    reviews: (id, params) => http.get(`/organizations/${id}/reviews`, { params }),
};
