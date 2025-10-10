<template>
    <div class="suppliers-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Suppliers</h1>
                <p class="page-subtitle">Manage your supplier information</p>
            </div>
            <router-link to="/suppliers/create" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Supplier
            </router-link>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input
                        v-model="filters.search"
                        @input="handleSearch"
                        type="text"
                        placeholder="Search suppliers..."
                        class="filter-input"
                    />
                </div>

                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select v-model="filters.is_active" @change="fetchSuppliers" class="filter-select">
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button @click="resetFilters" class="btn btn-secondary btn-sm">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Suppliers Table -->
        <div class="table-card">
            <!-- Loading State -->
            <div v-if="supplierStore.loading" class="loading-container">
                <div class="spinner"></div>
                <p>Loading suppliers...</p>
            </div>

            <!-- Empty State -->
            <div v-else-if="suppliers.length === 0" class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <h3>No suppliers found</h3>
                <p>Get started by adding your first supplier</p>
                <router-link to="/suppliers/create" class="btn btn-primary mt-4">
                    Add Supplier
                </router-link>
            </div>

            <!-- Table -->
            <div v-else class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Supplier Code</th>
                            <th>Company Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="supplier in suppliers" :key="supplier.id">
                            <td class="font-medium">{{ supplier.supplier_code }}</td>
                            <td>
                                <router-link 
                                    :to="`/suppliers/${supplier.id}`"
                                    class="link"
                                >
                                    {{ supplier.company_name }}
                                </router-link>
                            </td>
                            <td>{{ supplier.contact_name }}</td>
                            <td class="text-gray-600">{{ supplier.email }}</td>
                            <td class="text-gray-600">{{ supplier.phone || 'N/A' }}</td>
                            <td>{{ supplier.city }}, {{ supplier.state }}</td>
                            <td>
                                <span
                                    :class="[
                                        'status-badge',
                                        supplier.is_active ? 'badge-active' : 'badge-inactive'
                                    ]"
                                >
                                    {{ supplier.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <router-link
                                        :to="`/suppliers/${supplier.id}`"
                                        class="btn-icon btn-icon-view"
                                        title="View"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </router-link>
                                    <router-link
                                        :to="`/suppliers/${supplier.id}/edit`"
                                        class="btn-icon btn-icon-edit"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </router-link>
                                    <button
                                        @click="handleDelete(supplier)"
                                        class="btn-icon btn-icon-delete"
                                        title="Delete"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="suppliers.length > 0" class="pagination">
                <div class="pagination-info">
                    Showing {{ supplierStore.pagination.from || 1 }} to {{ supplierStore.pagination.to || suppliers.length }} of {{ supplierStore.pagination.total }} results
                </div>
                <div class="pagination-buttons">
                    <button
                        @click="changePage(supplierStore.pagination.current_page - 1)"
                        :disabled="supplierStore.pagination.current_page === 1"
                        class="pagination-btn"
                    >
                        Previous
                    </button>
                    <button
                        @click="changePage(supplierStore.pagination.current_page + 1)"
                        :disabled="supplierStore.pagination.current_page === supplierStore.pagination.last_page"
                        class="pagination-btn"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useSupplierStore } from '../../stores/supplier';

const supplierStore = useSupplierStore();

const filters = ref({
    search: '',
    is_active: '',
});

const suppliers = computed(() => supplierStore.suppliers);

let searchTimeout;

onMounted(() => {
    fetchSuppliers();
});

const fetchSuppliers = async () => {
    try {
        await supplierStore.fetchSuppliers(filters.value);
    } catch (error) {
        console.error('Error fetching suppliers:', error);
    }
};

const handleSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchSuppliers();
    }, 300);
};

const resetFilters = () => {
    filters.value = {
        search: '',
        is_active: '',
    };
    fetchSuppliers();
};

const changePage = (page) => {
    fetchSuppliers({ ...filters.value, page });
};

const handleDelete = async (supplier) => {
    if (!confirm(`Are you sure you want to delete ${supplier.company_name}?`)) {
        return;
    }

    try {
        await supplierStore.deleteSupplier(supplier.id);
        alert('Supplier deleted successfully');
    } catch (error) {
        alert(error.response?.data?.message || 'Failed to delete supplier');
    }
};
</script>

<style scoped>
.suppliers-page {
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

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors no-underline;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-sm {
    @apply px-3 py-1.5 text-sm;
}

.filters-card {
    @apply bg-white rounded-lg shadow p-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-4 gap-4 items-end;
}

.filter-group {
    @apply flex flex-col;
}

.filter-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.filter-input,
.filter-select {
    @apply px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.filter-actions {
    @apply flex items-end;
}

.table-card {
    @apply bg-white rounded-lg shadow;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-12;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.empty-state {
    @apply flex flex-col items-center justify-center py-12 text-center;
}

.empty-icon {
    @apply w-16 h-16 text-gray-300 mb-4;
}

.empty-state h3 {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.empty-state p {
    @apply text-gray-600;
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
    @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider;
}

.table td {
    @apply px-6 py-4 whitespace-nowrap text-sm text-gray-700 border-t border-gray-200;
}

.link {
    @apply text-indigo-600 hover:text-indigo-900 no-underline;
}

.status-badge {
    @apply inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium;
}

.badge-active {
    @apply bg-green-100 text-green-800;
}

.badge-inactive {
    @apply bg-red-100 text-red-800;
}

.action-buttons {
    @apply flex items-center justify-center gap-2;
}.btn-icon {
    @apply p-2 rounded-lg transition-colors no-underline;
}.btn-icon-view {
    @apply text-blue-600 hover:bg-blue-50;
}.btn-icon-edit {
    @apply text-green-600 hover:bg-green-50;
}.btn-icon-delete {
    @apply text-red-600 hover:bg-red-50 border-0 bg-transparent cursor-pointer;
}.pagination {
    @apply flex items-center justify-between px-6 py-4 border-t border-gray-200;
}.pagination-info {
    @apply text-sm text-gray-700;
}.pagination-buttons {
    @apply flex gap-2;
}.pagination-btn {
    @apply px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors;
}
</style>