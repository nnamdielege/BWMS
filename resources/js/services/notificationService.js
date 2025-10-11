import api from './api';

const notificationService = {
    getAll(params = {}) {
        return api.get('/notifications', { params });
    },

    getUnreadCount() {
        return api.get('/notifications/unread-count');
    },

    markAsRead(id) {
        return api.put(`/notifications/${id}/read`);
    },

    markAllAsRead() {
        return api.put('/notifications/mark-all-read');
    },

    delete(id) {
        return api.delete(`/notifications/${id}`);
    },
};

export default notificationService;