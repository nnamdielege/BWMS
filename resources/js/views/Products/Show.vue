<template>
    <div class="product-show-page">
        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading product...</p>
        </div>

        <!-- Error State -->
        <div v-else-if="error" class="error-container">
            <div class="error-card">
                <h2>Product Not Found</h2>
                <p>{{ error }}</p>
                <router-link to="/products" class="btn-back">
                    ← Back to Products
                </router-link>
            </div>
        </div>

        <!-- Product Details -->
        <div v-else-if="product" class="product-details">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <router-link to="/products" class="back-link">
                        ← Back to Products
                    </router-link>
                    <h1 class="page-title">{{ product.name }}</h1>
                    <p class="product-sku">SKU: {{ product.sku }}</p>
                </div>
                <div class="header-actions">
                    <router-link 
                        :to="`/products/${product.id}/edit`" 
                        class="btn-edit"
                    >
                        Edit Product
                    </router-link>
                    <button @click="handleDelete" class="btn-delete">
                        Delete
                    </button>
                </div>
            </div>

            <!-- Product Info Grid -->
            <div class="info-grid">
                <!-- Basic Information -->
                <div class="info-card">
                    <h3 class="card-title">Basic Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Name:</span>
                            <span class="value">{{ product.name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">SKU:</span>
                            <span class="value">{{ product.sku }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Barcode:</span>
                            <span class="value">{{ product.barcode || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Category:</span>
                            <span class="value">{{ product.category?.name || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Unit:</span>
                            <span class="value">{{ product.unit_of_measure }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Status:</span>
                            <span :class="['badge', product.is_active ? 'badge-active' : 'badge-inactive']">
                                {{ product.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Pricing -->
                <div class="info-card">
                    <h3 class="card-title">Pricing</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Cost:</span>
                            <span class="value">${{ parseFloat(product.cost).toFixed(2) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Price:</span>
                            <span class="value">${{ parseFloat(product.price).toFixed(2) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Margin:</span>
                            <span class="value">
                                ${{ (parseFloat(product.price) - parseFloat(product.cost)).toFixed(2) }}
                                ({{ calculateMargin() }}%)
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="info-card">
                    <h3 class="card-title">Inventory</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Total Stock:</span>
                            <span class="value font-bold">{{ product.total_stock || 0 }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Reorder Point:</span>
                            <span class="value">{{ product.reorder_point || 'Not set' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Reorder Quantity:</span>
                            <span class="value">{{ product.reorder_quantity || 'Not set' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Physical Details -->
                <div class="info-card">
                    <h3 class="card-title">Physical Details</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Weight:</span>
                            <span class="value">{{ product.weight ? `${product.weight} kg` : 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Dimensions:</span>
                            <span class="value">{{ product.dimensions || 'N/A' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div v-if="product.description" class="info-card full-width">
                <h3 class="card-title">Description</h3>
                <p class="description-text">{{ product.description }}</p>
            </div>

            <!-- Notes -->
            <div v-if="product.notes" class="info-card full-width">
                <h3 class="card-title">Notes</h3>
                <p class="notes-text">{{ product.notes }}</p>
            </div>

            <!-- Inventory by Warehouse -->
            <div v-if="product.inventory_details?.length" class="info-card full-width">
                <h3 class="card-title">Inventory by Warehouse</h3>
                <div class="table-container">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Warehouse</th>
                                <th>On Hand</th>
                                <th>Available</th>
                                <th>Allocated</th>
                                <th>On Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in product.inventory_details" :key="inv.id">
                                <td>{{ inv.warehouse?.name }}</td>
                                <td>{{ inv.quantity_on_hand }}</td>
                                <td>{{ inv.quantity_available }}</td>
                                <td>{{ inv.quantity_allocated }}</td>
                                <td>{{ inv.quantity_on_order }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useProductStore } from '../../stores/product';

const route = useRoute();
const router = useRouter();
const productStore = useProductStore();

const product = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    await fetchProduct();
});

const fetchProduct = async () => {
    loading.value = true;
    error.value = null;

    try {
        const id = route.params.id;
        console.log('Fetching product ID:', id); // Debug
        
        const response = await productStore.fetchProduct(id);
        product.value = response;
        
        console.log('Product loaded:', product.value); // Debug
    } catch (err) {
        console.error('Error fetching product:', err); // Debug
        error.value = err.response?.data?.message || 'Product not found';
    } finally {
        loading.value = false;
    }
};

const calculateMargin = () => {
    if (!product.value) return 0;
    const cost = parseFloat(product.value.cost);
    const price = parseFloat(product.value.price);
    if (cost === 0) return 0;
    return (((price - cost) / cost) * 100).toFixed(2);
};

const handleDelete = async () => {
    if (!confirm('Are you sure you want to delete this product?')) {
        return;
    }

    try {
        await productStore.deleteProduct(product.value.id);
        router.push('/products');
    } catch (err) {
        alert('Failed to delete product: ' + (err.response?.data?.message || err.message));
    }
};
</script>

<style scoped>
.product-show-page {
    @apply max-w-6xl mx-auto;
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

.page-header {
    @apply flex items-start justify-between mb-6;
}

.back-link {
    @apply text-indigo-600 hover:text-indigo-700 mb-2 inline-block no-underline;
}

.page-title {
    @apply text-3xl font-bold text-gray-900 mb-1;
}

.product-sku {
    @apply text-gray-600;
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

.info-grid {
    @apply grid grid-cols-1 md:grid-cols-2 gap-6 mb-6;
}

.info-card {
    @apply bg-white rounded-lg shadow p-6;
}

.info-card.full-width {
    @apply md:col-span-2;
}

.card-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200;
}

.info-rows {
    @apply space-y-3;
}

.info-row {
    @apply flex justify-between items-center;
}

.label {
    @apply text-gray-600 font-medium;
}

.value {
    @apply text-gray-900;
}

.badge {
    @apply px-3 py-1 rounded-full text-sm font-medium;
}

.badge-active {
    @apply bg-green-100 text-green-800;
}

.badge-inactive {
    @apply bg-red-100 text-red-800;
}

.description-text,
.notes-text {
    @apply text-gray-700 leading-relaxed;
}

.table-container {
    @apply overflow-x-auto;
}

.inventory-table {
    @apply w-full;
}

.inventory-table thead {
    @apply bg-gray-50;
}

.inventory-table th {
    @apply px-4 py-3 text-left text-sm font-semibold text-gray-900;
}

.inventory-table td {
    @apply px-4 py-3 text-sm text-gray-700 border-t border-gray-200;
}
</style>