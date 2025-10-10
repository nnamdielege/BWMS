<template>
    <div class="customer-show">
        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading customer...</p>
        </div>

        <!-- Error State -->
        <div v-else-if="error" class="error-container">
            <div class="error-card">
                <h2>Customer Not Found</h2>
                <p>{{ error }}</p>
                <router-link to="/customers" class="btn-back">
                    ← Back to Customers
                </router-link>
            </div>
        </div>

        <!-- Customer Details -->
        <div v-else-if="customer" class="customer-details">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <router-link to="/customers" class="back-link">
                        ← Back to Customers
                    </router-link>
                    <div class="flex items-center gap-4 mt-2">
                        <h1 class="page-title">{{ customer.company_name }}</h1>
                        <span
                            :class="[
                                'status-badge',
                                customer.is_active ? 'badge-active' : 'badge-inactive'
                            ]"
                        >
                            {{ customer.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <p class="page-subtitle">{{ customer.customer_code }}</p>
                </div>
                <div class="header-actions">
                    <router-link 
                        :to="`/customers/${customer.id}/edit`" 
                        class="btn btn-secondary"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit Customer
                    </router-link>
                    <button
                        @click="deleteCustomer"
                        class="btn btn-danger"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Delete
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div v-if="stats" class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon bg-blue-100 text-blue-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Orders</p>
                        <p class="stat-value">{{ stats.total_orders }}</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-yellow-100 text-yellow-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Pending Orders</p>
                        <p class="stat-value">{{ stats.pending_orders }}</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon bg-green-100 text-green-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Total Amount</p>
                        <p class="stat-value">${{ formatNumber(stats.total_amount) }}</p>
                    </div>
                </div>
            </div>

            <!-- Information Grid -->
            <div class="info-grid">
                <!-- Contact Information -->
                <div class="info-card">
                    <h3 class="card-title">Contact Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Contact Name:</span>
                            <span class="value">{{ customer.name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <a :href="`mailto:${customer.email}`" class="value link">{{ customer.email }}</a>
                        </div>
                        <div class="info-row">
                            <span class="label">Phone:</span>
                            <span class="value">{{ customer.phone || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Mobile:</span>
                            <span class="value">{{ customer.mobile || 'N/A' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="info-card">
                    <h3 class="card-title">Address Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Address:</span>
                            <span class="value">{{ customer.address }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">City:</span>
                            <span class="value">{{ customer.city }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">State:</span>
                            <span class="value">{{ customer.state }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Postal Code:</span>
                            <span class="value">{{ customer.postal_code }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Country:</span>
                            <span class="value">{{ customer.country }}</span>
                        </div>
                    </div>
                </div>

                <!-- Business Information -->
                <div class="info-card">
                    <h3 class="card-title">Business Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Tax ID:</span>
                            <span class="value">{{ customer.tax_id || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Payment Terms:</span>
                            <span class="value">{{ customer.payment_terms || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Credit Limit:</span>
                            <span class="value">{{ customer.credit_limit ? `$${formatNumber(customer.credit_limit)}` : 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Status:</span>
                            <span class="value">
                                <span :class="['status-badge', customer.is_active ? 'badge-active' : 'badge-inactive']">
                                    {{ customer.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div v-if="customer.notes" class="notes-card">
                <h3 class="card-title">Notes</h3>
                <p class="notes-text">{{ customer.notes }}</p>
            </div>

            <!-- Recent Orders -->
            <div v-if="customer.sales_orders && customer.sales_orders.length > 0" class="orders-card">
                <div class="card-header">
                    <h3 class="card-title">Recent Orders</h3>
                    <router-link to="/sales-orders" class="view-all-link">
                        View All Orders →
                    </router-link>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-right">Total</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="order in customer.sales_orders" :key="order.id">
                                <td class="font-medium">{{ order.order_number }}</td>
                                <td>{{ formatDate(order.order_date) }}</td>
                                <td>
                                    <span :class="['status-badge', `status-${order.status}`]">
                                        {{ order.status }}
                                    </span>
                                </td>
                                <td class="text-right font-semibold">${{ formatNumber(order.total) }}</td>
                                <td class="text-center">
                                    <router-link 
                                        :to="`/sales-orders/${order.id}`"
                                        class="btn-icon btn-icon-view"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
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
import { useCustomerStore } from '../../stores/customer';

const route = useRoute();
const router = useRouter();
const customerStore = useCustomerStore();

const customer = ref(null);
const stats = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    await fetchCustomer();
});

const fetchCustomer = async () => {
    loading.value = true;
    error.value = null;

    try {
        const id = route.params.id;
        const response = await customerStore.fetchCustomer(id);
        
        if (response.data) {
            customer.value = response.data;
            stats.value = response.stats;
        } else {
            customer.value = response;
        }

        console.log('Customer loaded:', customer.value);
    } catch (err) {
        console.error('Error fetching customer:', err);
        error.value = err.response?.data?.message || 'Customer not found';
    } finally {
        loading.value = false;
    }
};

const deleteCustomer = async () => {
    if (!confirm(`Are you sure you want to delete ${customer.value.company_name}?`)) {
        return;
    }

    try {
        await customerStore.deleteCustomer(customer.value.id);
        router.push('/customers');
    } catch (err) {
        alert(err.response?.data?.message || 'Failed to delete customer');
    }
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};
</script>

<style scoped>
.customer-show {
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

.customer-details {
    @apply space-y-6;
}

.page-header {
    @apply flex items-start justify-between mb-6;
}

.back-link {
    @apply text-indigo-600 hover:text-indigo-700 inline-block no-underline;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.status-badge {
    @apply inline-flex px-3 py-1 rounded-full text-sm font-medium;
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

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors no-underline;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700 border-0 cursor-pointer;
}

.stats-grid {
    @apply grid grid-cols-1 md:grid-cols-3 gap-6;
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
    @apply grid grid-cols-1 md:grid-cols-3 gap-6;
}

.info-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-3 border-b border-gray-200;
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

.link {
    @apply text-indigo-600 hover:text-indigo-800 no-underline;
}

.notes-card {
    @apply bg-white rounded-lg shadow p-6;
}

.notes-text {
    @apply text-gray-700 whitespace-pre-wrap;
}

.orders-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-header {
    @apply flex items-center justify-between mb-4 pb-3 border-b border-gray-200;
}

.view-all-link {
    @apply text-sm text-indigo-600 hover:text-indigo-800 no-underline;
}

.table-container {
    @apply overflow-x-auto;
}

.table {
    @apply w-full;
}

.table thead {
    @apply bg-gray-50;
}

.table th {
    @apply px-4 py-3 text-left text-sm font-semibold text-gray-900;
}

.table td {
    @apply px-4 py-3 text-sm text-gray-700 border-t border-gray-200;
}

.status-pending {
    @apply bg-yellow-100 text-yellow-800;
}

.status-processing {
    @apply bg-blue-100 text-blue-800;
}

.status-fulfilled {
    @apply bg-green-100 text-green-800;
}

.status-cancelled {
    @apply bg-red-100 text-red-800;
}

.btn-icon {
    @apply p-2 rounded-lg transition-colors no-underline inline-flex;
}

.btn-icon-view {
    @apply text-blue-600 hover:bg-blue-50;
}
</style>