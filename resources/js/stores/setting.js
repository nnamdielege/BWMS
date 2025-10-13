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

        // New helper method to get raw setting value
        getRawSetting(key) {
            if (this.settings._raw) {
                return this.settings._raw[key];
            }
            return null;
        },

        // Get default tax rate specifically
        getDefaultTaxRate() {
            // Try raw settings first
            if (this.settings._raw && this.settings._raw.default_tax_rate !== undefined) {
                return parseFloat(this.settings._raw.default_tax_rate);
            }
            
            // Fallback to grouped settings
            const taxRate = this.getSetting('orders', 'default_tax_rate');
            return taxRate ? parseFloat(taxRate) : 0;
        },

        clearError() {
            this.error = null;
        },
    },
});