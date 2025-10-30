<template>
  <div class="modal-overlay" @click.self="closeModal">
    <div class="modal-content">
      <!-- Header -->
      <div class="modal-header">
        <h2>{{ currentStep === 'select' ? 'Change Your Plan' : 'Confirm Plan Change' }}</h2>
        <button class="btn-close" @click="closeModal">✕</button>
      </div>

      <!-- Step 1: Plan Selection -->
      <div v-if="currentStep === 'select'" class="modal-body">
        <div class="current-plan-section">
          <h3>Current Plan</h3>
          <div class="plan-card current">
            <div class="plan-name">{{ subscriptionStore.currentPlan?.name }}</div>
            <div class="plan-price">${{ subscriptionStore.currentPlan?.price }}/month</div>
          </div>
        </div>

        <h3>Available Plans</h3>
        <div class="plans-grid">
          <div
            v-for="plan in subscriptionStore.availablePlans"
            :key="plan.id"
            class="plan-card clickable"
            :class="{ 
              upgrade: plan.type === 'upgrade', 
              downgrade: plan.type === 'downgrade' 
            }"
            @click="selectAndCalculate(plan)"
          >
            <div class="plan-badge">{{ plan.type }}</div>
            <div class="plan-name">{{ plan.name }}</div>
            <div class="plan-price">${{ plan.price }}/{{ plan.interval }}</div>
            
            <div class="features">
              <div v-for="(feature, idx) in plan.features" :key="idx" class="feature">
                ✓ {{ feature }}
              </div>
            </div>
          </div>
        </div>

        <!-- Error Alert -->
        <div v-if="subscriptionStore.error" class="alert alert-error">
          {{ subscriptionStore.error }}
        </div>
      </div>

      <!-- Step 2: Proration Review -->
      <div v-if="currentStep === 'review'" class="modal-body">
        <button class="btn-back" @click="goBack">← Back to Plans</button>

        <div v-if="subscriptionStore.loading" class="loading-spinner">
          <div class="spinner"></div>
          <p>Calculating proration...</p>
        </div>

        <div v-else-if="subscriptionStore.prorationData" class="proration-container">
          <!-- Plan Comparison -->
          <div class="plan-comparison">
            <div class="comparison-item">
              <span>From:</span>
              <strong>{{ subscriptionStore.prorationData.current_plan.name }}</strong>
            </div>
            <div class="comparison-arrow">→</div>
            <div class="comparison-item">
              <span>To:</span>
              <strong>{{ subscriptionStore.prorationData.new_plan.name }}</strong>
            </div>
          </div>

          <!-- Billing Cycle Info -->
          <div class="info-section">
            <h4>Billing Cycle</h4>
            <div class="info-row">
              <span>Period:</span>
              <span>{{ formatDate(subscriptionStore.prorationData.billing_cycle.start) }} - {{ formatDate(subscriptionStore.prorationData.billing_cycle.end) }}</span>
            </div>
            <div class="info-row">
              <span>Days Remaining:</span>
              <span>{{ subscriptionStore.prorationData.billing_cycle.days_remaining }} / {{ subscriptionStore.prorationData.billing_cycle.days_in_cycle }}</span>
            </div>
          </div>

          <!-- Calculation Breakdown -->
          <div class="calculation-section">
            <h4>Calculation Details</h4>
            <div class="calc-row">
              <span>Current Daily Rate:</span>
              <span>${{ subscriptionStore.prorationData.calculation.current_daily_rate }}/day</span>
            </div>
            <div class="calc-row">
              <span>New Daily Rate:</span>
              <span>${{ subscriptionStore.prorationData.calculation.new_daily_rate }}/day</span>
            </div>
            <div class="calc-row">
              <span>Days Already Paid:</span>
              <span>{{ subscriptionStore.prorationData.calculation.days_passed }}</span>
            </div>
            <div class="calc-row">
              <span>Should Pay for {{ subscriptionStore.prorationData.billing_cycle.days_remaining }} Remaining Days:</span>
              <span>${{ subscriptionStore.prorationData.calculation.should_pay_for_remaining }}</span>
            </div>
          </div>

          <!-- Amount Due / Credit -->
          <div 
            class="amount-section"
            :class="{ 
              'is-charge': subscriptionStore.prorationData.is_upgrade,
              'is-credit': subscriptionStore.prorationData.is_downgrade
            }"
          >
            <div v-if="subscriptionStore.prorationData.is_upgrade">
              <h4>Amount to Charge Today</h4>
              <div class="amount">${{ Math.abs(subscriptionStore.prorationData.amount_due).toFixed(2) }}</div>
              <p>This will be charged to your payment method immediately</p>
            </div>

            <div v-else>
              <h4>Account Credit</h4>
              <div class="amount">${{ subscriptionStore.prorationData.amount_credit.toFixed(2) }}</div>
              <p>This credit will be applied to your next billing cycle</p>
            </div>
          </div>

          <!-- Important Notes -->
          <div class="notes-section">
            <h4>⚠️ Important Information</h4>
            <ul>
              <li v-if="subscriptionStore.prorationData.is_upgrade">
                Your plan will upgrade immediately after payment is processed
              </li>
              <li v-else>
                Your plan will downgrade on {{ formatDate(subscriptionStore.prorationData.billing_cycle.end) }}
              </li>
              <li>Your renewal date remains: {{ subscriptionStore.renewalDate }}</li>
              <li v-if="subscriptionStore.prorationData?.new_plan">New plan will be charged at ${{ subscriptionStore.prorationData.new_plan.price }}/month</li>
            </ul>
          </div>

          <!-- Error Alert -->
          <div v-if="subscriptionStore.error" class="alert alert-error">
            {{ subscriptionStore.error }}
          </div>

          <!-- Actions -->
          <div class="modal-actions">
            <button 
              class="btn btn-secondary"
              @click="goBack"
              :disabled="subscriptionStore.loading"
            >
              Cancel
            </button>
            <button 
              class="btn btn-primary"
              @click="confirmPlanChange"
              :disabled="subscriptionStore.loading"
            >
              <span v-if="subscriptionStore.loading">Processing...</span>
              <span v-else>
                {{ subscriptionStore.prorationData.is_upgrade ? 'Upgrade & Charge' : 'Downgrade' }}
              </span>
            </button>
          </div>
        </div>
      </div>

      <!-- Step 3: Success -->
      <!-- Step 3: Success -->
      <div v-if="currentStep === 'success'" class="modal-body success">
        <div class="success-icon">✓</div>
        <h3>Plan Changed Successfully!</h3>
        
        <div class="success-details">
          <div class="detail-row">
            <span>New Plan:</span>
            <strong>{{ subscriptionStore.subscription?.plan?.name }}</strong>
          </div>
          <div class="detail-row">
            <span>New Amount:</span>
            <strong>${{ subscriptionStore.subscription?.plan?.price_monthly }}/month</strong>
          </div>
          <div v-if="subscriptionStore.prorationData?.amount_due > 0" class="detail-row charge">
            <span>Amount Charged:</span>
            <strong>${{ subscriptionStore.prorationData.amount_due.toFixed(2) }}</strong>
          </div>
          <div v-if="subscriptionStore.prorationData?.amount_credit > 0" class="detail-row credit">
            <span>Account Credit:</span>
            <strong>${{ subscriptionStore.prorationData.amount_credit.toFixed(2) }}</strong>
          </div>
        </div>

        <div class="success-message">
          <p v-if="subscriptionStore.prorationData?.is_upgrade">
            🎉 Enjoy your new plan! You now have access to all premium features.
          </p>
          <p v-else>
            ✓ Your plan has been downgraded. The credit will be applied to your next invoice.
          </p>
        </div>

        <button 
          class="btn btn-primary"
          @click="closeModal"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { useSubscriptionStore } from '../../stores/subscription';

export default {
  name: 'PlanChangeModal',
  
  emits: ['close', 'plan-changed'],

  data() {
    return {
      currentStep: 'select', // 'select', 'review', 'success'
    };
  },

  computed: {
    subscriptionStore() {
      return useSubscriptionStore();
    },
  },

  methods: {
    async selectAndCalculate(plan) {
      this.subscriptionStore.selectPlan(plan);
      this.currentStep = 'review';
      
      try {
        await this.subscriptionStore.calculateProration();
      } catch (error) {
        console.error('Proration calculation failed:', error);
      }
    },

    goBack() {
      this.currentStep = 'select';
      this.subscriptionStore.clearSelection();
    },

    async confirmPlanChange() {
      if (!confirm('Are you sure you want to change your plan?')) {
        return;
      }

      try {
        await this.subscriptionStore.changePlan();
        
        // Refresh subscription to get updated data
        await this.subscriptionStore.fetchSubscription();
        
        this.currentStep = 'success';
        
        // Emit event for parent to refresh
        this.$emit('plan-changed', this.subscriptionStore.subscription);

        // Auto close after 3 seconds
        setTimeout(() => {
          this.closeModal();
        }, 3000);
      } catch (error) {
        console.error('Plan change failed:', error);
      }
    },

    closeModal() {
      this.subscriptionStore.clearSelection();
      this.subscriptionStore.clearMessages();
      this.currentStep = 'select';
      this.$emit('close');
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
    // Load available plans if not already loaded
    if (this.subscriptionStore.availablePlans.length === 0) {
        try {
            await this.subscriptionStore.fetchAvailablePlans();            
            console.log('Available plans:', this.subscriptionStore.availablePlans);
        } catch (error) {
            console.error('Failed to fetch available plans:', error);
        }
    } else {
        console.log('Available plans already loaded:', this.subscriptionStore.availablePlans);
    }
 },
};
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 1rem;
}

.modal-content {
  background: white;
  border-radius: 8px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  width: 100%;
  max-width: 700px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 2rem;
  border-bottom: 1px solid #e0e0e0;
}

.modal-header h2 {
  margin: 0;
  font-size: 1.5rem;
  color: #333;
}

.btn-close {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: #999;
  padding: 0;
}

.btn-close:hover {
  color: #333;
}

.modal-body {
  padding: 2rem;
}

.btn-back {
  background: none;
  border: none;
  color: #0066cc;
  cursor: pointer;
  margin-bottom: 1.5rem;
  font-size: 0.95rem;
  padding: 0;
}

.btn-back:hover {
  text-decoration: underline;
}

/* Plans Grid */
.current-plan-section {
  margin-bottom: 2rem;
}

.current-plan-section h3 {
  margin: 0 0 1rem;
  color: #333;
  font-size: 1rem;
  font-weight: 600;
}

.plans-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1.5rem;
  margin: 1.5rem 0;
}

.plan-card {
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 1.5rem;
  text-align: center;
  position: relative;
  background: #fafafa;
}

.plan-card.current {
  border: 2px solid #0066cc;
  background: #f0f7ff;
}

.plan-card.clickable {
  cursor: pointer;
  transition: all 0.3s ease;
}

.plan-card.clickable:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
}

.plan-card.upgrade {
  border-color: #28a745;
  background: #f0fdf4;
}

.plan-card.downgrade {
  border-color: #ffc107;
  background: #fff8e1;
}

.plan-badge {
  position: absolute;
  top: 10px;
  right: 10px;
  font-size: 0.7rem;
  font-weight: bold;
  padding: 0.3rem 0.6rem;
  border-radius: 20px;
  background: #e0e0e0;
  color: #555;
  text-transform: uppercase;
}

.plan-card.upgrade .plan-badge {
  background: #28a745;
  color: white;
}

.plan-card.downgrade .plan-badge {
  background: #ffc107;
  color: #333;
}

.plan-name {
  font-size: 1.2rem;
  font-weight: bold;
  color: #333;
  margin: 0.5rem 0;
}

.plan-price {
  font-size: 1.6rem;
  font-weight: bold;
  color: #0066cc;
  margin: 0.5rem 0 1rem;
}

.features {
  text-align: left;
  margin-top: 1rem;
  border-top: 1px solid #e0e0e0;
  padding-top: 1rem;
}

.feature {
  font-size: 0.85rem;
  color: #666;
  margin: 0.5rem 0;
}

/* Proration Review */
.proration-container {
  animation: slideIn 0.3s ease;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateX(10px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.plan-comparison {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f5f5f5;
  padding: 1.5rem;
  border-radius: 6px;
  margin-bottom: 1.5rem;
}

.comparison-item {
  flex: 1;
  text-align: center;
}

.comparison-item span {
  display: block;
  font-size: 0.85rem;
  color: #666;
  margin-bottom: 0.3rem;
}

.comparison-item strong {
  display: block;
  font-size: 1.1rem;
  color: #333;
}

.comparison-arrow {
  color: #0066cc;
  font-size: 1.5rem;
  margin: 0 1rem;
}

.info-section,
.calculation-section {
  background: #fafafa;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 1rem;
  margin-bottom: 1.5rem;
}

.info-section h4,
.calculation-section h4 {
  margin: 0 0 1rem;
  color: #333;
  font-size: 0.9rem;
  font-weight: 600;
  text-transform: uppercase;
}

.info-row,
.calc-row {
  display: flex;
  justify-content: space-between;
  padding: 0.6rem 0;
  border-bottom: 1px solid #f0f0f0;
  font-size: 0.95rem;
}

.info-row:last-child,
.calc-row:last-child {
  border-bottom: none;
}

.amount-section {
  padding: 1.5rem;
  border-radius: 6px;
  margin-bottom: 1.5rem;
  text-align: center;
}

.amount-section.is-charge {
  background: #fff3cd;
  border: 1px solid #ffc107;
}

.amount-section.is-credit {
  background: #d4edda;
  border: 1px solid #28a745;
}

.amount-section h4 {
  margin: 0 0 0.5rem;
  color: #333;
  font-size: 0.9rem;
}

.amount {
  font-size: 2rem;
  font-weight: bold;
  color: #0066cc;
  margin: 0.5rem 0;
}

.amount-section p {
  margin: 0.5rem 0 0;
  color: #666;
  font-size: 0.85rem;
}

.notes-section {
  background: #e7f3ff;
  border-left: 4px solid #0066cc;
  padding: 1rem;
  border-radius: 4px;
  margin-bottom: 1.5rem;
}

.notes-section h4 {
  margin: 0 0 0.5rem;
  color: #0066cc;
  font-size: 0.9rem;
}

.notes-section ul {
  margin: 0;
  padding-left: 1.5rem;
}

.notes-section li {
  margin: 0.4rem 0;
  color: #333;
  font-size: 0.85rem;
}

/* Loading */
.loading-spinner {
  text-align: center;
  padding: 2rem;
}

.spinner {
  border: 4px solid #f0f0f0;
  border-top: 4px solid #0066cc;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.loading-spinner p {
  color: #666;
  margin: 0;
}

/* Success State */
.modal-body.success {
  text-align: center;
}

.success-icon {
  font-size: 3.5rem;
  color: #28a745;
  margin-bottom: 1rem;
}

.modal-body.success h3 {
  color: #28a745;
  font-size: 1.5rem;
  margin-bottom: 1.5rem;
}

.success-details {
  background: #f0fdf4;
  border: 1px solid #28a745;
  border-radius: 6px;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  text-align: left;
}

.detail-row {
  display: flex;
  justify-content: space-between;
  padding: 0.6rem 0;
  border-bottom: 1px solid #e0e0e0;
  font-size: 0.95rem;
}

.detail-row:last-child {
  border-bottom: none;
}

.detail-row.charge {
  color: #856404;
}

.detail-row.credit {
  color: #155724;
}

.success-message {
  background: #e8f5e9;
  border-left: 4px solid #28a745;
  padding: 1rem;
  border-radius: 4px;
  margin-bottom: 1.5rem;
  color: #155724;
  font-size: 0.95rem;
}

.success-message p {
  margin: 0;
}

/* Buttons */
.modal-actions {
  display: flex;
  gap: 1rem;
  justify-content: center;
  margin-top: 2rem;
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

.btn-secondary:hover:not(:disabled) {
  background: #d0d0d0;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Alerts */
.alert {
  padding: 1rem;
  border-radius: 4px;
  margin-top: 1rem;
  font-size: 0.9rem;
}

.alert-error {
  background: #f8d7da;
  border: 1px solid #f5c6cb;
  color: #721c24;
}

/* Responsive */
@media (max-width: 640px) {
  .modal-content {
    margin: 1rem;
    max-height: none;
  }

  .modal-header,
  .modal-body {
    padding: 1.5rem;
  }

  .plans-grid {
    grid-template-columns: 1fr;
  }

  .plan-comparison {
    flex-direction: column;
    gap: 1rem;
  }

  .comparison-arrow {
    transform: rotate(90deg);
    margin: 0;
  }

  .modal-actions {
    flex-direction: column;
  }

  .btn {
    width: 100%;
  }
}
</style>