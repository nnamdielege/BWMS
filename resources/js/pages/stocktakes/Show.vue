<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

interface Line {
    id: number;
    product: { id: number; sku: string; name: string; base_unit: string };
    location: string;
    batch: string | null;
    snapshot_qty: number;
    counted_qty: number | null;
    system_qty_at_count: number | null;
    variance: number | null;
}

const props = defineProps<{
    stocktake: { id: number; number: string; status: string; scope: string; note: string | null; snapshot_at: string; posted_at: string | null };
    lines: Line[];
    bins: { id: number; label: string }[];
    can: { count: boolean; manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stocktakes', href: '/stocktakes' },
    { title: props.stocktake.number, href: '#' },
];
const open = computed(() => props.stocktake.status === 'counting');
const errors = computed(() => usePage().props.errors as Record<string, string>);

const counts = reactive<Record<number, number | null>>(Object.fromEntries(props.lines.map((l) => [l.id, l.counted_qty])));
const saveCount = (l: Line) => {
    const value = counts[l.id];
    if (value === null || value === undefined || value === l.counted_qty) return;
    router.put(route('stocktakes.count', [props.stocktake.id, l.id]), { counted_qty: value }, { preserveScroll: true, preserveState: true });
};

const uncounted = computed(() => props.lines.filter((l) => l.counted_qty === null).length);
const netVariance = computed(() => props.lines.reduce((s, l) => s + (l.variance ?? 0), 0));
const post = () => {
    const zero = uncounted.value > 0 && confirm(`${uncounted.value} line(s) are uncounted. Post them as ZERO?`);
    if (uncounted.value > 0 && !zero) return;
    if (!confirm('Post all variances to the stock ledger? This cannot be undone.')) return;
    router.post(route('stocktakes.post', props.stocktake.id), { zero_uncounted: zero }, { preserveScroll: true });
};
const cancel = () => confirm('Cancel this stocktake? No stock will change.') && router.post(route('stocktakes.cancel', props.stocktake.id));

const found = useForm({ sku: '', location_id: null as number | null, lot_code: '', best_before: '' });
const addFound = () => found.post(route('stocktakes.lines.store', props.stocktake.id), { preserveScroll: true, onSuccess: () => found.reset() });
</script>

<template>
    <Head :title="stocktake.number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-center gap-4">
                <h1 class="text-xl font-semibold">{{ stocktake.number }}</h1>
                <span class="text-sm">{{ stocktake.scope }}</span>
                <span class="rounded bg-muted px-2 py-0.5 text-sm capitalize">{{ stocktake.status }}</span>
                <span class="text-sm text-muted-foreground">{{ uncounted }} uncounted · net variance {{ netVariance > 0 ? '+' : '' }}{{ netVariance }}</span>
                <div v-if="open && can.manage" class="ml-auto flex gap-2">
                    <Button @click="post">Review done — post variances</Button>
                    <Button variant="ghost" @click="cancel">Cancel</Button>
                </div>
            </header>
            <p class="text-xs text-muted-foreground">
                Work can continue during the count. Variance = counted − system quantity at the moment you enter the count, so re-enter a count if you recount later.
            </p>
            <InputError :message="errors.stocktake" />

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Location</th><th>SKU</th><th>Product</th><th>Batch</th>
                        <th class="text-right">Snapshot</th><th class="w-32 text-right">Counted</th><th class="text-right">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lines" :key="l.id" class="border-b">
                        <td class="py-1">{{ l.location }}</td>
                        <td class="font-mono">{{ l.product.sku }}</td>
                        <td>{{ l.product.name }}</td>
                        <td>{{ l.batch ?? '' }}</td>
                        <td class="text-right tabular-nums">{{ l.snapshot_qty }}</td>
                        <td class="text-right">
                            <Input
                                v-if="open && can.count"
                                v-model.number="counts[l.id]"
                                type="number"
                                min="0"
                                class="h-8 w-28 text-right"
                                @blur="saveCount(l)"
                                @keydown.enter.prevent="saveCount(l)"
                            />
                            <span v-else class="tabular-nums">{{ l.counted_qty ?? '—' }}</span>
                        </td>
                        <td class="text-right tabular-nums" :class="{ 'text-red-600': (l.variance ?? 0) < 0, 'text-green-700': (l.variance ?? 0) > 0 }">
                            {{ l.variance === null ? '' : (l.variance > 0 ? '+' : '') + l.variance }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <form v-if="open && can.count" class="flex flex-wrap items-end gap-2 rounded-lg border p-3" @submit.prevent="addFound">
                <span class="text-sm font-medium">Found something not listed?</span>
                <Input v-model="found.sku" placeholder="SKU" class="w-36 font-mono uppercase" required />
                <select v-model="found.location_id" class="h-9 rounded-md border bg-background px-2 text-sm" required>
                    <option :value="null" disabled>Bin…</option>
                    <option v-for="b in bins" :key="b.id" :value="b.id">{{ b.label }}</option>
                </select>
                <Input v-model="found.lot_code" placeholder="Lot (if tracked)" class="w-36" />
                <Input v-model="found.best_before" type="date" class="w-40" />
                <Button type="submit" variant="outline" :disabled="found.processing">Add line</Button>
                <InputError :message="found.errors.sku || found.errors.location_id" class="w-full" />
            </form>
        </div>
    </AppLayout>
</template>
