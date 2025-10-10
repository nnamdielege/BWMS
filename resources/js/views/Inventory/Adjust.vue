<template>
    <div class="adjust-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <router-link to="/inventory" class="back-link">
                    ← Back to Inventory
                </router-link>
                <h1 class="page-title">Adjust Inventory</h1>
                <p class="page-subtitle">Adjust stock levels for products</p>
            </div>
        </div>

        <!-- Adjustment Form -->
        <div class="form-card">
            <form @submit.prevent="handleSubmit">
                <!-- Product Selection (Read-only if pre-selected) -->
                <div class="form-group">
                    <label class="form-label">Product *</label>
                    <select
                        v-model="form.product_id"
                        class="form-select"
                        :disabled="isPreSelected"
                        required
                        @change="onProductChange"
                    >
                        <option value="">Select Product</option>
                        <option
                            v-for="product in products"
                            :key="product.id"
                            :value="product.id"
                        >
                            {{ product.name }} ({{ product.sku }})
                        </option>
                    </select>
                </div>

                <!-- Warehouse Selection (Read-only if pre-selected) -->
                <div class="form-group">
                    <label class="form-label">Warehouse *</label>
                    <select
                        v-model="form.warehouse_id"
                        class="form-select"
                        :disabled="isPreSelected"
                        required
                        @change="onWarehouseChange"
                    >
                        <option value="">Select Warehouse</option>
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }}
                        </option>
                    </select>
                </div>

                <!-- Current Stock (Read-only) -->
                <div class="form-group">
                    <label class="form-label">Current Stock</label>
                    <input
                        v-model="currentStock"
                        type="number"
                        class="form-input bg-gray-100"
                        readonly
                    />
                </div>

                <!-- Adjustment Type -->
                <div class="form-group">
                    <label class="form-label">Adjustment Type *</label>
                    <select
                        v-model="form.type"
                        class="form-select"
                        required
                    >
                        <option value="">Select Type</option>
                        <option value="adjustment">Stock Adjustment</option>
                        <option value="damage">Damaged/Lost Stock</option>
                        <option value="count">Physical Count Adjustment</option>
                    </select>
                </div>

                <!-- Quantity Change -->
                <div class="form-group">
                    <label class="form-label">Quantity Change *</label>
                    <input
                        v-model.number="form.quantity"
                        type="number"
                        class="form-input"
                        placeholder="Enter positive or negative number"
                        required
                    />
                    <p class="form-help">
                        Use positive numbers to add stock, negative to remove. 
                        Example: 10 adds 10 units, -5 removes 5 units
                    </p>
                </div>

                <!-- New Stock Level (Calculated) -->
                <div class="form-group">
                    <label class="form-label">New Stock Level</label>
                    <input
                        :value="newStockLevel"
                        type="number"
                        class="form-input bg-gray-100"
                        :class="{ 'text-red-600': newStockLevel < 0 }"
                        readonly
                    />
                    <p v-if="newStockLevel < 0" class="form-error">
                        Warning: New stock level cannot be negative
                    </p>
                </div>

                <!-- Add after New Stock Level field -->
                <div v-if="form.quantity !== 0" class="change-summary">
                    <h4 class="summary-title">Adjustment Summary</h4>
                    <div class="summary-content">
                        <div class="summary-row">
                            <span>Current Stock:</span>
                            <span class="font-semibold">{{ currentStock }}</span>
                        </div>
                        <div class="summary-row">
                            <span>Change:</span>
                            <span :class="form.quantity > 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                                {{ form.quantity > 0 ? '+' : '' }}{{ form.quantity }}
                            </span>
                        </div>
                        <div class="summary-row border-t pt-2">
                            <span class="font-semibold">New Stock:</span>
                            <span class="font-bold text-lg">{{ newStockLevel }}</span>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="form-group">
                    <label class="form-label">Reason *</label>
                    <input
                        v-model="form.reason"
                        type="text"
                        class="form-input"
                        placeholder="e.g., Damaged goods, Physical count correction"
                        required
                    />
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea
                        v-model="form.notes"
                        class="form-textarea"
                        rows="4"
                        placeholder="Additional notes about this adjustment..."
                    ></textarea>
                </div>

                <!-- Error Messages -->
                <div v-if="error" class="error-message">
                    {{ error }}
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <router-link to="/inventory" class="btn-cancel">
                        Cancel
                    </router-link>
                    <button
                        type="submit"
                        class="btn-submit"
                        :disabled="loading || newStockLevel < 0"
                    >
                        {{ loading ? 'Adjusting...' : 'Adjust Inventory' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Pre-selected Item Info -->
        <div v-if="isPreSelected" class="info-card">
            <h3 class="info-title">Adjusting Inventory For:</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Product:</span>
                    <span class="info-value">{{ preSelectedData.product_name }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">SKU:</span>
                    <span class="info-value">{{ preSelectedData.product_sku }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Warehouse:</span>
                    <span class="info-value">{{ preSelectedData.warehouse_name }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Current Stock:</span>
                    <span class="info-value font-bold">{{ preSelectedData.current_stock }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useInventoryStore } from '../../stores/inventory';
import { useProductStore } from '../../stores/product';
import { useWarehouseStore } from '../../stores/warehouse';

const router = useRouter();
const route = useRoute();
const inventoryStore = useInventoryStore();
const productStore = useProductStore();
const warehouseStore = useWarehouseStore();

const form = reactive({
    product_id: '',
    warehouse_id: '',
    quantity: 0,
    type: '',
    reason: '',
    notes: '',
});

const products = ref([]);
const warehouses = ref([]);
const currentStock = ref(0);
const loading = ref(false);
const error = ref(null);

// Pre-selected data from query params
const preSelectedData = reactive({
    product_id: '',
    warehouse_id: '',
    product_name: '',
    product_sku: '',
    warehouse_name: '',
    current_stock: 0,
});

const isPreSelected = computed(() => {
    return !!preSelectedData.product_id && !!preSelectedData.warehouse_id;
});

const newStockLevel = computed(() => {
    return currentStock.value + (form.quantity || 0);
});

onMounted(async () => {
    await loadProducts();
    await loadWarehouses();
    
    // Check if coming from inventory index with pre-selected item
    if (route.query.product_id && route.query.warehouse_id) {
        preSelectedData.product_id = route.query.product_id;
        preSelectedData.warehouse_id = route.query.warehouse_id;
        preSelectedData.product_name = route.query.product_name || '';
        preSelectedData.product_sku = route.query.product_sku || '';
        preSelectedData.warehouse_name = route.query.warehouse_name || '';
        preSelectedData.current_stock = parseInt(route.query.current_stock || 0);

        // Auto-populate form
        form.product_id = preSelectedData.product_id;
        form.warehouse_id = preSelectedData.warehouse_id;
        currentStock.value = preSelectedData.current_stock;
    }
});

const loadProducts = async () => {
    try {
        await productStore.fetchProducts({ per_page: 1000 });
        products.value = productStore.products;
    } catch (err) {
        console.error('Error loading products:', err);
    }
};

const loadWarehouses = async () => {
    try {
        await warehouseStore.fetchWarehouses();
        warehouses.value = warehouseStore.warehouses;
    } catch (err) {
        console.error('Error loading warehouses:', err);
    }
};

const onProductChange = async () => {
    if (form.product_id && form.warehouse_id) {
        await fetchCurrentStock();
    }
};

const onWarehouseChange = async () => {
    if (form.product_id && form.warehouse_id) {
        await fetchCurrentStock();
    }
};

const fetchCurrentStock = async () => {
    try {
        // Fetch current inventory level
        const response = await inventoryStore.fetchInventory({
            product_id: form.product_id,
            warehouse_id: form.warehouse_id,
        });

        if (inventoryStore.inventory.length > 0) {
            currentStock.value = inventoryStore.inventory[0].quantity_on_hand;
        } else {
            currentStock.value = 0;
        }
    } catch (err) {
        console.error('Error fetching current stock:', err);
        currentStock.value = 0;
    }
};

const handleSubmit = async () => {
    error.value = null;

    // Validate new stock level
    if (newStockLevel.value < 0) {
        error.value = 'New stock level cannot be negative';
        return;
    }

    // Validate form
    if (!form.product_id || !form.warehouse_id || !form.type || !form.reason) {
        error.value = 'Please fill in all required fields';
        return;
    }

    if (form.quantity === 0) {
        error.value = 'Quantity change cannot be zero';
        return;
    }

    loading.value = true;

    try {
        await inventoryStore.adjustInventory({
            product_id: form.product_id,
            warehouse_id: form.warehouse_id,
            quantity: form.quantity,
            type: form.type,
            reason: form.reason,
            notes: form.notes || null,
        });

        // Success - redirect to inventory
        router.push('/inventory');
    } catch (err) {
        console.error('Adjustment error:', err);
        error.value = err.response?.data?.message || 'Failed to adjust inventory';
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.adjust-page {
    @apply max-w-4xl mx-auto space-y-6;
}

.page-header {
    @apply mb-6;
}

.back-link {
    @apply text-indigo-600 hover:text-indigo-700 mb-2 inline-block no-underline;
}

.page-title {
    @apply text-3xl font-bold text-gray-900 mb-1;
}

.page-subtitle {
    @apply text-gray-600;
}

.form-card {
    @apply bg-white rounded-lg shadow p-6;
}

.form-group {
    @apply mb-6;
}

.form-label {
    @apply block text-sm font-medium text-gray-700 mb-2;
}

.form-input,
.form-select {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-input:disabled,
.form-select:disabled {
    @apply bg-gray-100 cursor-not-allowed;
}

.form-textarea {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-help {
    @apply mt-2 text-sm text-gray-500;
}

.form-error {
    @apply mt-2 text-sm text-red-600;
}

.error-message {
    @apply bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6;
}

.form-actions {
    @apply flex gap-4 justify-end;
}

.btn-cancel {
    @apply px-6 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors no-underline text-gray-700;
}

.btn-submit {
    @apply px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}

.info-card {
    @apply bg-blue-50 border border-blue-200 rounded-lg p-6;
}

.info-title {
    @apply text-lg font-semibold text-gray-900 mb-4;
}

.info-grid {
    @apply grid grid-cols-2 gap-4;
}

.info-item {
    @apply flex flex-col;
}

.info-label {
    @apply text-sm text-gray-600 mb-1;
}

.info-value {
    @apply text-base text-gray-900;
}

.change-summary {
    @apply bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6;
}

.summary-title {
    @apply text-sm font-semibold text-gray-900 mb-3;
}

.summary-content {
    @apply space-y-2;
}

.summary-row {
    @apply flex justify-between text-sm;
}
</style>