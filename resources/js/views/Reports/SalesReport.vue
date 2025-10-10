<template>
    <div class="sales-report">
        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total Orders</p>
                    <p class="stat-value">{{ data.summary.total_orders }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total Revenue</p>
                    <p class="stat-value">${{ formatNumber(data.summary.total_revenue) }}</p>
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
                    <p class="stat-value">{{ data.summary.pending_orders }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-purple-100 text-purple-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Avg Order Value</p>
                    <p class="stat-value">${{ formatNumber(data.summary.average_order_value) }}</p>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Sales by Status -->
            <div class="chart-card">
                <h3 class="chart-title">Sales by Status</h3>
                <div class="chart-content">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in data.sales_by_status" :key="item.status">
                                <td>
                                    <span :class="['status-badge', `status-${item.status}`]">
                                        {{ item.status }}
                                    </span>
                                </td>
                                <td class="text-right">{{ item.count }}</td>
                                <td class="text-right font-semibold">${{ formatNumber(item.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Daily Sales Trend -->
            <div class="chart-card full-width">
                <h3 class="chart-title">Daily Sales Trend</h3>
                <div class="chart-content">
                    <div class="trend-chart">
                        <div v-for="sale in data.daily_sales" :key="sale.date" class="trend-item">
                            <div class="trend-date">{{ formatDate(sale.date) }}</div>
                            <div class="trend-bar-container">
                                <div 
                                    class="trend-bar" 
                                    :style="{ width: getTrendWidth(sale.total_sales) + '%' }"
                                ></div>
                            </div>
                            <div class="trend-value">${{ formatNumber(sale.total_sales) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Customers -->
        <div class="table-card">
            <h3 class="card-title">Top Customers</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="customer in data.sales_by_customer" :key="customer.customer_id">
                            <td class="font-medium">{{ customer.customer?.company_name }}</td>
                            <td class="text-right">{{ customer.order_count }}</td>
                            <td class="text-right font-semibold">${{ formatNumber(customer.total_amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Products -->
        <div class="table-card">
            <h3 class="card-title">Top Products by Revenue</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-right">Quantity Sold</th>
                            <th class="text-right">Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in data.top_products" :key="product.product_id">
                            <td class="font-medium">{{ product.product?.name }}</td>
                            <td class="text-right">{{ product.total_quantity }}</td>
                            <td class="text-right font-semibold">${{ formatNumber(product.total_revenue) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
});

const maxSales = computed(() => {
    if (!props.data.daily_sales || props.data.daily_sales.length === 0) return 0;
    return Math.max(...props.data.daily_sales.map(s => parseFloat(s.total_sales)));
});

const getTrendWidth = (value) => {
    if (maxSales.value === 0) return 0;
    return (parseFloat(value) / maxSales.value) * 100;
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};
</script>

<style scoped>
.sales-report {
    @apply space-y-6;
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

.charts-grid {
    @apply grid grid-cols-1 lg:grid-cols-2 gap-6;
}

.chart-card {
    @apply bg-white rounded-lg shadow p-6;
}

.chart-card.full-width {
    @apply lg:col-span-2;
}

.chart-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-3 border-b border-gray-200;
}

.chart-content {
    @apply space-y-2;
}

.data-table {
    @apply w-full;
}

.data-table thead {
    @apply bg-gray-50;
}

.data-table th {
    @apply px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase;
}

.data-table td {
    @apply px-4 py-3 text-sm border-t border-gray-200;
}

.status-badge {
    @apply inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium;
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

.trend-chart {
    @apply space-y-3;
}

.trend-item {
    @apply grid grid-cols-12 gap-4 items-center;
}

.trend-date {
    @apply col-span-2 text-sm text-gray-600;
}

.trend-bar-container {
    @apply col-span-8 bg-gray-100 rounded-full h-8;
}

.trend-bar {
    @apply bg-indigo-600 h-full rounded-full transition-all duration-300;
}

.trend-value {
    @apply col-span-2 text-sm font-semibold text-gray-900 text-right;
}

.table-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-3 border-b border-gray-200;
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
    @apply px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase;
}

.table td {
    @apply px-4 py-3 text-sm border-t border-gray-200;
}
</style>