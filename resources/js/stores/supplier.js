import { defineStore } from 'pinia';
import supplierService from '../services/supplierService';

export const useSupplierStore = defineStore('supplier', {
    state: () => ({
        suppliers: [],
        currentSupplier: null,
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
        async fetchSuppliers(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await supplierService.getAll(params);

                if (response.data.data) {
                    this.suppliers = response.data.data;
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                        last_page: response.data.last_page,
                    };
                } else {
                    this.suppliers = response.data;
                }

                return this.suppliers;
            } catch (error) {
                console.error('Fetch suppliers error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch suppliers';
                this.suppliers = [];
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchSupplier(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await supplierService.getOne(id);
                this.currentSupplier = response.data.data || response.data;
                return this.currentSupplier;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch supplier';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async createSupplier(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await supplierService.create(data);
                this.suppliers.unshift(response.data.data || response.data);
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create supplier';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateSupplier(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await supplierService.update(id, data);
                const index = this.suppliers.findIndex(s => s.id === id);
                if (index !== -1) {
                    this.suppliers[index] = response.data.data || response.data;
                }
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update supplier';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async deleteSupplier(id) {
            this.loading = true;
            this.error = null;

            try {
                await supplierService.delete(id);
                this.suppliers = this.suppliers.filter(s => s.id !== id);
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete supplier';
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