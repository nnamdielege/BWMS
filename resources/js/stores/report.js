import { defineStore } from 'pinia';
import reportService from '../services/reportService';

export const useReportStore = defineStore('report', {
    state: () => ({
        currentReport: null,
        reportData: null,
        loading: false,
        error: null,
        filters: {
            start_date: null,
            end_date: null,
            warehouse_id: null,
            product_id: null,
            customer_id: null,
            supplier_id: null,
        },
    }),

    getters: {
        /**
         * Check if filters are set
         */
        hasFilters: (state) => {
            return Object.values(state.filters).some(value => value !== null);
        },
    },

    actions: {
        /**
         * Fetch inventory valuation report
         */
        async fetchInventoryValuation(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'inventory_valuation';

            try {
                const response = await reportService.inventoryValuation({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch inventory valuation report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch stock movement report
         */
        async fetchStockMovement(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'stock_movement';

            try {
                const response = await reportService.stockMovement({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch stock movement report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch sales report
         */
        async fetchSalesReport(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'sales';

            try {
                const response = await reportService.salesReport({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch sales report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch purchase report
         */
        async fetchPurchaseReport(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'purchases';

            try {
                const response = await reportService.purchaseReport({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch purchase report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch low stock report
         */
        async fetchLowStockReport(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'low_stock';

            try {
                const response = await reportService.lowStockReport({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch low stock report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch product performance report
         */
        async fetchProductPerformance(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'product_performance';

            try {
                const response = await reportService.productPerformance({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch product performance report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Fetch ABC analysis report
         */
        async fetchABCAnalysis(params = {}) {
            this.loading = true;
            this.error = null;
            this.currentReport = 'abc_analysis';

            try {
                const response = await reportService.abcAnalysis({
                    ...this.filters,
                    ...params,
                });
                this.reportData = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch ABC analysis report';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Export report to PDF
         */
        async exportToPDF(reportType, params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await reportService.exportPDF(reportType, {
                    ...this.filters,
                    ...params,
                });

                // Create a blob URL and trigger download
                const blob = new Blob([response.data], { type: 'application/pdf' });
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `${reportType}_report_${new Date().toISOString().split('T')[0]}.pdf`;
                link.click();
                window.URL.revokeObjectURL(url);
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to export report to PDF';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Export report to Excel
         */
        async exportToExcel(reportType, params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await reportService.exportExcel(reportType, {
                    ...this.filters,
                    ...params,
                });

                // Create a blob URL and trigger download
                const blob = new Blob([response.data], { 
                    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' 
                });
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `${reportType}_report_${new Date().toISOString().split('T')[0]}.xlsx`;
                link.click();
                window.URL.revokeObjectURL(url);
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to export report to Excel';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Set report filters
         */
        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
        },

        /**
         * Reset filters
         */
        resetFilters() {
            this.filters = {
                start_date: null,
                end_date: null,
                warehouse_id: null,
                product_id: null,
                customer_id: null,
                supplier_id: null,
            };
        },

        /**
         * Clear current report
         */
        clearReport() {
            this.currentReport = null;
            this.reportData = null;
        },

        /**
         * Clear error
         */
        clearError() {
            this.error = null;
        },
    },
});