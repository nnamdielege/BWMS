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
        return axios.get(`${API_BASE_URL}/subscription`);
    },

    // Cancel subscription
    cancelSubscription(data) {
        return axios.post(`${API_BASE_URL}/subscription/cancel`, data);
    },

    // Reactivate subscription
    reactivateSubscription() {
        return axios.post(`${API_BASE_URL}/subscription/reactivate`);
    },
};

export default subscriptionService;