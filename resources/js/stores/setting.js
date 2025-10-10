import { defineStore } from 'pinia';
import settingService from '../services/settingService';

export const useSettingStore = defineStore('setting', {
    state: () => ({
        settings: {},
        loading: false,
        error: null,
    }),

    actions: {
        async fetchSettings(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await settingService.getAll(params);
                this.settings = response.data;
                return this.settings;
            } catch (error) {
                console.error('Fetch settings error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch settings';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateSettings(data) {
            this.loading = true;
            this.error = null;

            try {
                await settingService.update(data);
                await this.fetchSettings();
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update settings';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async createSetting(data) {
            this.loading = true;
            this.error = null;

            try {
                await settingService.create(data);
                await this.fetchSettings();
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create setting';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        getSetting(group, key) {
            if (!this.settings[group]) return null;
            const setting = this.settings[group].find(s => s.key === key);
            return setting ? setting.value : null;
        },

        clearError() {
            this.error = null;
        },
    },
});