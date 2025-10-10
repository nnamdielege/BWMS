<template>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <Sidebar :is-open="sidebarOpen" @close="sidebarOpen = false" />

        <!-- Main Content Area -->
        <div class="main-content" :class="{ 'sidebar-open': sidebarOpen }">
            <!-- Top Header -->
            <Header @toggle-sidebar="toggleSidebar" />

            <!-- Page Content -->
            <main class="page-content">
                <div class="content-wrapper">
                    <router-view />
                </div>
            </main>

            <!-- Footer -->
            <footer class="page-footer">
                <div class="footer-content">
                    <p class="footer-text">
                        © {{ currentYear }} Boundless Warehouse Management System (BWMS). All rights reserved.
                    </p>
                    <div class="footer-links">
                        <a href="#" class="footer-link">Documentation</a>
                        <a href="#" class="footer-link">Support</a>
                        <a href="#" class="footer-link">Terms</a>
                        <a href="#" class="footer-link">Privacy</a>
                    </div>
                </div>
            </footer>
        </div>

        <!-- Mobile Overlay -->
        <div 
            v-if="sidebarOpen" 
            class="mobile-overlay"
            @click="sidebarOpen = false"
        ></div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import Sidebar from './Sidebar.vue';
import Header from './Header.vue';

const sidebarOpen = ref(false);

const currentYear = computed(() => new Date().getFullYear());

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};
</script>

<style scoped>
.dashboard-layout {
    @apply min-h-screen bg-gray-100;
}

.main-content {
    @apply lg:ml-64 min-h-screen flex flex-col transition-all duration-300;
}

.main-content.sidebar-open {
    @apply ml-0;
}

.page-content {
    @apply flex-1 p-4 md:p-6 lg:p-8;
}

.content-wrapper {
    @apply max-w-7xl mx-auto;
}

.page-footer {
    @apply bg-white border-t border-gray-200 mt-auto;
}

.footer-content {
    @apply max-w-7xl mx-auto px-4 md:px-6 lg:px-8 py-6 flex flex-col md:flex-row items-center justify-between gap-4;
}

.footer-text {
    @apply text-sm text-gray-600;
}

.footer-links {
    @apply flex items-center gap-6;
}

.footer-link {
    @apply text-sm text-gray-600 hover:text-indigo-600 transition-colors no-underline;
}

.mobile-overlay {
    @apply fixed inset-0 bg-black bg-opacity-50 z-30 lg:hidden;
}

/* Responsive adjustments */
@media (max-width: 1024px) {
    .main-content {
        @apply ml-0;
    }
}
</style>