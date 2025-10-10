<template>
    <div class="products-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Products</h1>
                <p class="page-subtitle">Manage your product catalog</p>
            </div>
            <div class="page-actions">
                <button @click="exportProducts" class="btn btn-secondary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Export
                </button>
                <router-link to="/products/create" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Product
                </router-link>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <FormInput
                    v-model="filters.search"
                    placeholder="Search products..."
                    @input="debouncedSearch"
                >
                    <template #append>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </template>
                </FormInput>

                <FormSelect
                    v-model="filters.category_id"
                    label="Category"
                    :options="categories"
                    placeholder="All Categories"
                    @update:modelValue="applyFilters"
                />

                <FormSelect
                    v-model="filters.is_active"
                    label="Status"
                    :options="statusOptions"
                    placeholder="All Status"
                    @update:modelValue="applyFilters"
                />

                <div class="flex items-end">
                    <button @click="resetFilters" class="btn btn-secondary w-full">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <Alert
            v-if="successMessage"
            type="success"
            :message="successMessage"
            @close="successMessage = ''"
        />

        <Alert
            v-if="productStore.error"
            type="error"
            :message="productStore.error"
            @close="productStore.clearError()"
        />

        <!-- Products Table -->
        <DataTable
            :columns="columns"
            :data="productStore.products"
            :loading="productStore.loading"
            :pagination="productStore.pagination"
            empty-message="No products found"
            @page-change="handlePageChange"
            @sort="handleSort"
        >
            <template #cell-image="{ row }">
                <div class="product-image">
                    <img v-if="row.image" :src="row.image" :alt="row.name" />
                    <div v-else class="image-placeholder">
                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                </div>
            </template>

            <template #cell-name="{ row }">
                <div class="product-info">
                    <router-link :to="`/products/${row.id}`" class="product-name">
                        {{ row.name }}
                    </router-link>
                    <p class="product-sku">SKU: {{ row.sku }}</p>
                </div>
            </template>

            <template #cell-category="{ row }">
                <Badge variant="info">{{ row.category?.name || 'Uncategorized' }}</Badge>
            </template>

            <template #cell-price="{ row }">
                <div class="product-price">
                    <span class="price-amount">${{ formatNumber(row.price) }}</span>
                    <span class="price-cost text-gray-500">Cost: ${{ formatNumber(row.cost) }}</span>
                </div>
            </template>

            <template #cell-stock="{ row }">
                <div class="stock-info">
                    <span :class="getStockClass(row.total_stock, row.reorder_point)">
                        {{ row.total_stock || 0 }} units
                    </span>
                    <span v-if="row.total_stock <= row.reorder_point" class="text-xs text-red-600">
                        Low Stock
                    </span>
                </div>
            </template>

            <template #cell-is_active="{ row }">
                <Badge :variant="row.is_active ? 'success' : 'error'">
                    {{ row.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </template>

            <template #cell-actions="{ row }">
                <div class="action-buttons">
                    <router-link :to="`/products/${row.id}`" class="action-btn view">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </router-link>
                    <router-link :to="`/products/${row.id}/edit`" class="action-btn edit">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </router-link>
                    <button @click="confirmDelete(row)" class="action-btn delete">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- Delete Confirmation Modal -->
        <Modal
            :show="showDeleteModal"
            title="Delete Product"
            size="small"
            @close="showDeleteModal = false"
        >
            <div class="delete-confirmation">
                <div class="warning-icon">
                    <svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p class="delete-message">
                    Are you sure you want to delete <strong>{{ productToDelete?.name }}</strong>?
                    This action cannot be undone.
                </p>
            </div>

            <template #footer>
                <button @click="showDeleteModal = false" class="btn btn-secondary">
                    Cancel
                </button>
                <button @click="deleteProduct" class="btn btn-danger" :disabled="deleting">
                    <span v-if="deleting" class="spinner-small"></span>
                    <span>{{ deleting ? 'Deleting...' : 'Delete' }}</span>
                </button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useProductStore } from '../../stores/product';
import DataTable from '../../components/common/DataTable.vue';
import FormInput from '../../components/common/FormInput.vue';
import FormSelect from '../../components/common/FormSelect.vue';
import Badge from '../../components/common/Badge.vue';
import Alert from '../../components/common/Alert.vue';
import Modal from '../../components/common/Modal.vue';

const productStore = useProductStore();

const filters = ref({
    search: '',
    category_id: '',
    is_active: '',
});

const categories = ref([]);
const statusOptions = [
    { id: '1', name: 'Active' },
    { id: '0', name: 'Inactive' },
];

const successMessage = ref('');
const showDeleteModal = ref(false);
const productToDelete = ref(null);
const deleting = ref(false);

const columns = [
    { key: 'image', label: 'Image', sortable: false },
    { key: 'name', label: 'Product', sortable: true },
    { key: 'category', label: 'Category', sortable: false },
    { key: 'price', label: 'Price', sortable: true },
    { key: 'stock', label: 'Stock', sortable: false },
    { key: 'is_active', label: 'Status', sortable: true },
    { key: 'actions', label: 'Actions', sortable: false, class: 'text-right' },
];

// Debounced search
let searchTimeout;
const debouncedSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 500);
};

const applyFilters = async () => {
    productStore.setFilters(filters.value);
    await productStore.fetchProducts();
};

const resetFilters = async () => {
    filters.value = {
        search: '',
        category_id: '',
        is_active: '',
    };
    productStore.resetFilters();
    await productStore.fetchProducts();
};

const handlePageChange = async (page) => {
    await productStore.fetchProducts({ page });
};

const handleSort = async ({ key, order }) => {
    await productStore.fetchProducts({ sort_by: key, sort_order: order });
};

const confirmDelete = (product) => {
    productToDelete.value = product;
    showDeleteModal.value = true;
};

const deleteProduct = async () => {
    if (!productToDelete.value) return;

    deleting.value = true;
    try {
        await productStore.deleteProduct(productToDelete.value.id);
        successMessage.value = 'Product deleted successfully';
        showDeleteModal.value = false;
        productToDelete.value = null;
    } catch (error) {
        console.error('Delete error:', error);
    } finally {
        deleting.value = false;
    }
};

const exportProducts = () => {
    // TODO: Implement export functionality
    alert('Export functionality coming soon!');
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};

const getStockClass = (stock, reorderPoint) => {
    if (stock <= 0) return 'text-red-600 font-semibold';
    if (stock <= reorderPoint) return 'text-yellow-600 font-semibold';
    return 'text-green-600 font-semibold';
};

onMounted(async () => {
    await productStore.fetchProducts();
    await productStore.fetchCategories();
    categories.value = productStore.categories;
});
</script>

<style scoped>
.products-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-center justify-between;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.page-actions {
    @apply flex gap-3;
}

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-all;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700 disabled:opacity-50;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-4 gap-4;
}

.product-image {
    @apply w-12 h-12 rounded-lg overflow-hidden;
}

.product-image img {
    @apply w-full h-full object-cover;
}

.image-placeholder {
    @apply w-full h-full bg-gray-100 flex items-center justify-center;
}

.product-info {
    @apply space-y-1;
}

.product-name {
    @apply font-medium text-gray-900 hover:text-indigo-600 no-underline;
}

.product-sku {
    @apply text-sm text-gray-500;
}

.product-price {
    @apply space-y-1;
}

.price-amount {
    @apply block font-semibold text-gray-900;
}

.price-cost {
    @apply block text-sm;
}

.stock-info {
    @apply space-y-1;
}

.action-buttons {
    @apply flex items-center justify-end gap-2;
}

.action-btn {
    @apply p-2 rounded-lg transition-colors;
}

.action-btn.view {
    @apply text-blue-600 hover:bg-blue-50;
}

.action-btn.edit {
    @apply text-indigo-600 hover:bg-indigo-50;
}

.action-btn.delete {
    @apply text-red-600 hover:bg-red-50;
}

.delete-confirmation {
    @apply text-center py-4;
}

.warning-icon {
    @apply flex justify-center mb-4;
}

.delete-message {
    @apply text-gray-700;
}

.spinner-small {
    @apply inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin;
}
</style>