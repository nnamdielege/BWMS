<script setup lang="ts">
import { dayLabel } from '@/lib/deliveryDays';

defineProps<{ from: string | null }>();
</script>

<template>
    <span v-if="from" class="rounded bg-sky-100 px-1.5 py-0.5 text-xs font-normal whitespace-nowrap text-sky-900 dark:bg-sky-950 dark:text-sky-200">Moved from {{ dayLabel(from) }}</span>
</template>
