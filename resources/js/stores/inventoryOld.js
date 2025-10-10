import { defineStore } from 'pinia';
import inventoryService from '../services/inventoryService';

export const useInventoryStore = defineStore('inventory', {
    state: () => ({
        inventory: [],
        transactions: [],
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
        async fetchInventory(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                console.log('Fetching inventory with params:', params);
                const response = await inventoryService.getAll(params);
                
                console.log('Inventory response:', response.data);

                if (response.data.data) {
                    this.inventory = response.data.data;
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                        last_page: response.data.last_page,
                    };
                } else {
                    this.inventory = response.data;
                }
            } catch (error) {
                console.error('Fetch inventory error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch inventory';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async adjustInventory(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await inventoryService.adjust(data);
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to adjust inventory';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async transferStock(data) {
            this.loading = true;
            this.error = null;          

            try {
                const response = await inventoryService.transfer(data);                
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to transfer stock';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchTransactions(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await inventoryService.getTransactions(params);
                
                if (response.data.data) {
                    this.transactions = response.data.data;
                } else {
                    this.transactions = response.data;
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch transactions';
                throw error;
            } finally {
                this.loading = false;
            }
        },
    },
});