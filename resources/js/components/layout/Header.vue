<template>
    <header class="header">
        <div class="header-content">
            <!-- Left Side: Menu Toggle & Search -->
            <div class="header-left">
                <!-- Mobile Menu Toggle -->
                <button @click="$emit('toggle-sidebar')" class="menu-toggle">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <!-- Global Search Component (Desktop Only) -->
                <GlobalSearch class="hidden md:flex" />
            </div>

            <!-- Right Side: Mobile Search, Notifications & User Menu -->
            <div class="header-right">
                <!-- Mobile Search Button -->
                <button @click="showMobileSearch = true" class="icon-btn md:hidden" title="Search">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </button>

                <!-- Notification Bell Component -->
                <NotificationBell />

                <!-- User Menu -->
                <div class="user-menu" @click="toggleUserDropdown">
                    <div class="user-avatar">
                        <img v-if="avatarUrl" :src="avatarUrl" alt="Avatar" class="avatar-image" />
                        <span v-else class="avatar-text">{{ userInitials }}</span>
                    </div>
                    <div class="user-info hidden md:block">
                        <p class="user-name">{{ authStore.user?.name }}</p>
                        <p class="user-role">{{ authStore.user?.role || 'User' }}</p>
                    </div>
                    <svg class="dropdown-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>

                <!-- User Dropdown Menu -->
                <transition name="dropdown">
                    <div v-if="showUserDropdown" class="user-dropdown">
                        <router-link to="/profile" class="dropdown-item" @click="showUserDropdown = false">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            My Profile
                        </router-link>
                        <router-link to="/settings" class="dropdown-item" @click="showUserDropdown = false">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span>Settings</span>
                        </router-link>
                        <div class="dropdown-divider"></div>
                        <button @click="handleLogout" class="dropdown-item text-red-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span>Logout</span>
                        </button>
                    </div>
                </transition>
            </div>
        </div>

        <!-- Mobile Search Modal -->
        <MobileSearch :show="showMobileSearch" @close="showMobileSearch = false" />
    </header>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import NotificationBell from './NotificationBell.vue';
import GlobalSearch from './GlobalSearch.vue';
import MobileSearch from './MobileSearch.vue';

const router = useRouter();
const authStore = useAuthStore();

const showUserDropdown = ref(false);
const showMobileSearch = ref(false);

const userInitials = computed(() => {
    if (!authStore.user?.name) return 'U';
    const names = authStore.user.name.split(' ');
    if (names.length > 1) {
        return names[0][0] + names[1][0];
    }
    return names[0][0] + (names[0][1] || '');
});

const avatarUrl = computed(() => {
    if (!authStore.user?.avatar) return null;
    const baseUrl = import.meta.env.VITE_API_URL || window.location.origin;
    return `${baseUrl}/storage/${authStore.user.avatar}`;
});

const toggleUserDropdown = () => {
    showUserDropdown.value = !showUserDropdown.value;
};

const handleLogout = async () => {
    try {
        await authStore.logout();
        router.push('/login');
    } catch (error) {
        console.error('Logout error:', error);
    }
};

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
    const userMenu = event.target.closest('.user-menu');
    const userDropdown = event.target.closest('.user-dropdown');
    
    if (!userMenu && !userDropdown) {
        showUserDropdown.value = false;
    }
};

// Close mobile search on Escape key
const handleEscapeKey = (event) => {
    if (event.key === 'Escape') {
        showMobileSearch.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleEscapeKey);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleEscapeKey);
});
</script>

<style scoped>
.header {
    @apply bg-white border-b border-gray-200 sticky top-0 z-20;
}

.header-content {
    @apply flex items-center justify-between px-4 md:px-6 lg:px-8 h-16;
}

.header-left {
    @apply flex items-center gap-4 flex-1;
}

.menu-toggle {
    @apply p-2 rounded-lg hover:bg-gray-100 transition-colors lg:hidden;
}

.header-right {
    @apply flex items-center gap-4 relative;
}

.icon-btn {
    @apply p-2 rounded-lg hover:bg-gray-100 transition-colors text-gray-600;
}

.user-menu {
    @apply flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-gray-100 transition-colors;
}

.user-avatar {
    @apply w-10 h-10 bg-indigo-600 rounded-full flex items-center justify-center overflow-hidden;
}

.avatar-image {
    @apply w-full h-full object-cover;
}

.avatar-text {
    @apply text-white font-semibold text-sm;
}

.user-info {
    @apply text-left;
}

.user-name {
    @apply text-sm font-semibold text-gray-900;
}

.user-role {
    @apply text-xs text-gray-500 capitalize;
}

.dropdown-icon {
    @apply w-5 h-5 text-gray-400;
}

.user-dropdown {
    @apply absolute right-0 top-full mt-2 w-56 bg-white border border-gray-200 rounded-lg shadow-lg py-2 z-50;
}

.dropdown-item {
    @apply flex items-center gap-3 px-4 py-2 hover:bg-gray-100 transition-colors cursor-pointer no-underline text-gray-700;
}

.dropdown-divider {
    @apply border-t border-gray-200 my-2;
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