<template>
    <div class="purchase-orders-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Purchase Orders</h1>
                <p class="page-subtitle">Manage supplier purchase orders</p>
            </div>
            <router-link to="/purchase-orders/create" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Create Purchase Order
            </router-link>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total Orders</p>
                    <p class="stat-value">{{ orderStore.pagination.total || 0 }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-yellow-100 text-yellow-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Pending</p>
                    <p class="stat-value">{{ pendingOrders }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Received</p>
                    <p class="stat-value">{{ receivedOrders }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-purple-100 text-purple-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total Value</p>
                    <p class="stat-value">${{ formatNumber(totalValue) }}</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input
                        v-model="filters.search"
                        @input="handleSearch"
                        type="text"
                        placeholder="Search orders..."
                        class="filter-input"
                    />
                </div>

                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select v-model="filters.status" @change="fetchOrders(true)" class="filter-select">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                        <option value="received">Received</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Date From</label>
                    <input
                        v-model="filters.date_from"
                        @change="fetchOrders(true)"
                        type="date"
                        class="filter-input"
                    />
                </div>

                <div class="filter-group">
                    <label class="filter-label">Date To</label>
                    <input
                        v-model="filters.date_to"
                        @change="fetchOrders(true)"
                        type="date"
                        class="filter-input"
                    />
                </div>

                <div class="filter-actions">
                    <button @click="resetFilters" class="btn btn-secondary btn-sm">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="table-card">
            <!-- Loading State -->
            <div v-if="orderStore.loading" class="loading-container">
                <div class="spinner"></div>
                <p>Loading orders...</p>
            </div>

            <!-- Empty State -->
            <div v-else-if="orders.length === 0" class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3>No purchase orders found</h3>
                <p>Get started by creating your first purchase order</p>
                <router-link to="/purchase-orders/create" class="btn btn-primary mt-4">
                    Create Purchase Order
                </router-link>
            </div>

            <!-- Table -->
            <div v-else class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order Number</th>
                            <th>Supplier</th>
                            <th>Warehouse</th>
                            <th>Order Date</th>
                            <th>Expected Date</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                            <td class="font-medium">
                                <router-link 
                                    :to="`/purchase-orders/${order.id}`"
                                    class="link"
                                >
                                    {{ order.order_number }}
                                </router-link>
                            </td>
                            <td>{{ order.supplier?.company_name }}</td>
                            <td>{{ order.warehouse?.name }}</td>
                            <td>{{ formatDate(order.order_date) }}</td>
                            <td>{{ order.expected_date ? formatDate(order.expected_date) : 'N/A' }}</td>
                            <td>
                                <span
                                    :class="[
                                        'status-badge',
                                        `status-${order.status}`
                                    ]"
                                >
                                    {{ order.status }}
                                </span>
                            </td>
                            <td class="text-right font-semibold">${{ formatNumber(order.total) }}</td>
                            <td>
                                <div class="action-buttons">
                                    <router-link
                                        :to="`/purchase-orders/${order.id}`"
                                        class="btn-icon btn-icon-view"
                                        title="View"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </router-link>
                                    <router-link
                                        v-if="order.status === 'draft' || order.status === 'pending'"
                                        :to="`/purchase-orders/${order.id}/edit`"
                                        class="btn-icon btn-icon-edit"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </router-link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="orders.length > 0" class="pagination">
                <div class="pagination-info">
                    Showing {{ (orderStore.pagination.current_page - 1) * orderStore.pagination.per_page + 1 }} 
                    to {{ Math.min(orderStore.pagination.current_page * orderStore.pagination.per_page, orderStore.pagination.total) }} 
                    of {{ orderStore.pagination.total }} results
                </div>
                <div class="pagination-buttons">
                    <button
                        @click="changePage(orderStore.pagination.current_page - 1)"
                        :disabled="orderStore.pagination.current_page === 1"
                        class="pagination-btn"
                    >
                        Previous
                    </button>
                    <button
                        @click="changePage(orderStore.pagination.current_page + 1)"
                        :disabled="orderStore.pagination.current_page === orderStore.pagination.last_page"
                        class="pagination-btn"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useOrderStore } from '../../../stores/order';

const orderStore = useOrderStore();

const filters = ref({
    search: '',
    status: '',
    date_from: '',
    date_to: '',
});

const orders = computed(() => orderStore.purchaseOrders);

const pendingOrders = computed(() => {
    return orders.value.filter(o => o.status === 'pending').length;
});

const receivedOrders = computed(() => {
    return orders.value.filter(o => o.status === 'received').length;
});

const totalValue = computed(() => {
    return orders.value.reduce((sum, order) => sum + parseFloat(order.total || 0), 0);
});

let searchTimeout;

onMounted(() => {
    fetchOrders();
});

// const fetchOrders = async () => {
//     try {
//         await orderStore.fetchPurchaseOrders(filters.value);
//     } catch (error) {
//         console.error('Error fetching orders:', error);
//     }
// };

const fetchOrders = async (resetPage = false) => {
    if (resetPage) filters.value.page = 1;
    try {
        await orderStore.fetchPurchaseOrders(filters.value);
    } catch (error) {
        console.error('Error fetching orders:', error);
    }
};

const handleSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchOrders(true); // reset page
    }, 300);
};

const resetFilters = () => {
    filters.value = { search: '', status: '', date_from: '', date_to: '', page: 1 };
    fetchOrders();
};

// const changePage = (page) => {
//     fetchOrders({ ...filters.value, page });
// };

const changePage = (page) => {
    filters.value.page = page;
    fetchOrders();
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};
</script>

<style scoped>
.purchase-orders-page {
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

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors no-underline;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-sm {
    @apply px-3 py-1.5 text-sm;
}

.stats-grid {
    @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6;
}

.stat-card {
    @apply bg-white rounded-lg shadow p-6 flex items-center gap-4;
}

.stat-icon {
    @apply w-16 h-16 rounded-lg flex items-center justify-center flex-shrink-0;
}

.stat-label {
    @apply text-sm text-gray-600 mb-1;
}

.stat-value {
    @apply text-2xl font-bold text-gray-900;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-5 gap-4 items-end;
}

.filter-group {
    @apply flex flex-col;
}

.filter-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.filter-input,
.filter-select {
    @apply px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.filter-actions {
    @apply flex items-end;
}

.table-card {
    @apply bg-white rounded-lg shadow;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-12;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.empty-state {
    @apply flex flex-col items-center justify-center py-12 text-center;
}

.empty-icon {
    @apply w-16 h-16 text-gray-300 mb-4;
}

.empty-state h3 {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.empty-state p {
    @apply text-gray-600;
}

.table-container {
    @apply overflow-x-auto;
}

.table {
    @apply w-full;
}

.table thead {
    @apply bg-gray-50;
}

.table th {
    @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider;
}

.table td {
    @apply px-6 py-4 whitespace-nowrap text-sm text-gray-700 border-t border-gray-200;
}

.link {
    @apply text-indigo-600 hover:text-indigo-900 no-underline;
}

.status-badge {
    @apply inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium;
}

.status-draft {
    @apply bg-gray-100 text-gray-800;
}

.status-pending {
    @apply bg-yellow-100 text-yellow-800;
}

.status-received {
    @apply bg-green-100 text-green-800;
}

.status-cancelled {
    @apply bg-red-100 text-red-800;
}

.action-buttons {
    @apply flex items-center justify-center gap-2;
}

.btn-icon {
    @apply p-2 rounded-lg transition-colors no-underline;
}

.btn-icon-view {
    @apply text-blue-600 hover:bg-blue-50;
}

.btn-icon-edit {
    @apply text-green-600 hover:bg-green-50;
}

.pagination {
    @apply flex items-center justify-between px-6 py-4 border-t border-gray-200;
}

.pagination-info {
    @apply text-sm text-gray-700;
}

.pagination-buttons {
    @apply flex gap-2;
}

.pagination-btn {
    @apply px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors;
}
</style>