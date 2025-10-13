<template>
    <div class="global-search" ref="searchContainer">
        <div class="search-bar">
            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input
                ref="searchInput"
                v-model="searchQuery"
                @input="handleSearch"
                @focus="showResults = true"
                @keydown.down.prevent="navigateResults('down')"
                @keydown.up.prevent="navigateResults('up')"
                @keydown.enter.prevent="selectResult"
                @keydown.esc="closeSearch"
                type="text"
                placeholder="Search products, orders, customers..."
                class="search-input"
            />
            <button v-if="searchQuery" @click="clearSearch" class="clear-btn">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <kbd class="search-kbd">⌘K</kbd>
        </div>

        <!-- Search Results Dropdown -->
        <transition name="dropdown">
            <div v-if="showResults && (searchQuery.length >= 2 || loading)" class="search-results">
                <!-- Loading -->
                <div v-if="loading" class="search-loading">
                    <div class="spinner-small"></div>
                    <span>Searching...</span>
                </div>

                <!-- Results -->
                <div v-else-if="hasResults" class="results-container">
                    <!-- Products -->
                    <div v-if="results.products?.length > 0" class="result-section">
                        <h4 class="section-title">
                            <component :is="getIcon('cube')" class="w-4 h-4" />
                            Products
                        </h4>
                        <div
                            v-for="(item, index) in results.products"
                            :key="`product-${item.id}`"
                            @click="navigateToResult(item)"
                            :class="['result-item', { active: selectedIndex === getGlobalIndex('products', index) }]"
                        >
                            <component :is="getIcon(item.icon)" class="result-icon" />
                            <div class="result-content">
                                <div class="result-title">{{ item.title }}</div>
                                <div class="result-subtitle">{{ item.subtitle }}</div>
                                <div class="result-meta">{{ item.meta }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Customers -->
                    <div v-if="results.customers?.length > 0" class="result-section">
                        <h4 class="section-title">
                            <component :is="getIcon('user')" class="w-4 h-4" />
                            Customers
                        </h4>
                        <div
                            v-for="(item, index) in results.customers"
                            :key="`customer-${item.id}`"
                            @click="navigateToResult(item)"
                            :class="['result-item', { active: selectedIndex === getGlobalIndex('customers', index) }]"
                        >
                            <component :is="getIcon(item.icon)" class="result-icon" />
                            <div class="result-content">
                                <div class="result-title">{{ item.title }}</div>
                                <div class="result-subtitle">{{ item.subtitle }}</div>
                                <div class="result-meta">{{ item.meta }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Suppliers -->
                    <div v-if="results.suppliers?.length > 0" class="result-section">
                        <h4 class="section-title">
                            <component :is="getIcon('truck')" class="w-4 h-4" />
                            Suppliers
                        </h4>
                        <div
                            v-for="(item, index) in results.suppliers"
                            :key="`supplier-${item.id}`"
                            @click="navigateToResult(item)"
                            :class="['result-item', { active: selectedIndex === getGlobalIndex('suppliers', index) }]"
                        >
                            <component :is="getIcon(item.icon)" class="result-icon" />
                            <div class="result-content">
                                <div class="result-title">{{ item.title }}</div>
                                <div class="result-subtitle">{{ item.subtitle }}</div>
                                <div class="result-meta">{{ item.meta }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Sales Orders -->
                    <div v-if="results.sales_orders?.length > 0" class="result-section">
                        <h4 class="section-title">
                            <component :is="getIcon('shopping-cart')" class="w-4 h-4" />
                            Sales Orders
                        </h4>
                        <div
                            v-for="(item, index) in results.sales_orders"
                            :key="`sales-${item.id}`"
                            @click="navigateToResult(item)"
                            :class="['result-item', { active: selectedIndex === getGlobalIndex('sales_orders', index) }]"
                        >
                            <component :is="getIcon(item.icon)" class="result-icon" />
                            <div class="result-content">
                                <div class="result-title">
                                    {{ item.title }}
                                    <span v-if="item.badge" :class="getBadgeClass(item.badge)" class="result-badge">
                                        {{ item.badge }}
                                    </span>
                                </div>
                                <div class="result-subtitle">{{ item.subtitle }}</div>
                                <div class="result-meta">{{ item.meta }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Purchase Orders -->
                    <div v-if="results.purchase_orders?.length > 0" class="result-section">
                        <h4 class="section-title">
                            <component :is="getIcon('shopping-bag')" class="w-4 h-4" />
                            Purchase Orders
                        </h4>
                        <div
                            v-for="(item, index) in results.purchase_orders"
                            :key="`purchase-${item.id}`"
                            @click="navigateToResult(item)"
                            :class="['result-item', { active: selectedIndex === getGlobalIndex('purchase_orders', index) }]"
                        >
                            <component :is="getIcon(item.icon)" class="result-icon" />
                            <div class="result-content">
                                <div class="result-title">
                                    {{ item.title }}
                                    <span v-if="item.badge" :class="getBadgeClass(item.badge)" class="result-badge">
                                        {{ item.badge }}
                                    </span>
                                </div>
                                <div class="result-subtitle">{{ item.subtitle }}</div>
                                <div class="result-meta">{{ item.meta }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="search-footer">
                        <span class="text-xs text-gray-500">
                            {{ results.total }} result{{ results.total !== 1 ? 's' : '' }} found
                        </span>
                    </div>
                </div>

                <!-- No Results -->
                <div v-else class="no-results">
                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p>No results found for "{{ searchQuery }}"</p>
                    <p class="text-sm text-gray-500 mt-1">Try searching for products, orders, or customers</p>
                </div>

                <!-- Min Length Notice -->
                <div v-if="searchQuery.length < 2 && searchQuery.length > 0" class="no-results">
                    <p class="text-sm text-gray-500">Type at least 2 characters to search</p>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, h } from 'vue';
import { useRouter } from 'vue-router';
import searchService from '../../services/searchService';

const router = useRouter();

const searchContainer = ref(null);
const searchInput = ref(null);
const searchQuery = ref('');
const showResults = ref(false);
const loading = ref(false);
const results = ref({
    products: [],
    customers: [],
    suppliers: [],
    sales_orders: [],
    purchase_orders: [],
    total: 0,
});
const selectedIndex = ref(-1);

// Icons
const icons = {
    'cube': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' })
    ]),
    'user': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' })
    ]),
    'truck': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z' }),
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0' })
    ]),
    'shopping-cart': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z' })
    ]),
    'shopping-bag': () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z' })
    ]),
};

const getIcon = (iconName) => {
    return icons[iconName] || icons['cube'];
};

const hasResults = computed(() => {
    return results.value.total > 0;
});

// Flatten all results for keyboard navigation
const allResults = computed(() => {
    const flat = [];
    ['products', 'customers', 'suppliers', 'sales_orders', 'purchase_orders'].forEach(key => {
        if (results.value[key]?.length > 0) {
            results.value[key].forEach(item => {
                flat.push(item);
            });
        }
    });
    return flat;
});

const getGlobalIndex = (section, localIndex) => {
    let index = 0;
    const sections = ['products', 'customers', 'suppliers', 'sales_orders', 'purchase_orders'];
    
    for (const key of sections) {
        if (key === section) {
            return index + localIndex;
        }
        index += results.value[key]?.length || 0;
    }
    return -1;
};

let searchTimeout;
const handleSearch = () => {
    clearTimeout(searchTimeout);
    
    if (searchQuery.value.length < 2) {
        results.value = {
            products: [],
            customers: [],
            suppliers: [],
            sales_orders: [],
            purchase_orders: [],
            total: 0,
        };
        return;
    }

    loading.value = true;
    selectedIndex.value = -1;

    searchTimeout = setTimeout(async () => {
        try {
            const response = await searchService.globalSearch(searchQuery.value);
            results.value = response.data;
        } catch (error) {
            console.error('Search error:', error);
        } finally {
            loading.value = false;
        }
    }, 300);
};

const navigateResults = (direction) => {
    if (!hasResults.value) return;

    const maxIndex = allResults.value.length - 1;

    if (direction === 'down') {
        selectedIndex.value = selectedIndex.value < maxIndex ? selectedIndex.value + 1 : 0;
    } else {
        selectedIndex.value = selectedIndex.value > 0 ? selectedIndex.value - 1 : maxIndex;
    }
};

const selectResult = () => {
    if (selectedIndex.value >= 0 && allResults.value[selectedIndex.value]) {
        navigateToResult(allResults.value[selectedIndex.value]);
    }
};

const emit = defineEmits(['navigate']);

const navigateToResult = (item) => {
    router.push(item.link);
    closeSearch();
    emit('navigate'); // Emit event for mobile modal
};

const clearSearch = () => {
    searchQuery.value = '';
    results.value = {
        products: [],
        customers: [],
        suppliers: [],
        sales_orders: [],
        purchase_orders: [],
        total: 0,
    };
    selectedIndex.value = -1;
};

const closeSearch = () => {
    showResults.value = false;
    selectedIndex.value = -1;
};

const getBadgeClass = (status) => {
    const classes = {
        'draft': 'badge-gray',
        'pending': 'badge-yellow',
        'processing': 'badge-blue',
        'fulfilled': 'badge-green',
        'received': 'badge-green',
        'cancelled': 'badge-red',
    };
    return classes[status] || 'badge-gray';
};

// Keyboard shortcut (Cmd+K or Ctrl+K)
const handleKeyboardShortcut = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        searchInput.value?.focus();
        showResults.value = true;
    }
};

// Click outside to close
const handleClickOutside = (event) => {
    if (searchContainer.value && !searchContainer.value.contains(event.target)) {
        closeSearch();
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleKeyboardShortcut);
    document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeyboardShortcut);
    document.removeEventListener('click', handleClickOutside);
});
</script>

<style scoped>
.global-search {
    @apply relative flex-1 max-w-md;
}

.search-bar {
    @apply relative flex items-center;
}

.search-icon {
    @apply absolute left-3 w-5 h-5 text-gray-400 pointer-events-none;
}

.search-input {
    @apply w-full pl-10 pr-20 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm;
}

.clear-btn {
    @apply absolute right-16 p-1 text-gray-400 hover:text-gray-600 transition-colors;
}

.search-kbd {
    @apply absolute right-3 px-2 py-1 text-xs text-gray-500 bg-gray-100 border border-gray-300 rounded;
}

.search-results {
    @apply absolute top-full left-0 right-0 mt-2 bg-white rounded-lg shadow-xl border border-gray-200 max-h-[32rem] overflow-y-auto z-50;
}

.search-loading {
    @apply flex items-center justify-center gap-2 py-8 text-gray-500;
}

.spinner-small {
    @apply w-5 h-5 border-2 border-indigo-200 border-t-indigo-600 rounded-full animate-spin;
}

.results-container {
    @apply py-2;
}

.result-section {
    @apply border-b border-gray-100 last:border-0;
}

.section-title {
    @apply flex items-center gap-2 px-4 py-2 text-xs font-semibold text-gray-500 uppercase bg-gray-50;
}

.result-item {
    @apply flex items-start gap-3 px-4 py-3 hover:bg-gray-50 cursor-pointer transition-colors;
}

.result-item.active {
    @apply bg-indigo-50;
}

.result-icon {
    @apply w-8 h-8 p-1.5 bg-gray-100 text-gray-600 rounded-lg flex-shrink-0;
}

.result-content {
    @apply flex-1 min-w-0;
}

.result-title {
    @apply text-sm font-semibold text-gray-900 flex items-center gap-2;
}

.result-subtitle {
    @apply text-sm text-gray-600 truncate;
}

.result-meta {
    @apply text-xs text-gray-500 mt-0.5;
}

.result-badge {
    @apply inline-flex px-2 py-0.5 text-xs font-medium rounded-full;
}

.badge-gray {
    @apply bg-gray-100 text-gray-800;
}

.badge-blue {
    @apply bg-blue-100 text-blue-800;
}

.badge-yellow {
    @apply bg-yellow-100 text-yellow-800;
}

.badge-green {
    @apply bg-green-100 text-green-800;
}

.badge-red {
    @apply bg-red-100 text-red-800;
}

.search-footer {
    @apply px-4 py-2 border-t border-gray-100 bg-gray-50;
}

.no-results {
    @apply flex flex-col items-center justify-center py-12 text-gray-500 text-center;
}

.dropdown-enter-active,
.dropdown-leave-active {
    transition: all 0.2s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}
</style>