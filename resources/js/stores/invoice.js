import { defineStore } from 'pinia';
import { invoiceService } from '../services/invoiceService';

export const useInvoiceStore = defineStore('invoice', {
  state: () => ({
    invoices: [],
    statistics: null,
    pending: [],
    overdue: [],
    loading: false,
    error: null,
  }),

  getters: {
    totalPaid: (state) => {
      return state.invoices
        .filter(inv => inv.status === 'paid')
        .reduce((sum, inv) => sum + (inv.amount || 0), 0);
    },

    totalPending: (state) => {
      return state.invoices
        .filter(inv => inv.status === 'pending')
        .reduce((sum, inv) => sum + (inv.amount || 0), 0);
    },

    filteredInvoices: (state) => (status) => {
      if (status === 'all') return state.invoices;
      return state.invoices.filter(inv => inv.status === status);
    },
  },

  actions: {
    async fetchInvoices(status = null) {
      this.loading = true;
      this.error = null;
      try {
        const response = await invoiceService.getInvoices(status);
        if (response.success) {
          this.invoices = response.data;
        } else {
          this.error = response.message || 'Failed to fetch invoices';
        }
      } catch (err) {
        this.error = err.response?.data?.message || err.message || 'Failed to fetch invoices';
        console.error('Invoice fetch error:', err);
      } finally {
        this.loading = false;
      }
    },

    async fetchStatistics(startDate = null, endDate = null) {
      try {
        const response = await invoiceService.getStatistics(startDate, endDate);
        if (response.success) {
          this.statistics = response.data;
        }
      } catch (err) {
        console.error('Statistics fetch error:', err);
      }
    },

    async fetchPending() {
      try {
        const response = await invoiceService.getPending();
        if (response.success) {
          this.pending = response.data;
        }
      } catch (err) {
        console.error('Pending invoices fetch error:', err);
      }
    },

    async fetchOverdue() {
      try {
        const response = await invoiceService.getOverdue();
        if (response.success) {
          this.overdue = response.data;
        }
      } catch (err) {
        console.error('Overdue invoices fetch error:', err);
      }
    },

    clearError() {
      this.error = null;
    },
  },
});