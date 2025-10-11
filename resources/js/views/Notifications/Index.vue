<template>
    <div class="notifications-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Notifications</h1>
                <p class="page-subtitle">Stay updated with your latest notifications</p>
            </div>
            <div class="header-actions">
                <button
                    v-if="unreadCount > 0"
                    @click="handleMarkAllAsRead"
                    class="btn btn-secondary"
                    :disabled="loading"
                >
                    Mark all as read
                </button>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button
                @click="filterType = 'all'"
                :class="['filter-tab', { active: filterType === 'all' }]"
            >
                All ({{ totalCount }})
            </button>
            <button
                @click="filterType = 'unread'"
                :class="['filter-tab', { active: filterType === 'unread' }]"
            >
                Unread ({{ unreadCount }})
            </button>
        </div>

        <!-- Loading State -->
        <div v-if="loading && notifications.length === 0" class="loading-container">
            <div class="spinner"></div>
            <p>Loading notifications...</p>
        </div>

        <!-- Notifications List -->
        <div v-else-if="notifications.length > 0" class="notifications-container">
            <div
                v-for="notification in notifications"
                :key="notification.id"
                class="notification-card"
                :class="{ 'unread': !notification.is_read }"
            >
                <div class="notification-icon" :class="`icon-${notification.color}`">
                    <component :is="getIcon(notification.icon)" class="w-6 h-6" />
                </div>

                <div class="notification-body">
                    <div class="notification-header">
                        <h3 class="notification-title">{{ notification.title }}</h3>
                        <span class="notification-time">{{ formatTime(notification.created_at) }}</span>
                    </div>
                    <p class="notification-message">{{ notification.message }}</p>

                    <div class="notification-actions">
                        <button
                            v-if="notification.link"
                            @click="handleNavigate(notification)"
                            class="action-btn primary"
                        >
                            View Details
                        </button>
                        <button
                            v-if="!notification.is_read"
                            @click="handleMarkAsRead(notification.id)"
                            class="action-btn"
                        >
                            Mark as read
                        </button>
                        <button
                            @click="handleDelete(notification.id)"
                            class="action-btn danger"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="pagination.last_page > 1" class="pagination">
                <button
                    @click="changePage(pagination.current_page - 1)"
                    :disabled="pagination.current_page === 1"
                    class="pagination-btn"
                >
                    Previous
                </button>
                <span class="pagination-info">
                    Page {{ pagination.current_page }} of {{ pagination.last_page }}
                </span>
                <button
                    @click="changePage(pagination.current_page + 1)"
                    :disabled="pagination.current_page === pagination.last_page"
                    class="pagination-btn"
                >
                    Next
                </button>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else class="empty-state">
            <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <h3>No notifications</h3>
            <p>You're all caught up! We'll notify you when something new happens.</p>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, h } from 'vue';
import { useRouter } from 'vue-router';
import { useNotificationStore } from '../../stores/notification';

const router = useRouter();
const notificationStore = useNotificationStore();

const filterType = ref('all');
const loading = ref(false);
const currentPage = ref(1);

const notifications = computed(() => notificationStore.notifications);
const unreadCount = computed(() => notificationStore.unreadCount);
const totalCount = computed(() => notifications.value.length);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

// Icon components
const icons = {
    'exclamation': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z' })
    ]),
    'shopping-cart': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z' })
    ]),
    'check-circle': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' })
    ]),
    'alert': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' })
    ]),
    'info': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' })
    ]),
};

const getIcon = (iconName) => {
    return icons[iconName] || icons['info'];
};

onMounted(async () => {
    await fetchNotifications();
});

watch(filterType, async () => {
    currentPage.value = 1;
    await fetchNotifications();
});

const fetchNotifications = async () => {
    loading.value = true;

    try {
        const params = {
            page: currentPage.value,
            per_page: 15,
        };

        if (filterType.value === 'unread') {
            params.unread_only = true;
        }

        const response = await notificationStore.fetchNotifications(params);
        
        if (response && response.data) {
            pagination.value = {
                current_page: response.current_page,
                last_page: response.last_page,
                per_page: response.per_page,
                total: response.total,
            };
        }

        await notificationStore.fetchUnreadCount();
    } catch (error) {
        console.error('Error fetching notifications:', error);
    } finally {
        loading.value = false;
    }
};

const handleMarkAsRead = async (id) => {
    try {
        await notificationStore.markAsRead(id);
    } catch (error) {
        console.error('Error marking as read:', error);
    }
};

const handleMarkAllAsRead = async () => {
    try {
        await notificationStore.markAllAsRead();
    } catch (error) {
        console.error('Error marking all as read:', error);
    }
};

const handleDelete = async (id) => {
    if (!confirm('Are you sure you want to delete this notification?')) {
        return;
    }

    try {
        await notificationStore.deleteNotification(id);
    } catch (error) {
        console.error('Error deleting notification:', error);
    }
};

const handleNavigate = async (notification) => {
    if (!notification.is_read) {
        await handleMarkAsRead(notification.id);
    }

    if (notification.link) {
        router.push(notification.link);
    }
};

const changePage = async (page) => {
    currentPage.value = page;
    await fetchNotifications();
};

const formatTime = (date) => {
    const now = new Date();
    const notifDate = new Date(date);
    const diffMs = now - notifDate;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
    
    return notifDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>

<style scoped>
.notifications-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-start justify-between;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.header-actions {
    @apply flex gap-3;
}

.btn {
    @apply px-4 py-2 rounded-lg font-medium transition-colors;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed;
}

.filter-tabs {
    @apply flex gap-2 bg-white rounded-lg shadow p-2;
}

.filter-tab {
    @apply px-4 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors;
}

.filter-tab.active {
    @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-20 bg-white rounded-lg shadow;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.notifications-container {
    @apply space-y-4;
}

.notification-card {
    @apply bg-white rounded-lg shadow p-6 flex gap-4 transition-all hover:shadow-md;
}

.notification-card.unread {
    @apply border-l-4 border-indigo-600 bg-indigo-50;
}

.notification-icon {
    @apply w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0;
}

.icon-blue {
    @apply bg-blue-100 text-blue-600;
}

.icon-green {
    @apply bg-green-100 text-green-600;
}

.icon-yellow {
    @apply bg-yellow-100 text-yellow-600;
}

.icon-red {
    @apply bg-red-100 text-red-600;
}

.icon-gray {
    @apply bg-gray-100 text-gray-600;
}

.notification-body {
    @apply flex-1 min-w-0;
}

.notification-header {
    @apply flex items-start justify-between gap-4 mb-2;
}

.notification-title {
    @apply text-lg font-semibold text-gray-900;
}

.notification-time {
    @apply text-sm text-gray-500 flex-shrink-0;
}

.notification-message {
    @apply text-gray-600 mb-4;
}

.notification-actions {
    @apply flex flex-wrap gap-2;
}

.action-btn {
    @apply px-3 py-1.5 text-sm rounded-md border transition-colors;
}

.action-btn.primary {
    @apply bg-indigo-600 text-white border-indigo-600 hover:bg-indigo-700;
}

.action-btn:not(.primary):not(.danger) {
    @apply bg-white text-gray-700 border-gray-300 hover:bg-gray-50;
}

.action-btn.danger {
    @apply bg-white text-red-600 border-red-300 hover:bg-red-50;
}

.pagination {
    @apply flex items-center justify-between bg-white rounded-lg shadow p-4;
}

.pagination-btn {
    @apply px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors;
}

.pagination-info {
    @apply text-sm text-gray-700;
}

.empty-state {
    @apply flex flex-col items-center justify-center py-20 bg-white rounded-lg shadow text-center;
}

.empty-icon {
    @apply w-20 h-20 text-gray-300 mb-4;
}

.empty-state h3 {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.empty-state p {
    @apply text-gray-600 max-w-md;
}
</style>