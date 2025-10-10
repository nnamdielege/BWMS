<template>
    <span :class="['badge', variantClass, sizeClass]">
        <slot></slot>
    </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'default', // default, success, error, warning, info
    },
    size: {
        type: String,
        default: 'medium', // small, medium, large
    },
});

const variantClass = computed(() => {
    const variants = {
        default: 'badge-default',
        success: 'badge-success',
        error: 'badge-error',
        warning: 'badge-warning',
        info: 'badge-info',
    };
    return variants[props.variant] || variants.default;
});

const sizeClass = computed(() => {
    const sizes = {
        small: 'badge-small',
        medium: 'badge-medium',
        large: 'badge-large',
    };
    return sizes[props.size] || sizes.medium;
});
</script>

<style scoped>
.badge {
    @apply inline-flex items-center justify-center font-semibold rounded-full;
}

.badge-small {
    @apply px-2 py-0.5 text-xs;
}

.badge-medium {
    @apply px-2.5 py-1 text-sm;
}

.badge-large {
    @apply px-3 py-1.5 text-base;
}

.badge-default {
    @apply bg-gray-100 text-gray-800;
}

.badge-success {
    @apply bg-green-100 text-green-800;
}

.badge-error {
    @apply bg-red-100 text-red-800;
}

.badge-warning {
    @apply bg-yellow-100 text-yellow-800;
}

.badge-info {
    @apply bg-blue-100 text-blue-800;
}
</style>