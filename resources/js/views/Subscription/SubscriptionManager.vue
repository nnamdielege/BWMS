<template>
  <div class="subscription-manager">
    <!-- Header -->
    <div class="page-header">
      <h1>Subscription & Billing</h1>
      <p>Manage your subscription and view billing history</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="loading-container">
      <div class="spinner"></div>
      <p>Loading subscription details...</p>
    </div>

    <!-- No Subscription -->
    <div v-else-if="!subscription" class="no-subscription">
      <div class="empty-state">
        <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
        </svg>
        <h2>No Active Subscription</h2>
        <p>Start your free trial to access all features</p>
        <router-link to="/pricing" class="btn btn-primary">View Plans</router-link>
      </div>
    </div>

    <!-- Subscription Details -->
    <div v-else class="subscription-content">
      <!-- Current Plan Card -->
      <div class="plan-card">
        <div class="plan-header">
          <h2>{{ subscription.plan.name }} Plan</h2>
          <span class="status-badge" :class="'status-' + subscription.status">
            {{ subscription.status.charAt(0).toUpperCase() + subscription.status.slice(1) }}
          </span>
        </div>

        <div class="plan-details">
          <!-- Trial Info -->
          <div v-if="subscription.is_in_trial" class="trial-banner">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
              <p class="font-semibold">Free Trial Active</p>
              <p>{{ subscription.trial_days_remaining }} days remaining</p>
            </div>
          </div>

          <!-- Active Subscription Info -->
          <div v-else class="plan-info">
            <div class="info-row">
              <span class="label">Monthly Price</span>
              <span class="value">${{ subscription.plan.price_monthly }}</span>
            </div>
            <div class="info-row">
              <span class="label">Current Period</span>
              <span class="value">{{ formatDate(subscription.current_period_start) }} - {{ formatDate(subscription.current_period_end) }}</span>
            </div>
          </div>

          <!-- Plan Limits -->
          <div class="plan-limits">
            <div class="limit-item">
              <span>{{ subscription.plan.max_users }} Users</span>
            </div>
            <div class="limit-item">
              <span>{{ subscription.plan.max_locations }} Locations</span>
            </div>
            <div class="limit-item">
              <span>{{ subscription.plan.max_products.toLocaleString() }} Products</span>
            </div>
            <div class="limit-item">
              <span>{{ subscription.plan.data_limit_gb }} GB/month</span>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="action-buttons">
            <router-link to="/pricing" class="btn btn-secondary">Upgrade Plan</router-link>
            <button @click="showCancelModal = true" class="btn btn-outline">Cancel Subscription</button>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <button
          v-for="tab in tabs"
          :key="tab"
          @click="activeTab = tab"
          class="tab-btn"
          :class="{ 'tab-active': activeTab === tab }"
        >
          {{ tab }}
        </button>
      </div>

      <!-- Usage Tab -->
      <div v-if="activeTab === 'Usage'" class="tab-content">
        <div class="usage-card">
          <h3>Data Usage</h3>
          <div class="usage-meter">
            <div class="meter-header">
              <span>{{ usage.data_used_gb }} GB / {{ usage.data_limit_gb }} GB</span>
              <span class="percentage">{{ usage.data_percentage }}%</span>
            </div>
            <div class="meter-bar">
              <div class="meter-fill" :style="{ width: usage.data_percentage + '%' }"></div>
            </div>
          </div>

          <div class="usage-stats">
            <div class="stat">
              <p class="stat-label">API Calls This Month</p>
              <p class="stat-value">{{ usage.api_calls.toLocaleString() }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Invoices Tab -->
      <div v-if="activeTab === 'Invoices'" class="tab-content">
        <div v-if="invoices.length === 0" class="empty-state-small">
          <p>No invoices yet</p>
        </div>
        <div v-else class="invoices-table">
          <div class="table-header">
            <div class="col-invoice">Invoice</div>
            <div class="col-date">Date</div>
            <div class="col-amount">Amount</div>
            <div class="col-status">Status</div>
            <div class="col-action">Action</div>
          </div>
          <div v-for="invoice in invoices" :key="invoice.id" class="table-row">
            <div class="col-invoice">{{ invoice.invoice_number }}</div>
            <div class="col-date">{{ formatDate(invoice.issued_at) }}</div>
            <div class="col-amount">${{ invoice.amount }}</div>
            <div class="col-status">
              <span class="badge" :class="'badge-' + invoice.status">
                {{ invoice.status.charAt(0).toUpperCase() + invoice.status.slice(1) }}
              </span>
            </div>
            <div class="col-action">
              <button class="link-btn">Download PDF</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment Method Tab -->
      <div v-if="activeTab === 'Payment'" class="tab-content">
        <div class="payment-card">
          <h3>Payment Method</h3>
          <p class="text-gray-600 mb-4">Stripe</p>
          <button class="btn btn-secondary">Update Payment Method</button>
        </div>
      </div>
    </div>

    <!-- Cancel Subscription Modal -->
    <div v-if="showCancelModal" class="modal-overlay" @click="showCancelModal = false">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h2>Cancel Subscription?</h2>
          <button @click="showCancelModal = false" class="modal-close">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <p class="modal-message">
          You'll lose access to all features at the end of your current billing period. Your data will be retained for 30 days.
        </p>

        <div class="modal-actions">
          <button @click="showCancelModal = false" class="btn btn-secondary">Keep Subscription</button>
          <button @click="cancelSubscription" :disabled="cancelLoading" class="btn btn-danger">
            <span v-if="cancelLoading" class="spinner-small"></span>
            Cancel Subscription
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const subscription = ref(null);
const usage = ref(null);
const invoices = ref([]);
const loading = ref(false);
const cancelLoading = ref(false);
const showCancelModal = ref(false);
const activeTab = ref('Usage');
const tabs = ['Usage', 'Invoices', 'Payment'];

onMounted(async () => {
  await fetchSubscription();
  await fetchUsage();
  await fetchInvoices();
});

const fetchSubscription = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/subscription/current');
    subscription.value = response.data.data;
  } catch (error) {
    console.error('Error fetching subscription:', error);
  } finally {
    loading.value = false;
  }
};

const fetchUsage = async () => {
  try {
    const response = await axios.get('/api/subscription/usage');
    usage.value = response.data.data;
  } catch (error) {
    console.error('Error fetching usage:', error);
  }
};

const fetchInvoices = async () => {
  try {
    const response = await axios.get('/api/subscription/invoices');
    invoices.value = response.data.data;
  } catch (error) {
    console.error('Error fetching invoices:', error);
  }
};

const cancelSubscription = async () => {
  cancelLoading.value = true;
  try {
    const response = await axios.post('/api/subscription/cancel');
    if (response.data.success) {
      showCancelModal.value = false;
      await fetchSubscription();
      alert('Subscription cancelled successfully');
    }
  } catch (error) {
    console.error('Error cancelling subscription:', error);
    alert(error.response?.data?.message || 'Failed to cancel subscription');
  } finally {
    cancelLoading.value = false;
  }
};

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-AU', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  });
};
</script>

<style scoped>
.subscription-manager {
  @apply max-w-4xl mx-auto px-4 py-8;
}

.page-header {
  @apply mb-8;
}

.page-header h1 {
  @apply text-3xl font-bold text-gray-900 mb-2;
}

.page-header p {
  @apply text-gray-600;
}

.loading-container {
  @apply flex flex-col items-center justify-center py-12;
}

.spinner {
  @apply w-12 h-12 border-4 border-gray-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.no-subscription {
  @apply py-12;
}

.empty-state {
  @apply text-center;
}

.empty-state svg {
  @apply mx-auto text-gray-400 mb-4;
}

.empty-state h2 {
  @apply text-2xl font-bold text-gray-900 mb-2;
}

.empty-state p {
  @apply text-gray-600 mb-6;
}

.plan-card {
  @apply bg-white rounded-lg shadow mb-8 p-6;
}

.plan-header {
  @apply flex items-center justify-between mb-6;
}

.plan-header h2 {
  @apply text-2xl font-bold text-gray-900;
}

.status-badge {
  @apply px-3 py-1 rounded-full text-sm font-semibold;
}

.status-active {
  @apply bg-green-100 text-green-800;
}

.status-trial {
  @apply bg-blue-100 text-blue-800;
}

.status-cancelled {
  @apply bg-gray-100 text-gray-800;
}

.plan-details {
  @apply space-y-6;
}

.trial-banner {
  @apply flex items-center gap-4 bg-blue-50 p-4 rounded-lg border border-blue-200;
}

.trial-banner svg {
  @apply text-blue-600 flex-shrink-0;
}

.plan-info {
  @apply space-y-4;
}

.info-row {
  @apply flex justify-between;
}

.info-row .label {
  @apply text-gray-600;
}

.info-row .value {
  @apply font-semibold text-gray-900;
}

.plan-limits {
  @apply grid grid-cols-2 md:grid-cols-4 gap-4 py-4 border-t border-b border-gray-200;
}

.limit-item {
  @apply text-center;
}

.limit-item span {
  @apply text-sm text-gray-600;
}

.action-buttons {
  @apply flex gap-4 pt-4;
}

.btn {
  @apply px-4 py-2 rounded-lg font-medium transition-all;
}

.btn-primary {
  @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
  @apply bg-gray-200 text-gray-900 hover:bg-gray-300;
}

.btn-outline {
  @apply border-2 border-red-600 text-red-600 hover:bg-red-50;
}

.btn-danger {
  @apply bg-red-600 text-white hover:bg-red-700;
}

.btn:disabled {
  @apply opacity-50 cursor-not-allowed;
}

.tabs {
  @apply flex gap-4 border-b border-gray-200 mb-6;
}

.tab-btn {
  @apply px-4 py-2 font-medium text-gray-600 border-b-2 border-transparent hover:text-gray-900 transition-colors;
}

.tab-btn.tab-active {
  @apply text-indigo-600 border-indigo-600;
}

.tab-content {
  @apply bg-white rounded-lg shadow p-6;
}

.usage-card {
  @apply space-y-6;
}

.usage-card h3 {
  @apply text-lg font-semibold text-gray-900;
}

.usage-meter {
  @apply space-y-2;
}

.meter-header {
  @apply flex justify-between text-sm;
}

.meter-bar {
  @apply h-3 bg-gray-200 rounded-full overflow-hidden;
}

.meter-fill {
  @apply h-full bg-indigo-600 rounded-full transition-all;
}

.percentage {
  @apply font-semibold text-gray-900;
}

.usage-stats {
  @apply grid grid-cols-2 gap-4;
}

.stat {
  @apply bg-gray-50 p-4 rounded-lg;
}

.stat-label {
  @apply text-sm text-gray-600 mb-1;
}

.stat-value {
  @apply text-2xl font-bold text-gray-900;
}

.empty-state-small {
  @apply text-center py-8 text-gray-600;
}

.invoices-table {
  @apply divide-y divide-gray-200;
}

.table-header {
  @apply grid grid-cols-5 gap-4 p-4 bg-gray-50 font-semibold text-sm;
}

.table-row {
  @apply grid grid-cols-5 gap-4 p-4 items-center hover:bg-gray-50;
}

.col-invoice,
.col-date,
.col-amount,
.col-status,
.col-action {
  @apply truncate;
}

.badge {
  @apply inline-block px-2 py-1 rounded text-xs font-semibold;
}

.badge-paid {
  @apply bg-green-100 text-green-800;
}

.badge-pending {
  @apply bg-yellow-100 text-yellow-800;
}

.link-btn {
  @apply text-indigo-600 hover:text-indigo-700 font-medium text-sm;
}

.payment-card {
  @apply space-y-4;
}

.payment-card h3 {
  @apply text-lg font-semibold text-gray-900;
}

/* Modal */
.modal-overlay {
  @apply fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50;
}

.modal-content {
  @apply bg-white rounded-lg shadow-xl max-w-md p-6;
}

.modal-header {
  @apply flex items-center justify-between mb-4;
}

.modal-header h2 {
  @apply text-xl font-bold text-gray-900;
}

.modal-close {
  @apply p-1 hover:bg-gray-100 rounded;
}

.modal-message {
  @apply text-gray-600 mb-6;
}

.modal-actions {
  @apply flex gap-4;
}

.spinner-small {
  @apply inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin mr-2;
}
</style>