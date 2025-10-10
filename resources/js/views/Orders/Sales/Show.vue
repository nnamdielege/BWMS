<template>
    <div class="sales-order-show">
        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading order...</p>
        </div>

        <!-- Error State -->
        <div v-else-if="error" class="error-container">
            <div class="error-card">
                <h2>Order Not Found</h2>
                <p>{{ error }}</p>
                <router-link to="/sales-orders" class="btn-back">
                    ← Back to Orders
                </router-link>
            </div>
        </div>

        <!-- Order Details -->
        <div v-else-if="order" class="order-details">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <router-link to="/sales-orders" class="back-link">
                        ← Back to Orders
                    </router-link>
                    <div class="flex items-center gap-4 mt-2">
                        <h1 class="page-title">{{ order.order_number }}</h1>
                        <span
                            :class="[
                                'status-badge',
                                `status-${order.status}`
                            ]"
                        >
                            {{ order.status }}
                        </span>
                    </div>
                    <p class="page-subtitle">Order Date: {{ formatDate(order.order_date) }}</p>
                </div>
                <div class="header-actions">
                    <router-link 
                        v-if="order.status === 'draft' || order.status === 'pending'"
                        :to="`/sales-orders/${order.id}/edit`" 
                        class="btn btn-secondary"
                    >
                        Edit Order
                    </router-link>
                    <button
                        v-if="order.status === 'pending' || order.status === 'processing'"
                        @click="fulfillOrder"
                        class="btn btn-success"
                        :disabled="loading"
                    >
                        Fulfill Order
                    </button>
                    <button
                        v-if="order.status !== 'fulfilled' && order.status !== 'cancelled'"
                        @click="cancelOrder"
                        class="btn btn-danger"
                        :disabled="loading"
                    >
                        Cancel Order
                    </button>
                </div>
            </div>

            <!-- Order Information Grid -->
            <div class="info-grid">
                <!-- Customer Information -->
                <div class="info-card">
                    <h3 class="card-title">Customer Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Company:</span>
                            <span class="value">{{ order.customer?.company_name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Contact:</span>
                            <span class="value">{{ order.customer?.name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">{{ order.customer?.email }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Phone:</span>
                            <span class="value">{{ order.customer?.phone || 'N/A' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Warehouse Information -->
                <div class="info-card">
                    <h3 class="card-title">Warehouse Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Warehouse:</span>
                            <span class="value">{{ order.warehouse?.name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Code:</span>
                            <span class="value">{{ order.warehouse?.code }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Location:</span>
                            <span class="value">{{ order.warehouse?.city }}, {{ order.warehouse?.state }}</span>
                        </div>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="info-card">
                    <h3 class="card-title">Order Details</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Order Date:</span>
                            <span class="value">{{ formatDate(order.order_date) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Expected Date:</span>
                            <span class="value">{{ order.expected_date ? formatDate(order.expected_date) : 'N/A' }}</span>
                        </div>
                        <div v-if="order.fulfilled_date" class="info-row">
                            <span class="label">Fulfilled Date:</span>
                            <span class="value">{{ formatDate(order.fulfilled_date) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Status:</span>
                            <span class="value">
                                <span :class="`status-badge status-${order.status}`">
                                    {{ order.status }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Items -->
            <div class="items-card">
                <h3 class="card-title">Order Items</h3>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Discount</th>
                                <th class="text-right">Tax</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in order.items" :key="item.id">
                                <td class="font-medium">{{ item.product?.name }}</td>
                                <td class="text-gray-600">{{ item.product?.sku }}</td>
                                <td class="text-right">{{ item.quantity }}</td>
                                <td class="text-right">${{ formatNumber(item.unit_price) }}</td>
                                <td class="text-right">${{ formatNumber(item.discount) }}</td>
                                <td class="text-right">${{ formatNumber(item.tax) }}</td>
                                <td class="text-right font-semibold">${{ formatNumber(item.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="summary-card">
                <h3 class="card-title">Order Summary</h3>
                <div class="summary-rows">
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span>${{ formatNumber(order.subtotal) }}</span>
                    </div>
                    <div class="summary-row">
                        <span>Tax ({{ order.tax_rate }}%):</span>
                        <span>${{ formatNumber(order.tax) }}</span>
                    </div>
                    <div class="summary-row">
                        <span>Shipping:</span>
                        <span>${{ formatNumber(order.shipping) }}</span>
                    </div>
                    <div class="summary-row">
                        <span>Discount:</span>
                        <span>-${{ formatNumber(order.discount) }}</span>
                    </div>
                    <div class="summary-row total">
                        <span class="font-bold">Total:</span>
                        <span class="font-bold text-2xl text-indigo-600">${{ formatNumber(order.total) }}</span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div v-if="order.notes" class="notes-card">
                <h3 class="card-title">Notes</h3>
                <p class="notes-text">{{ order.notes }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useOrderStore } from '../../../stores/order';

const route = useRoute();
const router = useRouter();
const orderStore = useOrderStore();

const order = ref(null);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    await fetchOrder();
});

const fetchOrder = async () => {
    loading.value = true;
    error.value = null;

    try {
        const id = route.params.id;
        const response = await orderStore.getSalesOrder(id);
        order.value = response.data || response;
        console.log('Order loaded:', order.value);
    } catch (err) {
        console.error('Error fetching order:', err);
        error.value = err.response?.data?.message || 'Order not found';
    } finally {
        loading.value = false;
    }
};

const fulfillOrder = async () => {
    if (!confirm('Are you sure you want to fulfill this order? This will deduct inventory.')) {
        return;
    }

    loading.value = true;

    try {
        await orderStore.fulfillSalesOrder(order.value.id);
        await fetchOrder();
        alert('Order fulfilled successfully!');
    } catch (err) {
        console.error('Error fulfilling order:', err);
        alert(err.response?.data?.message || 'Failed to fulfill order');
    } finally {
        loading.value = false;
    }
};

const cancelOrder = async () => {
    if (!confirm('Are you sure you want to cancel this order?')) {
        return;
    }

    loading.value = true;

    try {
        await orderStore.cancelSalesOrder(order.value.id);
        await fetchOrder();
        alert('Order cancelled successfully!');
    } catch (err) {
        console.error('Error cancelling order:', err);
        alert(err.response?.data?.message || 'Failed to cancel order');
    } finally {
        loading.value = false;
    }
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};
</script>

<style scoped>
.sales-order-show {
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

.order-details {
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

.status-draft {
    @apply bg-gray-100 text-gray-800;
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

.header-actions {
    @apply flex gap-3;
}

.btn {
    @apply px-4 py-2 rounded-lg font-medium transition-colors no-underline inline-block;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-success {
    @apply bg-green-600 text-white hover:bg-green-700;
}

.btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700;
}

.info-grid {
    @apply grid grid-cols-1 md:grid-cols-3 gap-6;
}

.info-card {
    @apply bg-white rounded-lg shadow p-6;
}

.items-card {
    @apply bg-white rounded-lg shadow p-6;
}

.summary-card {
    @apply bg-white rounded-lg shadow p-6 max-w-md ml-auto;
}

.notes-card {
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

.summary-rows {
    @apply space-y-3;
}

.summary-row {
    @apply flex justify-between items-center py-2 border-b border-gray-200;
}

.summary-row.total {
    @apply border-t-2 border-gray-300 pt-4 mt-4;
}

.notes-text {
    @apply text-gray-700 whitespace-pre-wrap;
}
</style>