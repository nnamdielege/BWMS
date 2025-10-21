import { defineStore } from 'pinia';
import subscriptionService from '../services/subscriptionService';

export const useSubscriptionStore = defineStore('subscription', {
    state: () => ({
        subscription: null,
        availablePlans: [],
        selectedPlan: null,
        prorationData: null,
        loading: false,
        error: null,
        success: null,
    }),

    getters: {
        currentPlan: (state) => {
            return state.subscription?.plan || null;
        },

        isSubscriptionActive: (state) => {
            return state.subscription?.status === 'active';
        },

        isSubscriptionTrialing: (state) => {
            return state.subscription?.status === 'trialing';
        },

        daysUntilRenewal: (state) => {
            if (!state.subscription?.current_period_end) return null;
            const now = new Date();
            const renewalDate = new Date(state.subscription.current_period_end);
            const daysLeft = Math.ceil((renewalDate - now) / (1000 * 60 * 60 * 24));
            return Math.max(0, daysLeft);
        },

        renewalDate: (state) => {
            if (!state.subscription?.current_period_end) return null;
            return new Date(state.subscription.current_period_end).toLocaleDateString();
        },
    },

    actions: {
        async fetchSubscription() {
            this.loading = true;
            this.error = null;

            try {
                const response = await subscriptionService.getSubscription();
                this.subscription = response.data.data;
            } catch (error) {
                console.error('Fetch subscription error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch subscription';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchAvailablePlans() {
            this.loading = true;
            this.error = null;

            try {
                const response = await subscriptionService.getAvailablePlans();
                this.availablePlans = response.data.data.available_plans;
            } catch (error) {
                console.error('Fetch available plans error:', error);
                this.error = error.response?.data?.message || 'Failed to fetch available plans';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        selectPlan(plan) {
            this.selectedPlan = plan;
            this.prorationData = null;
            this.error = null;
        },

        clearSelection() {
            this.selectedPlan = null;
            this.prorationData = null;
        },

        async calculateProration() {
            if (!this.selectedPlan) {
                this.error = 'No plan selected';
                return;
            }

            this.loading = true;
            this.error = null;

            try {
                const response = await subscriptionService.calculateProration(
                    this.selectedPlan.id
                );
                this.prorationData = response.data.data;
            } catch (error) {
                console.error('Calculate proration error:', error);
                this.error = error.response?.data?.message || 'Failed to calculate proration';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async changePlan() {
            if (!this.selectedPlan) {
                this.error = 'No plan selected';
                return;
            }

            this.loading = true;
            this.error = null;
            this.success = null;

            try {
                const response = await subscriptionService.changePlan(this.selectedPlan.id);
                
                // Update subscription with new plan
                this.subscription = response.data.data.subscription;
                this.success = response.data.message;
                this.prorationData = null;
                this.selectedPlan = null;
                
                return response.data.data;
            } catch (error) {
                console.error('Change plan error:', error);
                this.error = error.response?.data?.message || 'Failed to change plan';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async cancelSubscription(data) {
            this.loading = true;
            this.error = null;
            this.success = null;

            try {
                const response = await subscriptionService.cancelSubscription(data);
                this.subscription = response.data.data;
                this.success = response.data.message;
                return response.data.data;
            } catch (error) {
                console.error('Cancel subscription error:', error);
                this.error = error.response?.data?.message || 'Failed to cancel subscription';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async reactivateSubscription() {
            this.loading = true;
            this.error = null;
            this.success = null;

            try {
                const response = await subscriptionService.reactivateSubscription();
                this.subscription = response.data.data;
                this.success = response.data.message;
                return response.data.data;
            } catch (error) {
                console.error('Reactivate subscription error:', error);
                this.error = error.response?.data?.message || 'Failed to reactivate subscription';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        clearMessages() {
            this.error = null;
            this.success = null;
        },
    },
});