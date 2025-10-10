<template>
    <div class="warehouses-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Warehouses</h1>
                <p class="page-subtitle">Manage warehouse locations</p>
            </div>
            <router-link to="/warehouses/create" class="btn-primary">
                Add Warehouse
            </router-link>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Search warehouses..."
                        class="filter-input"
                        @input="handleSearch"
                    />
                </div>

                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select
                        v-model="filters.is_active"
                        class="filter-select"
                        @change="applyFilters"
                    >
                        <option value="">All Warehouses</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <div class="filter-group flex items-end">
                    <button @click="clearFilters" class="btn-clear">
                        Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading warehouses...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="error-message">
            {{ error }}
        </div>

        <!-- Warehouses Grid -->
        <div v-else class="warehouses-grid">
            <div
                v-for="warehouse in warehouses"
                :key="warehouse.id"
                class="warehouse-card"
            >
                <div class="card-header">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="warehouse-name">{{ warehouse.name }}</h3>
                            <p class="warehouse-code">{{ warehouse.code }}</p>
                        </div>
                        <span
                            :class="[
                                'status-badge',
                                warehouse.is_active ? 'badge-active' : 'badge-inactive'
                            ]"
                        >
                            {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                </div>

                <div class="card-body">
                    <!-- Address -->
                    <div class="info-section">
                        <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <div class="info-text">
                            <p>{{ warehouse.address }}</p>
                            <p>{{ warehouse.city }}, {{ warehouse.state }} {{ warehouse.postal_code }}</p>
                            <p>{{ warehouse.country }}</p>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="stats-grid">
                        <div class="stat-item">
                            <p class="stat-label">Products</p>
                            <p class="stat-value">{{ warehouse.total_products || 0 }}</p>
                        </div>
                        <div class="stat-item">
                            <p class="stat-label">Total Stock</p>
                            <p class="stat-value">{{ warehouse.total_stock || 0 }}</p>
                        </div>
                        <div class="stat-item">
                            <p class="stat-label">Stock Value</p>
                            <p class="stat-value">${{ formatNumber(warehouse.stock_value) }}</p>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div v-if="warehouse.phone || warehouse.email" class="contact-section">
                        <p v-if="warehouse.phone" class="contact-item">
                            <svg class="icon-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            {{ warehouse.phone }}
                        </p>
                        <p v-if="warehouse.email" class="contact-item">
                            <svg class="icon-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            {{ warehouse.email }}
                        </p>
                    </div>

                    <!-- Manager -->
                    <div v-if="warehouse.manager" class="manager-section">
                        <svg class="icon-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span>Manager: {{ warehouse.manager }}</span>
                    </div>
                </div>

                <div class="card-footer">
                    <router-link
                        :to="`/warehouses/${warehouse.id}`"
                        class="btn-view"
                    >
                        View Details
                    </router-link>
                    <router-link
                        :to="`/warehouses/${warehouse.id}/edit`"
                        class="btn-edit"
                    >
                        Edit
                    </router-link>
                    <button
                        @click="handleDelete(warehouse)"
                        class="btn-delete"
                    >
                        Delete
                    </button>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="warehouses.length === 0" class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <h3>No Warehouses Found</h3>
                <p>Get started by adding your first warehouse</p>
                <router-link to="/warehouses/create" class="btn-primary mt-4">
                    Add Warehouse
                </router-link>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="pagination.total > pagination.per_page" class="pagination">
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
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useWarehouseStore } from '../../stores/warehouse';

const router = useRouter();
const warehouseStore = useWarehouseStore();

const warehouses = ref([]);
const loading = ref(false);
const error = ref(null);

const filters = reactive({
    search: '',
    is_active: '',
});

const pagination = reactive({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});

onMounted(async () => {
    await loadWarehouses();
});

const loadWarehouses = async () => {
    loading.value = true;
    error.value = null;

    try {
        const params = {
            page: pagination.current_page,
            per_page: pagination.per_page,
            search: filters.search,
            is_active: filters.is_active || undefined,
        };

        await warehouseStore.fetchWarehouses(params);
        
        warehouses.value = warehouseStore.warehouses;
        
        if (warehouseStore.pagination) {
            pagination.current_page = warehouseStore.pagination.current_page;
            pagination.per_page = warehouseStore.pagination.per_page;
            pagination.total = warehouseStore.pagination.total;
            pagination.last_page = warehouseStore.pagination.last_page;
        }
    } catch (err) {
        console.error('Error loading warehouses:', err);
        error.value = 'Failed to load warehouses';
    } finally {
        loading.value = false;
    }
};

let searchTimeout;
const handleSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 500);
};

const applyFilters = () => {
    pagination.current_page = 1;
    loadWarehouses();
};

const clearFilters = () => {
    filters.search = '';
    filters.is_active = '';
    applyFilters();
};

const changePage = (page) => {
    pagination.current_page = page;
    loadWarehouses();
};

const formatNumber = (value) => {
    if (!value) return '0.00';
    return parseFloat(value).toFixed(2);
};

const handleDelete = async (warehouse) => {
    if (!confirm(`Are you sure you want to delete ${warehouse.name}?`)) {
        return;
    }

    try {
        await warehouseStore.deleteWarehouse(warehouse.id);
        loadWarehouses();
    } catch (err) {
        alert(err.response?.data?.message || 'Failed to delete warehouse');
    }
};
</script>

<style scoped>
.warehouses-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-start justify-between mb-6;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.btn-primary {
    @apply bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors no-underline;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6 mb-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-3 gap-4;
}

.filter-group {
    @apply flex flex-col;
}

.filter-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.filter-input,
.filter-select {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.btn-clear {
    @apply w-full bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200 transition-colors;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-12;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.error-message {
    @apply bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg;
}

.warehouses-grid {
    @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6;
}

.warehouse-card {
    @apply bg-white rounded-lg shadow hover:shadow-lg transition-shadow flex flex-col;
}

.card-header {
    @apply p-6 pb-4 border-b border-gray-200;
}

.warehouse-name {
    @apply text-xl font-semibold text-gray-900;
}

.warehouse-code {
    @apply text-sm text-gray-600 mt-1;
}

.status-badge {
    @apply px-3 py-1 rounded-full text-xs font-medium;
}

.badge-active {
    @apply bg-green-100 text-green-800;
}

.badge-inactive {
    @apply bg-red-100 text-red-800;
}

.card-body {
    @apply p-6 flex-1 space-y-4;
}

.info-section {
    @apply flex items-start gap-3;
}

.icon {
    @apply w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5;
}

.info-text {
    @apply text-sm text-gray-600 space-y-0.5;
}

.stats-grid {
    @apply grid grid-cols-3 gap-2 p-4 bg-gray-50 rounded-lg;
}

.stat-item {
    @apply text-center;
}

.stat-label {
    @apply text-xs text-gray-600 mb-1;
}

.stat-value {
    @apply text-lg font-semibold text-gray-900;
}

.contact-section,
.manager-section {
    @apply text-sm text-gray-600 space-y-2;
}

.contact-item,
.manager-section {
    @apply flex items-center gap-2;
}

.icon-sm {
    @apply w-4 h-4 text-gray-400;
}

.card-footer {
    @apply p-4 bg-gray-50 border-t border-gray-200 flex gap-2;
}

.btn-view,
.btn-edit {
    @apply flex-1 text-center px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors no-underline text-gray-700;
}

.btn-delete {
    @apply px-3 py-2 text-sm text-red-600 border border-red-300 rounded-lg hover:bg-red-50 transition-colors;
}

.empty-state {
    @apply col-span-full flex flex-col items-center justify-center py-12 text-center;
}

.empty-icon {
    @apply w-16 h-16 text-gray-400 mb-4;
}

.empty-state h3 {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.empty-state p {
    @apply text-gray-600;
}

.pagination {
    @apply flex items-center justify-between px-6 py-4 bg-white rounded-lg shadow;
}

.pagination-btn {
    @apply px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}

.pagination-info {
    @apply text-sm text-gray-700;
}
</style>