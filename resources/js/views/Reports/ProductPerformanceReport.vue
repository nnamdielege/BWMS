<template>
    <div class="product-performance-report">
        <!-- Top Selling Products -->
        <div class="table-card">
            <h3 class="card-title">Top Selling Products by Quantity</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Product</th>
                            <th class="text-right">Quantity Sold</th>
                            <th class="text-right">Total Revenue</th>
                            <th class="text-right">Order Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(product, index) in data.top_selling_products" :key="product.product_id">
                            <td>
                                <span class="rank-badge" :class="getRankClass(index)">
                                    #{{ index + 1 }}
                                </span>
                            </td>
                            <td class="font-medium">{{ product.product?.name }}</td>
                            <td class="text-right font-semibold">{{ product.total_quantity }}</td>
                            <td class="text-right">${{ formatNumber(product.total_revenue) }}</td>
                            <td class="text-right">{{ product.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Products by Revenue -->
        <div class="table-card">
            <h3 class="card-title">Top Products by Revenue</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Product</th>
                            <th class="text-right">Total Revenue</th>
                            <th class="text-right">Quantity Sold</th>
                            <th class="text-right">Avg Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(product, index) in data.products_by_revenue" :key="product.product_id">
                            <td>
                                <span class="rank-badge" :class="getRankClass(index)">
                                    #{{ index + 1 }}
                                </span>
                            </td>
                            <td class="font-medium">{{ product.product?.name }}</td>
                            <td class="text-right font-semibold text-green-600">${{ formatNumber(product.total_revenue) }}</td>
                            <td class="text-right">{{ product.total_quantity }}</td>
                            <td class="text-right">${{ formatNumber(product.total_revenue / product.total_quantity) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Slow Moving Products -->
        <div class="table-card">
            <h3 class="card-title">Slow Moving Products</h3>
            <p class="text-sm text-gray-600 mb-4">Products with low sales activity in the selected period</p>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th class="text-right">Quantity Sold</th>
                            <th class="text-right">Price</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in data.slow_moving_products" :key="product.id">
                            <td class="font-medium">{{ product.name }}</td>
                            <td class="text-gray-600">{{ product.sku }}</td>
                            <td class="text-right">
                                <span class="text-orange-600 font-semibold">
                                    {{ product.total_sold || 0 }}
                                </span>
                            </td>
                            <td class="text-right">${{ formatNumber(product.price) }}</td>
                            <td class="text-center">
                                <span class="status-badge bg-orange-100 text-orange-800">
                                    Slow Moving
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
});

const getRankClass = (index) => {
    if (index === 0) return 'bg-yellow-100 text-yellow-800';
    if (index === 1) return 'bg-gray-100 text-gray-800';
    if (index === 2) return 'bg-orange-100 text-orange-800';
    return 'bg-blue-100 text-blue-800';
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};
</script>

<style scoped>
.product-performance-report {
    @apply space-y-6;
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

.rank-badge {
    @apply inline-flex px-3 py-1 rounded-full text-xs font-bold;
}

.status-badge {
    @apply inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium;
}
</style>