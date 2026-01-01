import api from './api';

const purchaseOrderService = {
    getAll(params = {}) {
        return api.get('/purchase-orders', { params });
    },

    getOne(id) {
        return api.get(`/purchase-orders/${id}`);
    },

    create(data) {
        return api.post('/purchase-orders', data);
    },

    update(id, data) {
        return api.put(`/purchase-orders/${id}`, data);
    },

    receive(id, data) {
        return api.post(`/purchase-orders/${id}/receive`, data);
    },

    cancel(id) {
        return api.post(`/purchase-orders/${id}/cancel`);
    },

    delete(id) {
        return api.delete(`/purchase-orders/${id}`);
    },

    /**
     * Download purchase order as PDF
     * @param {number} id - Purchase order ID
     * @returns {Promise<Blob>} PDF file blob
     */
    downloadPDF(id) {
        return api.get(`/purchase-orders/${id}/download`, {
            responseType: 'blob',
        });
    },

    /**
     * Send purchase order via email to custom recipient
     * @param {number} id - Purchase order ID
     * @param {Object} emailData - Email data
     * @param {string} emailData.recipient_email - Recipient email address
     * @param {string} [emailData.subject] - Email subject (optional)
     * @param {string} [emailData.message] - Email message (optional)
     * @returns {Promise}
     * 
     * @example
     * purchaseOrderService.sendEmail(1, {
     *   recipient_email: 'supplier@example.com',
     *   subject: 'Purchase Order #PO-001',
     *   message: 'Please process this order'
     * })
     */
    sendEmail(id, emailData) {
        return api.post(`/purchase-orders/${id}/send-email`, emailData);
    },

    /**
     * Send purchase order directly to supplier's email
     * Automatically uses supplier's email from their profile
     * @param {number} id - Purchase order ID
     * @returns {Promise}
     * 
     * @example
     * purchaseOrderService.sendToSupplier(1)
     */
    sendToSupplier(id) {
        return api.post(`/purchase-orders/${id}/send-to-supplier`);
    },
};

export default purchaseOrderService;