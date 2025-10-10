<template>
    <div class="settings-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Settings</h1>
                <p class="page-subtitle">Manage your system configuration</p>
            </div>
        </div>

        <!-- Settings Tabs -->
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

        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading settings...</p>
        </div>

        <!-- Settings Content -->
        <div v-else-if="settings" class="settings-content">
            <!-- General Settings -->
            <div v-if="activeTab === 'general'" class="settings-section">
                <div class="section-card">
                    <h3 class="section-title">Company Information</h3>
                    <form @submit.prevent="saveSettings" class="settings-form">
                        <div class="form-grid">
                            <div class="form-group col-span-2">
                                <label class="form-label">Company Name</label>
                                <input
                                    v-model="formData.general.company_name"
                                    type="text"
                                    class="form-input"
                                    placeholder="Your Company Name"
                                />
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input
                                    v-model="formData.general.company_email"
                                    type="email"
                                    class="form-input"
                                    placeholder="contact@company.com"
                                />
                            </div>

                            <div class="form-group">
                                <label class="form-label">Phone Number</label>
                                <input
                                    v-model="formData.general.company_phone"
                                    type="text"
                                    class="form-input"
                                    placeholder="+1-555-0100"
                                />
                            </div>

                            <div class="form-group col-span-2">
                                <label class="form-label">Address</label>
                                <input
                                    v-model="formData.general.company_address"
                                    type="text"
                                    class="form-input"
                                    placeholder="123 Business Street"
                                />
                            </div>

                            <div class="form-group">
                                <label class="form-label">Currency</label>
                                <select v-model="formData.general.currency" class="form-select">
                                    <option value="USD">USD - US Dollar</option>
                                    <option value="EUR">EUR - Euro</option>
                                    <option value="GBP">GBP - British Pound</option>
                                    <option value="CAD">CAD - Canadian Dollar</option>
                                    <option value="AUD">AUD - Australian Dollar</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Timezone</label>
                                <select v-model="formData.general.timezone" class="form-select">
                                    <option value="America/New_York">Eastern Time</option>
                                    <option value="America/Chicago">Central Time</option>
                                    <option value="America/Denver">Mountain Time</option>
                                    <option value="America/Los_Angeles">Pacific Time</option>
                                    <option value="UTC">UTC</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Inventory Settings -->
            <div v-if="activeTab === 'inventory'" class="settings-section">
                <div class="section-card">
                    <h3 class="section-title">Inventory Configuration</h3>
                    <form @submit.prevent="saveSettings" class="settings-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Low Stock Threshold</label>
                                <input
                                    v-model.number="formData.inventory.low_stock_threshold"
                                    type="number"
                                    class="form-input"
                                    min="0"
                                />
                                <p class="form-help">Alert when stock falls below this quantity</p>
                            </div>

                            <div class="form-group col-span-2">
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="formData.inventory.enable_negative_stock"
                                        type="checkbox"
                                        class="form-checkbox"
                                    />
                                    <span class="ml-2">Enable Negative Stock</span>
                                </label>
                                <p class="form-help mt-2">
                                    Allow stock levels to go below zero
                                </p>
                            </div>

                            <div class="form-group col-span-2">
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="formData.inventory.auto_allocate_stock"
                                        type="checkbox"
                                        class="form-checkbox"
                                    />
                                    <span class="ml-2">Auto-Allocate Stock</span>
                                </label>
                                <p class="form-help mt-2">
                                    Automatically allocate stock when orders are created
                                </p>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Order Settings -->
            <div v-if="activeTab === 'orders'" class="settings-section">
                <div class="section-card">
                    <h3 class="section-title">Order Configuration</h3>
                    <form @submit.prevent="saveSettings" class="settings-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Sales Order Prefix</label>
                                <input
                                    v-model="formData.orders.order_prefix_sales"
                                    type="text"
                                    class="form-input"
                                    placeholder="SO-"
                                />
                                <p class="form-help">Prefix for sales order numbers (e.g., SO-0001)</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Purchase Order Prefix</label>
                                <input
                                    v-model="formData.orders.order_prefix_purchase"
                                    type="text"
                                    class="form-input"
                                    placeholder="PO-"
                                />
                                <p class="form-help">Prefix for purchase order numbers (e.g., PO-0001)</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Default Payment Terms</label>
                                <select v-model="formData.orders.default_payment_terms" class="form-select">
                                    <option value="Net 15">Net 15</option>
                                    <option value="Net 30">Net 30</option>
                                    <option value="Net 45">Net 45</option>
                                    <option value="Net 60">Net 60</option>
                                    <option value="Due on Receipt">Due on Receipt</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Default Tax Rate (%)</label>
                                <input
                                    v-model.number="formData.orders.default_tax_rate"
                                    type="number"
                                    step="0.01"
                                    class="form-input"
                                    min="0"
                                    max="100"
                                />
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Notification Settings -->
            <div v-if="activeTab === 'notifications'" class="settings-section">
                <div class="section-card">
                    <h3 class="section-title">Notification Preferences</h3>
                    <form @submit.prevent="saveSettings" class="settings-form">
                        <div class="form-grid">
                            <div class="form-group col-span-2">
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="formData.notifications.email_notifications"
                                        type="checkbox"
                                        class="form-checkbox"
                                    />
                                    <span class="ml-2 font-medium">Enable Email Notifications</span>
                                </label>
                                <p class="form-help mt-2">
                                    Send email notifications for important events
                                </p>
                            </div>

                            <div class="form-group col-span-2">
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="formData.notifications.low_stock_notifications"
                                        type="checkbox"
                                        class="form-checkbox"
                                    />
                                    <span class="ml-2 font-medium">Low Stock Alerts</span>
                                </label>
                                <p class="form-help mt-2">
                                    Receive notifications when stock falls below threshold
                                </p>
                            </div>

                            <div class="form-group col-span-2">
                                <label class="flex items-center cursor-pointer">
                                    <input
                                        v-model="formData.notifications.order_notifications"
                                        type="checkbox"
                                        class="form-checkbox"
                                    />
                                    <span class="ml-2 font-medium">Order Notifications</span>
                                </label>
                                <p class="form-help mt-2">
                                    Receive notifications for new orders and status changes
                                </p>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Success Message -->
        <div v-if="showSuccess" class="success-toast">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <span>Settings saved successfully!</span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch, h } from 'vue';
import { useSettingStore } from '../../stores/setting';

const settingStore = useSettingStore();

// Icon components
const CogIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' }),
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z' })
]);

const CubeIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' })
]);

const ClipboardIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2' })
]);

const BellIcon = () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9' })
]);

const tabs = ref([
    { id: 'general', name: 'General', icon: CogIcon },
    { id: 'inventory', name: 'Inventory', icon: CubeIcon },
    { id: 'orders', name: 'Orders', icon: ClipboardIcon },
    { id: 'notifications', name: 'Notifications', icon: BellIcon },
]);

const activeTab = ref('general');
const loading = ref(true);
const saving = ref(false);
const showSuccess = ref(false);
const settings = ref(null);

const formData = reactive({
    general: {
        company_name: '',
        company_email: '',
        company_phone: '',
        company_address: '',
        currency: 'USD',
        timezone: 'America/New_York',
    },
    inventory: {
        low_stock_threshold: 20,
        enable_negative_stock: false,
        auto_allocate_stock: true,
    },
    orders: {
        order_prefix_sales: 'SO-',
        order_prefix_purchase: 'PO-',
        default_payment_terms: 'Net 30',
        default_tax_rate: 10,
    },
    notifications: {
        email_notifications: true,
        low_stock_notifications: true,
        order_notifications: true,
    },
});

onMounted(async () => {
    await loadSettings();
});

const loadSettings = async () => {
    loading.value = true;

    try {
        await settingStore.fetchSettings();
        settings.value = settingStore.settings;

        // Populate form data
        Object.keys(formData).forEach(group => {
            if (settings.value[group]) {
                settings.value[group].forEach(setting => {
                    if (formData[group].hasOwnProperty(setting.key)) {
                        formData[group][setting.key] = setting.value;
                    }
                });
            }
        });

        console.log('Settings loaded:', settings.value);
        console.log('Form data:', formData);
    } catch (error) {
        console.error('Error loading settings:', error);
        alert('Failed to load settings');
    } finally {
        loading.value = false;
    }
};

const saveSettings = async () => {
    saving.value = true;

    try {
        const settingsToUpdate = [];

        // Prepare settings array for current tab
        Object.keys(formData[activeTab.value]).forEach(key => {
            settingsToUpdate.push({
                key: key,
                value: formData[activeTab.value][key],
            });
        });

        await settingStore.updateSettings({ settings: settingsToUpdate });

        // Show success message
        showSuccess.value = true;
        setTimeout(() => {
            showSuccess.value = false;
        }, 3000);

    } catch (error) {
        console.error('Error saving settings:', error);
        alert('Failed to save settings');
    } finally {
        saving.value = false;
    }
};
</script>

<style scoped>
.settings-page {
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

.loading-container {
    @apply flex flex-col items-center justify-center py-20 bg-white rounded-lg shadow;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.settings-content {
    @apply space-y-6;
}

.settings-section {
    @apply space-y-6;
}

.section-card {
    @apply bg-white rounded-lg shadow p-6;
}

.section-title {
    @apply text-lg font-semibold text-gray-900 mb-6 pb-4 border-b border-gray-200;
}

.settings-form {
    @apply space-y-6;
}

.form-grid {
    @apply grid grid-cols-1 gap-6;
}

@media (min-width: 768px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .form-group.col-span-2 {
        grid-column: span 2;
    }
}

.form-group {
    @apply flex flex-col;
}

.form-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.form-input,
.form-select {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-checkbox {
    @apply w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-2 focus:ring-indigo-500;
}

.form-help {
    @apply text-sm text-gray-500 mt-1;
}

.form-actions {
    @apply flex justify-end pt-6 border-t border-gray-200;
}

.btn {
    @apply flex items-center gap-2 px-6 py-2.5 rounded-lg font-medium transition-colors;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.success-toast {
    @apply fixed bottom-6 right-6 bg-green-50 border border-green-200 text-green-800 px-6 py-4 rounded-lg shadow-lg flex items-center gap-3 animate-slide-up;
}

@keyframes slide-up {
    from {
        transform: translateY(100px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.animate-slide-up {
    animation: slide-up 0.3s ease-out;
}
</style>