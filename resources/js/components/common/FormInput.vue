<template>
    <div class="form-group">
        <label v-if="label" :for="id" class="form-label">
            {{ label }}
            <span v-if="required" class="text-red-500">*</span>
        </label>
        
        <div class="input-wrapper">
            <input
                :id="id"
                :type="type"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :readonly="readonly"
                :class="['form-input', { 'input-error': error }]"
            />
            <div v-if="$slots.append" class="input-append">
                <slot name="append"></slot>
            </div>
        </div>
        
        <p v-if="error" class="error-message">{{ error }}</p>
        <p v-else-if="hint" class="hint-message">{{ hint }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    id: {
        type: String,
        default: () => `input-${Math.random().toString(36).substr(2, 9)}`,
    },
    label: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    modelValue: {
        type: [String, Number],
        default: '',
    },
    placeholder: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    readonly: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    hint: {
        type: String,
        default: '',
    },
});

defineEmits(['update:modelValue']);
</script>

<style scoped>
.form-group {
    @apply space-y-2;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}

.input-wrapper {
    @apply relative;
}

.form-input {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all disabled:bg-gray-100 disabled:cursor-not-allowed;
}

.input-error {
    @apply border-red-500 focus:ring-red-500;
}

.input-append {
    @apply absolute right-3 top-1/2 transform -translate-y-1/2;
}

.error-message {
    @apply text-sm text-red-600;
}

.hint-message {
    @apply text-sm text-gray-500;
}
</style>