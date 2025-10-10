import api from './api';

const customerService = {
    getAll(params = {}) {
        return api.get('/customers', { params });
    },

    getOne(id) {
        return api.get(`/customers/${id}`);
    },

    create(data) {
        return api.post('/customers', data);
    },

    update(id, data) {
        return api.put(`/customers/${id}`, data);
    },

    delete(id) {
        return api.delete(`/customers/${id}`);
    },
};

export default customerService;