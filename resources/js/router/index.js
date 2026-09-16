import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { isWorthRemembering } from './safeRedirect';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/LoginPage.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'settings',
        component: () => import('../pages/SettingsPage.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/organizations/:id',
        name: 'organization',
        component: () => import('../pages/OrganizationPage.vue'),
        props: true,
        meta: { requiresAuth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'settings' },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    // Session state is only known after the first API call. Without this check
    // a page reload would throw an already-signed-in user back to the login
    // screen.
    if (!auth.isReady) {
        await auth.fetchUser();
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        // Only record where the user was headed when it is somewhere other than
        // the default route; otherwise the address bar picks up a `?redirect=/`
        // that changes nothing
        return isWorthRemembering(to.fullPath)
            ? { name: 'login', query: { redirect: to.fullPath } }
            : { name: 'login' };
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'settings' };
    }

    return true;
});

export default router;
