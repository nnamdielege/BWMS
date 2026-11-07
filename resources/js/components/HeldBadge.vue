<script setup lang="ts">
import { dayLabel } from '@/lib/deliveryDays';
import { computed } from 'vue';

export type Hold = { pay_by: string | null; overdue: boolean };

const props = defineProps<{ hold: Hold | null }>();
const text = computed(() => {
    if (!props.hold?.pay_by) return 'HELD';
    return props.hold.overdue ? 'HELD · pay-by passed' : `HELD · pay by ${dayLabel(props.hold.pay_by)}`;
});
</script>

<template>
    <span v-if="hold" class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap text-amber-900 dark:bg-amber-950 dark:text-amber-200">{{ text }}</span>
</template>
