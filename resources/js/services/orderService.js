import api from './api';

export default {
    // ============ SALES ORDERS ============

    /**
     * Get all sales orders
     * @param {Object} params - { status, customer_id, date_from, date_to, page, per_page }
     * @returns {Promise}
     */
    getSalesOrders(params = {}) {
        return api.get('/sales-orders', { params });
    },

    /**
     * Get single sales order
     * @param {Number} id - Sales order ID
     * @returns {Promise}
     */
    getSalesOrder(id) {
        return api.get(`/sales-orders/${id}`);
    },

    /**
     * Create new sales order
     * @param {Object} data - Sales order data
     * @returns {Promise}
     */
    createSalesOrder(data) {
        return api.post('/sales-orders', data);
    },

    /**
     * Update sales order
     * @param {Number} id - Sales order ID
     * @param {Object} data - Sales order data
     * @returns {Promise}
     */
    updateSalesOrder(id, data) {
        return api.put(`/sales-orders/${id}`, data);
    },

    /**
     * Fulfill sales order
     * @param {Number} id - Sales order ID
     * @param {Object} data - Fulfillment data
     * @returns {Promise}
     */
    fulfillSalesOrder(id, data = {}) {
        return api.post(`/sales-orders/${id}/fulfill`, data);
    },

    /**
     * Cancel sales order
     * @param {Number} id - Sales order ID
     * @returns {Promise}
     */
    cancelSalesOrder(id) {
        return api.delete(`/sales-orders/${id}`);
    },

    // ============ PURCHASE ORDERS ============

    /**
     * Get all purchase orders
     * @param {Object} params - { status, supplier_id, warehouse_id, date_from, date_to, page, per_page }
     * @returns {Promise}
     */
    getPurchaseOrders(params = {}) {
        return api.get('/purchase-orders', { params });
    },

    /**
     * Get single purchase order
     * @param {Number} id - Purchase order ID
     * @returns {Promise}
     */
    getPurchaseOrder(id) {
        return api.get(`/purchase-orders/${id}`);
    },

    /**
     * Create new purchase order
     * @param {Object} data - Purchase order data
     * @returns {Promise}
     */
    createPurchaseOrder(data) {
        return api.post('/purchase-orders', data);
    },

    /**
     * Update purchase order
     * @param {Number} id - Purchase order ID
     * @param {Object} data - Purchase order data
     * @returns {Promise}
     */
    updatePurchaseOrder(id, data) {
        return api.put(`/purchase-orders/${id}`, data);
    },

    /**
     * Receive purchase order
     * @param {Number} id - Purchase order ID
     * @param {Object} data - Receiving data with items
     * @returns {Promise}
     */
    receivePurchaseOrder(id, data) {
        return api.post(`/purchase-orders/${id}/receive`, data);
    },

    /**
     * Cancel purchase order
     * @param {Number} id - Purchase order ID
     * @returns {Promise}
     */
    cancelPurchaseOrder(id) {
        return api.delete(`/purchase-orders/${id}`);
    },

    // ============ ORDER STATISTICS ============

    /**
     * Get order statistics
     * @param {Object} params - { date_from, date_to }
     * @returns {Promise}
     */
    getOrderStatistics(params = {}) {
        return api.get('/orders/statistics', { params });
    },

    /**
     * Get pending orders count
     * @returns {Promise}
     */
    getPendingOrdersCount() {
        return api.get('/orders/pending-count');
    },
};