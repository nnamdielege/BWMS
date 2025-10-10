<template>
    <teleport to="body">
        <transition name="modal">
            <div v-if="show" class="modal-overlay" @click="closeOnOverlay">
                <div class="modal-container" :class="sizeClass" @click.stop>
                    <!-- Header -->
                    <div class="modal-header">
                        <h3 class="modal-title">{{ title }}</h3>
                        <button @click="close" class="modal-close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="modal-body">
                        <slot></slot>
                    </div>

                    <!-- Footer -->
                    <div v-if="$slots.footer" class="modal-footer">
                        <slot name="footer"></slot>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>

<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    size: {
        type: String,
        default: 'medium', // small, medium, large, full
    },
    closeOnOverlayClick: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close', 'update:show']);

const sizeClass = computed(() => `modal-${props.size}`);

const close = () => {
    emit('close');
    emit('update:show', false);
};

const closeOnOverlay = () => {
    if (props.closeOnOverlayClick) {
        close();
    }
};

// Prevent body scroll when modal is open
watch(() => props.show, (newVal) => {
    if (newVal) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});
</script>

<style scoped>
.modal-overlay {
    @apply fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4;
}

.modal-container {
    @apply bg-white rounded-lg shadow-xl max-h-[90vh] flex flex-col;
}

.modal-small {
    @apply w-full max-w-md;
}

.modal-medium {
    @apply w-full max-w-2xl;
}

.modal-large {
    @apply w-full max-w-4xl;
}

.modal-full {
    @apply w-full h-full max-w-none max-h-none rounded-none;
}

.modal-header {
    @apply flex items-center justify-between p-6 border-b border-gray-200;
}

.modal-title {
    @apply text-xl font-semibold text-gray-900;
}

.modal-close {
    @apply p-2 rounded-lg hover:bg-gray-100 transition-colors text-gray-500;
}

.modal-body {
    @apply flex-1 overflow-y-auto p-6;
}

.modal-footer {
    @apply flex items-center justify-end gap-3 p-6 border-t border-gray-200;
}

/* Transition animations */
.modal-enter-active,
.modal-leave-active {
    @apply transition-all duration-300;
}

.modal-enter-from,
.modal-leave-to {
    @apply opacity-0;
}

.modal-enter-from .modal-container,
.modal-leave-to .modal-container {
    @apply transform scale-95;
}
</style>