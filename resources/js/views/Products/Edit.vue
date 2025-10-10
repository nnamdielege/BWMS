<template>
    <div class="edit-product-page">
        <!-- Loading State -->
        <LoadingSpinner v-if="loading" size="large" message="Loading product..." />

        <!-- Product Form -->
        <div v-else-if="product">
            <!-- Page Header -->
            <div class="page-header">
                <div class="flex items-center gap-4">
                    <router-link :to="`/products/${product.id}`" class="back-btn">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </router-link>
                    <div>
                        <h1 class="page-title">Edit Product</h1>
                        <p class="page-subtitle">{{ product.name }}</p>
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

        <!-- Product Form -->
            <form @submit.prevent="handleSubmit" class="form-container">
                <div class="form-grid">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h3 class="section-title">Basic Information</h3>
                        <div class="section-content">
                            <FormInput
                                v-model="form.sku"
                                label="SKU"
                                placeholder="Enter SKU"
                                required
                                :error="errors.sku"
                            />

                            <FormInput
                                v-model="form.barcode"
                                label="Barcode"
                                placeholder="Enter barcode"
                                :error="errors.barcode"
                            />

                            <FormInput
                                v-model="form.name"
                                label="Product Name"
                                placeholder="Enter product name"
                                required
                                :error="errors.name"
                            />

                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea
                                    v-model="form.description"
                                    rows="4"
                                    placeholder="Enter product description"
                                    class="form-textarea"
                                ></textarea>
                            </div>

                            <FormSelect
                                v-model="form.category_id"
                                label="Category"
                                :options="categories"
                                placeholder="Select category"
                                required
                                :error="errors.category_id"
                            />
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="form-section">
                        <h3 class="section-title">Pricing</h3>
                        <div class="section-content">
                            <FormInput
                                v-model="form.cost"
                                type="number"
                                step="0.01"
                                label="Cost Price"
                                placeholder="0.00"
                                required
                                :error="errors.cost"
                            >
                                <template #append>
                                    <span class="input-addon">$</span>
                                </template>
                            </FormInput>

                            <FormInput
                                v-model="form.price"
                                type="number"
                                step="0.01"
                                label="Selling Price"
                                placeholder="0.00"
                                required
                                :error="errors.price"
                            >
                                <template #append>
                                    <span class="input-addon">$</span>
                                </template>
                            </FormInput>

                            <div v-if="form.cost && form.price" class="profit-margin">
                                <span class="label">Profit Margin:</span>
                                <span class="value" :class="profitMarginClass">
                                    {{ profitMargin }}%
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory Settings -->
                    <div class="form-section">
                        <h3 class="section-title">Inventory Settings</h3>
                        <div class="section-content">
                            <FormInput
                                v-model="form.unit_of_measure"
                                label="Unit of Measure"
                                placeholder="EA, BOX, KG, etc."
                                required
                                :error="errors.unit_of_measure"
                            />

                            <FormInput
                                v-model="form.reorder_point"
                                type="number"
                                label="Reorder Point"
                                placeholder="0"
                                hint="System will alert when stock reaches this level"
                                :error="errors.reorder_point"
                            />

                            <FormInput
                                v-model="form.reorder_quantity"
                                type="number"
                                label="Reorder Quantity"
                                placeholder="0"
                                hint="Recommended quantity to order"
                                :error="errors.reorder_quantity"
                            />
                        </div>
                    </div>

                    <!-- Additional Details -->
                    <div class="form-section">
                        <h3 class="section-title">Additional Details</h3>
                        <div class="section-content">
                            <FormInput
                                v-model="form.weight"
                                type="number"
                                step="0.01"
                                label="Weight"
                                placeholder="0.00"
                                :error="errors.weight"
                            >
                                <template #append>
                                    <span class="input-addon">kg</span>
                                </template>
                            </FormInput>

                            <FormInput
                                v-model="form.dimensions"
                                label="Dimensions"
                                placeholder="L x W x H (cm)"
                                :error="errors.dimensions"
                            />

                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="3"
                                    placeholder="Additional notes"
                                    class="form-textarea"
                                ></textarea>
                            </div>

                            <div class="form-checkbox">
                                <input
                                    id="is_active"
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="checkbox"
                                />
                                <label for="is_active" class="checkbox-label">
                                    Active (Product is available for sale)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <router-link :to="`/products/${product.id}`" class="btn btn-secondary">
                        Cancel
                    </router-link>
                    <button type="submit" class="btn btn-primary" :disabled="productStore.loading">
                        <span v-if="productStore.loading" class="spinner-small"></span>
                        <span>{{ productStore.loading ? 'Updating...' : 'Update Product' }}</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Error State -->
        <div v-else class="error-state">
            <svg class="w-16 h-16 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-gray-600 mt-4">Product not found</p>
            <router-link to="/products" class="btn btn-primary mt-4">
                Back to Products
            </router-link>
        </div>
    </div>

</template>
<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useProductStore } from '../../stores/product';
import FormInput from '../../components/common/FormInput.vue';
import FormSelect from '../../components/common/FormSelect.vue';
import Alert from '../../components/common/Alert.vue';
import LoadingSpinner from '../../components/common/LoadingSpinner.vue';

const route = useRoute();
const router = useRouter();
const productStore = useProductStore();

const loading = ref(true);
const product = ref(null);
const form = ref({
    sku: '',
    barcode: '',
    name: '',
    description: '',
    category_id: '',
    cost: '',
    price: '',
    unit_of_measure: 'EA',
    reorder_point: '',
    reorder_quantity: '',
    weight: '',
    dimensions: '',
    notes: '',
    is_active: true,
});

const errors = ref({});
const categories = ref([]);
const successMessage = ref('');

const profitMargin = computed(() => {
    const cost = parseFloat(form.value.cost) || 0;
    const price = parseFloat(form.value.price) || 0;
    if (cost === 0 || price === 0) return '0.00';
    return (((price - cost) / cost) * 100).toFixed(2);
});

const profitMarginClass = computed(() => {
    const margin = parseFloat(profitMargin.value);
    if (margin < 0) return 'text-red-600';
    if (margin < 20) return 'text-yellow-600';
    return 'text-green-600';
});

const validateForm = () => {
    errors.value = {};
    let isValid = true;

    if (!form.value.sku) {
        errors.value.sku = 'SKU is required';
        isValid = false;
    }

    if (!form.value.name) {
        errors.value.name = 'Product name is required';
        isValid = false;
    }

    if (!form.value.category_id) {
        errors.value.category_id = 'Category is required';
        isValid = false;
    }

    if (!form.value.cost || parseFloat(form.value.cost) < 0) {
        errors.value.cost = 'Valid cost price is required';
        isValid = false;
    }

    if (!form.value.price || parseFloat(form.value.price) < 0) {
        errors.value.price = 'Valid selling price is required';
        isValid = false;
    }

    if (parseFloat(form.value.price) < parseFloat(form.value.cost)) {
        errors.value.price = 'Selling price should be greater than cost';
        isValid = false;
    }

    return isValid;
};

const handleSubmit = async () => {
    if (!validateForm()) {
        return;
    }

    try {
        await productStore.updateProduct(product.value.id, form.value);
        successMessage.value = 'Product updated successfully!';
        
        // Refresh product data
        product.value = await productStore.fetchProduct(product.value.id);
        populateForm();
        
        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (error) {
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        }
    }
};

const populateForm = () => {
    form.value = {
        sku: product.value.sku || '',
        barcode: product.value.barcode || '',
        name: product.value.name || '',
        description: product.value.description || '',
        category_id: product.value.category_id || '',
        cost: product.value.cost || '',
        price: product.value.price || '',
        unit_of_measure: product.value.unit_of_measure || 'EA',
        reorder_point: product.value.reorder_point || '',
        reorder_quantity: product.value.reorder_quantity || '',
        weight: product.value.weight || '',
        dimensions: product.value.dimensions || '',
        notes: product.value.notes || '',
        is_active: product.value.is_active ?? true,
    };
};

onMounted(async () => {
    try {
        const productId = route.params.id;
        product.value = await productStore.fetchProduct(productId);
        await productStore.fetchCategories();
        categories.value = productStore.categories;
        populateForm();
    } catch (error) {
        console.error('Error loading product:', error);
    } finally {
        loading.value = false;
    }
});
</script>
<style scoped>
/* Same styles as Create.vue */
.edit-product-page {
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

.form-grid {
    @apply grid grid-cols-1 lg:grid-cols-2 gap-6 p-6;
}

.form-section {
    @apply space-y-4;
}

.section-title {
    @apply text-lg font-semibold text-gray-900 pb-3 border-b border-gray-200;
}

.section-content {
    @apply space-y-4;
}

.form-textarea {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none;
}

.input-addon {
    @apply text-gray-500 font-medium;
}

.profit-margin {
    @apply flex items-center justify-between p-3 bg-gray-50 rounded-lg;
}

.profit-margin .label {
    @apply text-sm font-medium text-gray-700;
}

.profit-margin .value {
    @apply text-lg font-bold;
}

.form-checkbox {
    @apply flex items-center gap-2;
}

.checkbox {
    @apply w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500;
}

.checkbox-label {
    @apply text-sm text-gray-700;
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

.error-state {
    @apply flex flex-col items-center justify-center py-20;
}
</style>

