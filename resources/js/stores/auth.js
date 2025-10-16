import { defineStore } from 'pinia';
import authService from '../services/authService';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: JSON.parse(localStorage.getItem('user')) || null,
        token: localStorage.getItem('token') || null,
        loading: false,
        error: null,
    }),

    getters: {
        /**
         * Check if user is authenticated
         */
        isAuthenticated: (state) => !!state.token,

        /**
         * Get current user
         */
        currentUser: (state) => state.user,

        /**
         * Get user's name
         */
        userName: (state) => state.user?.name || 'User',

        /**
         * Get user's email
         */
        userEmail: (state) => state.user?.email || '',

        /**
         * Get user initials for avatar
         */
        userInitials: (state) => {
            if (!state.user?.name) return 'U';
            const parts = state.user.name.split(' ');
            if (parts.length >= 2) {
                return parts[0][0] + parts[1][0];
            }
            return state.user.name.substring(0, 2).toUpperCase();
        },
    },

    actions: {
        /**
         * Login user
         */
        async login(credentials) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authService.login(credentials);
                this.token = response.data.token;
                this.user = response.data.user;

                localStorage.setItem('token', this.token);
                localStorage.setItem('user', JSON.stringify(this.user));

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Login failed';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Register new user
         */
        async register(userData) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authService.register(userData);
                this.token = response.data.token;
                this.user = response.data.user;

                localStorage.setItem('token', this.token);
                localStorage.setItem('user', JSON.stringify(this.user));

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Registration failed';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Logout user
         */
        async logout() {
            try {
                await authService.logout();
            } catch (error) {
                console.error('Logout error:', error);
            } finally {
                this.user = null;
                this.token = null;
                this.error = null;
                localStorage.removeItem('token');
                localStorage.removeItem('user');
            }
        },

        /**
         * Fetch current user data
         */
        async fetchUser() {
            try {
                const response = await authService.getUser();
                this.user = response.data;
                localStorage.setItem('user', JSON.stringify(this.user));
            } catch (error) {
                console.error('Fetch user error:', error);
                this.logout();
                throw error;
            }
        },

        /**
         * Update user profile
         */
        async updateProfile(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authService.updateProfile(data);
                this.user = response.data.user;
                localStorage.setItem('user', JSON.stringify(this.user));
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Profile update failed';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Change password
         */
        async changePassword(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authService.changePassword(data);
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Password change failed';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Clear error
         */
        clearError() {
            this.error = null;
        },
    },
});