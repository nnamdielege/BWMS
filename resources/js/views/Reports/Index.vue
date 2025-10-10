<template>
    <div class="reports-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Reports</h1>
                <p class="page-subtitle">Generate and view business reports</p>
            </div>
        </div>

        <!-- Report Type Tabs -->
        <div class="tabs-container">
            <div class="tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="['tab', { 'tab-active': activeTab === tab.id }]"
                >
                    <component :is="tab.icon" class="w-5 h-5" />
                    {{ tab.name }}
                </button>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="filters-card">
            <div class="filters-grid">
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input
                        v-model="filters.start_date"
                        type="date"
                        class="form-input"
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">End Date</label>
                    <input
                        v-model="filters.end_date"
                        type="date"
                        class="form-input"
                    />
                </div>

                <div v-if="activeTab === 'inventory'" class="form-group">
                    <label class="form-label">Warehouse</label>
                    <select v-model="filters.warehouse_id" class="form-select">
                        <option value="">All Warehouses</option>
                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}
                        </option>
                    </select>
                </div>

                <div class="form-actions">
                    <button @click="generateReport" class="btn btn-primary" :disabled="loading">
                        <svg v-if="loading" class="animate-spin w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Generate Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Generating report...</p>
        </div>

        <!-- Report Content -->
        <div v-else-if="reportData" class="report-content">
            <!-- Sales Report -->
            <SalesReport v-if="activeTab === 'sales'" :data="reportData" />

            <!-- Purchase Report -->
            <PurchaseReport v-if="activeTab === 'purchases'" :data="reportData" />

            <!-- Inventory Report -->
            <InventoryReport v-if="activeTab === 'inventory'" :data="reportData" />

            <!-- Product Performance Report -->
            <ProductPerformanceReport v-if="activeTab === 'product-performance'" :data="reportData" />
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, h } from 'vue';
import reportService from '../../services/reportService';
import { useWarehouseStore } from '../../stores/warehouse';
import SalesReport from './SalesReport.vue';
import PurchaseReport from './PurchaseReport.vue';
import InventoryReport from './InventoryReport.vue';
import ProductPerformanceReport from './ProductPerformanceReport.vue';

const warehouseStore = useWarehouseStore();

// Icon components (inline SVGs)
const ChartBarIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' })
]);

const ShoppingCartIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z' })
]);

const CubeIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' })
]);

const TrendingUpIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' })
]);

const tabs = ref([
    { id: 'sales', name: 'Sales Report', icon: ChartBarIcon },
    { id: 'purchases', name: 'Purchase Report', icon: ShoppingCartIcon },
    { id: 'inventory', name: 'Inventory Report', icon: CubeIcon },
    { id: 'product-performance', name: 'Product Performance', icon: TrendingUpIcon },
]);

const activeTab = ref('sales');
const reportData = ref(null);
const loading = ref(false);
const warehouses = ref([]);

const filters = ref({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
    warehouse_id: '',
});

onMounted(async () => {
    await warehouseStore.fetchWarehouses();
    warehouses.value = warehouseStore.warehouses;
    await generateReport();
});

const generateReport = async () => {
    loading.value = true;
    reportData.value = null;

    try {
        let response;

        switch (activeTab.value) {
            case 'sales':
                response = await reportService.getSalesReport(filters.value);
                break;
            case 'purchases':
                response = await reportService.getPurchaseReport(filters.value);
                break;
            case 'inventory':
                response = await reportService.getInventoryReport(filters.value);
                break;
            case 'product-performance':
                response = await reportService.getProductPerformanceReport(filters.value);
                break;
        }

        reportData.value = response.data;
        console.log('Report data:', reportData.value);
    } catch (error) {
        console.error('Error generating report:', error);
        alert('Failed to generate report');
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.reports-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-start justify-between;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.tabs-container {
    @apply bg-white rounded-lg shadow;
}

.tabs {
    @apply flex border-b border-gray-200 overflow-x-auto;
}

.tab {
    @apply flex items-center gap-2 px-6 py-4 font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 transition-colors border-b-2 border-transparent whitespace-nowrap;
}

.tab-active {
    @apply text-indigo-600 border-indigo-600;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-4 gap-4 items-end;
}

.form-group {
    @apply flex flex-col;
}

.form-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.form-input,
.form-select {
    @apply px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-actions {
    @apply flex items-end;
}

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-20 bg-white rounded-lg shadow;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.report-content {
    @apply space-y-6;
}
</style>