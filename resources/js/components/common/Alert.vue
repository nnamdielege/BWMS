<template>
    <div v-if="show" :class="['alert', alertClass]" role="alert">
        <div class="alert-icon">
            <svg v-if="type === 'success'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg v-else-if="type === 'error'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg v-else-if="type === 'warning'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <div class="alert-content">
            <p class="alert-title" v-if="title">{{ title }}</p>
            <p class="alert-message">{{ message }}</p>
        </div>
        <button v-if="dismissible" @click="close" class="alert-close">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    type: {
        type: String,
        default: 'info', // success, error, warning, info
    },
    title: {
        type: String,
        default: '',
    },
    message: {
        type: String,
        required: true,
    },
    dismissible: {
        type: Boolean,
        default: true,
    },
    modelValue: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue', 'close']);

const show = ref(props.modelValue);

const alertClass = computed(() => {
    const classes = {
        success: 'alert-success',
        error: 'alert-error',
        warning: 'alert-warning',
        info: 'alert-info',
    };
    return classes[props.type] || classes.info;
});

const close = () => {
    show.value = false;
    emit('update:modelValue', false);
    emit('close');
};
</script>

<style scoped>
.alert {
    @apply flex items-start gap-3 p-4 rounded-lg border;
}

.alert-success {
    @apply bg-green-50 text-green-800 border-green-200;
}

.alert-error {
    @apply bg-red-50 text-red-800 border-red-200;
}

.alert-warning {
    @apply bg-yellow-50 text-yellow-800 border-yellow-200;
}

.alert-info {
    @apply bg-blue-50 text-blue-800 border-blue-200;
}

.alert-icon {
    @apply flex-shrink-0;
}

.alert-content {
    @apply flex-1;
}

.alert-title {
    @apply font-semibold mb-1;
}

.alert-message {
    @apply text-sm;
}

.alert-close {
    @apply flex-shrink-0 p-1 rounded hover:bg-black/5 transition-colors;
}
</style>