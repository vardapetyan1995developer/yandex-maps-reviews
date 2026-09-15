<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from './stores/auth';
import AppHeader from './components/AppHeader.vue';

const auth = useAuthStore();

onMounted(() => {
    if (!auth.isReady) {
        auth.fetchUser();
    }
});
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <AppHeader v-if="auth.isAuthenticated" />

        <main class="mx-auto max-w-5xl px-4 py-8">
            <RouterView v-slot="{ Component }">
                <Transition name="fade" mode="out-in">
                    <component :is="Component" />
                </Transition>
            </RouterView>
        </main>
    </div>
</template>
