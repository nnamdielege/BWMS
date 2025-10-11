<template>
    <div class="notification-bell">
        <button
            @click="toggleDropdown"
            class="bell-button"
            :class="{ 'has-unread': unreadCount > 0 }"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span v-if="unreadCount > 0" class="badge">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
        </button>

        <!-- Dropdown -->
        <transition name="dropdown">
            <div v-if="showDropdown" class="dropdown">
                <!-- Header -->
                <div class="dropdown-header">
                    <h3 class="dropdown-title">Notifications</h3>
                    <button
                        v-if="unreadCount > 0"
                        @click="handleMarkAllAsRead"
                        class="mark-all-btn"
                    >
                        Mark all as read
                    </button>
                </div>

                <!-- Loading -->
                <div v-if="loading" class="loading">
                    <div class="spinner-small"></div>
                    <p>Loading...</p>
                </div>

                <!-- Notifications List -->
                <div v-else-if="notifications.length > 0" class="notifications-list">
                    <div
                        v-for="notification in notifications"
                        :key="notification.id"
                        class="notification-item"
                        :class="{ 'unread': !notification.is_read }"
                        @click="handleNotificationClick(notification)"
                    >
                        <div class="notification-icon" :class="`icon-${notification.color}`">
                            <component :is="getIcon(notification.icon)" class="w-5 h-5" />
                        </div>
                        <div class="notification-content">
                            <h4 class="notification-title">{{ notification.title }}</h4>
                            <p class="notification-message">{{ notification.message }}</p>
                            <span class="notification-time">{{ formatTime(notification.created_at) }}</span>
                        </div>
                        <button
                            @click.stop="handleDelete(notification.id)"
                            class="delete-btn"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="empty-state">
                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <p>No notifications</p>
                </div>

                <!-- Footer -->
                <div class="dropdown-footer">
                    <router-link to="/notifications" class="view-all-link" @click="showDropdown = false">
                        View all notifications
                    </router-link>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, h, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useNotificationStore } from '../../stores/notification';

const router = useRouter();
const notificationStore = useNotificationStore();

const showDropdown = ref(false);
const loading = computed(() => notificationStore.loading);
const notifications = computed(() => notificationStore.notifications.slice(0, 5)); // Show only 5 in dropdown
const unreadCount = computed(() => notificationStore.unreadCount);

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

let refreshInterval;

onMounted(async () => {
    await fetchData();
    
    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(() => {
        notificationStore.fetchUnreadCount();
    }, 30000);

    // Close dropdown when clicking outside
    document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
    clearInterval(refreshInterval);
    document.removeEventListener('click', handleClickOutside);
});

const fetchData = async () => {
    await notificationStore.fetchNotifications({ per_page: 5 });
    await notificationStore.fetchUnreadCount();
};

const toggleDropdown = () => {
    showDropdown.value = !showDropdown.value;
    if (showDropdown.value) {
        fetchData();
    }
};

const handleNotificationClick = async (notification) => {
    // Mark as read
    if (!notification.is_read) {
        await notificationStore.markAsRead(notification.id);
    }

    // Navigate if link exists
    if (notification.link) {
        router.push(notification.link);
        showDropdown.value = false;
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
    try {
        await notificationStore.deleteNotification(id);
    } catch (error) {
        console.error('Error deleting notification:', error);
    }
};

const handleClickOutside = (event) => {
    const dropdown = document.querySelector('.notification-bell');
    if (dropdown && !dropdown.contains(event.target)) {
        showDropdown.value = false;
    }
};

const formatTime = (date) => {
    const now = new Date();
    const notifDate = new Date(date);
    const diffMs = now - notifDate;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    
    return notifDate.toLocaleDateString();
};
</script>

<style scoped>
.notification-bell {
    @apply relative;
}

.bell-button {
    @apply relative p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors;
}

.bell-button.has-unread {
    @apply text-indigo-600;
}

.badge {
    @apply absolute top-0 right-0 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full min-w-[1.25rem];
}

.dropdown {
    @apply absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-lg border border-gray-200 z-50 max-h-[32rem] flex flex-col;
}

.dropdown-header {
    @apply flex items-center justify-between px-4 py-3 border-b border-gray-200;
}

.dropdown-title {
    @apply text-lg font-semibold text-gray-900;
}

.mark-all-btn {
    @apply text-sm text-indigo-600 hover:text-indigo-800;
}

.loading {
    @apply flex flex-col items-center justify-center py-8;
}

.spinner-small {
    @apply w-8 h-8 border-2 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-2;
}

.notifications-list {
    @apply overflow-y-auto max-h-96;
}

.notification-item {
    @apply flex items-start gap-3 px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors;
}

.notification-item.unread {
    @apply bg-indigo-50;
}

.notification-icon {
    @apply w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0;
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

.notification-content {
    @apply flex-1 min-w-0;
}

.notification-title {
    @apply text-sm font-semibold text-gray-900 mb-1;
}

.notification-message {
    @apply text-sm text-gray-600 line-clamp-2 mb-1;
}

.notification-time {
    @apply text-xs text-gray-500;
}

.delete-btn {
    @apply p-1 text-gray-400 hover:text-red-600 transition-colors flex-shrink-0;
}

.empty-state {
    @apply flex flex-col items-center justify-center py-12 text-gray-500;
}

.empty-state p {
    @apply mt-2 text-sm;
}

.dropdown-footer {
    @apply px-4 py-3 border-t border-gray-200 bg-gray-50;
}

.view-all-link {
    @apply block text-center text-sm text-indigo-600 hover:text-indigo-800 font-medium no-underline;
}

/* Dropdown animation */
.dropdown-enter-active,
.dropdown-leave-active {
    transition: all 0.2s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}
</style>