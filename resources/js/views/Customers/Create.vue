<template>
    <div class="create-customer-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <router-link to="/customers" class="back-link">
                    ← Back to Customers
                </router-link>
                <h1 class="page-title">Add New Customer</h1>
                <p class="page-subtitle">Create a new customer record</p>
            </div>
        </div>

        <!-- Form Card -->
        <div class="form-card">
            <form @submit.prevent="handleSubmit">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3 class="section-title">Basic Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Customer Code *</label>
                            <input
                                v-model="form.customer_code"
                                type="text"
                                class="form-input"
                                placeholder="CUST001"
                                required
                            />
                            <p class="form-help">Unique identifier for the customer</p>
                            <p v-if="errors.customer_code" class="form-error">{{ errors.customer_code[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Company Name *</label>
                            <input
                                v-model="form.company_name"
                                type="text"
                                class="form-input"
                                placeholder="ABC Corporation"
                                required
                            />
                            <p v-if="errors.company_name" class="form-error">{{ errors.company_name[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Contact Name *</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="form-input"
                                placeholder="John Doe"
                                required
                            />
                            <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="form-input"
                                placeholder="contact@company.com"
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
                                placeholder="+1-555-0100"
                            />
                            <p v-if="errors.phone" class="form-error">{{ errors.phone[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Number</label>
                            <input
                                v-model="form.mobile"
                                type="text"
                                class="form-input"
                                placeholder="+1-555-0101"
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
                                placeholder="123 Main Street"
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
                                placeholder="New York"
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
                                placeholder="NY"
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
                                placeholder="10001"
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
                                placeholder="USA"
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
                                placeholder="TAX-001"
                            />
                            <p v-if="errors.tax_id" class="form-error">{{ errors.tax_id[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Terms</label>
                            <input
                                v-model="form.payment_terms"
                                type="text"
                                class="form-input"
                                placeholder="Net 30"
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
                                placeholder="10000.00"
                            />
                            <p v-if="errors.credit_limit" class="form-error">{{ errors.credit_limit[0] }}</p>
                        </div>

                        <div class="form-group col-span-2">
                            <label class="form-label">Notes</label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                class="form-textarea"
                                placeholder="Additional notes about the customer..."
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
                            <span class="ml-2 text-gray-700">Active Customer</span>
                        </label>
                        <p class="form-help mt-2">
                            Active customers can place orders
                        </p>
                    </div>
                </div>

                <!-- Error Message -->
                <div v-if="error" class="error-message">
                    {{ error }}
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <router-link to="/customers" class="btn-cancel">
                        Cancel
                    </router-link>
                    <button
                        type="submit"
                        class="btn-submit"
                        :disabled="loading"
                    >
                        {{ loading ? 'Creating...' : 'Create Customer' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useCustomerStore } from '../../stores/customer';

const router = useRouter();
const customerStore = useCustomerStore();

const form = reactive({
    customer_code: '',
    company_name: '',
    name: '',
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

const handleSubmit = async () => {
    errors.value = {};
    error.value = null;
    loading.value = true;

    try {
        await customerStore.createCustomer(form);
        router.push('/customers');
    } catch (err) {
        console.error('Submit error:', err);
        error.value = err.response?.data?.message || 'Failed to create customer';
        
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors;
        }
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.create-customer-page {
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