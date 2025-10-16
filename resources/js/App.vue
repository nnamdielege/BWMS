<template>
    <div id="app">
        <DashboardLayout v-if="authStore.isAuthenticated">
            <router-view />
        </DashboardLayout>
        <div v-else>
            <router-view />
        </div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from './stores/auth';
import { usePermissions } from '@/composables/usePermissions';
import DashboardLayout from './components/layout/DashboardLayout.vue';

const authStore = useAuthStore();
const { fetchPermissions, loading } = usePermissions();

onMounted(async () => {
    if (authStore.token) {
        fetchPermissions();
        await authStore.checkAuth();        
    }
});
</script>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

#app {
    min-height: 100vh;
}
</style>