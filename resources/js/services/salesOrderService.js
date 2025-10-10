import api from './api';

const salesOrderService = {
    getAll(params = {}) {
        return api.get('/sales-orders', { params });
    },

    getOne(id) {
        return api.get(`/sales-orders/${id}`);
    },

    create(data) {
        return api.post('/sales-orders', data);
    },

    update(id, data) {
        return api.put(`/sales-orders/${id}`, data);
    },

    fulfill(id) {
        return api.post(`/sales-orders/${id}/fulfill`);
    },

    cancel(id) {
        return api.post(`/sales-orders/${id}/cancel`);
    },

    delete(id) {
        return api.delete(`/sales-orders/${id}`);
    },
};

export default salesOrderService;