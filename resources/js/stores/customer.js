import { defineStore } from 'pinia';
import customerService from '../services/customerService';

export const useCustomerStore = defineStore('customer', {
    state: () => ({
        customers: [],
        currentCustomer: null,
        loading: false,
        error: null,
        pagination: {
            current_page: 1,
            per_page: 15,
            total: 0,
            last_page: 1,
        },
    }),

    actions: {
        async fetchCustomers(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await customerService.getAll(params);

                if (response.data.data) {
                    this.customers = response.data.data;
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                        last_page: response.data.last_page,
                    };
                } else {
                    this.customers = response.data;
                }

                return this.customers;
            } catch (error) {
                console.error('Fetch customers error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch customers';
                this.customers = [];
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchCustomer(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await customerService.getOne(id);
                this.currentCustomer = response.data.data || response.data;
                return this.currentCustomer;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async createCustomer(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await customerService.create(data);
                this.customers.unshift(response.data.data || response.data);
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateCustomer(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await customerService.update(id, data);
                const index = this.customers.findIndex(c => c.id === id);
                if (index !== -1) {
                    this.customers[index] = response.data.data || response.data;
                }
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async deleteCustomer(id) {
            this.loading = true;
            this.error = null;

            try {
                await customerService.delete(id);
                this.customers = this.customers.filter(c => c.id !== id);
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        clearError() {
            this.error = null;
        },
    },
});