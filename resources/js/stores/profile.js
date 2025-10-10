import { defineStore } from 'pinia';
import profileService from '../services/profileService';

export const useProfileStore = defineStore('profile', {
    state: () => ({
        user: null,
        loading: false,
        error: null,
    }),

    actions: {
        async fetchProfile() {
            this.loading = true;
            this.error = null;

            try {
                const response = await profileService.getProfile();
                this.user = response.data.data || response.data;
                return this.user;
            } catch (error) {
                console.error('Fetch profile error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch profile';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateProfile(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await profileService.updateProfile(data);
                this.user = response.data.data || response.data;
                return this.user;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update profile';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updatePassword(data) {
            this.loading = true;
            this.error = null;

            try {
                await profileService.updatePassword(data);
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update password';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async uploadAvatar(file) {
            this.loading = true;
            this.error = null;

            try {
                const formData = new FormData();
                formData.append('avatar', file);

                const response = await profileService.uploadAvatar(formData);
                
                // Update user avatar
                if (this.user) {
                    this.user.avatar = response.data.data.avatar;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to upload avatar';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async deleteAvatar() {
            this.loading = true;
            this.error = null;

            try {
                await profileService.deleteAvatar();
                
                // Clear user avatar
                if (this.user) {
                    this.user.avatar = null;
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete avatar';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        clearError() {
            this.error = null;
        },
    },

    getters: {
        avatarUrl: (state) => {
            if (!state.user?.avatar) return null;
            const baseUrl = import.meta.env.VITE_API_URL || window.location.origin;
            return `${baseUrl}/storage/${state.user.avatar}`;
        },
    },
});