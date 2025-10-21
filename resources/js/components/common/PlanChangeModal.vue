<template>
  <div class="subscription-manager">
    <div class="container">
      <h1>Subscription Management</h1>

      <!-- Loading State -->
      <div v-if="subscriptionStore.loading && !subscriptionStore.subscription" class="loading">
        <p>Loading subscription details...</p>
      </div>

      <!-- No Subscription -->
      <div v-else-if="!subscriptionStore.subscription" class="no-subscription">
        <p>You don't have an active subscription yet.</p>
        <router-link to="/pricing" class="btn btn-primary">
          View Pricing Plans
        </router-link>
      </div>

      <!-- Active Subscription -->
      <div v-else class="subscription-content">
        <!-- Success Message -->
        <div v-if="subscriptionStore.success" class="alert alert-success">
          {{ subscriptionStore.success }}
        </div>

        <!-- Error Message -->
        <div v-if="subscriptionStore.error" class="alert alert-error">
          {{ subscriptionStore.error }}
        </div>

        <!-- Current Plan Card -->
        <div class="plan-card">
          <div class="plan-header">
            <h2>{{ subscriptionStore.subscription.plan.name }}</h2>
            <span class="status-badge" :class="subscriptionStore.subscription.status">
              {{ subscriptionStore.subscription.status }}
            </span>
          </div>

          <div class="plan-details">
            <div class="detail-row">
              <span>Price:</span>
              <strong>${{ subscriptionStore.subscription.amount }}/month</strong>
            </div>

            <div class="detail-row">
              <span>Status:</span>
              <strong>{{ subscriptionStore.subscription.status }}</strong>
            </div>

            <div class="detail-row">
              <span>Current Period:</span>
              <strong>
                {{ formatDate(subscriptionStore.subscription.current_period_start) }} -
                {{ formatDate(subscriptionStore.subscription.current_period_end) }}
              </strong>
            </div>

            <div class="detail-row">
              <span>Days Until Renewal:</span>
              <strong>{{ subscriptionStore.daysUntilRenewal }} days</strong>
            </div>

            <div class="detail-row">
              <span>Next Billing Date:</span>
              <strong>{{ subscriptionStore.renewalDate }}</strong>
            </div>
          </div>

          <!-- Actions -->
          <div class="plan-actions">
            <button
              class="btn btn-primary"
              @click="showPlanChangeModal = true"
              :disabled="subscriptionStore.loading"
            >
              Change Plan
            </button>

            <button
              class="btn btn-secondary"
              @click="showUsageStats"
            >
              View Usage
            </button>

            <button
              class="btn btn-danger"
              @click="goToCancellation"
            >
              Cancel Subscription
            </button>
          </div>
        </div>

        <!-- Plan Features -->
        <div class="features-section" v-if="subscriptionStore.subscription.plan.features">
          <h3>Plan Features</h3>
          <ul class="features-list">
            <li
              v-for="(feature, idx) in subscriptionStore.subscription.plan.features"
              :key="idx"
            >
              ✓ {{ feature }}
            </li>
          </ul>
        </div>
      </div>

      <!-- Plan Change Modal -->
      <PlanChangeModal
        v-if="showPlanChangeModal"
        @close="showPlanChangeModal = false"
        @plan-changed="onPlanChanged"
      />
    </div>
  </div>
</template>

<script>
import { useSubscriptionStore } from '../../stores/subscription';
import PlanChangeModal from '../../components/common/PlanChangeModal.vue';

export default {
  name: 'SubscriptionManager',

  components: {
    PlanChangeModal,
  },

  data() {
    return {
      showPlanChangeModal: false,
    };
  },

  computed: {
    subscriptionStore() {
      return useSubscriptionStore();
    },
  },

  methods: {
    async loadSubscription() {
      try {
        await this.subscriptionStore.fetchSubscription();
      } catch (error) {
        console.error('Failed to load subscription:', error);
      }
    },

    onPlanChanged(subscription) {
      // Plan successfully changed
      // Modal will close automatically
      // You can refresh or show success message
      console.log('Plan changed to:', subscription);
    },

    showUsageStats() {
      this.$router.push({ name: 'UsageStats' });
    },

    goToCancellation() {
      this.$router.push({ name: 'SubscriptionCancel' });
    },

    formatDate(dateString) {
      return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      });
    },
  },

  async mounted() {
    await this.loadSubscription();
  },
};
</script>

<style scoped>
.subscription-manager {
  padding: 2rem 0;
}

.container {
  max-width: 900px;
  margin: 0 auto;
}

h1 {
  margin-bottom: 2rem;
  color: #333;
  font-size: 2rem;
}

.loading {
  text-align: center;
  padding: 2rem;
  color: #666;
}

.no-subscription {
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 2rem;
  text-align: center;
}

.no-subscription p {
  margin-bottom: 1.5rem;
  color: #666;
}

/* Alerts */
.alert {
  padding: 1rem;
  border-radius: 6px;
  margin-bottom: 1.5rem;
  font-size: 0.95rem;
}

.alert-success {
  background: #d4edda;
  border: 1px solid #c3e6cb;
  color: #155724;
}

.alert-error {
  background: #f8d7da;
  border: 1px solid #f5c6cb;
  color: #721c24;
}

/* Plan Card */
.plan-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 2rem;
  margin-bottom: 2rem;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.plan-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid #e0e0e0;
}

.plan-header h2 {
  margin: 0;
  color: #333;
  font-size: 1.8rem;
}

.status-badge {
  padding: 0.5rem 1rem;
  border-radius: 20px;
  font-size: 0.85rem;
  font-weight: 600;
  text-transform: uppercase;
}

.status-badge.active {
  background: #d4edda;
  color: #155724;
}

.status-badge.trialing {
  background: #cce5ff;
  color: #004085;
}

.status-badge.past_due {
  background: #f8d7da;
  color: #721c24;
}

.status-badge.canceled {
  background: #e2e3e5;
  color: #383d41;
}

.plan-details {
  background: #f9f9f9;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
}

.detail-row {
  display: flex;
  justify-content: space-between;
  padding: 0.8rem 0;
  border-bottom: 1px solid #e0e0e0;
}

.detail-row:last-child {
  border-bottom: none;
}

.detail-row span {
  color: #666;
  font-weight: 500;
}

.detail-row strong {
  color: #333;
  font-weight: 600;
}

/* Actions */
.plan-actions {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.btn {
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 4px;
  font-size: 0.95rem;
  cursor: pointer;
  transition: all 0.3s ease;
  font-weight: 500;
}

.btn-primary {
  background: #0066cc;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #0052a3;
}

.btn-secondary {
  background: #e0e0e0;
  color: #333;
}

.btn-secondary:hover {
  background: #d0d0d0;
}

.btn-danger {
  background: #dc3545;
  color: white;
}

.btn-danger:hover {
  background: #c82333;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Features */
.features-section {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 2rem;
}

.features-section h3 {
  margin-top: 0;
  margin-bottom: 1rem;
  color: #333;
  font-size: 1.1rem;
}

.features-list {
  margin: 0;
  padding-left: 1.5rem;
  list-style: none;
}

.features-list li {
  padding: 0.5rem 0;
  color: #666;
}

.features-list li:before {
  content: "✓ ";
  color: #28a745;
  font-weight: bold;
  margin-right: 0.5rem;
}

/* Responsive */
@media (max-width: 768px) {
  .plan-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }

  .plan-actions {
    flex-direction: column;
  }

  .btn {
    width: 100%;
  }
}
</style>