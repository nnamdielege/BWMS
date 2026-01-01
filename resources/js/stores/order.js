import { defineStore } from 'pinia';
import salesOrderService from '../services/salesOrderService';
import purchaseOrderService from '../services/purchaseOrderService';

export const useOrderStore = defineStore('order', {
    state: () => ({
        salesOrders: [],
        purchaseOrders: [],
        currentOrder: null,
        loading: false,
        error: null,
        pagination: {
            current_page: 1,
            per_page: 15,
            total: 0,
            last_page: 1,
        },
        filters: {
            search: '',
            status: '',
            date_from: '',
            date_to: '',
        },
    }),

    getters: {
        pendingSalesOrders: (state) => {
            return state.salesOrders.filter(order => order.status === 'pending').length;
        },

        pendingPurchaseOrders: (state) => {
            return state.purchaseOrders.filter(order => order.status === 'pending').length;
        },

        totalSalesAmount: (state) => {
            return state.salesOrders.reduce((sum, order) => sum + parseFloat(order.total || 0), 0);
        },

        totalPurchaseAmount: (state) => {
            return state.purchaseOrders.reduce((sum, order) => sum + parseFloat(order.total || 0), 0);
        },
    },

    actions: {
        // Sales Orders
        async fetchSalesOrders(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await salesOrderService.getAll({
                    ...this.filters,
                    ...params,
                });

                if (response.data.data) {
                    this.salesOrders = response.data.data;
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                        last_page: response.data.last_page,
                    };
                } else {
                    this.salesOrders = response.data;
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch sales orders';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async getSalesOrder(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await salesOrderService.getOne(id);
                this.currentOrder = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch sales order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async createSalesOrder(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await salesOrderService.create(data);
                this.salesOrders.unshift(response.data.data || response.data);
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create sales order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateSalesOrder(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await salesOrderService.update(id, data);
                const index = this.salesOrders.findIndex(order => order.id === id);
                if (index !== -1) {
                    this.salesOrders[index] = response.data.data || response.data;
                }
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update sales order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fulfillSalesOrder(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await salesOrderService.fulfill(id);
                await this.fetchSalesOrders();
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fulfill sales order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async cancelSalesOrder(id) {
            this.loading = true;
            this.error = null;

            try {
                await salesOrderService.cancel(id);
                await this.fetchSalesOrders();
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to cancel sales order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        // Purchase Orders
        async fetchPurchaseOrders(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.getAll({
                    ...this.filters,
                    ...params,
                });

                if (response.data.data) {
                    this.purchaseOrders = response.data.data;
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                        last_page: response.data.last_page,
                    };
                } else {
                    this.purchaseOrders = response.data;
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch purchase orders';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async getPurchaseOrder(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.getOne(id);
                this.currentOrder = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch purchase order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async createPurchaseOrder(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.create(data);
                this.purchaseOrders.unshift(response.data.data || response.data);
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create purchase order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updatePurchaseOrder(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.update(id, data);
                const index = this.purchaseOrders.findIndex(order => order.id === id);
                if (index !== -1) {
                    this.purchaseOrders[index] = response.data.data || response.data;
                }
                return response.data.data || response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update purchase order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async receivePurchaseOrder(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.receive(id, data);
                await this.fetchPurchaseOrders();
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to receive purchase order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async cancelPurchaseOrder(id) {
            this.loading = true;
            this.error = null;

            try {
                await purchaseOrderService.cancel(id);
                await this.fetchPurchaseOrders();
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to cancel purchase order';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        // ============================================================
        // NEW METHODS: PDF Download and Email for Purchase Orders
        // ============================================================

        /**
         * Download purchase order as PDF
         * @param {number} id - Purchase order ID
         * @returns {Blob} PDF file blob
         */
        async downloadPurchaseOrderPDF(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.downloadPDF(id);
                return response.data;  // ← Changed: return response.data, not response
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to download PDF';
                throw error;
            } finally {
                this.loading = false;
            }
        },
        /**
         * Send purchase order via email to custom recipient
         * @param {number} id - Purchase order ID
         * @param {Object} emailData - Email data { recipient_email, subject, message }
         * @returns {Object} Response data
         */
        async sendPurchaseOrderEmail(id, emailData) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.sendEmail(id, emailData);
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to send email';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Send purchase order directly to supplier's email
         * @param {number} id - Purchase order ID
         * @returns {Object} Response data
         */
        async sendPurchaseOrderToSupplier(id) {
            this.loading = true;
            this.error = null;

            try {
                const response = await purchaseOrderService.sendToSupplier(id);
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to send email to supplier';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        // ============================================================
        // UTILITY METHODS
        // ============================================================

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
        },

        resetFilters() {
            this.filters = {
                search: '',
                status: '',
                date_from: '',
                date_to: '',
            };
        },

        clearError() {
            this.error = null;
        },
    },
});