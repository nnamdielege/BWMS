<template>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="logo">
                    <svg class="w-12 h-12 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <h2 class="auth-title">Sign in to Inventory WMS</h2>
                <p class="auth-subtitle">Manage your inventory with ease</p>
            </div>

            <form @submit.prevent="handleLogin" class="auth-form">
                <!-- Error Alert -->
                <div v-if="error" class="alert alert-error">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ error }}</span>
                </div>

                <!-- Email Field -->
                <div class="form-group">
                    <label for="email" class="form-label">Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        class="form-input"
                        :class="{ 'input-error': errors.email }"
                        placeholder="you@example.com"
                    />
                    <p v-if="errors.email" class="error-message">{{ errors.email }}</p>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <div class="form-label-row">
                        <label for="password" class="form-label">Password</label>
                        <a href="#" class="text-link">Forgot password?</a>
                    </div>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="form-input"
                        :class="{ 'input-error': errors.password }"
                        placeholder="••••••••"
                    />
                    <p v-if="errors.password" class="error-message">{{ errors.password }}</p>
                </div>

                <!-- Remember Me -->
                <div class="form-checkbox">
                    <input
                        id="remember"
                        v-model="form.remember"
                        type="checkbox"
                        class="checkbox"
                    />
                    <label for="remember" class="checkbox-label">Remember me</label>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="loading"
                >
                    <span v-if="loading" class="spinner"></span>
                    <span>{{ loading ? 'Signing in...' : 'Sign in' }}</span>
                </button>
            </form>

            <!-- Footer -->
            <div class="auth-footer">
                <p>
                    Don't have an account?
                    <router-link to="/register" class="text-link">Sign up</router-link>
                </p>
            </div>

            <!-- Demo Credentials -->
            <div class="demo-credentials">
                <p class="demo-title">Demo Credentials:</p>
                <p class="demo-text">Email: admin@inventorywms.com</p>
                <p class="demo-text">Password: password</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';

const router = useRouter();
const authStore = useAuthStore();

const form = reactive({
    email: '',
    password: '',
    remember: false,
});

const loading = ref(false);
const error = ref('');
const errors = reactive({
    email: '',
    password: '',
});

const handleLogin = async () => {
    // Reset errors
    error.value = '';
    errors.email = '';
    errors.password = '';

    // Validate
    if (!form.email) {
        errors.email = 'Email is required';
        return;
    }
    if (!form.password) {
        errors.password = 'Password is required';
        return;
    }

    loading.value = true;

    try {
        await authStore.login({
            email: form.email,
            password: form.password,
        });

        router.push('/');
    } catch (err) {
        error.value = err.response?.data?.message || 'Invalid email or password';
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.auth-container {
    @apply min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-100 via-white to-purple-100 py-12 px-4 sm:px-6 lg:px-8;
}

.auth-card {
    @apply w-full max-w-md bg-white rounded-2xl shadow-xl p-8;
}

.auth-header {
    @apply text-center mb-8;
}

.logo {
    @apply flex justify-center mb-4;
}

.auth-title {
    @apply text-3xl font-bold text-gray-900 mb-2;
}

.auth-subtitle {
    @apply text-gray-600;
}

.auth-form {
    @apply space-y-6;
}

.alert {
    @apply p-4 rounded-lg flex items-center gap-3;
}

.alert-error {
    @apply bg-red-50 text-red-800 border border-red-200;
}

.form-group {
    @apply space-y-2;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}

.form-label-row {
    @apply flex items-center justify-between;
}

.form-input {
    @apply w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all;
}

.input-error {
    @apply border-red-500 focus:ring-red-500;
}

.error-message {
    @apply text-sm text-red-600;
}

.form-checkbox {
    @apply flex items-center gap-2;
}

.checkbox {
    @apply w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500;
}

.checkbox-label {
    @apply text-sm text-gray-700;
}

.btn {
    @apply w-full flex items-center justify-center gap-2 px-4 py-3 rounded-lg font-medium transition-all;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed;
}

.spinner {
    @apply w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin;
}

.text-link {
    @apply text-sm font-medium text-indigo-600 hover:text-indigo-500;
}

.auth-footer {
    @apply mt-6 text-center text-sm text-gray-600;
}

.demo-credentials {
    @apply mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200;
}

.demo-title {
    @apply text-xs font-semibold text-gray-700 mb-2;
}

.demo-text {
    @apply text-xs text-gray-600;
}
</style>