<template>
  <div class="success-page">
    <div class="container">
      <div class="success-card">
        <div v-if="loading" class="loading">
          <div class="spinner"></div>
          <p>Verifying your subscription...</p>
        </div>

        <div v-else>
          <div class="success-icon">✓</div>
          
          <h1>Subscription Confirmed!</h1>
          
          <p class="message">
            Thank you for subscribing! Your subscription is now active.
          </p>

          <div class="subscription-details" v-if="subscriptionStore.subscription">
            <div class="detail-item">
              <span>Plan:</span>
              <strong>{{ subscriptionStore.subscription.plan.name }}</strong>
            </div>

            <div class="detail-item">
              <span>Amount:</span>
              <strong>                    
                ${{ parseFloat(subscriptionStore.subscription.plan.price).toFixed(2) }}/{{ subscriptionStore.subscription.plan.interval }}
              </strong>
            </div>

            <div class="detail-item">
              <span>Status:</span>
              <strong class="status-badge">{{ subscriptionStore.subscription.status }}</strong>
            </div>

            <div class="detail-item">
              <span>Next Billing Date:</span>
              <strong>{{ subscriptionStore.renewalDate }}</strong>
            </div>
          </div>

          <div class="next-steps">
            <h3>What's Next?</h3>
            <ul>
              <li>Start using your new plan immediately</li>
              <li>Access all features included in your plan</li>
              <li>You'll receive a confirmation email shortly</li>
              <li>Visit your dashboard to get started</li>
            </ul>
          </div>

          <div class="actions">
            <router-link to="/subscription/manage" class="btn btn-primary">
              Go to Subscription
            </router-link>
            <router-link to="/" class="btn btn-secondary">
              Go to Dashboard
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import { useSubscriptionStore } from '../../stores/subscription';

export default {
  name: 'StripeSuccess',

  data() {
    return {
      loading: true,
    };
  },

  computed: {
    subscriptionStore() {
      return useSubscriptionStore();
    },
  },

  async mounted() {
    try {
      console.log('Verifying payment...');

      // Step 1: Verify the payment and create subscription
      const verifyResponse = await axios.post('/api/v1/subscription/verify-payment');
      
      console.log('Payment verified:', verifyResponse.data);

      // Step 2: Fetch updated subscription details
      await this.subscriptionStore.fetchSubscription();

      console.log('Subscription loaded:', this.subscriptionStore.subscription);

    } catch (error) {
      console.error('Error during payment verification:', error);
      alert('There was an issue activating your subscription. Please contact support.');
    } finally {
      this.loading = false;
    }
  },
};
</script>

<style scoped>
.success-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 2rem 0;
}

.container {
  max-width: 500px;
  width: 100%;
  padding: 0 1rem;
}

.success-card {
  background: white;
  border-radius: 8px;
  padding: 3rem 2rem;
  text-align: center;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
  min-height: 400px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
}

.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #667eea;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.success-icon {
  width: 80px;
  height: 80px;
  background: #28a745;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 3rem;
  color: white;
  margin: 0 auto 1.5rem;
}

h1 {
  color: #333;
  margin-bottom: 1rem;
  font-size: 2rem;
}

.message {
  color: #666;
  margin-bottom: 2rem;
  font-size: 1.05rem;
}

.subscription-details {
  background: #f9f9f9;
  border-radius: 6px;
  padding: 1.5rem;
  margin-bottom: 2rem;
  text-align: left;
}

.detail-item {
  display: flex;
  justify-content: space-between;
  padding: 0.75rem 0;
  border-bottom: 1px solid #e0e0e0;
}

.detail-item:last-child {
  border-bottom: none;
}

.detail-item span {
  color: #666;
}

.detail-item strong {
  color: #333;
  font-weight: 600;
}

.status-badge {
  display: inline-block;
  background: #28a745;
  color: white;
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.85rem;
}

.next-steps {
  background: #e7f3ff;
  border-left: 4px solid #0066cc;
  padding: 1.5rem;
  margin-bottom: 2rem;
  text-align: left;
  border-radius: 4px;
}

.next-steps h3 {
  margin-top: 0;
  color: #0066cc;
}

.next-steps ul {
  margin: 1rem 0 0;
  padding-left: 1.5rem;
}

.next-steps li {
  margin: 0.5rem 0;
  color: #333;
}

.actions {
  display: flex;
  gap: 1rem;
  flex-direction: column;
}

.btn {
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.3s ease;
  text-decoration: none;
  display: inline-block;
  font-weight: 600;
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

@media (max-width: 480px) {
  .success-card {
    padding: 2rem 1.5rem;
  }

  h1 {
    font-size: 1.5rem;
  }

  .actions {
    flex-direction: column;
  }
}
</style>