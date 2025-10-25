<template>
    <div id="app">
        <!-- Loading while checking auth -->
        <div v-if="checkingAuth" class="auth-loading">
            <div class="spinner"></div>
            <p>Initializing...</p>
        </div>

        <!-- Authenticated user - show dashboard layout -->
        <DashboardLayout v-else-if="authStore.isAuthenticated">
            <router-view />
        </DashboardLayout>

        <!-- Not authenticated - show router content (login/register/pricing) -->
        <div v-else>
            <router-view />
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useAuthStore } from './stores/auth';
import { usePermissions } from '@/composables/usePermissions';
import axios from 'axios';
import DashboardLayout from './components/layout/DashboardLayout.vue';

const authStore = useAuthStore();
const { fetchPermissions } = usePermissions();
const checkingAuth = ref(false);

onMounted(async () => {
    // Use environment variable, fallback to relative URL
    const apiUrl = import.meta.env.VITE_API_BASE_URL || '';
    
    axios.defaults.baseURL = apiUrl;
    axios.defaults.headers.common['Accept'] = 'application/json';
    axios.defaults.headers.common['Content-Type'] = 'application/json';

    console.log('Axios configured:', axios.defaults.baseURL);

    // If user has a token, restore it in axios
    if (authStore.token) {
        axios.defaults.headers.common['Authorization'] = `Bearer ${authStore.token}`;
        console.log('Token restored to axios');
    }

    // Check auth on app initialization
    if (authStore.token) {
        checkingAuth.value = true;
        
        try {
            console.log('Checking auth status...');
            
            // Check auth
            await authStore.checkAuth();
            
            // Fetch permissions
            await fetchPermissions();
            
            console.log('✅ Auth and permissions loaded');
        } catch (error) {
            console.error('Error during initialization:', error);
        } finally {
            checkingAuth.value = false;
        }
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

.auth-loading {
    @apply min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-indigo-100 via-white to-purple-100;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.auth-loading p {
    @apply text-gray-600 text-lg font-medium;
}
</style>