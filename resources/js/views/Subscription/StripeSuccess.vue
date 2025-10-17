<template>
  <div class="success-container">
    <!-- Loading State -->
    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Processing your payment...</p>
    </div>

    <!-- Success State -->
    <div v-else-if="success" class="success-state">
      <div class="success-card">
        <!-- Success Icon -->
        <div class="success-icon">
          <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>

        <!-- Success Message -->
        <h1 class="success-title">Payment Successful!</h1>
        <p class="success-message">Your subscription has been activated</p>

        <!-- Subscription Details -->
        <div class="details-card" v-if="subscription">
          <div class="detail-row">
            <span class="label">Plan</span>
            <span class="value">{{ subscription.plan.name }}</span>
          </div>
          <div class="detail-row">
            <span class="label">Amount</span>
            <span class="value">${{ subscription.plan.price_monthly }}/month</span>
          </div>
          <div class="detail-row">
            <span class="label">Status</span>
            <span class="value">
              <span class="status-badge status-active">Active</span>
            </span>
          </div>
          <div class="detail-row">
            <span class="label">Next Billing</span>
            <span class="value">{{ nextBillingDate }}</span>
          </div>
        </div>

        <!-- What's Included -->
        <div class="included-section">
          <h3>Your subscription includes:</h3>
          <ul class="features-list">
            <li>
              <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
              <span>{{ subscription.plan.max_users }} users</span>
            </li>
            <li>
              <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
              <span>{{ subscription.plan.max_locations }} warehouse locations</span>
            </li>
            <li>
              <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
              <span>{{ subscription.plan.data_limit_gb }} GB data per month</span>
            </li>
            <li>
              <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
              <span>Priority support</span>
            </li>
          </ul>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
          <router-link to="/subscription/manage" class="btn btn-primary">
            View My Subscription
          </router-link>
          <router-link to="/" class="btn btn-secondary">
            Go to Dashboard
          </router-link>
        </div>

        <!-- Auto-redirect message -->
        <p class="redirect-message">
          Redirecting to dashboard in {{ redirectCountdown }} seconds...
        </p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else class="error-state">
      <div class="error-card">
        <div class="error-icon">
          <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2M7.08 6.47a9 9 0 1 1 9.84 0" />
          </svg>
        </div>

        <h1 class="error-title">Payment Processing Failed</h1>
        <p class="error-message">{{ errorMessage }}</p>

        <div class="action-buttons">
          <router-link to="/pricing" class="btn btn-primary">
            Try Again
          </router-link>
          <router-link to="/" class="btn btn-secondary">
            Back to Dashboard
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import axios from 'axios';

const router = useRouter();
const route = useRoute();

const loading = ref(true);
const success = ref(false);
const subscription = ref(null);
const errorMessage = ref('');
const redirectCountdown = ref(5);

onMounted(async () => {
  try {
    // Get session ID from URL
    const sessionId = route.query.session_id;

    if (!sessionId) {
      throw new Error('No session ID provided');
    }

    console.log('Processing payment with session:', sessionId);

    // Call backend to verify and process payment
    const response = await axios.get('/api/v1/subscription/stripe/success', {
      params: { session_id: sessionId }
    });

    if (response.data.success) {
      subscription.value = response.data.data;
      success.value = true;
      console.log('✅ Payment verified successfully');

      // Start countdown to redirect
      startRedirectCountdown();
    } else {
      throw new Error(response.data.message || 'Payment verification failed');
    }
  } catch (error) {
    console.error('Error processing payment:', error);
    errorMessage.value = error.response?.data?.message || error.message || 'An error occurred while processing your payment';
    success.value = false;
  } finally {
    loading.value = false;
  }
});

const startRedirectCountdown = () => {
  const countdown = setInterval(() => {
    redirectCountdown.value--;

    if (redirectCountdown.value <= 0) {
      clearInterval(countdown);
      router.push('/');
    }
  }, 1000);
};

const nextBillingDate = (() => {
  const date = new Date();
  date.setMonth(date.getMonth() + 1);
  return date.toLocaleDateString('en-AU', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  });
})();
</script>

<style scoped>
.success-container {
  @apply min-h-screen flex items-center justify-center bg-gradient-to-br from-green-50 via-white to-blue-50 px-4 py-8;
}

.loading-state,
.success-state,
.error-state {
  @apply w-full max-w-md;
}

.loading-state {
  @apply flex flex-col items-center justify-center;
}

.spinner {
  @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.loading-state p {
  @apply text-gray-600 text-lg font-medium;
}

/* Success State */
.success-card,
.error-card {
  @apply bg-white rounded-lg shadow-xl p-8;
}

.success-icon,
.error-icon {
  @apply flex justify-center mb-4;
}

.success-icon svg {
  @apply text-green-500;
}

.error-icon svg {
  @apply text-red-500;
}

.success-title,
.error-title {
  @apply text-2xl font-bold text-gray-900 text-center mb-2;
}

.success-title {
  @apply text-green-600;
}

.error-title {
  @apply text-red-600;
}

.success-message,
.error-message {
  @apply text-gray-600 text-center mb-6;
}

.details-card {
  @apply bg-gray-50 rounded-lg p-6 mb-6 space-y-4;
}

.detail-row {
  @apply flex justify-between items-center;
}

.label {
  @apply text-sm font-medium text-gray-600;
}

.value {
  @apply text-sm font-semibold text-gray-900;
}

.status-badge {
  @apply px-3 py-1 rounded-full text-xs font-semibold;
}

.status-active {
  @apply bg-green-100 text-green-800;
}

.included-section {
  @apply mb-6;
}

.included-section h3 {
  @apply text-lg font-semibold text-gray-900 mb-4;
}

.features-list {
  @apply list-none space-y-3;
}

.features-list li {
  @apply flex items-center gap-3 text-gray-700;
}

.action-buttons {
  @apply flex flex-col gap-3 mb-6;
}

.btn {
  @apply px-4 py-3 rounded-lg font-medium transition-all text-center no-underline;
}

.btn-primary {
  @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
  @apply bg-gray-200 text-gray-900 hover:bg-gray-300;
}

.redirect-message {
  @apply text-center text-sm text-gray-600;
}

/* Error State */
.error-card {
  @apply border-2 border-red-200;
}
</style>