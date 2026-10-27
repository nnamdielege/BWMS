<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Local Selection roundel (public/images/logo.png). It has its own light fill, so it reads on light and dark backgrounds. -->
    <img src="/images/logo.png" alt="Local Selection" :class="['object-contain', className]" v-bind="$attrs" />
</template>
