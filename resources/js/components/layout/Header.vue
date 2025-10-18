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
                        <p class="user-role">{{ authStore.user?.role || 'User Not Assigned' }}</p>
                    </div>
                    <svg class="dropdown-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>

                <!-- User Dropdown Menu -->
                <transition name="dropdown">
                    <div v-if="showUserDropdown" class="user-dropdown">
                        <!-- Profile -->
                        <router-link to="/profile" class="dropdown-item" @click="showUserDropdown = false">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            My Profile
                        </router-link>

                        <!-- Settings -->
                        <router-link to="/settings" class="dropdown-item" @click="showUserDropdown = false">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            Settings
                        </router-link>

                        <!-- Pricing -->
                        <router-link to="/pricing" class="dropdown-item" @click="showUserDropdown = false">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.105 0-2 .895-2 2v8h8v-8c0-1.105-.895-2-2-2h-4zM4 12v8h4v-8H4z" />
                            </svg>
                            Pricing
                        </router-link>

                        <!-- My Subscription -->
                        <router-link to="/subscription/manage" class="dropdown-item" @click="showUserDropdown = false">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8h18M3 12h18M3 16h18M6 8v8M18 8v8" />
                            </svg>
                            My Subscription
                        </router-link>

                        <!-- Usage & Limits (Conditional) -->
                        <router-link 
                            v-if="hasUsageAccess"
                            to="/usage" 
                            class="dropdown-item" 
                            @click="showUserDropdown = false"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            Usage & Limits
                        </router-link>

                        <!-- Divider -->
                        <div class="dropdown-divider"></div>

                        <!-- Logout -->
                        <button @click="handleLogout" class="dropdown-item text-red-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            Logout
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
import { usePermissions } from '../../composables/usePermissions';

const router = useRouter();
const authStore = useAuthStore();
const { hasUsageAccess } = usePermissions();

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