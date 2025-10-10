import api from './api';

export default {
    /**
     * Get all inventory with optional filters
     * @param {Object} params - { warehouse_id, product_id, low_stock, page, per_page }
     * @returns {Promise}
     */
    getAll(params = {}) {
        return api.get('/inventory', { params });
    },

    /**
     * Adjust inventory quantity
     * @param {Object} data - { product_id, warehouse_id, quantity, reason, reference, notes }
     * @returns {Promise}
     */
    adjust(data) {
        return api.post('/inventory/adjust', data);
    },

    /**
     * Transfer inventory between warehouses
     * @param {Object} data - { product_id, from_warehouse_id, to_warehouse_id, quantity, notes }
     * @returns {Promise}
     */
    transfer(data) {
        return api.post('/inventory/transfer', data);
    },

    /**
     * Get inventory transactions
     * @param {Object} params - { product_id, warehouse_id, type, date_from, date_to }
     * @returns {Promise}
     */
    getTransactions(params = {}) {
        return api.get('/inventory/transactions', { params });
    },

    /**
     * Get low stock items
     * @param {Object} params - { warehouse_id }
     * @returns {Promise}
     */
    getLowStock(params = {}) {
        return api.get('/inventory/low-stock', { params });
    },

    /**
     * Get out of stock items
     * @param {Object} params - { warehouse_id }
     * @returns {Promise}
     */
    getOutOfStock(params = {}) {
        return api.get('/inventory/out-of-stock', { params });
    },

    /**
     * Perform stock count
     * @param {Object} data - Stock count data
     * @returns {Promise}
     */
    stockCount(data) {
        return api.post('/inventory/stock-count', data);
    },

    /**
     * Get inventory by warehouse
     * @param {Number} warehouseId - Warehouse ID
     * @returns {Promise}
     */
    getByWarehouse(warehouseId) {
        return api.get('/inventory', { params: { warehouse_id: warehouseId } });
    },

    /**
     * Get inventory by product
     * @param {Number} productId - Product ID
     * @returns {Promise}
     */
    getByProduct(productId) {
        return api.get('/inventory', { params: { product_id: productId } });
    },
};