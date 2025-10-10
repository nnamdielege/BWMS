<template>
    <div class="edit-supplier-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <router-link to="/suppliers" class="back-link">
                    ← Back to Suppliers
                </router-link>
                <h1 class="page-title">Edit Supplier</h1>
                <p class="page-subtitle">Update supplier information</p>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading && !form.company_name" class="loading-container">
            <div class="spinner"></div>
            <p>Loading supplier...</p>
        </div>

        <!-- Form Card -->
        <div v-else class="form-card">
            <form @submit.prevent="handleSubmit">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3 class="section-title">Basic Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Supplier Code *</label>
                            <input
                                v-model="form.supplier_code"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p class="form-help">Unique identifier for the supplier</p>
                            <p v-if="errors.supplier_code" class="form-error">{{ errors.supplier_code[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Company Name *</label>
                            <input
                                v-model="form.company_name"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.company_name" class="form-error">{{ errors.company_name[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Contact Name *</label>
                            <input
                                v-model="form.contact_name"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.contact_name" class="form-error">{{ errors.contact_name[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.email" class="form-error">{{ errors.email[0] }}</p>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="form-section">
                    <h3 class="section-title">Contact Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input
                                v-model="form.phone"
                                type="text"
                                class="form-input"
                            />
                            <p v-if="errors.phone" class="form-error">{{ errors.phone[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Number</label>
                            <input
                                v-model="form.mobile"
                                type="text"
                                class="form-input"
                            />
                            <p v-if="errors.mobile" class="form-error">{{ errors.mobile[0] }}</p>
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="form-section">
                    <h3 class="section-title">Address Information</h3>
                    <div class="form-grid">
                        <div class="form-group col-span-2">
                            <label class="form-label">Street Address *</label>
                            <input
                                v-model="form.address"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.address" class="form-error">{{ errors.address[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">City *</label>
                            <input
                                v-model="form.city"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.city" class="form-error">{{ errors.city[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">State/Province *</label>
                            <input
                                v-model="form.state"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.state" class="form-error">{{ errors.state[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Postal Code *</label>
                            <input
                                v-model="form.postal_code"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.postal_code" class="form-error">{{ errors.postal_code[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Country *</label>
                            <input
                                v-model="form.country"
                                type="text"
                                class="form-input"
                                required
                            />
                            <p v-if="errors.country" class="form-error">{{ errors.country[0] }}</p>
                        </div>
                    </div>
                </div>

                <!-- Business Information -->
                <div class="form-section">
                    <h3 class="section-title">Business Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Tax ID</label>
                            <input
                                v-model="form.tax_id"
                                type="text"
                                class="form-input"
                            />
                            <p v-if="errors.tax_id" class="form-error">{{ errors.tax_id[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Terms</label>
                            <input
                                v-model="form.payment_terms"
                                type="text"
                                class="form-input"
                            />
                            <p v-if="errors.payment_terms" class="form-error">{{ errors.payment_terms[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Credit Limit</label>
                            <input
                                v-model.number="form.credit_limit"
                                type="number"
                                step="0.01"
                                class="form-input"
                            />
                            <p v-if="errors.credit_limit" class="form-error">{{ errors.credit_limit[0] }}</p>
                        </div>

                        <div class="form-group col-span-2">
                            <label class="form-label">Notes</label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                class="form-textarea"
                            ></textarea>
                            <p v-if="errors.notes" class="form-error">{{ errors.notes[0] }}</p>
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="form-section">
                    <h3 class="section-title">Status</h3>
                    <div class="form-group">
                        <label class="flex items-center cursor-pointer">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="form-checkbox"
                            />
                            <span class="ml-2 text-gray-700">Active Supplier</span>
                        </label>
                        <p class="form-help mt-2">
                            Active suppliers can be selected for purchase orders
                        </p>
                    </div>
                </div>

                <!-- Error Message -->
                <div v-if="error" class="error-message">
                    {{ error }}
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <router-link to="/suppliers" class="btn-cancel">
                        Cancel
                    </router-link>
                    <button
                        type="submit"
                        class="btn-submit"
                        :disabled="loading"
                    >
                        {{ loading ? 'Updating...' : 'Update Supplier' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useSupplierStore } from '../../stores/supplier';

const router = useRouter();
const route = useRoute();
const supplierStore = useSupplierStore();

const form = reactive({
    supplier_code: '',
    company_name: '',
    contact_name: '',
    email: '',
    phone: '',
    mobile: '',
    address: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    tax_id: '',
    payment_terms: '',
    credit_limit: null,
    notes: '',
    is_active: true,
});

const errors = ref({});
const loading = ref(false);
const error = ref(null);

onMounted(async () => {
    await fetchSupplier();
});

const fetchSupplier = async () => {
    loading.value = true;

    try {
        const supplier = await supplierStore.fetchSupplier(route.params.id);
        
        // Populate form
        Object.keys(form).forEach(key => {
            form[key] = supplier[key];
        });
    } catch (err) {
        console.error('Error fetching supplier:', err);
        error.value = 'Failed to load supplier';
    } finally {
        loading.value = false;
    }
};

const handleSubmit = async () => {
    errors.value = {};
    error.value = null;
    loading.value = true;

    try {
        await supplierStore.updateSupplier(route.params.id, form);
        router.push('/suppliers');
    } catch (err) {
        console.error('Submit error:', err);
        error.value = err.response?.data?.message || 'Failed to update supplier';
        
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors;
        }
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
/* Same styles as Create.vue */
.edit-supplier-page {
    @apply max-w-4xl mx-auto;
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

.loading-container {
    @apply flex flex-col items-center justify-center py-12;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.form-card {
    @apply bg-white rounded-lg shadow p-6;
}

.form-section {
    @apply mb-8 pb-8 border-b border-gray-200 last:border-b-0 last:mb-0 last:pb-0;
}

.section-title {
    @apply text-lg font-semibold text-gray-900 mb-4;
}

.form-grid {
    @apply grid grid-cols-1 gap-6;
}

@media (min-width: 768px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .form-group.col-span-2 {
        grid-column: span 2;
    }
}

.form-group {
    @apply flex flex-col;
}

.form-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.form-input,
.form-textarea {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-textarea {
    @apply resize-none;
}

.form-checkbox {
    @apply w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-2 focus:ring-indigo-500;
}

.form-help {
    @apply text-sm text-gray-500 mt-1;
}

.form-error {
    @apply text-sm text-red-600 mt-1;
}

.error-message {
    @apply bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6;
}

.form-actions {
    @apply flex gap-4 justify-end pt-6;
}

.btn-cancel {
    @apply px-6 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors no-underline text-gray-700;
}

.btn-submit {
    @apply px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}
</style>