import api from './api';

const exportImportService = {
    // Export methods
    exportProducts(params = {}) {
        return api.get('/export/products', {
            params,
            responseType: 'blob'
        });
    },

    exportCustomers(params = {}) {
        return api.get('/export/customers', {
            params,
            responseType: 'blob'
        });
    },

    exportSuppliers(params = {}) {
        return api.get('/export/suppliers', {
            params,
            responseType: 'blob'
        });
    },

    exportSalesOrders(params = {}) {
        return api.get('/export/sales-orders', {
            params,
            responseType: 'blob'
        });
    },

    exportPurchaseOrders(params = {}) {
        return api.get('/export/purchase-orders', {
            params,
            responseType: 'blob'
        });
    },

    exportInventory(params = {}) {
        return api.get('/export/inventory', {
            params,
            responseType: 'blob'
        });
    },

    // Import methods
    importProducts(file) {
        const formData = new FormData();
        formData.append('file', file);

        return api.post('/import/products', formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });
    },

    importCustomers(file) {
        const formData = new FormData();
        formData.append('file', file);

        return api.post('/import/customers', formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });
    },

    // Download template
    downloadTemplate(type) {
        return api.get(`/import/template/${type}`, {
            responseType: 'blob'
        });
    },

    // Helper to download blob as file
    downloadFile(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    }
};

export default exportImportService;