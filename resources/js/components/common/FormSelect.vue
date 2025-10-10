<template>
    <div class="form-group">
        <label v-if="label" :for="id" class="form-label">
            {{ label }}
            <span v-if="required" class="text-red-500">*</span>
        </label>
        
        <select
            :id="id"
            :value="modelValue"
            @change="$emit('update:modelValue', $event.target.value)"
            :required="required"
            :disabled="disabled"
            :class="['form-select', { 'input-error': error }]"
        >
            <option v-if="placeholder" value="">{{ placeholder }}</option>
            <option
                v-for="option in options"
                :key="option[valueKey]"
                :value="option[valueKey]"
            >
                {{ option[labelKey] }}
            </option>
        </select>
        
        <p v-if="error" class="error-message">{{ error }}</p>
        <p v-else-if="hint" class="hint-message">{{ hint }}</p>
    </div>
</template>

<script setup>
const props = defineProps({
    id: {
        type: String,
        default: () => `select-${Math.random().toString(36).substr(2, 9)}`,
    },
    label: {
        type: String,
        default: '',
    },
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        required: true,
    },
    valueKey: {
        type: String,
        default: 'id',
    },
    labelKey: {
        type: String,
        default: 'name',
    },
    placeholder: {
        type: String,
        default: 'Select an option',
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
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

.form-select {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all disabled:bg-gray-100 disabled:cursor-not-allowed;
}

.input-error {
    @apply border-red-500 focus:ring-red-500;
}

.error-message {
    @apply text-sm text-red-600;
}

.hint-message {
    @apply text-sm text-gray-500;
}
</style>