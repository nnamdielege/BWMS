import api from './api';

const warehouseService = {
    getAll(params = {}) {
        return api.get('/warehouses', { params });
    },

    getOne(id) {
        return api.get(`/warehouses/${id}`);
    },

    create(data) {
        return api.post('/warehouses', data);
    },

    update(id, data) {
        return api.put(`/warehouses/${id}`, data);
    },

    delete(id) {
        return api.delete(`/warehouses/${id}`);
    },
};

export default warehouseService;