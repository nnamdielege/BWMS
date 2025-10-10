<template>
    <div class="edit-sales-order-page">
        <!-- Loading State -->
        <div v-if="initialLoading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading order...</p>
        </div>

        <!-- Main Content -->
        <div v-else>
            <!-- Page Header -->
            <div class="page-header">
                <div class="flex items-center gap-4">
                    <router-link to="/sales-orders" class="back-btn">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </router-link>
                    <div>
                        <h1 class="page-title">Edit Sales Order</h1>
                        <p class="page-subtitle">{{ form.order_number }}</p>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <Alert
                v-if="orderStore.error"
                type="error"
                :message="orderStore.error"
                @close="orderStore.clearError()"
            />

            <!-- Order Form -->
            <form @submit.prevent="handleSubmit" class="order-form">
                <div class="form-grid">
                    <!-- Order Information -->
                    <div class="form-section">
                        <h3 class="section-title">Order Information</h3>
                        <div class="section-content">
                            <!-- Customer Selection -->
                            <div class="form-group">
                                <label class="form-label">Customer *</label>
                                <div class="search-select">
                                    <input
                                        v-model="customerSearch"
                                        @input="searchCustomers"
                                        @focus="showCustomerDropdown = true"
                                        type="text"
                                        placeholder="Search customer..."
                                        class="form-input"
                                        :class="{ 'input-error': errors.customer_id }"
                                    />
                                    <div v-if="showCustomerDropdown && customerResults.length > 0" class="search-dropdown">
                                        <div
                                            v-for="customer in customerResults"
                                            :key="customer.id"
                                            @click="selectCustomer(customer)"
                                            class="dropdown-item"
                                        >
                                            <div class="dropdown-item-main">
                                                <span class="font-medium">{{ customer.company_name }}</span>
                                                <Badge variant="info">{{ customer.customer_code }}</Badge>
                                            </div>
                                            <p class="text-sm text-gray-500">{{ customer.email }}</p>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="errors.customer_id" class="error-message">{{ errors.customer_id }}</p>

                                <!-- Selected Customer Display -->
                                <div v-if="selectedCustomer" class="selected-item">
                                    <div class="item-details">
                                        <div>
                                            <p class="item-name">{{ selectedCustomer.company_name }}</p>
                                            <p class="item-code">{{ selectedCustomer.customer_code }}</p>
                                        </div>
                                        <button @click="clearCustomer" type="button" class="btn-clear">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Warehouse Selection -->
                            <FormSelect
                                v-model="form.warehouse_id"
                                label="Warehouse"
                                :options="warehouses"
                                placeholder="Select warehouse"
                                required
                                :error="errors.warehouse_id"
                            />

                            <!-- Order Date -->
                            <FormInput
                                v-model="form.order_date"
                                type="date"
                                label="Order Date"
                                required
                                :error="errors.order_date"
                            />

                            <!-- Expected Delivery Date -->
                            <FormInput
                                v-model="form.expected_date"
                                type="date"
                                label="Expected Delivery Date"
                                :error="errors.expected_date"
                            />

                            <!-- Status -->
                            <FormSelect
                                v-model="form.status"
                                label="Status"
                                :options="statusOptions"
                                required
                                :error="errors.status"
                            />

                            <!-- Notes -->
                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="3"
                                    placeholder="Additional notes..."
                                    class="form-textarea"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="form-section full-width">
                        <div class="section-header">
                            <h3 class="section-title">Order Items</h3>
                            <button type="button" @click="showAddItemModal = true" class="btn btn-secondary btn-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Add Item
                            </button>
                        </div>

                        <div class="items-table">
                            <table class="table" v-if="form.items.length > 0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>SKU</th>
                                        <th class="text-center">Available</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-right">Unit Price</th>
                                        <th class="text-right">Discount</th>
                                        <th class="text-right">Subtotal</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(item, index) in form.items" :key="index">
                                        <td class="font-medium">{{ item.product_name }}</td>
                                        <td class="text-gray-600">{{ item.sku }}</td>
                                        <td class="text-center">
                                            <span :class="getStockClass(item.available_stock)">
                                                {{ item.available_stock }}
                                            </span>
                                        </td>
                                        <td>
                                            <input
                                                v-model.number="item.quantity"
                                                type="number"
                                                min="1"
                                                :max="item.available_stock"
                                                @input="calculateItemTotal(item)"
                                                class="form-input-sm text-center"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model.number="item.unit_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                @input="calculateItemTotal(item)"
                                                class="form-input-sm text-right"
                                            />
                                        </td>
                                        <td>
                                            <input
                                                v-model.number="item.discount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                @input="calculateItemTotal(item)"
                                                class="form-input-sm text-right"
                                            />
                                        </td>
                                        <td class="text-right font-semibold">${{ formatNumber(item.subtotal) }}</td>
                                        <td class="text-center">
                                            <button type="button" @click="removeItem(index)" class="btn-icon-danger">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div v-else class="empty-items">
                                <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <p class="text-gray-500 mt-2">No items added yet</p>
                                <button type="button" @click="showAddItemModal = true" class="btn btn-secondary btn-sm mt-4">
                                    Add First Item
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary -->
                    <div class="form-section">
                        <h3 class="section-title">Order Summary</h3>
                        <div class="section-content">
                            <div class="summary-row">
                                <span>Subtotal:</span>
                                <span class="font-semibold">${{ formatNumber(orderSubtotal) }}</span>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Tax (%)</label>
                                <input
                                    v-model.number="form.tax_rate"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    @input="calculateTotals"
                                    class="form-input"
                                />
                            </div>

                            <div class="summary-row">
                                <span>Tax:</span>
                                <span class="font-semibold">${{ formatNumber(form.tax) }}</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Shipping</label>
                                <input
                                    v-model.number="form.shipping"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    @input="calculateTotals"
                                    class="form-input"
                                />
                            </div>

                            <div class="form-group">
                                <label class="form-label">Discount</label>
                                <input
                                    v-model.number="form.discount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    @input="calculateTotals"
                                    class="form-input"
                                />
                            </div>

                            <div class="summary-row total">
                                <span>Total:</span>
                                <span class="text-2xl font-bold text-indigo-600">${{ formatNumber(form.total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <router-link to="/sales-orders" class="btn btn-secondary">
                        Cancel
                    </router-link>
                    <button type="submit" class="btn btn-primary" :disabled="orderStore.loading || form.items.length === 0">
                        <span v-if="orderStore.loading" class="spinner-small"></span>
                        <span>{{ orderStore.loading ? 'Updating...' : 'Update Order' }}</span>
                    </button>
                </div>
            </form>

            <!-- Add Item Modal -->
            <Modal
                :show="showAddItemModal"
                title="Add Product to Order"
                size="medium"
                @close="closeAddItemModal"
            >
                <div class="add-item-form">
                    <!-- Product Search -->
                    <div class="form-group">
                        <label class="form-label">Search Product</label>
                        <div class="search-select">
                            <input
                                v-model="productSearch"
                                @input="searchProducts"
                                @focus="showProductDropdown = true"
                                type="text"
                                placeholder="Search by name or SKU..."
                                class="form-input"
                            />
                            <div v-if="showProductDropdown && productResults.length > 0" class="search-dropdown">
                                <div
                                    v-for="product in productResults"
                                    :key="product.id"
                                    @click="selectProduct(product)"
                                    class="dropdown-item"
                                >
                                    <div class="dropdown-item-main">
                                        <span class="font-medium">{{ product.name }}</span>
                                        <Badge variant="info">{{ product.sku }}</Badge>
                                    </div>
                                    <div class="flex items-center justify-between mt-1">
                                        <span class="text-sm text-gray-500">Price: ${{ product.price }}</span>
                                        <span class="text-sm" :class="getStockClass(product.available_stock)">
                                            Stock: {{ product.available_stock }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Product Details -->
                    <div v-if="selectedProduct" class="selected-product-details">
                        <div class="product-info">
                            <h4 class="font-semibold text-gray-900">{{ selectedProduct.name }}</h4>
                            <p class="text-sm text-gray-600">SKU: {{ selectedProduct.sku }}</p>
                            <p class="text-sm text-gray-600">Available: {{ selectedProduct.available_stock }} units</p>
                            <p class="text-sm text-gray-600">Price: ${{ selectedProduct.price }}</p>
                        </div>

                        <div class="space-y-4 mt-4">
                            <FormInput
                                v-model.number="newItem.quantity"
                                type="number"
                                label="Quantity"
                                placeholder="Enter quantity"
                                :min="1"
                                :max="selectedProduct.available_stock"
                                required
                                :error="newItemErrors.quantity"
                            />

                            <FormInput
                                v-model.number="newItem.unit_price"
                                type="number"
                                step="0.01"
                                label="Unit Price"
                                placeholder="Enter price"
                                required
                                :error="newItemErrors.unit_price"
                            />

                            <FormInput
                                v-model.number="newItem.discount"
                                type="number"
                                step="0.01"
                                label="Discount"
                                placeholder="0.00"
                            />
                        </div>
                    </div>

                    <div v-else class="text-center text-gray-500 py-8">
                        Search and select a product to add to the order
                    </div>
                </div>

                <template #footer>
                    <button @click="closeAddItemModal" class="btn btn-secondary">
                        Cancel
                    </button>
                    <button 
                        @click="addItemToOrder" 
                        class="btn btn-primary" 
                        :disabled="!selectedProduct"
                    >
                        Add to Order
                    </button>
                </template>
            </Modal>
        </div>
    </div>
</template>

<script setup>
    import { ref, computed, onMounted } from 'vue';
    import { useRouter, useRoute } from 'vue-router';
    import { useOrderStore } from '../../../stores/order';
    import { useWarehouseStore } from '../../../stores/warehouse';
    import { useProductStore } from '../../../stores/product';
    import customerService from '../../../services/customerService';
    import inventoryService from '../../../services/inventoryService';
    import FormInput from '../../../components/common/FormInput.vue';
    import FormSelect from '../../../components/common/FormSelect.vue';
    import Badge from '../../../components/common/Badge.vue';
    import Alert from '../../../components/common/Alert.vue';
    import Modal from '../../../components/common/Modal.vue';

    const router = useRouter();
    const route = useRoute();
    const orderStore = useOrderStore();
    const warehouseStore = useWarehouseStore();
    const productStore = useProductStore();

    const form = ref({
        order_number: '',
        customer_id: '',
        warehouse_id: '',
        order_date: '',
        expected_date: '',
        items: [],
        subtotal: 0,
        tax_rate: 0,
        tax: 0,
        shipping: 0,
        discount: 0,
        total: 0,
        notes: '',
        status: 'pending',
    });

    const initialLoading = ref(true);
    const errors = ref({});
    const warehouses = ref([]);
    const customerSearch = ref('');
    const showCustomerDropdown = ref(false);
    const customerResults = ref([]);
    const selectedCustomer = ref(null);

    const productSearch = ref('');
    const showProductDropdown = ref(false);
    const productResults = ref([]);
    const selectedProduct = ref(null);

    const showAddItemModal = ref(false);
    const newItem = ref({
        quantity: 1,
        unit_price: 0,
        discount: 0,
    });
    const newItemErrors = ref({});

    const statusOptions = ref([
        { id: 'draft', name: 'Draft' },
        { id: 'pending', name: 'Pending' },
        { id: 'processing', name: 'Processing' },
        { id: 'fulfilled', name: 'Fulfilled' },
        { id: 'cancelled', name: 'Cancelled' },
    ]);

    const orderSubtotal = computed(() => {
        return form.value.items.reduce((sum, item) => sum + (item.subtotal || 0), 0);
    });

    onMounted(async () => {
        await loadData();
    });

    const loadData = async () => {
        initialLoading.value = true;

        try {
            // Load warehouses
            await warehouseStore.fetchWarehouses();
            warehouses.value = warehouseStore.warehouses;

            // Load order
            const orderId = route.params.id;
            const orderData = await orderStore.getSalesOrder(orderId);
            const order = orderData.data || orderData;

            console.log('Order loaded:', order);

            // Populate form
            form.value = {
                order_number: order.order_number,
                customer_id: order.customer_id,
                warehouse_id: order.warehouse_id,
                order_date: order.order_date,
                expected_date: order.expected_date || '',
                status: order.status,
                subtotal: parseFloat(order.subtotal),
                tax_rate: parseFloat(order.tax_rate),
                tax: parseFloat(order.tax),
                shipping: parseFloat(order.shipping),
                discount: parseFloat(order.discount),
                total: parseFloat(order.total),
                notes: order.notes || '',
                items: [],
            };

            // Set selected customer
            if (order.customer) {
                selectedCustomer.value = order.customer;
                customerSearch.value = order.customer.company_name;
            }

            // Load items with inventory info
            if (order.items && order.items.length > 0) {
                for (const item of order.items) {
                    try {
                        const inventoryResponse = await inventoryService.getAll({
                            product_id: item.product_id,
                            warehouse_id: order.warehouse_id,
                        });
                        
                        const inventory = inventoryResponse.data.data?.[0] || inventoryResponse.data[0];
                        
                        form.value.items.push({
                            product_id: item.product_id,
                            product_name: item.product?.name,
                            sku: item.product?.sku,
                            available_stock: inventory?.quantity_available || 0,
                            quantity: item.quantity,
                            unit_price: parseFloat(item.unit_price),
                            discount: parseFloat(item.discount),
                            tax: parseFloat(item.tax),
                            subtotal: parseFloat(item.subtotal),
                        });
                    } catch (error) {
                        console.error('Error loading inventory for item:', error);
                        form.value.items.push({
                            product_id: item.product_id,
                            product_name: item.product?.name,
                            sku: item.product?.sku,
                            available_stock: 0,
                            quantity: item.quantity,
                            unit_price: parseFloat(item.unit_price),
                            discount: parseFloat(item.discount),
                            tax: parseFloat(item.tax),
                            subtotal: parseFloat(item.subtotal),
                        });
                    }
                }
            }

            document.addEventListener('click', handleClickOutside);

        } catch (error) {
            console.error('Error loading order:', error);
            alert('Failed to load order');
            router.push('/sales-orders');
        } finally {
            initialLoading.value = false;
        }
    };

    // Search customers
    let customerSearchTimeout;
    const searchCustomers = () => {
        clearTimeout(customerSearchTimeout);
        customerSearchTimeout = setTimeout(async () => {
            if (customerSearch.value.length < 2) {
                customerResults.value = [];
                return;
            }

            try {
                const response = await customerService.getAll({ search: customerSearch.value, per_page: 10 });
                customerResults.value = response.data.data || response.data;
                showCustomerDropdown.value = true;
            } catch (error) {
                console.error('Customer search error:', error);
            }
        }, 300);
    };

    const selectCustomer = (customer) => {
        selectedCustomer.value = customer;
        form.value.customer_id = customer.id;
        customerSearch.value = customer.company_name;
        showCustomerDropdown.value = false;
        customerResults.value = [];
        errors.value.customer_id = '';
    };

    const clearCustomer = () => {
        selectedCustomer.value = null;
        form.value.customer_id = '';
        customerSearch.value = '';
    };

    // Search products with warehouse stock
    let productSearchTimeout;
    const searchProducts = () => {
        clearTimeout(productSearchTimeout);
        productSearchTimeout = setTimeout(async () => {
            if (productSearch.value.length < 2) {
                productResults.value = [];
                return;
            }

            if (!form.value.warehouse_id) {
                alert('Please select a warehouse first');
                return;
            }

            try {
                await productStore.fetchProducts({ search: productSearch.value, per_page: 10 });
                
                // Get stock for each product
                const productsWithStock = await Promise.all(
                    productStore.products.map(async (product) => {
                        try {
                            const inventoryResponse = await inventoryService.getAll({
                                product_id: product.id,
                                warehouse_id: form.value.warehouse_id,
                            });
                            
                            const inventory = inventoryResponse.data.data?.[0] || inventoryResponse.data[0];
                            
                            return {
                                ...product,
                                available_stock: inventory?.quantity_available || 0,
                            };
                        } catch (error) {
                            return {
                                ...product,
                                available_stock: 0,
                            };
                        }
                    })
                );
                
                productResults.value = productsWithStock;
                showProductDropdown.value = true;
            } catch (error) {
                console.error('Product search error:', error);
            }
        }, 300);
    };

    const selectProduct = (product) => {
        selectedProduct.value = product;
        newItem.value = {
            quantity: 1,
            unit_price: parseFloat(product.price),
            discount: 0,
        };
        productSearch.value = product.name;
        showProductDropdown.value = false;
        productResults.value = [];
    };

    const addItemToOrder = () => {
        newItemErrors.value = {};

        if (!selectedProduct.value) {
            return;
        }

        if (!newItem.value.quantity || newItem.value.quantity <= 0) {
            newItemErrors.value.quantity = 'Quantity is required';
            return;
        }

        if (newItem.value.quantity > selectedProduct.value.available_stock) {
            newItemErrors.value.quantity = `Only ${selectedProduct.value.available_stock} units available`;
            return;
        }

        if (!newItem.value.unit_price || newItem.value.unit_price <= 0) {
            newItemErrors.value.unit_price = 'Unit price is required';
            return;
        }

        // Check if product already in order
        const existingIndex = form.value.items.findIndex(item => item.product_id === selectedProduct.value.id);
        
        if (existingIndex !== -1) {
            // Update existing item
            form.value.items[existingIndex].quantity += newItem.value.quantity;
            calculateItemTotal(form.value.items[existingIndex]);
        } else {
            // Add new item
            const item = {
                product_id: selectedProduct.value.id,
                product_name: selectedProduct.value.name,
                sku: selectedProduct.value.sku,
                available_stock: selectedProduct.value.available_stock,
                quantity: newItem.value.quantity,
                unit_price: newItem.value.unit_price,
                discount: newItem.value.discount || 0,
                tax: 0,
                subtotal: 0,
            };
            
            calculateItemTotal(item);
            form.value.items.push(item);
        }

        calculateTotals();
        closeAddItemModal();
    };

    const removeItem = (index) => {
        form.value.items.splice(index, 1);
        calculateTotals();
    };

    const calculateItemTotal = (item) => {
        const subtotal = (item.quantity * item.unit_price) - (item.discount || 0);
        item.subtotal = Math.max(0, subtotal);
    };

    const calculateTotals = () => {
        form.value.subtotal = orderSubtotal.value;
        form.value.tax = (form.value.subtotal * (form.value.tax_rate || 0)) / 100;
        form.value.total = form.value.subtotal + form.value.tax + (form.value.shipping || 0) - (form.value.discount || 0);
    };

    const closeAddItemModal = () => {
        showAddItemModal.value = false;
        selectedProduct.value = null;
        productSearch.value = '';
        newItem.value = {
            quantity: 1,
            unit_price: 0,
            discount: 0,
        };
        newItemErrors.value = {};
    };

    const validateForm = () => {
        errors.value = {};
        let isValid = true;

        if (!form.value.customer_id) {
            errors.value.customer_id = 'Customer is required';
            isValid = false;
        }

        if (!form.value.warehouse_id) {
            errors.value.warehouse_id = 'Warehouse is required';
            isValid = false;
        }

        if (!form.value.order_date) {
            errors.value.order_date = 'Order date is required';
            isValid = false;
        }

        if (form.value.items.length === 0) {
            alert('Please add at least one item to the order');
            isValid = false;
        }

        return isValid;
    };

    const handleSubmit = async () => {
        if (!validateForm()) {
            return;
        }

        try {
            calculateTotals();
            
            const orderData = {
                ...form.value,
                expected_date: form.value.expected_date || null,
                notes: form.value.notes || null,
                items: form.value.items.map(item => ({
                    product_id: item.product_id,
                    quantity: item.quantity,
                    unit_price: item.unit_price,
                    discount: item.discount,
                    tax: item.tax,
                    subtotal: item.subtotal,
                })),
            };

            console.log('Updating order:', orderData);

            await orderStore.updateSalesOrder(route.params.id, orderData);
            router.push(`/sales-orders/${route.params.id}`);
        } catch (error) {
            console.error('Update error:', error);
            if (error.response?.data?.errors) {
                errors.value = error.response.data.errors;
            }
            if (error.response?.data?.message) {
                alert(error.response.data.message);
            }
        }
    };

    const formatNumber = (num) => {
        return parseFloat(num || 0).toFixed(2);
    };

    const getStockClass = (stock) => {
        if (stock <= 0) return 'text-red-600 font-semibold';
        if (stock <= 10) return 'text-yellow-600 font-semibold';
        return 'text-green-600';
    };

    // Click outside to close dropdowns
    const handleClickOutside = (event) => {
        if (!event.target.closest('.search-select')) {
            showCustomerDropdown.value = false;
            showProductDropdown.value = false;
        }
    };
</script>


<style scoped>
/* Use the same styles as Create.vue */
.edit-sales-order-page {
    @apply space-y-6;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-20;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.page-header {
    @apply flex items-center justify-between;
}

.back-btn {
    @apply p-2 rounded-lg hover:bg-gray-100 transition-colors;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.order-form {
    @apply space-y-6;
}

.form-grid {
    @apply grid grid-cols-1 lg:grid-cols-3 gap-6;
}

.form-section {
    @apply bg-white rounded-lg shadow p-6;
}

.form-section.full-width {
    @apply lg:col-span-3;
}

.section-title {
    @apply text-lg font-semibold text-gray-900 mb-4 pb-3 border-b border-gray-200;
}

.section-header {
    @apply flex items-center justify-between mb-4 pb-3 border-b border-gray-200;
}

.section-content {
    @apply space-y-4;
}

.search-select {
    @apply relative;
}

.form-input {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.input-error {
    @apply border-red-500 focus:ring-red-500;
}

.search-dropdown {
    @apply absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto;
}

.dropdown-item {
    @apply px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0;
}

.dropdown-item-main {
    @apply flex items-center justify-between mb-1;
}

.selected-item {
    @apply mt-2 p-4 bg-indigo-50 border border-indigo-200 rounded-lg;
}

.item-details {
    @apply flex items-start justify-between;
}

.item-name {
    @apply font-semibold text-gray-900;
}

.item-code {
    @apply text-sm text-gray-600;
}

.btn-clear {
    @apply p-1 text-gray-400 hover:text-red-600 transition-colors;
}

.items-table {
    @apply overflow-x-auto;
}

.table {
    @apply min-w-full divide-y divide-gray-200;
}

.table thead {
    @apply bg-gray-50;
}

.table th {
    @apply px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider;
}

.table td {
    @apply px-4 py-4 whitespace-nowrap text-sm;
}

.form-input-sm {
    @apply w-24 px-2 py-1 border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.btn-icon-danger {
    @apply p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors;
}

.empty-items {
    @apply text-center py-12;
}

.summary-row {
    @apply flex items-center justify-between py-2 border-b border-gray-200;
}

.summary-row.total {
    @apply border-t-2 border-gray-300 pt-4 mt-4;
}

.form-textarea {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none;
}

.form-actions {
    @apply flex items-center justify-end gap-3 bg-white rounded-lg shadow p-6;
}

.btn {
    @apply flex items-center gap-2 px-6 py-2.5 rounded-lg font-medium transition-all;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 no-underline;
}

.btn-sm {
    @apply px-3 py-1.5 text-sm;
}

.spinner-small {
    @apply w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin;
}

.error-message {
    @apply text-sm text-red-600 mt-1;
}

.form-group {
    @apply space-y-2;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}

.add-item-form {
    @apply space-y-4;
}

.selected-product-details {
    @apply p-4 bg-gray-50 rounded-lg;
}

.product-info {
    @apply space-y-1;
}
</style>