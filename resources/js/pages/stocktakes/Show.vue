<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PackQtyInput from '@/components/PackQtyInput.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { fromBase, isBlank, packLabel, toBase, type Pack, type PackEntry } from '@/lib/packs';
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
    pack: Pack;
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

// Counted in the product's largest pack plus loose units, like the count sheet; the server saves base units.
const counts = reactive<Record<number, PackEntry>>(Object.fromEntries(props.lines.map((l) => [l.id, fromBase(l.counted_qty, l.pack.per)])));
const saveCount = (l: Line) => {
    const entry = counts[l.id];
    if (isBlank(entry) || toBase(entry, l.pack.per) === l.counted_qty) return;
    router.put(route('stocktakes.count', [props.stocktake.id, l.id]), { packs: entry.packs, each: entry.each }, { preserveScroll: true, preserveState: true });
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

const exportUrl = (kind: 'results' | 'count-sheet', format: string) => route('stocktakes.export', [props.stocktake.id, kind, format]);

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
                <div class="ml-auto flex gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline">Export</Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-60">
                            <DropdownMenuLabel>Results{{ can.manage ? '' : ' (without costs)' }}</DropdownMenuLabel>
                            <DropdownMenuItem as-child><a :href="exportUrl('results', 'xlsx')">Excel (.xlsx)</a></DropdownMenuItem>
                            <DropdownMenuItem as-child><a :href="exportUrl('results', 'csv')">CSV</a></DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Count sheet, for counters</DropdownMenuLabel>
                            <DropdownMenuItem as-child><a :href="exportUrl('count-sheet', 'xlsx')">Excel (.xlsx)</a></DropdownMenuItem>
                            <DropdownMenuItem as-child><a :href="exportUrl('count-sheet', 'pdf')" target="_blank">PDF to print</a></DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <template v-if="open && can.manage">
                        <Button @click="post">Review done — post variances</Button>
                        <Button variant="ghost" @click="cancel">Cancel</Button>
                    </template>
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
                        <th class="text-right">Snapshot</th><th class="w-56 text-right">Counted</th><th class="text-right">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lines" :key="l.id" class="border-b">
                        <td class="py-1">{{ l.location }}</td>
                        <td class="font-mono">{{ l.product.sku }}</td>
                        <td>{{ l.product.name }}</td>
                        <td>{{ l.batch ?? '' }}</td>
                        <td class="text-right tabular-nums">{{ packLabel(l.snapshot_qty, l.pack) }}</td>
                        <td class="text-right">
                            <PackQtyInput v-if="open && can.count" v-model="counts[l.id]" :pack="l.pack" @commit="saveCount(l)" />
                            <span v-else class="tabular-nums">{{ l.counted_qty === null ? '—' : packLabel(l.counted_qty, l.pack) }}</span>
                        </td>
                        <td class="text-right tabular-nums" :class="{ 'text-red-600': (l.variance ?? 0) < 0, 'text-green-700': (l.variance ?? 0) > 0 }">
                            {{ l.variance === null ? '' : (l.variance > 0 ? '+' : '') + packLabel(l.variance, l.pack) }}
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
