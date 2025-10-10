<template>
    <div class="show-warehouse-page">
        <!-- Loading -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading warehouse...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="error-container">
            <div class="error-card">
                <h2>Warehouse Not Found</h2>
                <p>{{ error }}</p>
                <router-link to="/warehouses" class="btn-back">
                    ← Back to Warehouses
                </router-link>
            </div>
        </div>

        <!-- Warehouse Details -->
        <div v-else-if="warehouse" class="warehouse-details">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <router-link to="/warehouses" class="back-link">
                        ← Back to Warehouses
                    </router-link>
                    <div class="flex items-center gap-4">
                        <h1 class="page-title">{{ warehouse.name }}</h1>
                        <span
                            :class="[
                                'status-badge',
                                warehouse.is_active ? 'badge-active' : 'badge-inactive'
                            ]"
                        >
                            {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <p class="warehouse-code">{{ warehouse.code }}</p>
                </div>
                <div class="header-actions">
                    <router-link 
                        :to="`/warehouses/${warehouse.id}/edit`" 
                        class="btn-edit"
                    >
                        Edit Warehouse
                    </router-link>
                    <button @click="handleDelete" class="btn-delete">
                        Delete
                    </button>
                </div>
            </div>

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
                        <p class="stat-value">{{ warehouse.total_products || 0 }}</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-green-100 text-green-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Stock</p>
                        <p class="stat-value">{{ warehouse.total_stock || 0 }}</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-purple-100 text-purple-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Stock Value</p>
                        <p class="stat-value">${{ formatNumber(warehouse.stock_value) }}</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-yellow-100 text-yellow-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Low Stock Items</p>
                        <p class="stat-value">{{ warehouse.low_stock_items?.length || 0 }}</p>
                    </div>
                </div>
            </div>

            <!-- Information Grid -->
            <div class="info-grid">
                <!-- Location Details -->
                <div class="info-card">
                    <h3 class="card-title">Location Details</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Address:</span>
                            <span class="value">{{ warehouse.address }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">City:</span>
                            <span class="value">{{ warehouse.city }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">State/Province:</span>
                            <span class="value">{{ warehouse.state }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Postal Code:</span>
                            <span class="value">{{ warehouse.postal_code }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Country:</span>
                            <span class="value">{{ warehouse.country }}</span>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="info-card">
                    <h3 class="card-title">Contact Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Phone:</span>
                            <span class="value">{{ warehouse.phone || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">{{ warehouse.email || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Manager:</span>
                            <span class="value">{{ warehouse.manager || 'N/A' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Products -->
            <div v-if="warehouse.top_products?.length" class="info-card">
                <h3 class="card-title">Top 10 Products by Stock</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>On Hand</th>
                                <th>Available</th>
                                <th>Allocated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in warehouse.top_products" :key="item.id">
                                <td class="font-medium">{{ item.product?.name }}</td>
                                <td class="text-gray-600">{{ item.product?.sku }}</td>
                                <td class="font-semibold">{{ item.quantity_on_hand }}</td>
                                <td class="text-green-600">{{ item.quantity_available }}</td>
                                <td class="text-yellow-600">{{ item.quantity_allocated }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Low Stock Alert -->
            <div v-if="warehouse.low_stock_items?.length" class="alert-card">
                <div class="alert-header">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <h3 class="card-title">Low Stock Alerts</h3>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Current Stock</th>
                                <th>Reorder Point</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in warehouse.low_stock_items" :key="item.id">
                                <td>
                                    <div>
                                        <p class="font-medium">{{ item.product?.name }}</p>
                                        <p class="text-sm text-gray-500">{{ item.product?.sku }}</p>
                                    </div>
                                </td>
                                <td class="text-red-600 font-semibold">{{ item.quantity_on_hand }}</td>
                                <td>{{ item.product?.reorder_point }}</td>
                                <td>
                                    <router-link 
                                        :to="{
                                            path: '/inventory/adjust',
                                            query: {
                                                product_id: item.product_id,
                                                warehouse_id: warehouse.id
                                            }
                                        }"
                                        class="btn-action"
                                    >
                                        Reorder
                                    </router-link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useWarehouseStore } from '../../stores/warehouse';

const route = useRoute();
const router = useRouter();
const warehouseStore = useWarehouseStore();

const warehouse = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    await fetchWarehouse();
});

const fetchWarehouse = async () => {
    loading.value = true;
    error.value = null;

    try {
        const id = route.params.id;
        const response = await warehouseStore.fetchWarehouse(id);
        warehouse.value = response;
    } catch (err) {
        console.error('Error fetching warehouse:', err);
        error.value = err.response?.data?.message || 'Warehouse not found';
    } finally {
        loading.value = false;
    }
};

const formatNumber = (value) => {
    if (!value) return '0.00';
    return parseFloat(value).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
};

const handleDelete = async () => {
    if (!confirm(`Are you sure you want to delete ${warehouse.value.name}?`)) {
        return;
    }

    try {
        await warehouseStore.deleteWarehouse(warehouse.value.id);
        router.push('/warehouses');
    } catch (err) {
        alert(err.response?.data?.message || 'Failed to delete warehouse');
    }
};
</script>
<style scoped>
.show-warehouse-page {
    @apply max-w-7xl mx-auto;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-20;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.error-container {
    @apply flex items-center justify-center py-20;
}

.error-card {
    @apply bg-white rounded-lg shadow p-8 text-center max-w-md;
}

.error-card h2 {
    @apply text-2xl font-bold text-gray-900 mb-2;
}

.error-card p {
    @apply text-gray-600 mb-6;
}

.btn-back {
    @apply inline-block bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700 transition-colors no-underline;
}

.warehouse-details {
    @apply space-y-6;
}

.page-header {
    @apply flex items-start justify-between mb-6;
}

.back-link {
    @apply text-indigo-600 hover:text-indigo-700 mb-2 inline-block no-underline;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.warehouse-code {
    @apply text-gray-600 mt-1;
}

.status-badge {
    @apply px-3 py-1 rounded-full text-sm font-medium;
}

.badge-active {
    @apply bg-green-100 text-green-800;
}

.badge-inactive {
    @apply bg-red-100 text-red-800;
}

.header-actions {
    @apply flex gap-3;
}

.btn-edit {
    @apply bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors no-underline;
}

.btn-delete {
    @apply bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition-colors;
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

.info-grid {
    @apply grid grid-cols-1 md:grid-cols-2 gap-6;
}

.info-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200;
}

.info-rows {
    @apply space-y-3;
}

.info-row {
    @apply flex justify-between items-start;
}

.label {
    @apply text-gray-600 font-medium;
}

.value {
    @apply text-gray-900 text-right;
}

.table-container {
    @apply overflow-x-auto;
}

.data-table {
    @apply w-full;
}

.data-table thead {
    @apply bg-gray-50;
}

.data-table th {
    @apply px-4 py-3 text-left text-sm font-semibold text-gray-900;
}

.data-table td {
    @apply px-4 py-3 text-sm text-gray-700 border-t border-gray-200;
}

.alert-card {
    @apply bg-yellow-50 border border-yellow-200 rounded-lg p-6;
}

.alert-header {
    @apply flex items-center gap-3 mb-4;
}

.btn-action {
    @apply text-indigo-600 hover:text-indigo-800 font-medium no-underline;
}
</style>