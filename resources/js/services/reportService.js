import api from './api';

const reportService = {
    getSalesReport(params = {}) {
        return api.get('/reports/sales', { params });
    },

    getPurchaseReport(params = {}) {
        return api.get('/reports/purchases', { params });
    },

    getInventoryReport(params = {}) {
        return api.get('/reports/inventory', { params });
    },

    getProductPerformanceReport(params = {}) {
        return api.get('/reports/product-performance', { params });
    },
};

export default reportService;