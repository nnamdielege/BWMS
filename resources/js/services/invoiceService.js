import axios from 'axios';

export const invoiceService = {
  /**
   * Get all invoices
   */
  async getInvoices(status = null) {
    const params = status ? { status } : {};
    const response = await axios.get('/api/v1/subscription/invoices', { params });
    return response.data;
  },

  /**
   * Get invoice statistics
   */
  async getStatistics(startDate = null, endDate = null) {
    const params = {};
    if (startDate) params.start_date = startDate;
    if (endDate) params.end_date = endDate;
    
    const response = await axios.get('/api/v1/invoices/statistics', { params });
    return response.data;
  },

  /**
   * Get pending invoices
   */
  async getPending() {
    const response = await axios.get('/api/v1/invoices/pending');
    return response.data;
  },

  /**
   * Get overdue invoices
   */
  async getOverdue() {
    const response = await axios.get('/api/v1/invoices/overdue');
    return response.data;
  },

  /**
   * Get single invoice
   */
  async getInvoice(id) {
    const response = await axios.get(`/api/v1/invoices/${id}`);
    return response.data;
  },
};