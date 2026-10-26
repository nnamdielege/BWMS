<script setup lang="ts">
export interface MovementRow {
    id: number;
    occurred_at: string;
    type: string;
    type_label: string;
    qty: number;
    product: { id: number; sku: string; name: string } | null;
    location: string;
    batch: string | null;
    reason: string | null;
    note: string | null;
    user: string | null;
    source: string | null;
}

defineProps<{ movements: MovementRow[]; showProduct?: boolean }>();
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
</script>

<template>
    <table class="w-full text-left text-sm">
        <thead class="border-b text-muted-foreground">
            <tr>
                <th class="py-2">When (Brisbane)</th><th v-if="showProduct">Product</th><th>Type</th><th class="text-right">Qty</th>
                <th>Location</th><th>Batch</th><th>Reason / note</th><th>Source</th><th>By</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="m in movements" :key="m.id" class="border-b align-top">
                <td class="whitespace-nowrap py-2">{{ fmt(m.occurred_at) }}</td>
                <td v-if="showProduct" class="font-mono">{{ m.product?.sku }}</td>
                <td>{{ m.type_label }}</td>
                <td class="text-right tabular-nums" :class="m.qty < 0 ? 'text-red-600' : 'text-green-700'">{{ m.qty > 0 ? '+' : '' }}{{ m.qty }}</td>
                <td>{{ m.location }}</td>
                <td>{{ m.batch ?? '' }}</td>
                <td>{{ [m.reason, m.note].filter(Boolean).join(' — ') }}</td>
                <td>{{ m.source ?? '' }}</td>
                <td>{{ m.user ?? 'system' }}</td>
            </tr>
            <tr v-if="!movements.length"><td colspan="9" class="py-6 text-center text-muted-foreground">No movements.</td></tr>
        </tbody>
    </table>
</template>
