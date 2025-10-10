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
};

export default purchaseOrderService;