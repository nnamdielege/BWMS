import api from './api';

const settingService = {
    getAll(params = {}) {
        return api.get('/settings', { params });
    },

    getOne(key) {
        return api.get(`/settings/${key}`);
    },

    update(data) {
        return api.put('/settings', data);
    },

    create(data) {
        return api.post('/settings', data);
    },
};

export default settingService;