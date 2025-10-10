<template>
    <div class="inventory-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Inventory</h1>
                <p class="page-subtitle">Manage stock levels across warehouses</p>
            </div>
            <div class="header-actions">
                <router-link to="/inventory/adjust" class="btn-primary">
                    Adjust Stock
                </router-link>
                <router-link to="/inventory/transfer" class="btn-secondary">
                    Transfer Stock
                </router-link>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <!-- Search -->
                <div class="filter-group">
                    <label class="filter-label">Search Product</label>
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Search by name or SKU..."
                        class="filter-input"
                        @input="handleSearch"
                    />
                </div>

                <!-- Warehouse Filter -->
                <div class="filter-group">
                    <label class="filter-label">Warehouse</label>
                    <select
                        v-model="filters.warehouse_id"
                        class="filter-select"
                        @change="applyFilters"
                    >
                        <option value="">All Warehouses</option>
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }}
                        </option>
                    </select>
                </div>

                <!-- Stock Status Filter -->
                <div class="filter-group">
                    <label class="filter-label">Stock Status</label>
                    <select
                        v-model="filters.stock_status"
                        class="filter-select"
                        @change="applyFilters"
                    >
                        <option value="">All Items</option>
                        <option value="in_stock">In Stock</option>
                        <option value="low_stock">Low Stock</option>
                        <option value="out_of_stock">Out of Stock</option>
                    </select>
                </div>

                <!-- Clear Filters -->
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
            <p>Loading inventory...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="error-message">
            {{ error }}
        </div>

        <!-- Inventory Table -->
        <div v-else class="table-card">
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Warehouse</th>
                            <th>On Hand</th>
                            <th>Available</th>
                            <th>Allocated</th>
                            <th>On Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="inventory.length === 0">
                            <td colspan="9" class="text-center py-8 text-gray-500">
                                No inventory records found
                            </td>
                        </tr>
                        <tr v-for="item in inventory" :key="`${item.product_id}-${item.warehouse_id}`">
                            <td class="font-medium">{{ item.product?.name }}</td>
                            <td class="text-gray-600">{{ item.product?.sku }}</td>
                            <td>{{ item.warehouse?.name }}</td>
                            <td class="font-semibold">{{ item.quantity_on_hand }}</td>
                            <td class="text-green-600">{{ item.quantity_available }}</td>
                            <td class="text-yellow-600">{{ item.quantity_allocated }}</td>
                            <td class="text-blue-600">{{ item.quantity_on_order }}</td>
                            <td>
                                <span :class="getStockStatusClass(item)">
                                    {{ getStockStatus(item) }}
                                </span>
                            </td>
                            <td>
                                <button
                                    @click="adjustInventory(item)"
                                    class="btn-action"
                                    title="Adjust Stock"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useInventoryStore } from '../../stores/inventory';
import { useWarehouseStore } from '../../stores/warehouse';

const router = useRouter();
const inventoryStore = useInventoryStore();
const warehouseStore = useWarehouseStore();

const inventory = ref([]);
const warehouses = ref([]);
const loading = ref(false);
const error = ref(null);

const filters = reactive({
    search: '',
    warehouse_id: '',
    stock_status: '',
});

const pagination = reactive({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});

onMounted(async () => {
    await loadWarehouses();
    await loadInventory();
});

const loadWarehouses = async () => {
    try {
        await warehouseStore.fetchWarehouses();
        warehouses.value = warehouseStore.warehouses;
    } catch (err) {
        console.error('Error loading warehouses:', err);
    }
};

const loadInventory = async () => {
    loading.value = true;
    error.value = null;

    try {
        const params = {
            page: pagination.current_page,
            per_page: pagination.per_page,
            search: filters.search,
            warehouse_id: filters.warehouse_id || undefined,
            stock_status: filters.stock_status || undefined,
        };

        console.log('Loading inventory with params:', params);

        await inventoryStore.fetchInventory(params);
        
        inventory.value = inventoryStore.inventory;
        
        // Update pagination
        pagination.current_page = inventoryStore.pagination.current_page;
        pagination.per_page = inventoryStore.pagination.per_page;
        pagination.total = inventoryStore.pagination.total;
        pagination.last_page = inventoryStore.pagination.last_page;

        console.log('Inventory loaded:', inventory.value.length, 'items');
    } catch (err) {
        console.error('Error loading inventory:', err);
        error.value = 'Failed to load inventory';
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
    loadInventory();
};

const clearFilters = () => {
    filters.search = '';
    filters.warehouse_id = '';
    filters.stock_status = '';
    applyFilters();
};

const changePage = (page) => {
    pagination.current_page = page;
    loadInventory();
};

const getStockStatus = (item) => {
    const product = item.product;
    if (!product) return 'Unknown';

    if (item.quantity_on_hand === 0) return 'Out of Stock';
    if (product.reorder_point && item.quantity_on_hand <= product.reorder_point) {
        return 'Low Stock';
    }
    return 'In Stock';
};

const getStockStatusClass = (item) => {
    const status = getStockStatus(item);
    const baseClass = 'px-2 py-1 rounded-full text-xs font-medium';
    
    if (status === 'Out of Stock') return `${baseClass} bg-red-100 text-red-800`;
    if (status === 'Low Stock') return `${baseClass} bg-yellow-100 text-yellow-800`;
    return `${baseClass} bg-green-100 text-green-800`;
};

const adjustInventory = (item) => {
    // Navigate to adjust page with complete item data
    router.push({
        path: '/inventory/adjust',
        query: {
            product_id: item.product_id,
            warehouse_id: item.warehouse_id,
            product_name: item.product?.name,
            product_sku: item.product?.sku,
            warehouse_name: item.warehouse?.name,
            current_stock: item.quantity_on_hand,
        }
    });
};
</script>

<style scoped>
.inventory-page {
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

.header-actions {
    @apply flex gap-3;
}

.btn-primary {
    @apply bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors no-underline;
}

.btn-secondary {
    @apply bg-white text-indigo-600 border border-indigo-600 px-4 py-2 rounded-lg hover:bg-indigo-50 transition-colors no-underline;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6 mb-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4;
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

.table-card {
    @apply bg-white rounded-lg shadow overflow-hidden;
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
    @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider;
}

.data-table td {
    @apply px-6 py-4 whitespace-nowrap text-sm text-gray-900 border-t border-gray-200;
}

.btn-action {
    @apply text-indigo-600 hover:text-indigo-900 transition-colors;
}

.pagination {
    @apply flex items-center justify-between px-6 py-4 border-t border-gray-200;
}

.pagination-btn {
    @apply px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}

.pagination-info {
    @apply text-sm text-gray-700;
}
</style>