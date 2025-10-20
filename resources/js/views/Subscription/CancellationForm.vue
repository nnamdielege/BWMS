<template>
  <div class="cancellation-container">
    <!-- Header -->
    <div class="header">
      <h2 class="title">Cancel Subscription</h2>
      <p class="subtitle">We're sorry to see you go. Below are your cancellation options.</p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Processing your request...</p>
    </div>

    <!-- Success State -->
    <div v-else-if="successMessage" class="success-alert">
      <svg class="icon-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <div>
        <h3>{{ successMessage }}</h3>
        <p v-if="scheduledCancellation">
          Your subscription will be cancelled on <strong>{{ formatDate(cancelsAt) }}</strong>.
          You can reactivate it anytime before that date.
        </p>
        <p v-else>
          Your subscription has been cancelled immediately.
          Your access will be revoked within 24 hours.
        </p>
      </div>
      <button @click="goBack" class="btn btn-primary">Back to Subscription</button>
    </div>

    <!-- Cancellation Options -->
    <div v-else class="options-container">
      <!-- Option 1: Cancel At Period End -->
      <div class="option-card" :class="{ selected: selectedOption === 'period_end' }">
        <input 
          type="radio" 
          id="period_end" 
          value="period_end" 
          v-model="selectedOption"
          class="radio"
        >
        <label for="period_end" class="option-label">
          <div class="option-header">
            <span class="option-title">Cancel at End of Billing Period</span>
            <span class="badge badge-info">Recommended</span>
          </div>
          <p class="option-description">
            Keep your access until the end of your current billing cycle.
            You won't be charged again after {{ formatDate(currentPeriodEnd) }}.
          </p>
          <div class="option-benefits">
            <div class="benefit">
              <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              Continue using the platform until {{ formatDate(currentPeriodEnd) }}
            </div>
            <div class="benefit">
              <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              Reactivate anytime before expiration
            </div>
            <div class="benefit">
              <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              No additional charges
            </div>
          </div>
        </label>
      </div>

      <!-- Option 2: Cancel Immediately -->
      <div class="option-card" :class="{ selected: selectedOption === 'immediate' }">
        <input 
          type="radio" 
          id="immediate" 
          value="immediate" 
          v-model="selectedOption"
          class="radio"
        >
        <label for="immediate" class="option-label">
          <div class="option-header">
            <span class="option-title">Cancel Immediately</span>
          </div>
          <p class="option-description">
            Your subscription ends immediately. You will lose access to all features.
          </p>
          <div class="option-warnings">
            <div class="warning">
              <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 6v2M6.34 5.34a9 9 0 1112.73 12.73M6.34 18.66a9 9 0 1012.73-12.73" />
              </svg>
              Access will be revoked within 24 hours
            </div>
            <div class="warning">
              <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 6v2M6.34 5.34a9 9 0 1112.73 12.73M6.34 18.66a9 9 0 1012.73-12.73" />
              </svg>
              Your data may not be retained after 30 days
            </div>
          </div>
        </label>
      </div>

      <!-- Cancellation Reason -->
      <div class="form-group">
        <label for="reason" class="form-label">Tell us why you're cancelling (optional)</label>
        <textarea 
          id="reason"
          v-model="cancellationReason"
          class="form-textarea"
          placeholder="Your feedback helps us improve..."
          rows="4"
        ></textarea>
        <small class="form-hint">Your feedback is valuable and will help us serve you better.</small>
      </div>

      <!-- Confirmation Alert -->
      <div class="confirmation-alert">
        <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div>
          <p v-if="selectedOption === 'period_end'">
            Your access will continue until <strong>{{ formatDate(currentPeriodEnd) }}</strong>.
            No additional charges will apply after that date.
          </p>
          <p v-else>
            Your access will be revoked within 24 hours.
            Please download any important data before cancelling.
          </p>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="actions">
        <button @click="goBack" class="btn btn-secondary">Keep Subscription</button>
        <button @click="confirmCancellation" class="btn btn-danger" :disabled="loading">
          {{ selectedOption === 'period_end' ? 'Cancel at Period End' : 'Cancel Now' }}
        </button>
      </div>
    </div>

    <!-- Reactivate Section (if scheduled for cancellation) -->
    <div v-if="subscription?.cancel_at_period_end && !successMessage" class="reactivate-section">
      <h3>Change Your Mind?</h3>
      <p>Your subscription is scheduled for cancellation on {{ formatDate(subscription.cancels_at) }}.</p>
      <button @click="reactivateSubscription" class="btn btn-primary" :disabled="loading">
        Reactivate Subscription
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useRouter } from 'vue-router';

const router = useRouter();

const selectedOption = ref('period_end');
const cancellationReason = ref('');
const loading = ref(false);
const successMessage = ref('');
const scheduledCancellation = ref(false);
const cancelsAt = ref(null);
const subscription = ref(null);
const errorMessage = ref('');

const currentPeriodEnd = computed(() => subscription.value?.current_period_end);

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
};

const loadSubscription = async () => {
  try {
    const response = await axios.get('/api/v1/subscription');
    subscription.value = response.data.data.subscription;
  } catch (error) {
    console.error('Failed to load subscription:', error);
    errorMessage.value = 'Failed to load subscription details';
  }
};

const confirmCancellation = () => {
  if (window.confirm(
    selectedOption.value === 'period_end'
      ? `Cancel at end of billing period (${formatDate(currentPeriodEnd.value)})?`
      : 'Cancel subscription immediately? You will lose access within 24 hours.'
  )) {
    performCancellation();
  }
};

const performCancellation = async () => {
  loading.value = true;
  errorMessage.value = '';

  try {
    const endpoint = selectedOption.value === 'period_end'
      ? '/api/v1/subscription/cancel-at-period-end'
      : '/api/v1/subscription/cancel';

    const response = await axios.post(endpoint, {
      reason: cancellationReason.value || null,
    });

    if (response.data.success) {
      scheduledCancellation.value = selectedOption.value === 'period_end';
      cancelsAt.value = response.data.cancels_at;
      successMessage.value = response.data.message;
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Failed to cancel subscription';
    console.error('Cancellation error:', error);
  } finally {
    loading.value = false;
  }
};

const reactivateSubscription = async () => {
  if (window.confirm('Reactivate your subscription?')) {
    loading.value = true;
    try {
      const response = await axios.post('/api/v1/subscription/reactivate');
      if (response.data.success) {
        successMessage.value = 'Subscription reactivated successfully!';
        await loadSubscription();
      }
    } catch (error) {
      errorMessage.value = error.response?.data?.message || 'Failed to reactivate';
    } finally {
      loading.value = false;
    }
  }
};

const goBack = () => {
  router.push('/subscription/manage');
};

onMounted(() => {
  loadSubscription();
});
</script>

<style scoped>
.cancellation-container {
  max-width: 800px;
  margin: 0 auto;
  padding: 2rem;
  background: #fff;
  border-radius: 0.75rem;
}

.header {
  margin-bottom: 2rem;
  text-align: center;
}

.title {
  font-size: 1.875rem;
  font-weight: 700;
  color: #1f2937;
  margin: 0;
}

.subtitle {
  color: #6b7280;
  margin: 0.5rem 0 0 0;
}

.loading-state {
  text-align: center;
  padding: 3rem;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #f3f4f6;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.success-alert {
  display: flex;
  gap: 1rem;
  padding: 1.5rem;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 0.5rem;
  margin-bottom: 2rem;
}

.icon-success {
  width: 24px;
  height: 24px;
  color: #10b981;
  flex-shrink: 0;
  margin-top: 0.25rem;
}

.success-alert h3 {
  margin: 0 0 0.5rem 0;
  color: #10b981;
}

.success-alert p {
  margin: 0.5rem 0 1rem 0;
  color: #047857;
}

.options-container {
  margin-bottom: 2rem;
}

.option-card {
  border: 2px solid #e5e7eb;
  border-radius: 0.5rem;
  padding: 1.5rem;
  margin-bottom: 1rem;
  cursor: pointer;
  transition: all 0.2s;
}

.option-card:hover {
  border-color: #d1d5db;
  background: #f9fafb;
}

.option-card.selected {
  border-color: #3b82f6;
  background: #eff6ff;
}

.radio {
  width: 1.125rem;
  height: 1.125rem;
  cursor: pointer;
  margin-right: 0.75rem;
}

.option-label {
  display: block;
  cursor: pointer;
}

.option-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.option-title {
  font-weight: 600;
  color: #1f2937;
}

.badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.badge-info {
  background: #dbeafe;
  color: #1e40af;
}

.option-description {
  color: #6b7280;
  margin: 0.5rem 0;
  font-size: 0.9rem;
}

.option-benefits,
.option-warnings {
  margin-top: 0.75rem;
}

.benefit,
.warning {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: #4b5563;
  margin: 0.5rem 0;
}

.warning {
  color: #7c2d12;
}

.icon {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  color: #10b981;
}

.warning .icon {
  color: #dc2626;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: #374151;
}

.form-textarea {
  width: 100%;
  padding: 0.625rem;
  border: 1px solid #d1d5db;
  border-radius: 0.5rem;
  font-family: Arial, sans-serif;
  font-size: 1rem;
  resize: vertical;
}

.form-textarea:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-hint {
  display: block;
  margin-top: 0.25rem;
  color: #6b7280;
  font-size: 0.875rem;
}

.confirmation-alert {
  display: flex;
  gap: 0.75rem;
  padding: 1rem;
  background: #fef3c7;
  border-left: 4px solid #f59e0b;
  border-radius: 0.375rem;
  margin-bottom: 1.5rem;
}

.confirmation-alert .icon {
  color: #d97706;
  margin-top: 0.125rem;
}

.confirmation-alert p {
  margin: 0;
  color: #92400e;
  font-size: 0.9rem;
}

.actions {
  display: flex;
  gap: 1rem;
  justify-content: flex-end;
}

.btn {
  padding: 0.625rem 1.25rem;
  border-radius: 0.5rem;
  font-weight: 500;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  background: #3b82f6;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #2563eb;
}

.btn-secondary {
  background: #e5e7eb;
  color: #374151;
}

.btn-secondary:hover:not(:disabled) {
  background: #d1d5db;
}

.btn-danger {
  background: #ef4444;
  color: white;
}

.btn-danger:hover:not(:disabled) {
  background: #dc2626;
}

.reactivate-section {
  margin-top: 3rem;
  padding-top: 2rem;
  border-top: 1px solid #e5e7eb;
  text-align: center;
}

.reactivate-section h3 {
  margin: 0 0 0.5rem 0;
  color: #1f2937;
}

.reactivate-section p {
  color: #6b7280;
  margin: 0 0 1rem 0;
}
</style>