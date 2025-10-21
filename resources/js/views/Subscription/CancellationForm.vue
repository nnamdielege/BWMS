<template>
  <div class="cancellation-page">
    <div class="container">
      <h1>Manage Your Subscription</h1>

      <!-- Loading State -->
      <div v-if="subscriptionStore.loading && !subscriptionStore.subscription" class="loading">
        <p>Loading subscription details...</p>
      </div>

      <!-- No Subscription -->
      <div v-else-if="!subscriptionStore.subscription" class="no-subscription">
        <p>You don't have an active subscription.</p>
        <router-link to="/pricing" class="btn btn-primary">View Plans</router-link>
      </div>

      <!-- Active Subscription -->
      <div v-else class="cancellation-content">
        <!-- Success Message -->
        <div v-if="subscriptionStore.success" class="alert alert-success">
          {{ subscriptionStore.success }}
        </div>

        <!-- Error Message -->
        <div v-if="subscriptionStore.error" class="alert alert-error">
          {{ subscriptionStore.error }}
        </div>

        <!-- Current Subscription Info -->
        <div class="subscription-info">
          <h2>Current Subscription</h2>
          <div class="info-card">
            <div class="info-row">
              <span>Plan:</span>
              <strong>{{ subscriptionStore.subscription.plan.name }}</strong>
            </div>
            <div class="info-row">
                <span>Amount:</span>
                <strong>
                    ${{ parseFloat(subscriptionStore.subscription.plan.price).toFixed(2) }}/{{ subscriptionStore.subscription.plan.interval }}
                </strong>
            </div>
            <div class="info-row">
              <span>Status:</span>
              <strong class="status-active">{{ subscriptionStore.subscription.status }}</strong>
            </div>
            <div class="info-row">
              <span>Renewal Date:</span>
              <strong>{{ subscriptionStore.renewalDate }}</strong>
            </div>
            <div class="info-row">
              <span>Days Until Renewal:</span>
              <strong>{{ subscriptionStore.daysUntilRenewal }} days</strong>
            </div>
          </div>
        </div>

        <!-- Cancellation Options -->
        <div v-if="subscriptionStore.subscription.status !== 'canceled'" class="cancellation-section">
          <h2>Cancel Subscription</h2>
          <p class="warning-text">
            ⚠️ Canceling your subscription means you'll lose access to premium features at the end of your current billing period.
          </p>

          <div class="cancellation-options">
            <!-- Option 1: Cancel at Period End -->
            <div class="option-card">
              <h3>Cancel at Period End</h3>
              <p>Your subscription will remain active until {{ subscriptionStore.renewalDate }}. No refunds will be issued.</p>
              <button
                class="btn btn-secondary"
                @click="selectCancellation('end_of_period')"
              >
                Select This Option
              </button>
            </div>

            <!-- Option 2: Cancel Immediately -->
            <div class="option-card">
              <h3>Cancel Immediately</h3>
              <p>Your subscription will be canceled right away. You'll lose access to premium features.</p>
              <button
                class="btn btn-danger"
                @click="selectCancellation('immediately')"
              >
                Select This Option
              </button>
            </div>
          </div>

          <!-- Cancellation Form -->
          <div v-if="selectedCancellation" class="cancellation-form">
            <h3>Why are you canceling?</h3>
            <p class="form-text">(Optional - helps us improve)</p>

            <form @submit.prevent="submitCancellation">
              <div class="form-group">
                <label>Reason for cancellation:</label>
                <select v-model="cancellationReason" class="form-control">
                  <option value="">Select a reason...</option>
                  <option value="too_expensive">Too expensive</option>
                  <option value="not_using">Not using enough</option>
                  <option value="found_alternative">Found alternative</option>
                  <option value="technical_issues">Technical issues</option>
                  <option value="other">Other</option>
                </select>
              </div>

              <div v-if="cancellationReason === 'other'" class="form-group">
                <label>Please tell us more:</label>
                <textarea
                  v-model="cancellationComment"
                  class="form-control"
                  rows="4"
                  placeholder="Your feedback..."
                ></textarea>
              </div>

              <div class="form-actions">
                <button
                  type="button"
                  class="btn btn-secondary"
                  @click="selectedCancellation = null"
                >
                  Back
                </button>
                <button
                  type="submit"
                  class="btn btn-danger"
                  :disabled="subscriptionStore.loading"
                >
                  <span v-if="subscriptionStore.loading">Processing...</span>
                  <span v-else>
                    Confirm {{ selectedCancellation === 'end_of_period' ? 'Cancel at Period End' : 'Cancel Now' }}
                  </span>
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Canceled Subscription -->
        <div v-else class="canceled-section">
          <h2>Subscription Canceled</h2>
          <p class="info-text">Your subscription has been canceled.</p>
          
          <div v-if="subscriptionStore.subscription.cancel_at_period_end" class="info-card">
            <p>Your subscription will remain active until {{ subscriptionStore.renewalDate }}</p>
          </div>

          <button
            class="btn btn-primary"
            @click="reactivateSubscription"
            :disabled="subscriptionStore.loading"
          >
            <span v-if="subscriptionStore.loading">Processing...</span>
            <span v-else>Reactivate Subscription</span>
          </button>
        </div>

        <!-- Back Button -->
        <div class="back-section">
          <router-link to="/subscription/manage" class="link-back">
            ← Back to Subscription Management
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { useSubscriptionStore } from '../../stores/subscription';

export default {
  name: 'CancellationForm',

  data() {
    return {
      selectedCancellation: null,
      cancellationReason: '',
      cancellationComment: '',
    };
  },

  computed: {
    subscriptionStore() {
      return useSubscriptionStore();
    },
  },

  methods: {
    selectCancellation(type) {
      this.selectedCancellation = type;
      this.cancellationReason = '';
      this.cancellationComment = '';
    },

    async submitCancellation() {
      if (!confirm('Are you sure you want to cancel your subscription?')) {
        return;
      }

      try {
        const data = {
          cancellation_type: this.selectedCancellation,
          reason: this.cancellationReason,
          comment: this.cancellationComment,
        };

        await this.subscriptionStore.cancelSubscription(data);
        this.selectedCancellation = null;
      } catch (error) {
        console.error('Cancellation failed:', error);
      }
    },

    async reactivateSubscription() {
      if (!confirm('Are you sure you want to reactivate your subscription?')) {
        return;
      }

      try {
        await this.subscriptionStore.reactivateSubscription();
      } catch (error) {
        console.error('Reactivation failed:', error);
      }
    },
  },

  async mounted() {
    try {
      await this.subscriptionStore.fetchSubscription();
    //   console.log(this.subscriptionStore.subscription);
    } catch (error) {
      console.error('Failed to load subscription:', error);
    }
  },
};
</script>

<style scoped>
.cancellation-page {
  padding: 2rem 0;
  min-height: 100vh;
  background: #f5f5f5;
}

.container {
  max-width: 800px;
  margin: 0 auto;
  padding: 0 1rem;
}

h1 {
  color: #333;
  margin-bottom: 2rem;
}

h2 {
  color: #333;
  margin-bottom: 1.5rem;
  font-size: 1.5rem;
}

h3 {
  color: #333;
  margin-bottom: 0.5rem;
}

.loading {
  text-align: center;
  padding: 2rem;
  background: white;
  border-radius: 8px;
  color: #666;
}

.no-subscription {
  background: white;
  border-radius: 8px;
  padding: 2rem;
  text-align: center;
}

.cancellation-content {
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* Alerts */
.alert {
  padding: 1rem;
  border-radius: 6px;
  margin-bottom: 1.5rem;
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

/* Info Card */
.subscription-info {
  background: white;
  border-radius: 8px;
  padding: 2rem;
  margin-bottom: 2rem;
}

.info-card {
  background: #f9f9f9;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
}

.info-row {
  display: flex;
  justify-content: space-between;
  padding: 0.75rem 0;
  border-bottom: 1px solid #e0e0e0;
}

.info-row:last-child {
  border-bottom: none;
}

.info-row span {
  color: #666;
}

.info-row strong {
  color: #333;
  font-weight: 600;
}

.status-active {
  display: inline-block;
  background: #28a745;
  color: white;
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.85rem;
}

/* Cancellation Section */
.cancellation-section {
  background: white;
  border-radius: 8px;
  padding: 2rem;
  margin-bottom: 2rem;
}

.warning-text {
  background: #fff3cd;
  border-left: 4px solid #ffc107;
  padding: 1rem;
  border-radius: 4px;
  color: #856404;
  margin-bottom: 1.5rem;
}

.cancellation-options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.option-card {
  border: 2px solid #e0e0e0;
  border-radius: 6px;
  padding: 1.5rem;
  text-align: center;
  transition: all 0.3s ease;
}

.option-card:hover {
  border-color: #0066cc;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.option-card h3 {
  margin-top: 0;
}

.option-card p {
  color: #666;
  font-size: 0.95rem;
  margin-bottom: 1rem;
}

/* Form */
.cancellation-form {
  background: #f9f9f9;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 1.5rem;
  margin-top: 2rem;
}

.form-text {
  color: #666;
  font-size: 0.9rem;
  margin-top: -0.5rem;
  margin-bottom: 1rem;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  color: #333;
  font-weight: 600;
}

.form-control {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 1rem;
  font-family: inherit;
}

.form-control:focus {
  outline: none;
  border-color: #0066cc;
  box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
}

textarea.form-control {
  resize: vertical;
}

.form-actions {
  display: flex;
  gap: 1rem;
  margin-top: 2rem;
}

/* Canceled Section */
.canceled-section {
  background: white;
  border-radius: 8px;
  padding: 2rem;
  margin-bottom: 2rem;
}

.info-text {
  color: #666;
  margin-bottom: 1.5rem;
}

/* Buttons */
.btn {
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.3s ease;
  font-weight: 600;
  display: inline-block;
}

.btn-primary {
  background: #0066cc;
  color: white;
}

.btn-primary:hover {
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

/* Back Link */
.back-section {
  margin-top: 2rem;
}

.link-back {
  color: #0066cc;
  text-decoration: none;
}

.link-back:hover {
  text-decoration: underline;
}

@media (max-width: 640px) {
  .cancellation-options {
    grid-template-columns: 1fr;
  }

  .form-actions {
    flex-direction: column;
  }

  .btn {
    width: 100%;
  }
}
</style>