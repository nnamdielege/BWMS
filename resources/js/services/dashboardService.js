import api from './api';

const dashboardService = {
    getStats() {
        return api.get('/dashboard');
    },
};

export default dashboardService;