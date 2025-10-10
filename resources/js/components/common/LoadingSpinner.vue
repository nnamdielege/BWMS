<template>
    <div class="loading-spinner" :class="sizeClass">
        <div class="spinner"></div>
        <p v-if="message" class="loading-message">{{ message }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    size: {
        type: String,
        default: 'medium', // small, medium, large
    },
    message: {
        type: String,
        default: '',
    },
});

const sizeClass = computed(() => `loading-${props.size}`);
</script>

<style scoped>
.loading-spinner {
    @apply flex flex-col items-center justify-center py-8;
}

.spinner {
    @apply border-4 border-gray-200 border-t-indigo-600 rounded-full animate-spin;
}

.loading-small .spinner {
    @apply w-6 h-6;
}

.loading-medium .spinner {
    @apply w-10 h-10;
}

.loading-large .spinner {
    @apply w-16 h-16;
}

.loading-message {
    @apply mt-4 text-gray-600 text-sm;
}
</style>