<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';

export type StockWait = {
    id: number;
    number: string;
    customer: string | null;
    lines: { sku: string; name: string; short: string }[];
};

defineProps<{ orders: StockWait[]; canCancel: boolean }>();

const cancelRest = (o: StockWait) => {
    if (confirm(`Cancel the rest of ${o.number}? What hasn't been dispatched is cancelled.`)) {
        router.post(route('orders.cancel', o.id), { reason: 'No stock' }, { preserveScroll: true });
    }
};
</script>

<template>
    <section v-if="orders.length" class="rounded-lg border border-amber-300 p-2 text-sm dark:border-amber-800">
        <h3 class="font-semibold text-amber-900 dark:text-amber-200">Waiting for stock ({{ orders.length }})</h3>
        <p class="mb-1 text-xs text-muted-foreground">Ready to pick as soon as stock is received or adjusted.</p>
        <ul class="divide-y">
            <li v-for="o in orders" :key="o.id" class="py-1">
                <div class="flex items-center justify-between gap-2">
                    <span class="min-w-0 truncate">
                        <Link :href="route('orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                        {{ o.customer }}
                    </span>
                    <button v-if="canCancel" class="shrink-0 text-xs underline" @click="cancelRest(o)">Cancel rest</button>
                </div>
                <div v-for="l in o.lines" :key="l.sku" class="text-xs text-muted-foreground">
                    <span class="font-mono">{{ l.sku }}</span> {{ l.name }}: {{ l.short }}
                </div>
            </li>
        </ul>
    </section>
</template>
