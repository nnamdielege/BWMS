<template>
  <div class="pricing-container">
    <!-- Header -->
    <div class="pricing-header">
      <h1>Simple, Transparent Pricing</h1>
      <p>Choose the perfect plan for your warehouse</p>
    </div>

    <!-- Current Subscription Alert -->
    <div v-if="currentSubscription" class="alert alert-info">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <span>You're currently on the <strong>{{ currentSubscription.plan.name }}</strong> plan</span>
    </div>

    <!-- Pricing Cards -->
    <div class="pricing-grid">
      <div v-for="plan in plans" :key="plan.id" class="pricing-card" :class="{ 'pricing-card-featured': plan.name === 'Professional' }">
        <!-- Featured Badge -->
        <div v-if="plan.name === 'Professional'" class="featured-badge">
          Most Popular
        </div>

        <!-- Plan Name -->
        <h2 class="plan-name">{{ plan.name }}</h2>
        <p class="plan-description">{{ plan.description }}</p>

        <!-- Price -->
        <div class="plan-price">
          <span class="currency">$</span>
          <span class="amount">{{ plan.price_monthly }}</span>
          <span class="period">/month</span>
        </div>

        <!-- Trial Info -->
        <p class="trial-info">{{ plan.trial_days }} days free trial</p>

        <!-- Features -->
        <ul class="features-list">
          <li>
            <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>{{ plan.max_users }} users</span>
          </li>
          <li>
            <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>{{ plan.max_locations }} {{ plan.max_locations === 1 ? 'location' : 'locations' }}</span>
          </li>
          <li>
            <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>{{ plan.max_products.toLocaleString() }} products</span>
          </li>
          <li>
            <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>{{ plan.data_limit_gb }} GB data/month</span>
          </li>
          <li v-for="feature in plan.features" :key="feature">
            <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>{{ formatFeatureName(feature) }}</span>
          </li>
        </ul>

        <!-- Action Button -->
        <button
          v-if="!isCurrentPlan(plan.id)"
          @click="handlePlanSelection(plan)"
          class="btn btn-full"
          :class="plan.name === 'Professional' ? 'btn-primary' : 'btn-secondary'"
          :disabled="loading"
        >
          <span v-if="loading" class="spinner-small"></span>
          {{ currentSubscription ? 'Upgrade' : 'Start Free Trial' }}
        </button>
        <button v-else class="btn btn-full btn-disabled" disabled>
          Current Plan
        </button>
      </div>
    </div>

    <!-- FAQ Section -->
    <div class="faq-section">
      <h2>Frequently Asked Questions</h2>
      
      <div class="faq-grid">
        <div class="faq-item">
          <h3>Can I cancel anytime?</h3>
          <p>Yes, cancel your subscription at any time. No long-term contracts.</p>
        </div>
        <div class="faq-item">
          <h3>Do you offer discounts?</h3>
          <p>Contact us for annual billing discounts. We offer 20% off yearly plans.</p>
        </div>
        <div class="faq-item">
          <h3>What happens after trial?</h3>
          <p>Your trial converts to a paid subscription. We'll remind you 3 days before.</p>
        </div>
        <div class="faq-item">
          <h3>Can I change plans?</h3>
          <p>Upgrade or downgrade anytime. Changes take effect at your next billing cycle.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();
const plans = ref([]);
const currentSubscription = ref(null);
const loading = ref(false);

onMounted(async () => {
  await fetchPlans();
  await fetchCurrentSubscription();
});

const fetchPlans = async () => {
  try {
    const response = await axios.get('/api/v1/subscription/plans');
    plans.value = response.data.data;
  } catch (error) {
    console.error('Error fetching plans:', error);
  }
};

const fetchCurrentSubscription = async () => {
  try {
    const response = await axios.get('/api/v1/subscription/current');
    currentSubscription.value = response.data.data;
  } catch (error) {
    // No current subscription
    currentSubscription.value = null;
  }
};

const isCurrentPlan = (planId) => {
  return currentSubscription.value && currentSubscription.value.plan.id === planId;
};

const handlePlanSelection = async (plan) => {
  loading.value = true;

  try {
    if (!currentSubscription.value) {
      // Start trial
      const response = await axios.post('/api/v1/subscription/start-trial', {
        plan_id: plan.id,
      });

      if (response.data.success) {
        // Refresh subscription
        await fetchCurrentSubscription();
        // Show success message
        alert(`Trial started! You have ${plan.trial_days} days free.`);
        await router.push('/subscription/manage');
      }
    } else {
      // Create Stripe checkout for upgrade
      const response = await axios.post('/api/v1/subscription/stripe/checkout', {
        plan_id: plan.id,
        success_url: `${window.location.origin}/subscription/success?session_id={CHECKOUT_SESSION_ID}`,
      });

      if (response.data.checkout_url) {
        // Redirect to Stripe checkout
        window.location.href = response.data.checkout_url;
      }
    }
  } catch (error) {
    console.error('Error:', error);
    alert(error.response?.data?.message || 'An error occurred');
  } finally {
    loading.value = false;
  }
};

const formatFeatureName = (feature) => {
  return feature
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};
</script>

<style scoped>
.pricing-container {
  @apply max-w-7xl mx-auto px-4 py-12;
}

.pricing-header {
  @apply text-center mb-12;
}

.pricing-header h1 {
  @apply text-4xl font-bold text-gray-900 mb-2;
}

.pricing-header p {
  @apply text-xl text-gray-600;
}

.alert {
  @apply mb-8 p-4 rounded-lg flex items-center gap-3;
}

.alert-info {
  @apply bg-blue-50 text-blue-800 border border-blue-200;
}

.pricing-grid {
  @apply grid grid-cols-1 md:grid-cols-3 gap-8 mb-16;
}

.pricing-card {
  @apply bg-white rounded-lg shadow-lg p-8 relative border-2 border-transparent transition-all hover:shadow-xl;
}

.pricing-card-featured {
  @apply border-indigo-600 md:scale-105 ring-2 ring-indigo-600 ring-opacity-50;
}

.featured-badge {
  @apply absolute top-0 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-indigo-600 text-white px-4 py-1 rounded-full text-sm font-semibold;
}

.plan-name {
  @apply text-2xl font-bold text-gray-900 mb-2;
}

.plan-description {
  @apply text-gray-600 mb-6;
}

.plan-price {
  @apply flex items-baseline gap-2 mb-2;
}

.currency {
  @apply text-gray-600 text-lg;
}

.amount {
  @apply text-4xl font-bold text-gray-900;
}

.period {
  @apply text-gray-600;
}

.trial-info {
  @apply text-sm text-gray-500 mb-6;
}

.features-list {
  @apply list-none space-y-4 mb-8;
}

.features-list li {
  @apply flex items-center gap-3 text-gray-700;
}

.btn {
  @apply w-full py-3 px-4 rounded-lg font-semibold transition-all;
}

.btn-full {
  @apply w-full;
}

.btn-primary {
  @apply bg-indigo-600 text-white hover:bg-indigo-700;
}

.btn-secondary {
  @apply bg-gray-200 text-gray-900 hover:bg-gray-300;
}

.btn-disabled {
  @apply bg-gray-100 text-gray-400 cursor-not-allowed;
}

.spinner-small {
  @apply inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin mr-2;
}

.faq-section {
  @apply bg-gray-50 rounded-lg p-8;
}

.faq-section h2 {
  @apply text-2xl font-bold text-gray-900 mb-8;
}

.faq-grid {
  @apply grid grid-cols-1 md:grid-cols-2 gap-8;
}

.faq-item h3 {
  @apply font-semibold text-gray-900 mb-2;
}

.faq-item p {
  @apply text-gray-600;
}
</style>