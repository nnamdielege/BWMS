import axios from 'axios';

const API_BASE_URL = '/api/v1';

const subscriptionService = {
    // Get available plans for upgrade/downgrade
    getAvailablePlans() {
        return axios.get(`${API_BASE_URL}/subscription/plans/available`);
    },

    // Calculate proration for plan change
    calculateProration(planId) {
        return axios.post(`${API_BASE_URL}/subscription/plans/calculate`, {
            plan_id: planId,
        });
    },

    // Execute plan change
    changePlan(planId) {
        return axios.post(`${API_BASE_URL}/subscription/plans/change`, {
            plan_id: planId,
        });
    },

    // Get current subscription
    getSubscription() {
        return axios.get(`${API_BASE_URL}/subscription/current`)
            .catch(error => {
                if (error.response?.status === 404) {
                    return {
                        data: {
                            success: false,
                            data: null,
                            message: 'No active subscription'
                        }
                    };
                }
                throw error;
            });
    },

    // Create Stripe checkout (updated)
    createStripeCheckout(planId) {
        return axios.post(`${API_BASE_URL}/subscription/stripe/checkout`, {
            plan_id: planId,
        });
    },

    // Cancel subscription
    cancelSubscription(data) {
        return axios.post(`${API_BASE_URL}/subscription/cancel`, data);
    },

    // Reactivate subscription
    reactivateSubscription() {
        return axios.post(`${API_BASE_URL}/subscription/reactivate`);
    },

    // Get invoices
    getInvoices() {
        return axios.get(`${API_BASE_URL}/subscription/invoices`);
    },

    // Get usage
    getUsage() {
        return axios.get(`${API_BASE_URL}/subscription/usage`);
    },
};

export default subscriptionService;