<template>
    <div class="purchase-order-show">
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
                <router-link to="/purchase-orders" class="btn-back">
                    ← Back to Orders
                </router-link>
            </div>
        </div>

        <!-- Order Details -->
        <div v-else-if="order" class="order-details">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <router-link to="/purchase-orders" class="back-link">
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
                        :to="`/purchase-orders/${order.id}/edit`" 
                        class="btn btn-secondary"
                    >
                        Edit Order
                    </router-link>
                    <button
                        v-if="order.status === 'pending'"
                        @click="showReceiveModal = true"
                        class="btn btn-success"
                        :disabled="loading"
                    >
                        Receive Order
                    </button>
                    <button
                        v-if="order.status !== 'received' && order.status !== 'cancelled'"
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
                <!-- Supplier Information -->
                <div class="info-card">
                    <h3 class="card-title">Supplier Information</h3>
                    <div class="info-rows">
                        <div class="info-row">
                            <span class="label">Company:</span>
                            <span class="value">
                                <router-link :to="`/suppliers/${order.supplier?.id}`" class="link">
                                    {{ order.supplier?.company_name }}
                                </router-link>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="label">Contact:</span>
                            <span class="value">{{ order.supplier?.contact_name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">{{ order.supplier?.email }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Phone:</span>
                            <span class="value">{{ order.supplier?.phone || 'N/A' }}</span>
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
                        <div v-if="order.received_date" class="info-row">
                            <span class="label">Received Date:</span>
                            <span class="value">{{ formatDate(order.received_date) }}</span>
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
                                <th class="text-center">Ordered</th>
                                <th class="text-center">Received</th>
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
                                <td class="text-center">{{ item.quantity }}</td>
                                <td class="text-center">
                                    <span :class="getReceivedClass(item)">
                                        {{ item.received_quantity }}
                                    </span>
                                </td>
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

        <!-- Receive Order Modal -->
        <Modal
            :show="showReceiveModal"
            title="Receive Purchase Order"
            size="large"
            @close="closeReceiveModal"
        >
            <div class="receive-form">
                <p class="text-sm text-gray-600 mb-4">
                    Enter the quantities received for each item. You can partially receive items.
                </p>

                <div class="receive-items">
                    <div v-for="item in receiveItems" :key="item.id" class="receive-item">
                        <div class="item-info">
                            <p class="font-medium">{{ item.product_name }}</p>
                            <p class="text-sm text-gray-600">SKU: {{ item.sku }}</p>
                            <p class="text-sm text-gray-600">
                                Ordered: {{ item.quantity }} | 
                                Already Received: {{ item.received_quantity }} | 
                                Remaining: {{ item.quantity - item.received_quantity }}
                            </p>
                        </div>
                        <div class="item-input">
                            <label class="form-label">Receive Quantity</label>
                            <input
                                v-model.number="item.receiving_quantity"
                                type="number"
                                :min="0"
                                :max="item.quantity - item.received_quantity"
                                class="form-input"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <button @click="closeReceiveModal" class="btn btn-secondary">
                    Cancel
                </button>
                <button 
                    @click="handleReceive" 
                    class="btn btn-primary"
                    :disabled="receivingOrder"
                >
                    {{ receivingOrder ? 'Receiving...' : 'Receive Items' }}
                </button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useOrderStore } from '../../../stores/order';
import Modal from '../../../components/common/Modal.vue';

const route = useRoute();
const router = useRouter();
const orderStore = useOrderStore();

const order = ref(null);
const loading = ref(true);
const error = ref(null);
const showReceiveModal = ref(false);
const receivingOrder = ref(false);
const receiveItems = ref([]);

onMounted(async () => {
    await fetchOrder();
});

const fetchOrder = async () => {
    loading.value = true;
    error.value = null;

    try {
        const id = route.params.id;
        const response = await orderStore.getPurchaseOrder(id);
        order.value = response.data || response;
        console.log('Order loaded:', order.value);
    } catch (err) {
        console.error('Error fetching order:', err);
        error.value = err.response?.data?.message || 'Order not found';
    } finally {
        loading.value = false;
    }
};

const initializeReceiveItems = () => {
    if (!order.value || !order.value.items) return;
    
    receiveItems.value = order.value.items.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_name: item.product?.name,
        sku: item.product?.sku,
        quantity: item.quantity,
        received_quantity: item.received_quantity,
        receiving_quantity: Math.max(0, item.quantity - item.received_quantity),
    }));
};

watch(showReceiveModal, (newValue) => {
    if (newValue) {
        initializeReceiveItems();
    }
});

const handleReceive = async () => {
    // Validate
    const totalReceiving = receiveItems.value.reduce((sum, item) => sum + (item.receiving_quantity || 0), 0);
    
    if (totalReceiving === 0) {
        alert('Please enter at least one item quantity to receive');
        return;
    }

    receivingOrder.value = true;

    try {
        const receiveData = {
            items: receiveItems.value
                .filter(item => item.receiving_quantity > 0)
                .map(item => ({
                    id: item.id,
                    received_quantity: item.receiving_quantity,
                })),
        };

        await orderStore.receivePurchaseOrder(order.value.id, receiveData);
        await fetchOrder();
        closeReceiveModal();
        alert('Order items received successfully!');
    } catch (err) {
        console.error('Error receiving order:', err);
        alert(err.response?.data?.message || 'Failed to receive order');
    } finally {
        receivingOrder.value = false;
    }
};

const closeReceiveModal = () => {
    showReceiveModal.value = false;
    receiveItems.value = [];
};

const cancelOrder = async () => {
    if (!confirm('Are you sure you want to cancel this order?')) {
        return;
    }

    loading.value = true;

    try {
        await orderStore.cancelPurchaseOrder(order.value.id);
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

const getReceivedClass = (item) => {
    if (item.received_quantity === 0) return 'text-gray-600';
    if (item.received_quantity < item.quantity) return 'text-yellow-600 font-semibold';
    return 'text-green-600 font-semibold';
};
</script>

<style scoped>
.purchase-order-show {
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

.status-received {
    @apply bg-green-100 text-green-800;
}

.status-cancelled {
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

.btn-success {
    @apply bg-green-600 text-white hover:bg-green-700;
}

.btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
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

.link {
    @apply text-indigo-600 hover:text-indigo-800 no-underline;
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

.receive-form {
    @apply space-y-4;
}

.receive-items {
    @apply space-y-4 max-h-96 overflow-y-auto;
}

.receive-item {
    @apply border border-gray-200 rounded-lg p-4;
}

.item-info {
    @apply mb-3;
}

.item-input {
    @apply space-y-2;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}

.form-input {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}
</style>