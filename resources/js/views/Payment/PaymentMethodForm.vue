<template>
  <div class="payment-method-form">
    <h3>Add Payment Method</h3>
    
    <div v-if="loading" class="spinner">
      <p>Loading...</p>
    </div>

    <div v-else-if="!clientSecret" class="alert alert-error">
      Failed to initialize payment form
    </div>

    <div v-else>
      <!-- Stripe Elements will be mounted here -->
      <div id="card-element" class="card-element"></div>
      
      <div v-if="error" class="alert alert-error" style="margin-top: 1rem;">
        {{ error }}
      </div>

      <button 
        @click="savePaymentMethod"
        :disabled="loading || !clientSecret"
        class="btn btn-primary"
        style="margin-top: 1rem;"
      >
        {{ loading ? 'Processing...' : 'Save Payment Method' }}
      </button>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'PaymentMethodForm',

  data() {
    return {
      stripe: null,
      elements: null,
      cardElement: null,
      clientSecret: null,
      setupIntentId: null,
      loading: false,
      error: null,
    };
  },

  async mounted() {
    // Load Stripe
    if (!window.Stripe) {
      const script = document.createElement('script');
      script.src = 'https://js.stripe.com/v3/';
      script.async = true;
      script.onload = this.initializeStripe;
      document.head.appendChild(script);
    } else {
      this.initializeStripe();
    }
  },

  methods: {
    initializeStripe() {
      const publicKey = 'pk_test_51HQNztF69waXA7ShEIJlL3kXaJkLhIsgLOecjlgC82kbCSpV36KABe9pIYtxGctSgWoyZYz8ddA6vnjbULsTsQF300KSwpYSI4';
      this.stripe = window.Stripe(publicKey);
      this.elements = this.stripe.elements();
      this.cardElement = this.elements.create('card');
      this.cardElement.mount('#card-element');

      // Create setup intent
      this.createSetupIntent();
    },

    async createSetupIntent() {
      try {
        this.loading = true;
        const response = await axios.post('/api/v1/subscription/setupIntent');

        if (response.data.success) {
          this.clientSecret = response.data.client_secret;
          this.setupIntentId = response.data.setup_intent_id;
        } else {
          this.error = response.data.message || 'Failed to initialize payment form';
        }
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to initialize payment form';
      } finally {
        this.loading = false;
      }
    },

    async savePaymentMethod() {
      this.error = null;
      this.loading = true;

      try {
        // Confirm the card with Stripe
        const { setupIntent, error } = await this.stripe.confirmCardSetup(
          this.clientSecret,
          {
            payment_method: {
              card: this.cardElement,
              billing_details: {
                name: 'Customer',
              },
            },
          }
        );

        if (error) {
          this.error = error.message;
          return;
        }

        // Confirm on backend
        const response = await axios.post('/api/v1/subscription/confirm-setup-intent', {
          setup_intent_id: setupIntent.id,
        });

        if (response.data.success) {
          alert('Payment method saved successfully!');
          this.$emit('success', response.data.payment_method_id);
          this.$router.push('/subscription/manage');
        } else {
          this.error = response.data.message;
        }
      } catch (error) {
        this.error = error.response?.data?.message || error.message || 'An error occurred';
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>

<style scoped>
.payment-method-form {
  max-width: 500px;
  margin: 2rem auto;
  padding: 2rem;
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

h3 {
  margin: 0 0 1.5rem;
  color: #333;
}

.card-element {
  padding: 1rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: #fafafa;
}

.btn {
  width: 100%;
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 4px;
  font-size: 0.95rem;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-primary {
  background: #0066cc;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #0052a3;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.alert {
  padding: 1rem;
  border-radius: 4px;
  font-size: 0.9rem;
}

.alert-error {
  background: #f8d7da;
  border: 1px solid #f5c6cb;
  color: #721c24;
}

.spinner {
  text-align: center;
  padding: 2rem;
}
</style>