import { defineStore } from 'pinia';
import authService from '../services/authService';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: localStorage.getItem('token'),
        loading: false,
        error: null,
    }),

    getters: {
        isAuthenticated: (state) => !!state.token && !!state.user,
    },

    actions: {
        async login(credentials) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authService.login(credentials);
                
                this.token = response.data.token;
                this.user = response.data.user;
                
                localStorage.setItem('token', this.token);
                
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Login failed';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async register(userData) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authService.register(userData);
                
                this.token = response.data.token;
                this.user = response.data.user;
                
                localStorage.setItem('token', this.token);
                
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Registration failed';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async logout() {
            this.loading = true;

            try {
                await authService.logout();
            } catch (error) {
                console.error('Logout error:', error);
            } finally {
                this.user = null;
                this.token = null;
                localStorage.removeItem('token');
                this.loading = false;
            }
        },

        // Add the missing checkAuth method
        async checkAuth() {
            if (!this.token) {
                this.user = null;
                return;
            }

            this.loading = true;

            try {
                const response = await authService.getUser();
                this.user = response.data;
            } catch (error) {
                console.error('Check auth error:', error);
                this.user = null;
                this.token = null;
                localStorage.removeItem('token');
            } finally {
                this.loading = false;
            }
        },

        clearError() {
            this.error = null;
        },
    },
});