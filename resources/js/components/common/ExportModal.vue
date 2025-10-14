<template>
    <Modal :show="show" @close="$emit('close')" title="Export Data" size="medium">
        <div class="export-modal">
            <!-- Export Type Info -->
            <div class="info-section">
                <div class="info-icon">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <h3 class="info-title">Export {{ title }}</h3>
                    <p class="info-description">Download your data in Excel or CSV format</p>
                </div>
            </div>

            <!-- Format Selection -->
            <div class="form-section">
                <label class="form-label">Export Format</label>
                <div class="format-options">
                    <button
                        @click="selectedFormat = 'xlsx'"
                        :class="['format-btn', { active: selectedFormat === 'xlsx' }]"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Excel (.xlsx)</span>
                    </button>
                    <button
                        @click="selectedFormat = 'csv'"
                        :class="['format-btn', { active: selectedFormat === 'csv' }]"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>CSV (.csv)</span>
                    </button>
                </div>
            </div>

            <!-- Additional Options (if provided) -->
            <div v-if="$slots.filters" class="form-section">
                <label class="form-label">Filters</label>
                <slot name="filters"></slot>
            </div>

            <!-- Export Stats -->
            <div class="stats-section">
                <div class="stat-item">
                    <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <div>
                        <p class="stat-label">Total Records</p>
                        <p class="stat-value">{{ totalRecords }}</p>
                    </div>
                </div>
            </div>

            <!-- Loading State -->
            <div v-if="loading" class="loading-overlay">
                <div class="spinner"></div>
                <p>Preparing export...</p>
            </div>
        </div>

        <template #footer>
            <button @click="$emit('close')" class="btn btn-secondary" :disabled="loading">
                Cancel
            </button>
            <button @click="handleExport" class="btn btn-primary" :disabled="loading">
                <svg v-if="loading" class="animate-spin w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                <span>{{ loading ? 'Exporting...' : 'Export' }}</span>
            </button>
        </template>
    </Modal>
</template>

<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';

const props = defineProps({
    show: Boolean,
    title: {
        type: String,
        default: 'Data'
    },
    totalRecords: {
        type: Number,
        default: 0
    }
});

const emit = defineEmits(['close', 'export']);

const selectedFormat = ref('xlsx');
const loading = ref(false);

const handleExport = () => {
    emit('export', selectedFormat.value);
};

defineExpose({
    setLoading: (value) => {
        loading.value = value;
    }
});
</script>

<style scoped>
.export-modal {
    @apply space-y-6;
}

.info-section {
    @apply flex items-start gap-4 p-4 bg-indigo-50 rounded-lg;
}

.info-icon {
    @apply flex-shrink-0;
}

.info-title {
    @apply text-lg font-semibold text-gray-900;
}

.info-description {
    @apply text-sm text-gray-600 mt-1;
}

.form-section {
    @apply space-y-2;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}

.format-options {
    @apply grid grid-cols-2 gap-3;
}

.format-btn {
    @apply flex items-center gap-2 px-4 py-3 border-2 border-gray-300 rounded-lg hover:border-indigo-500 transition-all cursor-pointer;
}

.format-btn.active {
    @apply border-indigo-600 bg-indigo-50;
}

.stats-section {
    @apply p-4 bg-gray-50 rounded-lg;
}

.stat-item {
    @apply flex items-center gap-3;
}

.stat-icon {
    @apply w-8 h-8 text-gray-400;
}

.stat-label {
    @apply text-sm text-gray-600;
}

.stat-value {
    @apply text-lg font-semibold text-gray-900;
}

.loading-overlay {
    @apply absolute inset-0 bg-white bg-opacity-90 flex flex-col items-center justify-center;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.btn {
    @apply flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed;
}
</style>