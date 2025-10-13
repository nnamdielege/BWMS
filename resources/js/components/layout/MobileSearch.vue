<template>
    <teleport to="body">
        <transition name="modal">
            <div v-if="show" class="mobile-search-modal" @click.self="handleBackdropClick">
                <div class="modal-content">
                    <!-- Modal Header -->
                    <div class="modal-header">
                        <h3 class="modal-title">Search</h3>
                        <button @click="$emit('close')" class="close-btn">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Search Content -->
                    <div class="modal-body">
                        <GlobalSearch @navigate="$emit('close')" />
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>

<script setup>
import { watch, onMounted, onBeforeUnmount } from 'vue';
import GlobalSearch from './GlobalSearch.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const handleBackdropClick = () => {
    emit('close');
};

const handleEscape = (event) => {
    if (event.key === 'Escape' && props.show) {
        emit('close');
    }
};

// Prevent body scroll when modal is open
watch(() => props.show, (newValue) => {
    if (newValue) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>

<style scoped>
.mobile-search-modal {
    @apply fixed inset-0 z-50 flex items-start justify-center bg-black bg-opacity-50 p-4 overflow-y-auto;
    padding-top: 2rem;
}

.modal-content {
    @apply relative bg-white rounded-lg shadow-2xl w-full max-w-lg;
    max-height: calc(100vh - 4rem);
    display: flex;
    flex-direction: column;
}

.modal-header {
    @apply flex items-center justify-between px-4 py-3 border-b border-gray-200 flex-shrink-0;
}

.modal-title {
    @apply text-lg font-semibold text-gray-900;
}

.close-btn {
    @apply p-1 text-gray-400 hover:text-gray-600 transition-colors;
}

.modal-body {
    @apply p-4 overflow-y-auto flex-1;
}

/* Modal animation */
.modal-enter-active {
    transition: all 0.3s ease;
}

.modal-leave-active {
    transition: all 0.2s ease;
}

.modal-enter-from {
    opacity: 0;
    transform: scale(0.95) translateY(-20px);
}

.modal-leave-to {
    opacity: 0;
    transform: scale(0.95);
}

.modal-enter-from .modal-content,
.modal-leave-to .modal-content {
    transform: scale(0.95) translateY(-20px);
}

.modal-enter-to .modal-content {
    transform: scale(1) translateY(0);
}

/* Responsive adjustments */
@media (max-width: 640px) {
    .mobile-search-modal {
        @apply p-0;
        padding-top: 0;
    }

    .modal-content {
        @apply rounded-none h-full;
        max-height: 100vh;
    }
}
</style>