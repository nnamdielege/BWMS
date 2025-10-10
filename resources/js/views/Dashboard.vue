<template>
    <div class="dashboard">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Dashboard</h1>
            <p class="dashboard-subtitle">Welcome back! Here's what's happening with your inventory.</p>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading dashboard...</p>
        </div>

        <!-- Dashboard Content -->
        <div v-else-if="dashboardData" class="dashboard-content">
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon bg-blue-100 text-blue-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Products</p>
                        <p class="stat-value">{{ dashboardData.stats.total_products }}</p>
                        <router-link to="/products" class="stat-link">View all →</router-link>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-green-100 text-green-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Customers</p>
                        <p class="stat-value">{{ dashboardData.stats.total_customers }}</p>
                        <router-link to="/customers" class="stat-link">View all →</router-link>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-purple-100 text-purple-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Suppliers</p>
                        <p class="stat-value">{{ dashboardData.stats.total_suppliers }}</p>
                        <router-link to="/suppliers" class="stat-link">View all →</router-link>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-yellow-100 text-yellow-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Warehouses</p>
                        <p class="stat-value">{{ dashboardData.stats.total_warehouses }}</p>
                        <router-link to="/warehouses" class="stat-link">View all →</router-link>
                    </div>
                </div>
            </div>

            <!-- Orders Overview -->
            <div class="orders-grid">
                <!-- Sales Orders -->
                <div class="order-card">
                    <div class="card-header">
                        <h3 class="card-title">Sales Orders</h3>
                        <router-link to="/sales-orders" class="view-all-link">View All →</router-link>
                    </div>
                    <div class="order-stats">
                        <div class="order-stat">
                            <span class="order-stat-label">Total</span>
                            <span class="order-stat-value">{{ dashboardData.sales_orders.total }}</span>
                        </div>
                        <div class="order-stat">
                            <span class="order-stat-label">Pending</span>
                            <span class="order-stat-value text-yellow-600">{{ dashboardData.sales_orders.pending }}</span>
                        </div>
                        <div class="order-stat">
                            <span class="order-stat-label">Processing</span>
                            <span class="order-stat-value text-blue-600">{{ dashboardData.sales_orders.processing }}</span>
                        </div>
                        <div class="order-stat">
                            <span class="order-stat-label">Fulfilled</span>
                            <span class="order-stat-value text-green-600">{{ dashboardData.sales_orders.fulfilled }}</span>
                        </div>
                    </div>
                    <div class="order-total">
                        <span>Total Amount:</span>
                        <span class="font-bold text-lg">${{ formatNumber(dashboardData.sales_orders.total_amount) }}</span>
                    </div>
                </div>

                <!-- Purchase Orders -->
                <div class="order-card">
                    <div class="card-header">
                        <h3 class="card-title">Purchase Orders</h3>
                        <router-link to="/purchase-orders" class="view-all-link">View All →</router-link>
                    </div>
                    <div class="order-stats">
                        <div class="order-stat">
                            <span class="order-stat-label">Total</span>
                            <span class="order-stat-value">{{ dashboardData.purchase_orders.total }}</span>
                        </div>
                        <div class="order-stat">
                            <span class="order-stat-label">Pending</span>
                            <span class="order-stat-value text-yellow-600">{{ dashboardData.purchase_orders.pending }}</span>
                        </div>
                        <div class="order-stat">
                            <span class="order-stat-label">Received</span>
                            <span class="order-stat-value text-green-600">{{ dashboardData.purchase_orders.received }}</span>
                        </div>
                    </div>
                    <div class="order-total">
                        <span>Total Amount:</span>
                        <span class="font-bold text-lg">${{ formatNumber(dashboardData.purchase_orders.total_amount) }}</span>
                    </div>
                </div>
            </div>

            <!-- Inventory Overview -->
            <div class="inventory-section">
                <div class="section-header">
                    <h3 class="section-title">Inventory Overview</h3>
                    <router-link to="/inventory" class="view-all-link">View All →</router-link>
                </div>
                <div class="inventory-stats">
                    <div class="inventory-stat">
                        <p class="inventory-stat-label">Total Stock</p>
                        <p class="inventory-stat-value">{{ dashboardData.inventory.total_stock }}</p>
                    </div>
                    <div class="inventory-stat">
                        <p class="inventory-stat-label">Allocated</p>
                        <p class="inventory-stat-value text-yellow-600">{{ dashboardData.inventory.allocated_stock }}</p>
                    </div>
                    <div class="inventory-stat">
                        <p class="inventory-stat-label">Available</p>
                        <p class="inventory-stat-value text-green-600">{{ dashboardData.inventory.available_stock }}</p>
                    </div>
                    <div class="inventory-stat">
                        <p class="inventory-stat-label">Out of Stock</p>
                        <p class="inventory-stat-value text-red-600">{{ dashboardData.inventory.out_of_stock }}</p>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="recent-section">
                <!-- Recent Sales Orders -->
                <div class="recent-card">
                    <h3 class="card-title">Recent Sales Orders</h3>
                    <div v-if="dashboardData.sales_orders.recent.length > 0" class="recent-list">
                        <div v-for="order in dashboardData.sales_orders.recent" :key="order.id" class="recent-item">
                            <div class="recent-item-info">
                                <router-link :to="`/sales-orders/${order.id}`" class="recent-item-title">
                                    {{ order.order_number }}
                                </router-link>
                                <p class="recent-item-subtitle">{{ order.customer?.company_name }}</p>
                            </div>
                            <div class="recent-item-meta">
                                <span :class="['status-badge', `status-${order.status}`]">{{ order.status }}</span>
                                <span class="recent-item-amount">${{ formatNumber(order.total) }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state-small">
                        <p>No sales orders yet</p>
                    </div>
                </div>

                <!-- Recent Purchase Orders -->
                <div class="recent-card">
                    <h3 class="card-title">Recent Purchase Orders</h3>
                    <div v-if="dashboardData.purchase_orders.recent.length > 0" class="recent-list">
                        <div v-for="order in dashboardData.purchase_orders.recent" :key="order.id" class="recent-item">
                            <div class="recent-item-info">
                                <router-link :to="`/purchase-orders/${order.id}`" class="recent-item-title">
                                    {{ order.order_number }}
                                </router-link>
                                <p class="recent-item-subtitle">{{ order.supplier?.company_name }}</p>
                            </div>
                            <div class="recent-item-meta">
                                <span :class="['status-badge', `status-${order.status}`]">{{ order.status }}</span>
                                <span class="recent-item-amount">${{ formatNumber(order.total) }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state-small">
                        <p>No purchase orders yet</p>
                    </div>
                </div>

                <!-- Low Stock Items -->
                <div class="recent-card">
                    <h3 class="card-title">Low Stock Alerts</h3>
                    <div v-if="dashboardData.inventory.low_stock_items.length > 0" class="recent-list">
                        <div v-for="item in dashboardData.inventory.low_stock_items" :key="item.id" class="recent-item">
                            <div class="recent-item-info">
                                <p class="recent-item-title">{{ item.product?.name }}</p>
                                <p class="recent-item-subtitle">{{ item.warehouse?.name }}</p>
                            </div>
                            <div class="recent-item-meta">
                                <span class="text-red-600 font-semibold">{{ item.quantity_on_hand }} units</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state-small">
                        <p>No low stock items</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import dashboardService from '../services/dashboardService';

const loading = ref(true);
const dashboardData = ref(null);

onMounted(async () => {
    await fetchDashboard();
});

const fetchDashboard = async () => {
    loading.value = true;

    try {
        const response = await dashboardService.getStats();
        dashboardData.value = response.data;
        console.log('Dashboard data loaded:', dashboardData.value);
    } catch (error) {
        console.error('Error loading dashboard:', error);
    } finally {
        loading.value = false;
    }
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
};
</script>

<style scoped>
.dashboard {
    @apply space-y-6;
}

.dashboard-header {
    @apply mb-8;
}

.dashboard-title {
    @apply text-3xl font-bold text-gray-900;
}

.dashboard-subtitle {
    @apply text-gray-600 mt-2;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-20;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.dashboard-content {
    @apply space-y-6;
}

.stats-grid {
    @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6;
}

.stat-card {
    @apply bg-white rounded-lg shadow p-6 flex items-start gap-4;
}

.stat-icon {
    @apply w-16 h-16 rounded-lg flex items-center justify-center flex-shrink-0;
}

.stat-label {
    @apply text-sm text-gray-600 mb-1;
}

.stat-value {
    @apply text-3xl font-bold text-gray-900;
}

.stat-link {
    @apply text-sm text-indigo-600 hover:text-indigo-800 no-underline mt-2 inline-block;
}

.orders-grid {
    @apply grid grid-cols-1 md:grid-cols-2 gap-6;
}

.order-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-header {
    @apply flex items-center justify-between mb-4 pb-3 border-b border-gray-200;
}

.card-title {
    @apply text-lg font-semibold text-gray-900;
}

.view-all-link {
    @apply text-sm text-indigo-600 hover:text-indigo-800 no-underline;
}

.order-stats {
    @apply grid grid-cols-2 gap-4 mb-4;
}

.order-stat {
    @apply flex flex-col;
}

.order-stat-label {
    @apply text-sm text-gray-600;
}

.order-stat-value {
    @apply text-2xl font-bold text-gray-900;
}

.order-total {
    @apply flex items-center justify-between pt-4 border-t border-gray-200;
}

.inventory-section {
    @apply bg-white rounded-lg shadow p-6;
}

.section-header {
    @apply flex items-center justify-between mb-4 pb-3 border-b border-gray-200;
}

.section-title {
    @apply text-lg font-semibold text-gray-900;
}

.inventory-stats {
    @apply grid grid-cols-2 md:grid-cols-4 gap-6;
}

.inventory-stat {
    @apply text-center;
}

.inventory-stat-label {
    @apply text-sm text-gray-600 mb-2;
}

.inventory-stat-value {
    @apply text-3xl font-bold text-gray-900;
}

.recent-section {
    @apply grid grid-cols-1 lg:grid-cols-3 gap-6;
}

.recent-card {
    @apply bg-white rounded-lg shadow p-6;
}

.recent-list {
    @apply space-y-3;
}

.recent-item {
    @apply flex items-center justify-between p-3 bg-gray-50 rounded-lg;
}

.recent-item-info {
    @apply flex-1;
}

.recent-item-title {
    @apply font-medium text-gray-900 hover:text-indigo-600 no-underline;
}

.recent-item-subtitle {
    @apply text-sm text-gray-600;
}

.recent-item-meta {
    @apply flex flex-col items-end gap-2;
}

.recent-item-amount {
    @apply text-sm font-semibold text-gray-900;
}

.status-badge {
    @apply px-2 py-1 rounded-full text-xs font-medium;
}

.status-draft {
    @apply bg-gray-100 text-gray-800;
}

.status-pending {
    @apply bg-yellow-100 text-yellow-800;
}

.status-processing {
    @apply bg-blue-100 text-blue-800;
}

.status-fulfilled {
    @apply bg-green-100 text-green-800;
}

.status-received {
    @apply bg-green-100 text-green-800;
}

.status-cancelled {
    @apply bg-red-100 text-red-800;
}

.empty-state-small {
    @apply text-center py-8 text-gray-500;
}
</style>