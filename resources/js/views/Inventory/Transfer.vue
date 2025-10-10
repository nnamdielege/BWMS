<template>
    <div class="transfer-stock-page">
        <!-- Page Header -->
        <div class="page-header">
            <div class="flex items-center gap-4">
                <router-link to="/inventory" class="back-btn">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </router-link>
                <div>
                    <h1 class="page-title">Stock Transfer</h1>
                    <p class="page-subtitle">Transfer inventory between warehouses</p>
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
            v-if="inventoryStore.error"
            type="error"
            :message="inventoryStore.error"
            @close="inventoryStore.clearError()"
        />

        <!-- Transfer Form -->
        <div class="form-container">
            <div class="form-section">
                <h3 class="section-title">Transfer Details</h3>
                <div class="section-content">
                    <!-- Product Selection -->
                    <div class="form-group">
                        <label class="form-label">Product *</label>
                        <div class="search-select">
                            <input
                                v-model="productSearch"
                                @input="searchProducts"
                                @focus="showProductDropdown = true"
                                type="text"
                                placeholder="Search product by name or SKU..."
                                class="form-input"
                                :class="{ 'input-error': errors.product_id }"
                            />
                            <div v-if="showProductDropdown && searchResults.length > 0" class="search-dropdown">
                                <div
                                    v-for="product in searchResults"
                                    :key="product.id"
                                    @click="selectProduct(product)"
                                    class="dropdown-item"
                                >
                                    <div class="dropdown-item-main">
                                        <span class="font-medium">{{ product.name }}</span>
                                        <Badge variant="info">{{ product.sku }}</Badge>
                                    </div>
                                    <p class="text-sm text-gray-500">Category: {{ product.category?.name || 'N/A' }}</p>
                                </div>
                            </div>
                        </div>
                        <p v-if="errors.product_id" class="error-message">{{ errors.product_id }}</p>

                        <!-- Selected Product Display -->
                        <div v-if="selectedProduct" class="selected-product">
                            <div class="product-details">
                                <div>
                                    <p class="product-name">{{ selectedProduct.name }}</p>
                                    <p class="product-sku">SKU: {{ selectedProduct.sku }}</p>
                                </div>
                                <button @click="clearProduct" class="btn-clear">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- From Warehouse -->
                    <FormSelect
                        v-model="form.from_warehouse_id"
                        label="From Warehouse"
                        :options="warehouses"
                        placeholder="Select source warehouse"
                        required
                        :error="errors.from_warehouse_id"
                        @update:modelValue="loadSourceStock"
                    />

                    <!-- Source Stock Display -->
                    <div v-if="sourceStock !== null" class="stock-display">
                        <div class="stock-header">
                            <h4 class="stock-title">Available Stock at Source</h4>
                        </div>
                        <div class="stock-grid">
                            <div class="stock-item">
                                <span class="label">On Hand:</span>
                                <span class="value">{{ sourceStock.quantity_on_hand || 0 }}</span>
                            </div>
                            <div class="stock-item">
                                <span class="label">Available:</span>
                                <span :class="['value', getStockClass(sourceStock.quantity_available)]">
                                    {{ sourceStock.quantity_available || 0 }}
                                </span>
                            </div>
                            <div class="stock-item">
                                <span class="label">Location:</span>
                                <span class="value">{{ sourceStock.bin_location || 'N/A' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- To Warehouse -->
                    <FormSelect
                        v-model="form.to_warehouse_id"
                        label="To Warehouse"
                        :options="filteredToWarehouses"
                        placeholder="Select destination warehouse"
                        required
                        :error="errors.to_warehouse_id"
                        @update:modelValue="loadDestinationStock"
                    />

                    <!-- Destination Stock Display -->
                    <div v-if="destinationStock !== null" class="stock-display">
                        <div class="stock-header">
                            <h4 class="stock-title">Current Stock at Destination</h4>
                        </div>
                        <div class="stock-grid">
                            <div class="stock-item">
                                <span class="label">On Hand:</span>
                                <span class="value">{{ destinationStock.quantity_on_hand || 0 }}</span>
                            </div>
                            <div class="stock-item">
                                <span class="label">Available:</span>
                                <span class="value">{{ destinationStock.quantity_available || 0 }}</span>
                            </div>
                            <div class="stock-item">
                                <span class="label">Location:</span>
                                <span class="value">{{ destinationStock.bin_location || 'N/A' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Quantity -->
                    <FormInput
                        v-model="form.quantity"
                        type="number"
                        label="Quantity to Transfer"
                        placeholder="Enter quantity"
                        required
                        :error="errors.quantity"
                        @input="calculateNewQuantities"
                    />

                    <!-- Transfer Preview -->
                    <div v-if="form.quantity && sourceStock !== null" class="transfer-preview">
                        <div class="preview-section">
                            <h4 class="preview-title">Source Warehouse</h4>
                            <div class="preview-calculation">
                                <span class="preview-value">{{ sourceStock.quantity_available || 0 }}</span>
                                <span class="preview-operator">-</span>
                                <span class="preview-quantity">{{ form.quantity }}</span>
                                <span class="preview-equals">=</span>
                                <span class="preview-result text-red-600">{{ newSourceQuantity }}</span>
                            </div>
                        </div>

                        <div class="preview-arrow">
                            <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </div>

                        <div class="preview-section">
                            <h4 class="preview-title">Destination Warehouse</h4>
                            <div class="preview-calculation">
                                <span class="preview-value">{{ destinationStock?.quantity_available || 0 }}</span>
                                <span class="preview-operator">+</span>
                                <span class="preview-quantity">{{ form.quantity }}</span>
                                <span class="preview-equals">=</span>
                                <span class="preview-result text-green-600">{{ newDestinationQuantity }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea
                            v-model="form.notes"
                            rows="4"
                            placeholder="Add transfer notes or reason..."
                            class="form-textarea"
                        ></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <router-link to="/inventory" class="btn btn-secondary">
                    Cancel
                </router-link>
                <button @click="submitTransfer" class="btn btn-primary" :disabled="inventoryStore.loading">
                    <span v-if="inventoryStore.loading" class="spinner-small"></span>
                    <span>{{ inventoryStore.loading ? 'Processing...' : 'Transfer Stock' }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useInventoryStore } from '../../stores/inventory';
import { useWarehouseStore } from '../../stores/warehouse';
import { useProductStore } from '../../stores/product';
import FormInput from '../../components/common/FormInput.vue';
import FormSelect from '../../components/common/FormSelect.vue';
import Badge from '../../components/common/Badge.vue';
import Alert from '../../components/common/Alert.vue';

const router = useRouter();
const inventoryStore = useInventoryStore();
const warehouseStore = useWarehouseStore();
const productStore = useProductStore();

const form = ref({
    product_id: '',
    from_warehouse_id: '',
    to_warehouse_id: '',
    quantity: '',
    notes: '',
});

const errors = ref({});
const successMessage = ref('');
const productSearch = ref('');
const showProductDropdown = ref(false);
const searchResults = ref([]);
const selectedProduct = ref(null);
const sourceStock = ref(null);
const destinationStock = ref(null);
const warehouses = ref([]);
const newSourceQuantity = ref(0);
const newDestinationQuantity = ref(0);

const filteredToWarehouses = computed(() => {
    return warehouses.value.filter(w => w.id !== parseInt(form.value.from_warehouse_id));
});

let searchTimeout;
const searchProducts = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        if (productSearch.value.length < 2) {
            searchResults.value = [];
            return;
        }

        try {
            await productStore.fetchProducts({ search: productSearch.value, per_page: 10 });
            searchResults.value = productStore.products;
            showProductDropdown.value = true;
        } catch (error) {
            console.error('Search error:', error);
        }
    }, 300);
};
const selectProduct = (product) => {
    selectedProduct.value = product;
    form.value.product_id = product.id;
    productSearch.value = product.name;
    showProductDropdown.value = false;
    searchResults.value = [];
    errors.value.product_id = '';
    if (form.value.from_warehouse_id) {
    loadSourceStock();
}
if (form.value.to_warehouse_id) {
    loadDestinationStock();
}};

const clearProduct = () => {
    selectedProduct.value = null;
    form.value.product_id = '';
    productSearch.value = '';
    sourceStock.value = null;
    destinationStock.value = null;
};
const loadSourceStock = async () => {
    if (!form.value.product_id || !form.value.from_warehouse_id) {
    sourceStock.value = null;
    return;
}
try {
    await inventoryStore.fetchInventory({
        product_id: form.value.product_id,
        warehouse_id: form.value.from_warehouse_id,
    });

    if (inventoryStore.inventoryItems.length > 0) {
        sourceStock.value = inventoryStore.inventoryItems[0];
    } else {
        sourceStock.value = {
            quantity_on_hand: 0,
            quantity_available: 0,
            quantity_allocated: 0,
            bin_location: null,
        };
    }
    calculateNewQuantities();
} catch (error) {
    console.error('Error loading source stock:', error);
}};

const loadDestinationStock = async () => {
    if (!form.value.product_id || !form.value.to_warehouse_id) {
    destinationStock.value = null;
    return;
}
try {
    await inventoryStore.fetchInventory({
        product_id: form.value.product_id,
        warehouse_id: form.value.to_warehouse_id,
    });

    if (inventoryStore.inventoryItems.length > 0) {
        destinationStock.value = inventoryStore.inventoryItems[0];
    } else {
        destinationStock.value = {
            quantity_on_hand: 0,
            quantity_available: 0,
            quantity_allocated: 0,
            bin_location: null,
        };
    }
    calculateNewQuantities();
} catch (error) {
    console.error('Error loading destination stock:', error);
}};

const calculateNewQuantities = () => {
    if (!form.value.quantity || sourceStock.value === null) {
        newSourceQuantity.value = sourceStock.value?.quantity_available || 0;
        newDestinationQuantity.value = destinationStock.value?.quantity_available || 0;
        return;
    }
    const qty = parseInt(form.value.quantity) || 0;
    newSourceQuantity.value = Math.max(0, (sourceStock.value.quantity_available || 0) - qty);
    newDestinationQuantity.value = (destinationStock.value?.quantity_available || 0) + qty;
};

const getStockClass = (quantity) => {
    if (quantity <= 0) return 'text-red-600 font-semibold';
        if (quantity <= 10) return 'text-yellow-600 font-semibold';
        return 'text-green-600 font-semibold';
    };
    const validateForm = () => {
        errors.value = {};
        let isValid = true;
        if (!form.value.product_id) {
            errors.value.product_id = 'Product is required';
            isValid = false;
        }

        if (!form.value.from_warehouse_id) {
            errors.value.from_warehouse_id = 'Source warehouse is required';
            isValid = false;
        }

        if (!form.value.to_warehouse_id) {
            errors.value.to_warehouse_id = 'Destination warehouse is required';
            isValid = false;
        }

        if (form.value.from_warehouse_id === form.value.to_warehouse_id) {
            errors.value.to_warehouse_id = 'Destination must be different from source';
            isValid = false;
        }

        if (!form.value.quantity || parseInt(form.value.quantity) <= 0) {
            errors.value.quantity = 'Quantity must be greater than 0';
            isValid = false;
        }

        const available = sourceStock.value?.quantity_available || 0;
        const qty = parseInt(form.value.quantity) || 0;
        if (qty > available) {
            errors.value.quantity = `Cannot transfer more than available stock (${available})`;
            isValid = false;
        }

        return isValid;
    };
    const submitTransfer = async () => {
        if (!validateForm()) {
            return;
        }
        try {
            await inventoryStore.transferInventory({
                product_id: form.value.product_id,
                from_warehouse_id: form.value.from_warehouse_id,
                to_warehouse_id: form.value.to_warehouse_id,
                quantity: parseInt(form.value.quantity),
                notes: form.value.notes,
            });

            successMessage.value = 'Stock transferred successfully!';
    
            // Reset form and redirect
            setTimeout(() => {
                router.push('/inventory');
            }, 2000);
        } catch (error) {
            if (error.response?.data?.errors) {
                errors.value = error.response.data.errors;
            }
        }};
        // Click outside to close dropdown
        const handleClickOutside = (event) => {
            if (!event.target.closest('.search-select')) {
                showProductDropdown.value = false;
            }
        };
        onMounted(async () => {
            await warehouseStore.fetchWarehouses();
            warehouses.value = warehouseStore.warehouses;
            document.addEventListener('click', handleClickOutside);
        });
</script>

<style scoped>
.transfer-stock-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-center justify-between;
}

.back-btn {
    @apply p-2 rounded-lg hover:bg-gray-100 transition-colors;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.form-container {
    @apply bg-white rounded-lg shadow;
}

.form-section {
    @apply p-6;
}

.section-title {
    @apply text-lg font-semibold text-gray-900 mb-6 pb-3 border-b border-gray-200;
}

.section-content {
    @apply space-y-6;
}

.search-select {
    @apply relative;
}

.form-input {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.input-error {
    @apply border-red-500 focus:ring-red-500;
}

.search-dropdown {
    @apply absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto;
}

.dropdown-item {
    @apply px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0;
}

.dropdown-item-main {
    @apply flex items-center justify-between mb-1;
}

.selected-product {
    @apply mt-2 p-4 bg-indigo-50 border border-indigo-200 rounded-lg;
}

.product-details {
    @apply flex items-start justify-between;
}

.product-name {
    @apply font-semibold text-gray-900;
}

.product-sku {
    @apply text-sm text-gray-600;
}

.btn-clear {
    @apply p-1 text-gray-400 hover:text-red-600 transition-colors;
}

.stock-display {
    @apply p-4 bg-gray-50 rounded-lg border border-gray-200;
}

.stock-header {
    @apply mb-3;
}

.stock-title {
    @apply text-sm font-semibold text-gray-700;
}

.stock-grid {
    @apply grid grid-cols-3 gap-4;
}

.stock-item {
    @apply flex flex-col;
}

.stock-item .label {
    @apply text-xs text-gray-600 mb-1;
}

.stock-item .value {
    @apply text-lg font-semibold text-gray-900;
}

.transfer-preview {
    @apply flex items-center justify-between gap-4 p-6 bg-gradient-to-r from-red-50 via-gray-50 to-green-50 rounded-lg border border-gray-200;
}

.preview-section {
    @apply flex-1 space-y-3;
}

.preview-title {
    @apply text-sm font-semibold text-gray-700 text-center;
}

.preview-calculation {
    @apply flex items-center justify-center gap-3;
}

.preview-value {
    @apply text-2xl font-bold text-gray-900;
}

.preview-operator {
    @apply text-xl text-gray-400;
}

.preview-quantity {
    @apply text-2xl font-bold text-indigo-600;
}

.preview-equals {
    @apply text-xl text-gray-400;
}

.preview-result {
    @apply text-3xl font-bold;
}

.preview-arrow {
    @apply flex items-center;
}

.form-textarea {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none;
}

.form-actions {
    @apply flex items-center justify-end gap-3 p-6 border-t border-gray-200;
}

.btn {
    @apply flex items-center gap-2 px-6 py-2.5 rounded-lg font-medium transition-all;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 no-underline;
}

.spinner-small {
    @apply w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin;
}

.error-message {
    @apply text-sm text-red-600 mt-1;
}

.form-group {
    @apply space-y-2;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}
</style>