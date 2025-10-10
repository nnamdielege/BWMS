import api from './api';

const supplierService = {
    getAll(params = {}) {
        return api.get('/suppliers', { params });
    },

    getOne(id) {
        return api.get(`/suppliers/${id}`);
    },

    create(data) {
        return api.post('/suppliers', data);
    },

    update(id, data) {
        return api.put(`/suppliers/${id}`, data);
    },

    delete(id) {
        return api.delete(`/suppliers/${id}`);
    },
};

export default supplierService;