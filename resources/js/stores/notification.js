import { defineStore } from 'pinia';
import notificationService from '../services/notificationService';

export const useNotificationStore = defineStore('notification', {
    state: () => ({
        notifications: [],
        unreadCount: 0,
        loading: false,
        error: null,
    }),

    actions: {
        async fetchNotifications(params = {}) {
            this.loading = true;
            this.error = null;

            try {
                const response = await notificationService.getAll(params);
                this.notifications = response.data.data || response.data;
                return this.notifications;
            } catch (error) {
                console.error('Fetch notifications error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch notifications';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchUnreadCount() {
            try {
                const response = await notificationService.getUnreadCount();
                this.unreadCount = response.data.count;
                return this.unreadCount;
            } catch (error) {
                console.error('Fetch unread count error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch unread count';
                throw error;
            }
        },

        async markAsRead(id) {
            try {
                await notificationService.markAsRead(id);
                
                // Update local state
                const notification = this.notifications.find(n => n.id === id);
                if (notification) {
                    notification.is_read = true;
                    notification.read_at = new Date().toISOString();
                }
                
                // Update unread count
                await this.fetchUnreadCount();
            } catch (error) {
                console.error('Mark as read error:', error);
                this.error = error.response?.data?.message || 'Failed to mark as read';
                throw error;
            }
        },

        async markAllAsRead() {
            try {
                await notificationService.markAllAsRead();
                
                // Update local state
                this.notifications.forEach(n => {
                    n.is_read = true;
                    n.read_at = new Date().toISOString();
                });
                
                this.unreadCount = 0;
            } catch (error) {
                console.error('Mark all as read error:', error);
                this.error = error.response?.data?.message || 'Failed to mark all as read';
                throw error;
            }
        },

        async deleteNotification(id) {
            try {
                await notificationService.delete(id);
                
                // Remove from local state
                this.notifications = this.notifications.filter(n => n.id !== id);
                
                // Update unread count
                await this.fetchUnreadCount();
            } catch (error) {
                console.error('Delete notification error:', error);
                this.error = error.response?.data?.message || 'Failed to delete notification';
                throw error;
            }
        },

        clearError() {
            this.error = null;
        },
    },
});