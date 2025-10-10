import api from './api';

const productService = {
    getAll(params = {}) {
        return api.get('/products', { params });
    },

    getOne(id) {
        return api.get(`/products/${id}`);
    },

    create(data) {
        return api.post('/products', data);
    },

    update(id, data) {
        return api.put(`/products/${id}`, data);
    },

    delete(id) {
        return api.delete(`/products/${id}`);
    },

    getCategories() {
        return api.get('/product-categories');
    },
};

export default productService;