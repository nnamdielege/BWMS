<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ channel: string }>();
const label = computed(() => ({ ordermentum: 'Ordermentum', portal: 'Portal', manual: 'Manual' })[props.channel] ?? props.channel);
</script>

<template>
    <span class="rounded border px-1.5 py-0.5 text-xs font-normal whitespace-nowrap text-muted-foreground">{{ label }}</span>
</template>
