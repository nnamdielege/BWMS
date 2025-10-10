<template>
    <div class="inventory-report">
        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total Products</p>
                    <p class="stat-value">{{ data.summary.total_products }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total Stock</p>
                    <p class="stat-value">{{ data.summary.total_stock }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-yellow-100 text-yellow-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Low Stock Items</p>
                    <p class="stat-value">{{ data.summary.low_stock_items }}</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon bg-red-100 text-red-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Out of Stock</p>
                    <p class="stat-value">{{ data.summary.out_of_stock }}</p>
                </div>
            </div>
        </div>

        <!-- Inventory by Warehouse -->
        <div class="table-card">
            <h3 class="card-title">Inventory by Warehouse</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Warehouse</th>
                            <th class="text-right">Product Count</th>
                            <th class="text-right">Total Stock</th>
                            <th class="text-right">Allocated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in data.inventory_by_warehouse" :key="item.warehouse_id">
                            <td class="font-medium">{{ item.warehouse?.name }}</td>
                            <td class="text-right">{{ item.product_count }}</td>
                            <td class="text-right">{{ item.total_stock }}</td>
                            <td class="text-right">{{ item.allocated_stock }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Low Stock Items -->
        <div class="table-card">
            <h3 class="card-title">Low Stock Alerts</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th class="text-right">On Hand</th>
                            <th class="text-right">Allocated</th>
                            <th class="text-right">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in data.low_stock_items" :key="item.id">
                            <td class="font-medium">{{ item.product?.name }}</td>
                            <td>{{ item.warehouse?.name }}</td>
                            <td class="text-right">
                                <span :class="getStockClass(item.quantity_on_hand)">
                                    {{ item.quantity_on_hand }}
                                </span>
                            </td>
                            <td class="text-right">{{ item.quantity_allocated }}</td>
                            <td class="text-right">{{ item.quantity_on_hand - item.quantity_allocated }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Stock Value -->
        <div class="table-card">
            <h3 class="card-title">Top Products by Stock Value</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Stock Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in data.stock_value" :key="item.product_id">
                            <td class="font-medium">{{ item.product?.name}}</td>
<td class="text-right">{{ item.quantity_on_hand }}</td>
<td class="text-right">${{ formatNumber(item.price) }}</td>
<td class="text-right font-semibold">${{ formatNumber(item.stock_value) }}</td>
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

const getStockClass = (quantity) => {
    if (quantity === 0) return 'text-red-600 font-semibold';
    if (quantity <= 10) return 'text-orange-600 font-semibold';
    if (quantity <= 20) return 'text-yellow-600 font-semibold';
    return 'text-gray-900';
};

const formatNumber = (num) => {
    return parseFloat(num || 0).toFixed(2);
};
</script>
<style scoped>
.inventory-report {
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