<template>
    <Modal :show="show" @close="handleClose" title="Import Data" size="medium">
        <div class="import-modal">
            <!-- Step 1: Upload File -->
            <div v-if="currentStep === 1" class="step-content">
                <!-- Info -->
                <div class="info-section">
                    <div class="info-icon">
                        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="info-title">Import {{ title }}</h3>
                        <p class="info-description">Upload an Excel or CSV file to import your data</p>
                    </div>
                </div>

                <!-- Download Template -->
                <div class="template-section">
                    <p class="text-sm text-gray-600 mb-2">Don't have a file? Download our template:</p>
                    <button @click="downloadTemplate" class="btn btn-secondary btn-sm" :disabled="downloadingTemplate">
                        <svg v-if="downloadingTemplate" class="animate-spin w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        <span>{{ downloadingTemplate ? 'Downloading...' : 'Download Template' }}</span>
                    </button>
                </div>

                <!-- File Upload -->
                <div class="upload-section">
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        @change="handleFileSelect"
                        class="hidden"
                    />

                    <div
                        v-if="!selectedFile"
                        @click="$refs.fileInput.click()"
                        @dragover.prevent
                        @drop.prevent="handleFileDrop"
                        class="upload-area"
                    >
                        <svg class="upload-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p class="upload-text">Click to upload or drag and drop</p>
                        <p class="upload-hint">Excel (.xlsx, .xls) or CSV files only (Max: 10MB)</p>
                    </div>

                    <div v-else class="file-preview">
                        <div class="file-icon">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="file-info">
                            <p class="file-name">{{ selectedFile.name }}</p>
                            <p class="file-size">{{ formatFileSize(selectedFile.size) }}</p>
                        </div>
                        <button @click="removeFile" class="remove-btn">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Import Options -->
                <div class="options-section">
                    <label class="checkbox-label">
                        <input v-model="updateExisting" type="checkbox" class="checkbox" />
                        <span>Update existing records (match by unique identifier)</span>
                    </label>
                </div>
            </div>

            <!-- Step 2: Results -->
            <div v-if="currentStep === 2" class="step-content">
                <!-- Success State -->
                <div v-if="importResults.success" class="success-section">
                    <svg class="success-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h3 class="success-title">Import Successful!</h3>
                    <p class="success-message">{{ importResults.message }}</p>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <p class="stat-label">Total Rows</p>
                            <p class="stat-value">{{ importResults.total_rows || 0 }}</p>
                        </div>
                        <div class="stat-card success">
                            <p class="stat-label">Successfully Imported</p>
                            <p class="stat-value">{{ importResults.success_count || 0 }}</p>
                        </div>
                        <div v-if="importResults.failures && importResults.failures.length > 0" class="stat-card error">
                            <p class="stat-label">Failed Records</p>
                            <p class="stat-value">{{ importResults.failures.length }}</p>
                        </div>
                    </div>
                </div>

                <!-- Error State -->
                <div v-else class="error-section">
                    <svg class="error-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h3 class="error-title">Import Failed</h3>
                    <p class="error-message">{{ importResults.message }}</p>
                    
                    <!-- General Error Details -->
                    <div v-if="importResults.error" class="error-detail-box">
                        <h4 class="error-detail-title">Error Details:</h4>
                        <p class="error-detail-text">{{ importResults.error }}</p>
                    </div>
                </div>

                <!-- Validation Errors -->
                <div v-if="importResults.failures && importResults.failures.length > 0" class="errors-list">
                    <h4 class="errors-title">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        Validation Errors ({{ importResults.failures.length }} rows):
                    </h4>
                    <div class="error-items">
                        <div v-for="(failure, index) in importResults.failures.slice(0, 10)" :key="index" class="error-item">
                            <div class="error-item-header">
                                <span class="error-row">Row {{ failure.row }}</span>
                                <span v-if="failure.attribute" class="error-attribute">{{ failure.attribute }}</span>
                            </div>
                            <ul class="error-messages">
                                <li v-for="(error, idx) in failure.errors" :key="idx" class="error-text">
                                    {{ error }}
                                </li>
                            </ul>
                            <div v-if="failure.values" class="error-values">
                                <span class="values-label">Row Data:</span>
                                <code class="values-code">{{ JSON.stringify(failure.values).substring(0, 100) }}...</code>
                            </div>
                        </div>
                        <p v-if="importResults.failures.length > 10" class="text-sm text-gray-500 mt-3 text-center">
                            And {{ importResults.failures.length - 10 }} more errors... 
                            <span class="text-indigo-600 font-medium">Fix the errors above and try again.</span>
                        </p>
                    </div>
                </div>

                <!-- Tips for Fixing Errors -->
                <div v-if="!importResults.success" class="tips-section">
                    <h4 class="tips-title">💡 Common Issues & Solutions:</h4>
                    <ul class="tips-list">
                        <li>✓ Ensure all required fields (SKU, Name, Price) are filled</li>
                        <li>✓ Check that numeric fields contain only numbers</li>
                        <li>✓ Verify column headers match the template exactly</li>
                        <li>✓ Remove any empty rows at the end of the file</li>
                        <li>✓ Make sure file format is .xlsx, .xls, or .csv</li>
                    </ul>
                </div>
            </div>

            <!-- Loading State -->
            <div v-if="importing" class="loading-overlay">
                <div class="spinner"></div>
                <p class="loading-text">Importing data...</p>
                <p class="loading-subtext">This may take a few moments</p>
            </div>
        </div>

        <template #footer>
            <button v-if="currentStep === 1" @click="handleClose" class="btn btn-secondary" :disabled="importing">
                Cancel
            </button>
            <button v-if="currentStep === 1" @click="handleImport" class="btn btn-primary" :disabled="!selectedFile || importing">
                <svg v-if="importing" class="animate-spin w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
                <span>{{ importing ? 'Importing...' : 'Import' }}</span>
            </button>
            <button v-if="currentStep === 2" @click="handleClose" class="btn btn-primary">
                {{ importResults.success ? 'Done' : 'Close' }}
            </button>
            <button v-if="currentStep === 2 && !importResults.success" @click="resetImport" class="btn btn-secondary">
                Try Again
            </button>
        </template>
    </Modal>
</template>

<script setup>
import { ref } from 'vue';
import Modal from './Modal.vue';
import exportImportService from '../../services/exportImportService';

const props = defineProps({
    show: Boolean,
    title: {
        type: String,
        default: 'Data'
    },
    type: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['close', 'imported']);

const fileInput = ref(null);
const selectedFile = ref(null);
const updateExisting = ref(true);
const importing = ref(false);
const downloadingTemplate = ref(false);
const currentStep = ref(1);
const importResults = ref({});

const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        // Validate file size (10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB');
            return;
        }
        
        // Validate file type
        const validTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv'
        ];
        
        if (!validTypes.includes(file.type) && !file.name.match(/\.(xlsx|xls|csv)$/i)) {
            alert('Invalid file type. Please upload .xlsx, .xls, or .csv file');
            return;
        }
        
        selectedFile.value = file;
    }
};

const handleFileDrop = (event) => {
    const file = event.dataTransfer.files[0];
    if (file && (file.name.endsWith('.xlsx') || file.name.endsWith('.xls') || file.name.endsWith('.csv'))) {
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB');
            return;
        }
        selectedFile.value = file;
    } else {
        alert('Please drop a valid Excel or CSV file');
    }
};

const removeFile = () => {
    selectedFile.value = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const downloadTemplate = async () => {
    try {
        downloadingTemplate.value = true;
        const response = await exportImportService.downloadTemplate(props.type);
        const filename = `${props.type}_import_template.xlsx`;
        exportImportService.downloadFile(response.data, filename);
    } catch (error) {
        console.error('Download template error:', error);
        alert('Failed to download template: ' + (error.response?.data?.message || error.message));
    } finally {
        downloadingTemplate.value = false;
    }
};

const handleImport = async () => {
    if (!selectedFile.value) return;

    try {
        importing.value = true;

        let response;
        if (props.type === 'products') {
            response = await exportImportService.importProducts(selectedFile.value);
        } else if (props.type === 'customers') {
            response = await exportImportService.importCustomers(selectedFile.value);
        }

        importResults.value = {
            success: true,
            ...response.data
        };
        currentStep.value = 2;
        emit('imported');

    } catch (error) {
        console.error('Import error:', error);
        console.error('Error response:', error.response);
        
        importResults.value = {
            success: false,
            message: error.response?.data?.message || 'Import failed. Please check your file and try again.',
            error: error.response?.data?.error || error.message,
            failures: error.response?.data?.failures || [],
            errors: error.response?.data?.errors || [],
            success_count: error.response?.data?.success_count || 0,
            total_rows: error.response?.data?.total_rows || 0,
        };
        currentStep.value = 2;
    } finally {
        importing.value = false;
    }
};

const resetImport = () => {
    currentStep.value = 1;
    removeFile();
    importResults.value = {};
};

const handleClose = () => {
    selectedFile.value = null;
    updateExisting.value = true;
    importing.value = false;
    currentStep.value = 1;
    importResults.value = {};
    if (fileInput.value) {
        fileInput.value.value = '';
    }
    emit('close');
};
</script>

<style scoped>
.import-modal {
    @apply relative min-h-[300px];
}

.step-content {
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

.template-section {
    @apply p-4 bg-gray-50 rounded-lg;
}

.upload-section {
    @apply space-y-4;
}

.upload-area {
    @apply border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-indigo-500 transition-colors;
}

.upload-icon {
    @apply w-12 h-12 mx-auto text-gray-400 mb-4;
}

.upload-text {
    @apply text-sm font-medium text-gray-700 mb-1;
}

.upload-hint {
    @apply text-xs text-gray-500;
}

.file-preview {
    @apply flex items-center gap-4 p-4 bg-green-50 border border-green-200 rounded-lg;
}

.file-icon {
    @apply flex-shrink-0;
}

.file-info {
    @apply flex-1 min-w-0;
}

.file-name {
    @apply text-sm font-medium text-gray-900 truncate;
}

.file-size {
    @apply text-xs text-gray-500;
}

.remove-btn {
    @apply p-1 text-red-600 hover:text-red-800 transition-colors;
}

.options-section {
    @apply space-y-2;
}

.checkbox-label {
    @apply flex items-center gap-2 cursor-pointer;
}

.checkbox {
    @apply w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500;
}

.success-section,
.error-section {
    @apply text-center py-8;
}

.success-icon {
    @apply w-16 h-16 mx-auto text-green-600 mb-4;
}

.error-icon {
    @apply w-16 h-16 mx-auto text-red-600 mb-4;
}

.success-title {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.error-title {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.success-message,
.error-message {
    @apply text-gray-600 mb-6;
}

.error-detail-box {
    @apply mt-4 p-4 bg-red-50 border border-red-200 rounded-lg text-left;
}

.error-detail-title {
    @apply text-sm font-semibold text-red-900 mb-2;
}

.error-detail-text {
    @apply text-sm text-red-700 font-mono;
}

.stats-grid {
    @apply grid grid-cols-2 md:grid-cols-3 gap-4 max-w-2xl mx-auto;
}

.stat-card {
    @apply p-4 bg-gray-50 rounded-lg;
}

.stat-card.success {
    @apply bg-green-50 border border-green-200;
}

.stat-card.error {
    @apply bg-red-50 border border-red-200;
}

.stat-label {
    @apply text-xs text-gray-600 mb-1 font-medium;
}

.stat-value {
    @apply text-2xl font-bold text-gray-900;
}

.errors-list {
    @apply mt-6 p-4 bg-red-50 border border-red-200 rounded-lg max-h-96 overflow-y-auto;
}

.errors-title {
    @apply text-sm font-semibold text-red-900 mb-3 flex items-center;
}

.error-items {
    @apply space-y-3;
}

.error-item {
    @apply p-3 bg-white border border-red-200 rounded-lg;
}

.error-item-header {
    @apply flex items-center gap-2 mb-2;
}

.error-row {
    @apply font-semibold text-red-700 text-sm;
}

.error-attribute {
    @apply text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded;
}

.error-messages {
    @apply list-disc list-inside space-y-1 mb-2;
}

.error-text {
    @apply text-sm text-red-600;
}

.error-values {
    @apply mt-2 p-2 bg-gray-100 rounded text-xs;
}

.values-label {
    @apply font-medium text-gray-700;
}

.values-code {
    @apply block mt-1 text-gray-600 overflow-hidden text-ellipsis;
}

.tips-section {
    @apply mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg;
}

.tips-title {
    @apply text-sm font-semibold text-blue-900 mb-2;
}

.tips-list {
    @apply space-y-1 text-sm text-blue-800;
}

.loading-overlay {
    @apply absolute inset-0 bg-white bg-opacity-95 flex flex-col items-center justify-center rounded-lg z-10;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.loading-text {
    @apply text-gray-900 font-medium;
}

.loading-subtext {
    @apply text-sm text-gray-500 mt-2;
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

.btn-sm {
    @apply px-3 py-1.5 text-sm;
}
</style>