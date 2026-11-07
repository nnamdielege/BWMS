<script setup lang="ts">
import HeldBadge, { type Hold } from '@/components/HeldBadge.vue';
import MovedBadge from '@/components/MovedBadge.vue';
import SourceBadge from '@/components/SourceBadge.vue';

export type ManifestRow = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    customer: string | null;
    channel: string;
    moved_from: string | null;
    hold: Hold | null;
    address: string;
    cartons: number;
    packed: boolean;
};

defineProps<{ order: ManifestRow }>();
</script>

<template>
    <li class="flex items-start gap-3 p-3">
        <div class="flex-1">
            <div class="font-mono font-semibold">
                {{ order.number }} <span class="ml-1 text-xs font-normal text-muted-foreground">{{ order.status_label }}</span> <SourceBadge :channel="order.channel" />
            </div>
            <div class="text-sm">{{ order.customer }}</div>
            <div class="text-xs text-muted-foreground">{{ order.address || 'No address' }}</div>
            <MovedBadge :from="order.moved_from" />
            <HeldBadge :hold="order.hold" />
        </div>
        <div class="text-right text-sm">
            <div class="tabular-nums">{{ order.cartons }} ctn</div>
            <div :class="order.packed ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300'">{{ order.packed ? 'packed' : 'not packed' }}</div>
        </div>
    </li>
</template>
