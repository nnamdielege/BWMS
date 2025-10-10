<template>
    <div class="data-table">
        <!-- Search and Actions -->
        <div v-if="searchable || $slots.actions" class="table-header">
            <div v-if="searchable" class="search-box">
                <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search..."
                    class="search-input"
                    @input="onSearch"
                />
            </div>
            <div v-if="$slots.actions" class="table-actions">
                <slot name="actions"></slot>
            </div>
        </div>

        <!-- Table -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th v-for="column in columns" :key="column.key" :class="column.headerClass">
                            <div class="th-content" @click="column.sortable && sort(column.key)">
                                <span>{{ column.label }}</span>
                                <svg v-if="column.sortable && sortKey === column.key" class="sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path v-if="sortOrder === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading" class="loading-row">
                        <td :colspan="columns.length">
                            <div class="loading-container">
                                <div class="spinner"></div>
                                <span>Loading...</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-else-if="data.length === 0" class="empty-row">
                        <td :colspan="columns.length">
                            <div class="empty-state">
                                <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <p>{{ emptyMessage }}</p>
                            </div>
                        </td>
                    </tr>
                    <tr v-else v-for="(row, index) in data" :key="index" class="data-row">
                        <td v-for="column in columns" :key="column.key" :class="column.class">
                            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                                {{ row[column.key] }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="pagination && data.length > 0" class="table-footer">
            <div class="pagination-info">
                Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to 
                {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} 
                of {{ pagination.total }} results
            </div>
            <div class="pagination-controls">
                <button
                    @click="goToPage(pagination.current_page - 1)"
                    :disabled="pagination.current_page === 1"
                    class="pagination-btn"
                >
                    Previous
                </button>
                <button
                    @click="goToPage(pagination.current_page + 1)"
                    :disabled="pagination.current_page === pagination.last_page"
                    class="pagination-btn"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    columns: {
        type: Array,
        required: true,
    },
    data: {
        type: Array,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    searchable: {
        type: Boolean,
        default: false,
    },
    pagination: {
        type: Object,
        default: null,
    },
    emptyMessage: {
        type: String,
        default: 'No data available',
    },
});

const emit = defineEmits(['search', 'sort', 'page-change']);

const searchQuery = ref('');
const sortKey = ref('');
const sortOrder = ref('asc');

const onSearch = () => {
    emit('search', searchQuery.value);
};

const sort = (key) => {
    if (sortKey.value === key) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortOrder.value = 'asc';
    }
    emit('sort', { key: sortKey.value, order: sortOrder.value });
};

const goToPage = (page) => {
    emit('page-change', page);
};
</script>

<style scoped>
.data-table {
    @apply bg-white rounded-lg shadow;
}

.table-header {
    @apply flex items-center justify-between gap-4 p-4 border-b border-gray-200;
}

.search-box {
    @apply relative flex-1 max-w-md;
}

.search-icon {
    @apply absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400;
}

.search-input {
    @apply w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.table-actions {
    @apply flex items-center gap-2;
}

.table-container {
    @apply overflow-x-auto;
}

.table {
    @apply min-w-full divide-y divide-gray-200;
}

.table thead {
    @apply bg-gray-50;
}

.table th {
    @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider;
}

.th-content {
    @apply flex items-center gap-2 cursor-pointer select-none;
}

.sort-icon {
    @apply w-4 h-4;
}

.table td {
    @apply px-6 py-4 whitespace-nowrap text-sm text-gray-900;
}

.data-row {
    @apply hover:bg-gray-50 transition-colors;
}

.loading-row td,
.empty-row td {
    @apply bg-gray-50;
}

.loading-container {
    @apply flex items-center justify-center gap-3 py-12;
}

.spinner {
    @apply w-8 h-8 border-4 border-gray-200 border-t-indigo-600 rounded-full animate-spin;
}

.empty-state {
    @apply flex flex-col items-center justify-center py-12 text-gray-400;
}

.empty-icon {
    @apply w-12 h-12 mb-4;
}

.table-footer {
    @apply flex items-center justify-between px-4 py-3 border-t border-gray-200;
}

.pagination-info {
    @apply text-sm text-gray-700;
}

.pagination-controls {
    @apply flex gap-2;
}

.pagination-btn {
    @apply px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed;
}
</style>