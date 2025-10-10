import { defineStore } from 'pinia';
import dashboardService from '../services/dashboardService';

export const useDashboardStore = defineStore('dashboard', {
    state: () => ({
        stats: null,
        recentOrders: [],
        lowStockItems: [],
        topProducts: [],
        salesChart: null,
        inventoryValue: null,
        inventoryStats: null,
        recentTransactions: [],
        loading: false,
        error: null,
    }),

    getters: {
        /**
         * Get formatted total sales
         */
        formattedTotalSales: (state) => {
            if (!state.stats?.sales_this_month) return '$0.00';
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
            }).format(state.stats.sales_this_month);
        },

        /**
         * Get formatted inventory value
         */
        formattedInventoryValue: (state) => {
            if (!state.inventoryValue?.total_value) return '$0.00';
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
            }).format(state.inventoryValue.total_value);
        },

        /**
         * Get low stock alerts count
         */
        lowStockAlertsCount: (state) => {
            return (state.stats?.low_stock_count || 0) + (state.stats?.out_of_stock_count || 0);
        },

        /**
         * Check if there are critical alerts
         */
        hasCriticalAlerts: (state) => {
            return (state.stats?.out_of_stock_count || 0) > 0;
        },
    },

    actions: {
        /**
         * Fetch all dashboard data
         */
        async fetchDashboardData() {
            this.loading = true;
            this.error = null;

            try {
                const response = await dashboardService.getOverview();
                
                this.stats = response.data.stats;
                this.recentOrders = response.data.recent_orders || [];
                this.lowStockItems = response.data.low_stock_items || [];
                this.topProducts = response.data.top_products || [];
                this.salesChart = response.data.sales_chart || null;
                this.inventoryValue = response.data.inventory_value || null;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch dashboard data';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch inventory statistics
         */
        async fetchInventoryStats() {
            try {
                const response = await dashboardService.getInventoryStats();
                this.inventoryStats = response.data;
            } catch (error) {
                console.error('Failed to fetch inventory stats:', error);
            }
        },

        /**
         * Fetch recent transactions
         */
        async fetchRecentTransactions(params = {}) {
            try {
                const response = await dashboardService.getRecentTransactions(params);
                this.recentTransactions = response.data.data || response.data;
            } catch (error) {
                console.error('Failed to fetch recent transactions:', error);
            }
        },

        /**
         * Refresh all dashboard data
         */
        async refreshAll() {
            await Promise.all([
                this.fetchDashboardData(),
                this.fetchInventoryStats(),
                this.fetchRecentTransactions({ per_page: 10 }),
            ]);
        },

        /**
         * Clear error
         */
        clearError() {
            this.error = null;
        },

        /**
         * Reset dashboard state
         */
        reset() {
            this.stats = null;
            this.recentOrders = [];
            this.lowStockItems = [];
            this.topProducts = [];
            this.salesChart = null;
            this.inventoryValue = null;
            this.inventoryStats = null;
            this.recentTransactions = [];
            this.error = null;
        },
    },
});