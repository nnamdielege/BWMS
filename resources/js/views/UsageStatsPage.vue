<template>
  <div class="min-h-screen bg-gray-50">
    <div class="container mx-auto px-4 py-8">
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Usage & Limits</h1>
        <p class="text-gray-600 mt-2">
          Monitor your usage and upgrade your plan when needed.
        </p>
      </div>

      <UsageStats />
    </div>
  </div>
</template>

<script>
import UsageStats from '@/components/UsageStats.vue';

export default {
  name: 'UsageStatsPage',
  components: {
    UsageStats,
  },
};
</script>