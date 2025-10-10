<template>
    <div class="sales-orders-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Sales Orders</h1>
                <p class="page-subtitle">Manage customer orders and fulfillment</p>
            </div>
            <div class="page-actions">
                <button @click="exportOrders" class="btn btn-secondary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Export
                </button>
                <router-link to="/sales-orders/create" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    New Sales Order
                </router-link>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-yellow-100 text-yellow-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-content">
                    <p class="stat-label">Pending Orders</p>
                    <p class="stat-value">{{ orderStore.pendingSalesOrders }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="stat-content">
                    <p class="stat-label">Processing</p>
                    <p class="stat-value">{{ processingCount }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-content">
                    <p class="stat-label">Completed Today</p>
                    <p class="stat-value">{{ completedTodayCount }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-indigo-100 text-indigo-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-content">
                    <p class="stat-label">Total Sales</p>
                    <p class="stat-value">${{ formatNumber(orderStore.totalSalesAmount) }}</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <FormInput
                    v-model="filters.search"
                    placeholder="Search by order number or customer..."
                    @input="debouncedSearch"
                >
                    <template #append>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </template>
                </FormInput>

                <FormSelect
                    v-model="filters.status"
                    label="Status"
                    :options="statusOptions"
                    placeholder="All Status"
                    @update:modelValue="applyFilters"
                />

                <FormInput
                    v-model="filters.date_from"
                    type="date"
                    label="From Date"
                    @change="applyFilters"
                />

                <FormInput
                    v-model="filters.date_to"
                    type="date"
                    label="To Date"
                    @change="applyFilters"
                />

                <div class="flex items-end">
                    <button @click="resetFilters" class="btn btn-secondary w-full">
                        Reset Filters
                    </button>
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
            v-if="orderStore.error"
            type="error"
            :message="orderStore.error"
            @close="orderStore.clearError()"
        />

        <!-- Orders Table -->
        <DataTable
            :columns="columns"
            :data="orderStore.salesOrders"
            :loading="orderStore.loading"
            :pagination="orderStore.pagination"
            empty-message="No sales orders found"
            @page-change="handlePageChange"
        >
            <template #cell-order_number="{ row }">
                <router-link :to="`/sales-orders/${row.id}`" class="order-link">
                    {{ row.order_number }}
                </router-link>
            </template>

            <template #cell-customer="{ row }">
                <div class="customer-info">
                    <p class="customer-name">{{ row.customer?.company_name || row.customer?.name }}</p>
                    <p class="customer-email">{{ row.customer?.email }}</p>
                </div>
            </template>

            <template #cell-order_date="{ row }">
                {{ formatDate(row.order_date) }}
            </template>

            <template #cell-items="{ row }">
                <span class="items-count">{{ row.items?.length || 0 }} items</span>
            </template>

            <template #cell-total="{ row }">
                <span class="order-total">${{ formatNumber(row.total) }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :variant="getStatusVariant(row.status)">
                    {{ getStatusText(row.status) }}
                </Badge>
            </template>

            <template #cell-actions="{ row }">
                <div class="action-buttons">
                    <router-link :to="`/sales-orders/${row.id}`" class="action-btn view" title="View Details">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </router-link>
                    <button 
                        v-if="row.status === 'pending' || row.status === 'processing'" 
                        @click="fulfillOrder(row)" 
                        class="action-btn fulfill" 
                        title="Fulfill Order"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                    <button 
                        v-if="row.status === 'pending'" 
                        @click="confirmCancel(row)" 
                        class="action-btn cancel" 
                        title="Cancel Order"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </template>
        </DataTable>

        <!-- Cancel Confirmation Modal -->
        <Modal
            :show="showCancelModal"
            title="Cancel Sales Order"
            size="small"
            @close="showCancelModal = false"
        >
            <div class="cancel-confirmation">
                <div class="warning-icon">
                    <svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p class="cancel-message">
                    Are you sure you want to cancel order <strong>{{ orderToCancel?.order_number }}</strong>?
                    This action cannot be undone.
                </p>
            </div>

            <template #footer>
                <button @click="showCancelModal = false" class="btn btn-secondary">
                    No, Keep Order
                </button>
                <button @click="cancelOrder" class="btn btn-danger" :disabled="cancelling">
                    <span v-if="cancelling" class="spinner-small"></span>
                    <span>{{ cancelling ? 'Cancelling...' : 'Yes, Cancel Order' }}</span>
                </button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useOrderStore } from '../../../stores/order';
import DataTable from '../../../components/common/DataTable.vue';
import FormInput from '../../../components/common/FormInput.vue';
import FormSelect from '../../../components/common/FormSelect.vue';
import Badge from '../../../components/common/Badge.vue';
import Alert from '../../../components/common/Alert.vue';
import Modal from '../../../components/common/Modal.vue';

const router = useRouter();
const orderStore = useOrderStore();

const filters = ref({
    search: '',
    status: '',
    date_from: '',
    date_to: '',
});

const statusOptions = [
    { id: 'draft', name: 'Draft' },
    { id: 'pending', name: 'Pending' },
    { id: 'processing', name: 'Processing' },
    { id: 'completed', name: 'Completed' },
    { id: 'cancelled', name: 'Cancelled' },
];

const successMessage = ref('');
const showCancelModal = ref(false);
const orderToCancel = ref(null);
const cancelling = ref(false);

const columns = [
    { key: 'order_number', label: 'Order #', sortable: true },
    { key: 'customer', label: 'Customer', sortable: false },
    { key: 'order_date', label: 'Order Date', sortable: true },
    { key: 'items', label: 'Items', sortable: false },
    { key: 'total', label: 'Total', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'actions', label: 'Actions', sortable: false, class: 'text-right' },
];

const processingCount = computed(() => {
    return orderStore.salesOrders.filter(order => order.status === 'processing').length;
});

const completedTodayCount = computed(() => {
    const today = new Date().toDateString();
    return orderStore.salesOrders.filter(order => {
        return order.status === 'completed' && 
               new Date(order.updated_at).toDateString() === today;
    }).length;
});

// Debounced search
let searchTimeout;
const debouncedSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 500);
};

const applyFilters = async () => {
    orderStore.setFilters(filters.value);
    await orderStore.fetchSalesOrders();
};

const resetFilters = async () => {
    filters.value = {
        search: '',
        status: '',
        date_from: '',
        date_to: '',
    };
    orderStore.resetFilters();
    await orderStore.fetchSalesOrders();
};

const handlePageChange = async (page) => {
    await orderStore.fetchSalesOrders({ page });
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString();
};

const getStatusVariant = (status) => {
    const variants = {
        draft: 'default',
        pending: 'warning',
        processing: 'info',
        completed: 'success',
        cancelled: 'error',
    };
    return variants[status] || 'default';
};

const getStatusText = (status) => {
    return status.charAt(0).toUpperCase() + status.slice(1);
};

const fulfillOrder = (order) => {
    router.push(`/sales-orders/${order.id}`);
};

const confirmCancel = (order) => {
    orderToCancel.value = order;
    showCancelModal.value = true;
};

const cancelOrder = async () => {
    if (!orderToCancel.value) return;

    cancelling.value = true;
    try {
        await orderStore.cancelSalesOrder(orderToCancel.value.id);
        successMessage.value = 'Order cancelled successfully';
        showCancelModal.value = false;
        orderToCancel.value = null;
    } catch (error) {
        console.error('Cancel error:', error);
    } finally {
        cancelling.value = false;
    }
};

const exportOrders = () => {
    alert('Export functionality coming soon!');
};

onMounted(async () => {
    await orderStore.fetchSalesOrders();
});
</script>

<style scoped>
.sales-orders-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-center justify-between;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.page-actions {
    @apply flex gap-3;
}

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-all no-underline;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700 disabled:opacity-50;
}

.stats-grid {
    @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6;
}

.stat-card {
    @apply bg-white rounded-lg shadow p-6 flex items-start gap-4;
}

.stat-icon {
    @apply rounded-lg p-3;
}

.stat-content {
    @apply flex-1;
}

.stat-label {
    @apply text-sm text-gray-600 mb-1;
}

.stat-value {
    @apply text-3xl font-bold text-gray-900;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-5 gap-4;
}

.order-link {
    @apply font-medium text-indigo-600 hover:text-indigo-800 no-underline;
}

.customer-info {
    @apply space-y-1;
}

.customer-name {
    @apply font-medium text-gray-900;
}

.customer-email {
    @apply text-sm text-gray-500;
}

.items-count {
    @apply text-sm text-gray-600;
}

.order-total {
    @apply font-semibold text-gray-900;
}

.action-buttons {
    @apply flex items-center justify-end gap-2;
}

.action-btn {
    @apply p-2 rounded-lg transition-colors;
}

.action-btn.view {
    @apply text-blue-600 hover:bg-blue-50;
}

.action-btn.fulfill {
    @apply text-green-600 hover:bg-green-50;
}

.action-btn.cancel {
    @apply text-red-600 hover:bg-red-50;
}

.cancel-confirmation {
    @apply text-center py-4;
}

.warning-icon {
    @apply flex justify-center mb-4;
}

.cancel-message {
    @apply text-gray-700;
}

.spinner-small {
    @apply w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin;
}
</style>