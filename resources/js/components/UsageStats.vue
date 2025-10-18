<template>
  <div class="usage-stats-container">
    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-8">
      <div class="spinner"></div>
      <span class="ml-3">Loading usage statistics...</span>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
      {{ error }}
    </div>

    <!-- Usage Stats -->
    <div v-else class="space-y-6">
      <!-- Header -->
      <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-start mb-4">
          <div>
            <h2 class="text-2xl font-bold text-gray-800">Usage Statistics</h2>
            <p class="text-gray-600 text-sm mt-1">{{ planName }} Plan</p>
          </div>
          <button
            @click="refreshStats"
            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 transition"
          >
            🔄 Refresh
          </button>
        </div>

        <!-- Billing Cycle Info -->
        <div class="bg-blue-50 rounded p-4">
          <p class="text-sm text-gray-600">
            <strong>Billing Cycle:</strong> {{ formatDate(billingCycleStart) }} to {{ formatDate(billingCycleEnd) }}
          </p>
          <p class="text-sm text-gray-600 mt-1">
            <strong>Days Remaining:</strong> {{ daysRemaining }} days
          </p>
        </div>
      </div>

      <!-- Usage Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="stat in stats"
          :key="stat.action_type"
          class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition"
        >
          <!-- Title -->
          <h3 class="text-lg font-semibold text-gray-800 mb-2">
            {{ stat.description }}
          </h3>

          <!-- Usage Bar -->
          <div class="mb-4">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm text-gray-600">
                {{ stat.current }} / {{ stat.limit }}
              </span>
              <span
                :class="['text-sm font-bold', getStatusClass(stat.percentage)]"
              >
                {{ stat.percentage }}%
              </span>
            </div>

            <!-- Progress Bar -->
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
              <div
                class="h-full rounded-full transition-all duration-300"
                :class="getBarColor(stat.percentage)"
                :style="{ width: stat.percentage + '%' }"
              ></div>
            </div>
          </div>

          <!-- Remaining Info -->
          <div
            :class="[
              'p-3 rounded text-sm',
              stat.remaining > 0
                ? 'bg-green-50 text-green-700'
                : 'bg-red-50 text-red-700'
            ]"
          >
            <span v-if="stat.remaining > 0">
              ✅ {{ stat.remaining }} remaining
            </span>
            <span v-else>
              ⚠️ Limit reached!
            </span>
          </div>

          <!-- Status Icon -->
          <div class="mt-3 text-right">
            <span
              v-if="stat.percentage < 50"
              class="inline-block px-2 py-1 bg-green-100 text-green-800 text-xs rounded font-semibold"
            >
              Good
            </span>
            <span
              v-else-if="stat.percentage < 80"
              class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded font-semibold"
            >
              Caution
            </span>
            <span
              v-else
              class="inline-block px-2 py-1 bg-red-100 text-red-800 text-xs rounded font-semibold"
            >
              Critical
            </span>
          </div>
        </div>
      </div>

      <!-- Upgrade Suggestion -->
      <div
        v-if="hasLimitExceeded"
        class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded"
      >
        <p class="text-sm text-yellow-700">
          <strong>⚠️ You've reached your usage limit for one or more features.</strong>
          Consider upgrading your plan to get higher limits.
        </p>
      </div>

      <!-- Upgrade CTA -->
      <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow p-6 text-white">
        <h3 class="text-lg font-bold mb-2">Need more capacity?</h3>
        <p class="text-blue-100 mb-4 text-sm">
          Upgrade your plan to unlock higher limits and more features.
        </p>
        <router-link
          to="/pricing"
          class="inline-block px-4 py-2 bg-white text-blue-600 rounded font-semibold hover:bg-blue-50 transition"
        >
          View Plans →
        </router-link>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

export default {
  name: 'UsageStats',
  
  setup() {
    const loading = ref(true);
    const error = ref(null);
    const stats = ref([]);
    const planName = ref('');
    const billingCycleStart = ref(null);
    const billingCycleEnd = ref(null);

    const daysRemaining = computed(() => {
      if (!billingCycleEnd.value) return 0;
      const end = new Date(billingCycleEnd.value);
      const today = new Date();
      const diff = end - today;
      return Math.max(0, Math.ceil(diff / (1000 * 60 * 60 * 24)));
    });

    const hasLimitExceeded = computed(() => {
      return stats.value.some(stat => stat.remaining === 0);
    });

    const formatDate = (date) => {
      if (!date) return '';
      return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      });
    };

    const getBarColor = (percentage) => {
      if (percentage < 50) return 'bg-green-500';
      if (percentage < 80) return 'bg-yellow-500';
      return 'bg-red-500';
    };

    const getStatusClass = (percentage) => {
      if (percentage < 50) return 'text-green-600';
      if (percentage < 80) return 'text-yellow-600';
      return 'text-red-600';
    };

    const fetchUsageStats = async () => {
      try {
        loading.value = true;
        error.value = null;

        const response = await axios.get('/api/v1/usage/stats');
        
        stats.value = response.data.stats;
        planName.value = response.data.plan_name;
        billingCycleStart.value = response.data.billing_cycle_start;
        billingCycleEnd.value = response.data.billing_cycle_end;
      } catch (err) {
        error.value = err.response?.data?.message || 'Failed to load usage statistics';
        console.error(err);
      } finally {
        loading.value = false;
      }
    };

    const refreshStats = () => {
      fetchUsageStats();
    };

    onMounted(() => {
      fetchUsageStats();
    });

    return {
      loading,
      error,
      stats,
      planName,
      billingCycleStart,
      billingCycleEnd,
      daysRemaining,
      hasLimitExceeded,
      formatDate,
      getBarColor,
      getStatusClass,
      refreshStats,
    };
  },
};
</script>

<style scoped>
.usage-stats-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem 0;
}

.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #3498db;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>