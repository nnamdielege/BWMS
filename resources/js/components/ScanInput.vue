<script setup lang="ts">
/**
 * Scan input for warehouse handhelds. Primary: built-in scanners in keyboard-wedge
 * mode, which "type" the code and press Enter. The field stays focused (re-focused
 * after a tap elsewhere) and uses inputmode="none" so the soft keyboard doesn't pop
 * up. Fallback: the phone camera via the browser BarcodeDetector API (HTTPS only).
 * Reused later by the driver app screens on the same handhelds.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ placeholder?: string; disabled?: boolean }>(), { placeholder: 'Scan…', disabled: false });
const emit = defineEmits<{ scan: [code: string] }>();

const input = ref<HTMLInputElement | null>(null);
const value = ref('');
const keyboard = ref(false); // show the soft keyboard for typing a code by hand
const camera = ref(false);
const cameraError = ref('');
const video = ref<HTMLVideoElement | null>(null);
let stream: MediaStream | null = null;
let timer: number | undefined;

const focus = () => {
    if (!props.disabled && !camera.value) input.value?.focus({ preventScroll: true });
};
const submit = () => {
    const code = value.value.trim();
    value.value = '';
    if (code) emit('scan', code);
};
// Keep focus unless the user moved to another field (number inputs, selects).
const onBlur = () => {
    window.setTimeout(() => {
        const active = document.activeElement;
        if (!active || active === document.body) focus();
    }, 150);
};

const cameraSupported = typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices;
const startCamera = async () => {
    cameraError.value = '';
    try {
        const detector = new (window as any).BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf'] });
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        camera.value = true;
        await new Promise((r) => requestAnimationFrame(r));
        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play();
        }
        timer = window.setInterval(async () => {
            if (!video.value) return;
            const codes = await detector.detect(video.value).catch(() => []);
            if (codes.length) {
                stopCamera();
                emit('scan', String(codes[0].rawValue).trim());
            }
        }, 250);
    } catch (e) {
        cameraError.value = 'Camera not available: ' + (e instanceof Error ? e.message : String(e));
        stopCamera();
    }
};
const stopCamera = () => {
    window.clearInterval(timer);
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    camera.value = false;
    focus();
};

onMounted(focus);
onBeforeUnmount(stopCamera);
defineExpose({ focus });
</script>

<template>
    <div class="space-y-2">
        <div class="flex gap-2">
            <input
                ref="input"
                v-model="value"
                :placeholder="placeholder"
                :disabled="disabled"
                :inputmode="keyboard ? 'text' : 'none'"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                class="h-14 w-full rounded-lg border-2 border-primary/40 bg-background px-3 font-mono text-lg focus:border-primary focus:outline-none"
                @keydown.enter.prevent="submit"
                @blur="onBlur"
            />
            <button type="button" class="h-14 shrink-0 rounded-lg border px-3 text-sm" :aria-pressed="keyboard" @click="(keyboard = !keyboard), focus()">⌨</button>
            <button v-if="cameraSupported" type="button" class="h-14 shrink-0 rounded-lg border px-3 text-sm" @click="camera ? stopCamera() : startCamera()">
                {{ camera ? 'Stop' : '📷' }}
            </button>
        </div>
        <video v-if="camera" ref="video" class="w-full rounded-lg bg-black" playsinline muted />
        <p v-if="cameraError" class="text-sm text-red-700">{{ cameraError }}</p>
    </div>
</template>
