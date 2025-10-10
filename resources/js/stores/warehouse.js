import { defineStore } from 'pinia';
import warehouseService from '../services/warehouseService';

export const useWarehouseStore = defineStore('warehouse', {
    state: () => ({
        warehouses: [],
        currentWarehouse: null,
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
        async fetchWarehouses(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await warehouseService.getAll(params);

                if (response.data.data) {
                    this.warehouses = response.data.data;
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                        last_page: response.data.last_page,
                    };
                } else {
                    this.warehouses = response.data;
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch warehouses';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchWarehouse(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await warehouseService.getOne(id);
                this.currentWarehouse = response.data.data || response.data;
                return this.currentWarehouse;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch warehouse';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async createWarehouse(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await warehouseService.create(data);
                this.warehouses.unshift(response.data.data || response.data);
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create warehouse';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateWarehouse(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await warehouseService.update(id, data);
                const index = this.warehouses.findIndex(w => w.id === id);
                if (index !== -1) {
                    this.warehouses[index] = response.data.data || response.data;
                }
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update warehouse';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async deleteWarehouse(id) {
            this.loading = true;
            this.error = null;

            try {
                await warehouseService.delete(id);
                this.warehouses = this.warehouses.filter(w => w.id !== id);
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete warehouse';
                throw error;
            } finally {
                this.loading = false;
            }
        },
    },
});