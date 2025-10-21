<template>
  <div class="pricing-page">
    <div class="container">
      <!-- Hero Section -->
      <div class="hero-section">
        <h1>Choose Your Plan</h1>
        <p>Select the perfect plan for your inventory management needs</p>
      </div>

      <!-- Plans Grid -->
      <div class="plans-container">
        <div
          v-for="plan in plans"
          :key="plan.id"
          class="plan-card"
          :class="{ featured: plan.featured }"
        >
          <div v-if="plan.featured" class="featured-badge">Most Popular</div>
          
          <h2>{{ plan.name }}</h2>
          <div class="price">
            <span class="amount">${{ plan.price }}</span>
            <span class="period">/month</span>
          </div>

          <p class="description">{{ plan.description }}</p>

          <ul class="features-list">
            <li v-for="(feature, idx) in plan.features" :key="idx">
              <span class="check-icon">✓</span>
              {{ feature }}
            </li>
          </ul>

          <button
            v-if="subscriptionStore.subscription && currentUserPlan?.id === plan.id"
            class="btn btn-secondary"
            disabled
          >
            Current Plan
          </button>
          <button
            v-else-if="subscriptionStore.subscription && currentUserPlan && currentUserPlan.id !== plan.id"
            @click="showPlanChangeModal(plan)"
            class="btn btn-primary"
          >
            Change Plan
          </button>
          <button
            v-else
            @click="subscribeToPlan(plan)"
            :disabled="isLoading"
            class="btn btn-primary"
          >
            {{ isLoading ? 'Processing...' : 'Subscribe Now' }}
          </button>
        </div>
      </div>

      <!-- Plan Change Modal -->
      <PlanChangeModal
        v-if="showModal"
        @close="showModal = false"
        @plan-changed="onPlanChanged"
      />
    </div>
  </div>
</template>

<script>
import { useSubscriptionStore } from '../../stores/subscription';
import PlanChangeModal from '../../components/common/PlanChangeModal.vue';
import subscriptionService from '../../services/subscriptionService';

export default {
  name: 'PricingPlans',

  components: {
    PlanChangeModal,
  },

  data() {
    return {
      showModal: false,
      isLoading: false,
      plansLoading: true,
      plans: [],
    };
  },

  computed: {
    subscriptionStore() {
      return useSubscriptionStore();
    },

    currentUserPlan() {
      return this.subscriptionStore.currentPlan;
    },
  },

  methods: {
    async fetchPlans() {
      try {
        this.plansLoading = true;
        const response = await subscriptionService.getAvailablePlans();
        
        console.log('Plans API response:', response.data);
        
        // Handle response from /api/v1/subscription/plans/available
        if (response.data.success && response.data.data) {
          // Response structure: { success: true, data: { available_plans: [...] } }
          const plansData = Array.isArray(response.data.data) 
            ? response.data.data 
            : response.data.data.available_plans || [];
          
          this.plans = plansData;
          console.log('Plans loaded from database:', this.plans);
        } else {
          throw new Error('Failed to load plans');
        }
      } catch (error) {
        console.error('Error fetching plans:', error);
        alert('Failed to load subscription plans: ' + (error.message || 'Unknown error'));
      } finally {
        this.plansLoading = false;
      }
    },

    async subscribeToPlan(plan) {
        this.isLoading = true;
        
        try {
            console.log('Creating checkout for plan:', plan.id);
            
            const response = await subscriptionService.createStripeCheckout(plan.id);
            
            console.log('Response:', response.data);
            
            if (!response.data.success) {
            throw new Error(response.data.message || 'Failed to create checkout');
            }

            // NEW: Use checkout_url instead of session_id
            const checkoutUrl = response.data.checkout_url;
            
            if (!checkoutUrl) {
            throw new Error('No checkout URL returned');
            }

            console.log('Redirecting to:', checkoutUrl);
            window.location.href = checkoutUrl;
            
        } catch (error) {
            console.error('Error:', error);
            alert('Failed: ' + (error.response?.data?.message || error.message));
        } finally {
            this.isLoading = false;
        }
    },

    loadStripeFromCDN() {
      return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
      });
    },

    showPlanChangeModal(plan) {
      this.showModal = true;
    },

    onPlanChanged(subscription) {
      // Handle successful plan change
      this.showModal = false;
      this.$router.push({ name: 'subscription-manage' });
    },
  },

  async mounted() {
    console.log('VITE_STRIPE_PUBLIC_KEY:', import.meta.env.VITE_STRIPE_PUBLIC_KEY);
    console.log('All env vars:', import.meta.env);

    // Fetch plans from database
    await this.fetchPlans();

    // Clear store first
    this.subscriptionStore.subscription = null;
    this.subscriptionStore.error = null;
    
    // Load current subscription
    try {
      await this.subscriptionStore.fetchSubscription();
    } catch (error) {
      // Subscription doesn't exist - that's fine
      this.subscriptionStore.subscription = null;
      console.error('No subscription found:', error);
    }
  },
};
</script>

<style scoped>
.pricing-page {
  padding: 3rem 0;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  min-height: 100vh;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 1rem;
}

.hero-section {
  text-align: center;
  color: white;
  margin-bottom: 3rem;
}

.hero-section h1 {
  font-size: 2.5rem;
  margin-bottom: 1rem;
}

.hero-section p {
  font-size: 1.1rem;
  opacity: 0.9;
}

.plans-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 2rem;
  margin-bottom: 3rem;
}

.plan-card {
  background: white;
  border-radius: 8px;
  padding: 2rem;
  position: relative;
  transition: all 0.3s ease;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.plan-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
}

.plan-card.featured {
  border: 2px solid #667eea;
  transform: scale(1.05);
}

.featured-badge {
  position: absolute;
  top: -15px;
  left: 50%;
  transform: translateX(-50%);
  background: #667eea;
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 20px;
  font-size: 0.85rem;
  font-weight: bold;
}

.plan-card h2 {
  margin: 1rem 0 0.5rem;
  color: #333;
  font-size: 1.5rem;
}

.price {
  display: flex;
  align-items: baseline;
  margin-bottom: 1rem;
}

.amount {
  font-size: 2.5rem;
  font-weight: bold;
  color: #667eea;
}

.period {
  color: #666;
  margin-left: 0.5rem;
}

.description {
  color: #666;
  margin-bottom: 1.5rem;
  font-size: 0.95rem;
}

.features-list {
  list-style: none;
  padding: 0;
  margin-bottom: 2rem;
}

.features-list li {
  display: flex;
  align-items: center;
  padding: 0.75rem 0;
  border-bottom: 1px solid #f0f0f0;
  color: #666;
  font-size: 0.95rem;
}

.features-list li:last-child {
  border-bottom: none;
}

.check-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  background: #28a745;
  color: white;
  border-radius: 50%;
  margin-right: 0.75rem;
  font-size: 0.8rem;
  flex-shrink: 0;
}

.btn {
  width: 100%;
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.3s ease;
  font-weight: 600;
}

.btn-primary {
  background: #667eea;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #5568d3;
}

.btn-primary:disabled {
  background: #999;
  cursor: not-allowed;
  opacity: 0.7;
}

.btn-secondary {
  background: #e0e0e0;
  color: #333;
  cursor: not-allowed;
}

@media (max-width: 768px) {
  .plan-card.featured {
    transform: scale(1);
  }

  .hero-section h1 {
    font-size: 2rem;
  }
}
</style>