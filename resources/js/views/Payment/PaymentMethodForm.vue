<template>
  <div class="payment-method-form">
    <h3>Add Payment Method</h3>
    
    <!-- Always render the card element so Stripe can mount it -->
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
      customerId: null,
      loading: false,
      error: null,
    };
  },

  async mounted() {
    console.log('🔵 PaymentMethodForm mounted');
    console.log('Stripe already loaded?', typeof window.Stripe);
    
    // Load Stripe
    if (!window.Stripe) {
      console.log('Loading Stripe script...');
      const script = document.createElement('script');
      script.src = 'https://js.stripe.com/v3/';
      script.async = true;
      script.onload = () => {
        console.log('✅ Stripe script loaded');
        this.initializeStripe();
      };
      script.onerror = () => {
        console.error('❌ Failed to load Stripe script');
        this.error = 'Failed to load Stripe. Check your internet connection.';
      };
      document.head.appendChild(script);
    } else {
      console.log('Stripe already available, initializing...');
      this.initializeStripe();
    }
  },

  methods: {
    initializeStripe() {
      try {
        const publicKey = 'pk_test_51HQNztF69waXA7ShEIJlL3kXaJkLhIsgLOecjlgC82kbCSpV36KABe9pIYtxGctSgWoyZYz8ddA6vnjbULsTsQF300KSwpYSI4';
        
        if (!publicKey) {
          this.error = 'Stripe public key is not configured';
          return;
        }

        // Wait for Vue to render the DOM before mounting Stripe elements
        const waitForElement = () => {
          const element = document.getElementById('card-element');
          if (!element) {
            // Element not ready yet, try again in 100ms
            setTimeout(waitForElement, 100);
            return;
          }

          // Element is ready, initialize Stripe
          this.stripe = window.Stripe(publicKey);
          this.elements = this.stripe.elements();
          this.cardElement = this.elements.create('card');
          this.cardElement.mount('#card-element');

          // Create setup intent
          this.createSetupIntent();
        };

        waitForElement();
      } catch (err) {
        this.error = 'Failed to initialize Stripe: ' + err.message;
        console.error('Stripe initialization error:', err);
      }
    },

    async createSetupIntent() {
      try {
        this.loading = true;
        this.error = null;

        console.log('Creating setup intent...');

        const response = await axios.post('/api/v1/subscription/setupIntent', {}, {
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
        });

        console.log('Setup intent response:', response.data);

        if (response.data.success) {
          this.clientSecret = response.data.client_secret;
          this.customerId = response.data.customer_id;
          console.log('Client secret set:', this.clientSecret);
        } else {
          this.error = response.data.message || 'Failed to create setup intent';
          console.error('API returned success: false', response.data);
        }
      } catch (error) {
        console.error('Setup intent error:', error);
        this.error = error.response?.data?.message || error.message || 'Failed to initialize payment form';
        console.error('Full error:', error.response?.data);
      } finally {
        this.loading = false;
      }
    },

    async savePaymentMethod() {
      this.error = null;
      this.loading = true;

      try {
        if (!this.clientSecret) {
          this.error = 'Setup intent not initialized';
          return;
        }

        console.log('Confirming card setup...');

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
          console.error('Stripe error:', error);
          return;
        }

        console.log('Setup intent confirmed:', setupIntent);

        // Confirm on backend
        const response = await axios.post('/api/v1/subscription/confirm-setup-intent', {
          setup_intent_id: setupIntent.id,
        });

        console.log('Backend confirmation response:', response.data);

        if (response.data.success) {
          alert('Payment method saved successfully!');
          this.$emit('success', response.data.payment_method_id);
          this.$router.push('/subscription/manage');
        } else {
          this.error = response.data.message || 'Failed to save payment method';
        }
      } catch (error) {
        console.error('Save payment method error:', error);
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