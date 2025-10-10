<template>
    <div class="show-supplier-page">
        <!-- Loading -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading supplier...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="error-container">
            <div class="error-card">
                <h2>Supplier Not Found</h2>
                <p>{{ error }}</p>
                <router-link to="/suppliers" class="btn-back">
                    ← Back to Suppliers
                </router-link>
            </div>
        </div>

        <!-- Supplier Details -->
        <div v-else-if="supplier" class="supplier-details">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <router-link to="/suppliers" class="back-link">
                        ← Back to Suppliers
                    </router-link>
                    <div class="flex items-center gap-4 mt-2">
                        <h1 class="page-title">{{ supplier.company_name }}</h1>
                        <span
                            :class="[
                                'status-badge',
                                supplier.is_active ? 'badge-active' : 'badge-inactive'
                            ]"
                        >
                            {{ supplier.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <p class="page-subtitle">{{ supplier.supplier_code }}</p>
                </div>
                <div class="header-actions">
                    <router-link 
                        :to="`/suppliers/${supplier.id}/edit`" 
                        class="btn-edit"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit Supplier
                    </router-link>
                    <button @click="handleDelete" class="btn-delete">
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
                        <p class="stat-label">Total Purchase Orders</p>
                        <p class="stat-value">{{ stats.total_purchase_orders || 0 }}</p>
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
                        <p class="stat-value">{{ stats.pending_orders || 0 }}</p>
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

                <div class="stat-card">
                    <div class="stat-icon bg-purple-100 text-purple-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <div>
                        <p class="stat-label">Credit Limit</p>
                        <p class="stat-value">${{ formatNumber(supplier.credit_limit) }}</p>
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
                            <span class="value">{{ supplier.contact_name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">
                                <a :href="`mailto:${supplier.email}`" class="link">
                                    {{ supplier.email }}
                                </a>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="label">Phone:</span>
                            <span class="value">{{ supplier.phone || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Mobile:</span>
                            <span class="value">{{ supplier.mobile || 'N/A' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Location Details -->
                <div class="info-card">
                    <h3 class="card-title">Location Details</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Address:</span>
                            <span class="value">{{ supplier.address }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">City:</span>
                            <span class="value">{{ supplier.city }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">State/Province:</span>
                            <span class="value">{{ supplier.state }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Postal Code:</span>
                            <span class="value">{{ supplier.postal_code }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Country:</span>
                            <span class="value">{{ supplier.country }}</span>
                        </div>
                    </div>
                </div>

                <!-- Business Information -->
                <div class="info-card">
                    <h3 class="card-title">Business Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Tax ID:</span>
                            <span class="value">{{ supplier.tax_id || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Payment Terms:</span>
                            <span class="value">{{ supplier.payment_terms || 'N/A' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Credit Limit:</span>
                            <span class="value">${{ formatNumber(supplier.credit_limit) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Status:</span>
                            <span class="value">
                                <span :class="['status-badge', supplier.is_active ? 'badge-active' : 'badge-inactive']">
                                    {{ supplier.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="info-card">
                    <h3 class="card-title">Additional Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Created:</span>
                            <span class="value">{{ formatDate(supplier.created_at) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Last Updated:</span>
                            <span class="value">{{ formatDate(supplier.updated_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div v-if="supplier.notes" class="info-card">
                <h3 class="card-title">Notes</h3>
                <p class="notes-text">{{ supplier.notes }}</p>
            </div>

            <!-- Recent Purchase Orders -->
            <div v-if="supplier.purchase_orders && supplier.purchase_orders.length > 0" class="info-card">
                <div class="card-header">
                    <h3 class="card-title">Recent Purchase Orders</h3>
                    <router-link 
                        to="/purchase-orders" 
                        class="view-all-link"
                    >
                        View All →
                    </router-link>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Order Date</th>
                                <th>Expected Date</th>
                                <th>Status</th>
                                <th class="text-right">Total</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="order in supplier.purchase_orders" :key="order.id">
                                <td class="font-medium">{{ order.order_number }}</td>
                                <td>{{ formatDate(order.order_date) }}</td>
                                <td>{{ order.expected_date ? formatDate(order.expected_date) : 'N/A' }}</td>
                                <td>
                                    <span :class="['status-badge', `status-${order.status}`]">
                                        {{ order.status }}
                                    </span>
                                </td>
                                <td class="text-right font-semibold">${{ formatNumber(order.total) }}</td>
                                <td class="text-center">
                                    <router-link
                                        :to="`/purchase-orders/${order.id}`"
                                        class="btn-action"
                                    >
                                        View
                                    </router-link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Empty State for Purchase Orders -->
            <div v-else class="info-card">
                <h3 class="card-title">Recent Purchase Orders</h3>
                <div class="empty-state-small">
                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p class="text-gray-500 mt-2">No purchase orders yet</p>
                    <router-link 
                        to="/purchase-orders/create" 
                        class="btn btn-secondary btn-sm mt-4"
                    >
                        Create Purchase Order
                    </router-link>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSupplierStore } from '../../stores/supplier';

const route = useRoute();
const router = useRouter();
const supplierStore = useSupplierStore();

const supplier = ref(null);
const stats = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    await fetchSupplier();
});

const fetchSupplier = async () => {
    loading.value = true;
    error.value = null;

    try {
        const id = route.params.id;
        const response = await supplierStore.fetchSupplier(id);
        
        if (response.data) {
            supplier.value = response.data;
            stats.value = response.stats;
        } else {
            supplier.value = response;
        }
        
        console.log('Supplier loaded:', supplier.value);
    } catch (err) {
        console.error('Error fetching supplier:', err);
        error.value = err.response?.data?.message || 'Supplier not found';
    } finally {
        loading.value = false;
    }
};

const formatNumber = (value) => {
    if (!value) return '0.00';
    return parseFloat(value).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const handleDelete = async () => {
    if (!confirm(`Are you sure you want to delete ${supplier.value.company_name}?`)) {
        return;
    }

    try {
        await supplierStore.deleteSupplier(supplier.value.id);
        router.push('/suppliers');
    } catch (err) {
        alert(err.response?.data?.message || 'Failed to delete supplier');
    }
};
</script>

<style scoped>
.show-supplier-page {
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

.supplier-details {
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
    @apply px-3 py-1 rounded-full text-sm font-medium;
}

.badge-active {
    @apply bg-green-100 text-green-800;
}

.badge-inactive {
    @apply bg-red-100 text-red-800;
}

.status-draft {
    @apply bg-gray-100 text-gray-800;
}

.status-pending {
    @apply bg-yellow-100 text-yellow-800;
}

.status-received {
    @apply bg-green-100 text-green-800;
}

.status-cancelled {
    @apply bg-red-100 text-red-800;
}

.header-actions {
    @apply flex gap-3;
}

.btn-edit {
    @apply flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors no-underline;
}

.btn-delete {
    @apply flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition-colors;
}

.stats-grid {
    @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6;
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
    @apply grid grid-cols-1 md:grid-cols-2 gap-6;
}

.info-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200;
}

.card-header {
    @apply flex items-center justify-between mb-4 pb-2 border-b border-gray-200;
}

.view-all-link {
    @apply text-indigo-600 hover:text-indigo-800 text-sm font-medium no-underline;
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

.notes-text {
    @apply text-gray-700 whitespace-pre-wrap;
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
    @apply px-4 py-3 text-left text-sm font-semibold text-gray-900;
}

.data-table td {
    @apply px-4 py-3 text-sm text-gray-700 border-t border-gray-200;
}

.btn-action {
    @apply text-indigo-600 hover:text-indigo-800 font-medium no-underline;
}

.empty-state-small {
    @apply text-center py-8;
}

.btn {
    @apply inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors no-underline;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-sm {
    @apply px-3 py-1.5 text-sm;
}
</style>