<template>
  <div class="billing-history">
    <!-- Header -->
    <div class="header">
      <h1>Billing History</h1>
      <p>View and manage your invoices and payments</p>
    </div>

    <!-- Error Alert -->
    <div v-if="error" class="alert alert-error">
      <p><strong>Error:</strong> {{ error }}</p>
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
      <button
        v-for="tab in filterTabs"
        :key="tab.value"
        @click="filter = tab.value"
        :class="['tab', { active: filter === tab.value }]"
      >
        {{ tab.label }}
        <span v-if="tab.value === 'all' && invoices.length > 0" class="count">
          ({{ invoices.length }})
        </span>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Loading billing history...</p>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredInvoices.length === 0" class="empty-state">
      <p class="empty-title">No invoices yet</p>
      <p class="empty-description">
        {{ filter === 'all'
          ? "You don't have any invoices yet. They will appear here once you make a payment."
          : `No ${filter} invoices found.`
        }}
      </p>
    </div>

    <!-- Invoices Table -->
    <div v-else class="invoices-table">
      <table>
        <thead>
          <tr>
            <th>Invoice Number</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Due Date</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="invoice in filteredInvoices" :key="invoice.id">
            <td class="font-medium">{{ invoice.invoice_number || `INV-${invoice.id}` }}</td>
            <td>{{ formatDate(invoice.issued_at) }}</td>
            <td class="font-medium">{{ formatAmount(invoice.amount) }}</td>
            <td>
              <span :class="['status-badge', `status-${invoice.status}`]">
                {{ getStatusLabel(invoice.status) }}
              </span>
            </td>
            <td>{{ formatDate(invoice.due_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Summary Card -->
    <div v-if="!loading && invoices.length > 0" class="summary-card">
      <h3>Summary</h3>
      <div class="summary-grid">
        <div class="summary-item">
          <p class="label">Total Paid</p>
          <p class="amount paid">{{ formatAmount(getTotalByStatus('paid')) }}</p>
        </div>
        <div class="summary-item">
          <p class="label">Pending</p>
          <p class="amount pending">{{ formatAmount(getTotalByStatus('pending')) }}</p>
        </div>
        <div class="summary-item">
          <p class="label">Total Invoices</p>
          <p class="amount">{{ invoices.length }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { useInvoiceStore } from '../../stores/invoice';

export default {
  name: 'BillingHistory',
  
  data() {
    return {
      filter: 'all',
      filterTabs: [
        { value: 'all', label: 'All Invoices' },
        { value: 'paid', label: 'Paid' },
        { value: 'pending', label: 'Pending' },
      ],
    };
  },

  computed: {
    invoiceStore() {
      return useInvoiceStore();
    },

    invoices() {
      return this.invoiceStore.invoices;
    },

    loading() {
      return this.invoiceStore.loading;
    },

    error() {
      return this.invoiceStore.error;
    },

    filteredInvoices() {
      return this.invoiceStore.filteredInvoices(this.filter);
    },
  },

  async mounted() {
    await this.invoiceStore.fetchInvoices();
  },

  methods: {
    formatDate(dateString) {
      if (!dateString) return 'N/A';
      return new Date(dateString).toLocaleDateString('en-AU', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      });
    },

    formatAmount(amount) {
      return new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency: 'AUD',
      }).format(amount || 0);
    },

    getStatusLabel(status) {
      const labels = {
        paid: 'Paid',
        pending: 'Pending',
        failed: 'Failed',
        draft: 'Draft',
      };
      return labels[status] || 'Unknown';
    },

    getTotalByStatus(status) {
        return this.invoices
            .filter(inv => inv.status === status)
            .reduce((sum, inv) => {
            const amount = parseFloat(inv.amount) || 0;
            return sum + amount;
            }, 0);
    },
  },
};
</script>

<style scoped>
.billing-history {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem;
}

.header {
  margin-bottom: 2rem;
}

.header h1 {
  font-size: 1.875rem;
  font-weight: bold;
  color: #111;
  margin-bottom: 0.5rem;
}

.header p {
  color: #666;
}

.alert {
  margin-bottom: 1.5rem;
  padding: 1rem;
  border-radius: 0.5rem;
}

.alert-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
}

.filter-tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1.5rem;
}

.tab {
  padding: 0.5rem 1rem;
  border-radius: 0.5rem;
  font-weight: 500;
  border: 1px solid #e5e7eb;
  background: white;
  color: #374151;
  cursor: pointer;
}

.tab.active {
  background: #2563eb;
  color: white;
  border-color: #2563eb;
}

.loading-state,
.empty-state {
  background: white;
  border-radius: 0.5rem;
  padding: 3rem;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.spinner {
  width: 2rem;
  height: 2rem;
  border: 2px solid #e5e7eb;
  border-top-color: #2563eb;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.invoices-table {
  background: white;
  border-radius: 0.5rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

table {
  width: 100%;
  border-collapse: collapse;
}

thead {
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
}

th {
  padding: 0.75rem 1.5rem;
  text-align: left;
  font-size: 0.875rem;
  font-weight: 600;
  color: #111;
}

td {
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #e5e7eb;
  font-size: 0.875rem;
}

tbody tr:hover {
  background: #f9fafb;
}

.status-badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 500;
}

.status-paid {
  background: #dcfce7;
  color: #166534;
}

.status-pending {
  background: #fef3c7;
  color: #92400e;
}

.status-failed {
  background: #fee2e2;
  color: #991b1b;
}

.summary-card {
  margin-top: 2rem;
  background: white;
  border-radius: 0.5rem;
  padding: 1.5rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.summary-card h3 {
  font-size: 1.125rem;
  font-weight: 600;
  color: #111;
  margin-bottom: 1rem;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1.5rem;
}

.summary-item .label {
  font-size: 0.875rem;
  color: #666;
  margin-bottom: 0.25rem;
}

.summary-item .amount {
  font-size: 1.5rem;
  font-weight: bold;
  color: #111;
}

.summary-item .amount.paid {
  color: #166534;
}

.summary-item .amount.pending {
  color: #b45309;
}
</style>