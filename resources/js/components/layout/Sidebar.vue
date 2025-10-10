<template>
    <aside class="sidebar" :class="{ 'is-open': isOpen }">
        <!-- Logo & Brand -->
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo-icon">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <div class="logo-text">
                    <h1 class="brand-name">Boundless</h1>
                    <p class="brand-tagline">Warehouse Systems</p>
                </div>
            </div>
            
            <!-- Close Button (Mobile) -->
            <button @click="$emit('close')" class="close-btn lg:hidden">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Navigation -->
        <nav class="sidebar-nav">
            <!-- Dashboard -->
            <div class="nav-section">
                <router-link to="/" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span>Dashboard</span>
                </router-link>
            </div>

            <!-- Inventory Section -->
            <div class="nav-section">
                <p class="nav-section-title">Inventory</p>
                <router-link to="/products" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <span>Products</span>
                </router-link>
                <router-link to="/inventory" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                    <span>Inventory</span>
                </router-link>
                <router-link to="/warehouses" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <span>Warehouses</span>
                </router-link>
            </div>

            <!-- Orders Section -->
            <div class="nav-section">
                <p class="nav-section-title">Orders</p>
                <router-link to="/sales-orders" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span>Sales Orders</span>
                </router-link>
                <router-link to="/purchase-orders" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    <span>Purchase Orders</span>
                </router-link>
            </div>

            <!-- Contacts Section -->
            <div class="nav-section">
                <p class="nav-section-title">Contacts</p>
                <router-link to="/customers" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span>Customers</span>
                </router-link>
                <router-link to="/suppliers" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <span>Suppliers</span>
                </router-link>
            </div>

            <!-- Reports Section -->
            <div class="nav-section">
                <p class="nav-section-title">Reports</p>
                <router-link to="/reports" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span>Reports</span>
                </router-link>
            </div>

            <!-- Settings Section -->
            <div class="nav-section">
                <p class="nav-section-title">System</p>
                <router-link to="/settings" class="nav-link" @click="$emit('close')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span>Settings</span>
                </router-link>
            </div>
        </nav>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="footer-card">
                <div class="footer-icon">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <div class="footer-content">
                    <p class="footer-title">Upgrade to Pro</p>
                    <p class="footer-text">Enjoy advanced features</p>
                </div>
            </div>
        </div>
    </aside>
</template>

<script setup>
defineProps({
    isOpen: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['close']);
</script>

<style scoped>
.sidebar {
    @apply fixed left-0 top-0 h-full w-64 bg-gray-900 text-white transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-40 flex flex-col;
}

.sidebar.is-open {
    @apply translate-x-0;
}

.sidebar-header {
    @apply flex items-center justify-between p-6 border-b border-gray-800;
}

.logo-container {
    @apply flex items-center gap-3;
}

.logo-icon {
    @apply w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center;
}

.logo-text {
    @apply flex flex-col;
}

.brand-name {
    @apply text-xl font-bold;
}

.brand-tagline {
    @apply text-xs text-gray-400;
}

.close-btn {
    @apply p-2 rounded-lg hover:bg-gray-800 transition-colors;
}

.sidebar-nav {
    @apply flex-1 overflow-y-auto py-6 px-3;
}

.nav-section {
    @apply mb-6;
}

.nav-section-title {
    @apply px-3 mb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider;
}

.nav-link {
    @apply flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-300 hover:bg-gray-800 hover:text-white transition-all no-underline mb-1;
}

.nav-link.router-link-active {
    @apply bg-indigo-600 text-white;
}

.sidebar-footer {
    @apply p-4 border-t border-gray-800;
}

.footer-card {
    @apply bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg p-4 flex items-start gap-3;
}

.footer-icon {
    @apply w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center flex-shrink-0;
}

.footer-content {
    @apply flex-1;
}

.footer-title {
    @apply text-sm font-semibold mb-1;
}

.footer-text {
    @apply text-xs text-gray-200;
}

/* Custom Scrollbar */
.sidebar-nav::-webkit-scrollbar {
    @apply w-2;
}

.sidebar-nav::-webkit-scrollbar-track {
    @apply bg-gray-800;
}

.sidebar-nav::-webkit-scrollbar-thumb {
    @apply bg-gray-700 rounded-full;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
    @apply bg-gray-600;
}
</style>