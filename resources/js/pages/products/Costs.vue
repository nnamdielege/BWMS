<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Row {
    id: number;
    sku: string;
    name: string;
    base_unit: string;
    avg_cost: string;
    is_active: boolean;
    units: { id: number; name: string; base_qty: number }[];
}
interface PreviewRow {
    line: number;
    sku: string;
    cost: string;
    pack: string;
    pack_label?: string;
    old_cost: string | null;
    new_cost: string | null;
    error: string | null;
}

const props = defineProps<{
    products: Paginated<Row>;
    filters: { q: string; zero: boolean };
    zeroCount: number;
    preview: { token: string; rows: PreviewRow[]; valid: number; errors: number; total: number } | null;
    canEdit: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Costs', href: '/product-costs' },
];

const filter = reactive({ q: props.filters.q, zero: props.filters.zero });
const search = () => router.get(route('product-costs.index'), { q: filter.q, zero: filter.zero ? 1 : 0 }, { preserveState: true, replace: true });

// Inline edit: cost entered per chosen pack; server stores per base unit.
const edits = reactive<Record<number, { cost: string; unit: number }>>({});
const editFor = (p: Row) => (edits[p.id] ??= { cost: '', unit: p.units.find((u) => u.base_qty === 1)?.id ?? p.units[0]?.id });
const save = (p: Row) => {
    const e = editFor(p);
    if (e.cost === '') return;
    router.put(route('product-costs.update', p.id), { cost: e.cost, product_unit_id: e.unit }, { preserveScroll: true, onSuccess: () => delete edits[p.id] });
};

const upload = useForm<{ file: File | null }>({ file: null });
const sendFile = () => upload.post(route('product-costs.preview'), { forceFormData: true, preserveScroll: true });
const apply = () =>
    props.preview &&
    confirm(`Apply ${props.preview.valid} cost change(s)? Rows with errors are skipped.`) &&
    router.post(route('product-costs.apply'), { token: props.preview.token }, { preserveScroll: true });
</script>

<template>
    <Head title="Product costs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-center gap-4">
                <h1 class="text-xl font-semibold">Product costs</h1>
                <span class="rounded bg-amber-100 px-2 py-0.5 text-sm text-amber-900">{{ zeroCount }} product(s) with cost 0</span>
                <a :href="route('product-costs.export')" class="ml-auto text-sm underline">Download zero-cost list (CSV template)</a>
            </header>
            <p class="text-sm text-muted-foreground">
                Costs drive stock valuation (weighted average). They are stored per base unit; you can enter a pack's cost and it is divided by the pack quantity.
                Receipts update cost automatically once purchasing is live.
            </p>

            <section v-if="canEdit" class="space-y-3 rounded-lg border p-4">
                <h2 class="font-semibold">Bulk import</h2>
                <p class="text-sm text-muted-foreground">CSV with header <code>sku,cost,pack</code>. <code>pack</code> is optional: a pack name ("Carton") or quantity ("12"). Blank = base unit.</p>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="sendFile">
                    <input type="file" accept=".csv,text/csv" @change="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <Button type="submit" variant="outline" :disabled="!upload.file || upload.processing">Preview</Button>
                    <span v-if="upload.errors.file" class="text-sm text-red-600">{{ upload.errors.file }}</span>
                </form>

                <div v-if="preview" class="space-y-2">
                    <p class="text-sm">
                        {{ preview.total }} row(s): <strong>{{ preview.valid }}</strong> valid, <strong :class="{ 'text-red-600': preview.errors }">{{ preview.errors }}</strong> with errors.
                        <span v-if="preview.total > preview.rows.length">(showing first {{ preview.rows.length }})</span>
                    </p>
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr><th class="py-1">Line</th><th>SKU</th><th>Entered</th><th>Pack</th><th class="text-right">Old / base</th><th class="text-right">New / base</th><th>Problem</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in preview.rows" :key="r.line" class="border-b" :class="{ 'bg-red-50 dark:bg-red-950': r.error }">
                                <td class="py-1">{{ r.line }}</td>
                                <td class="font-mono">{{ r.sku }}</td>
                                <td>{{ r.cost }}</td>
                                <td>{{ r.pack_label ?? r.pack }}</td>
                                <td class="text-right tabular-nums">{{ r.old_cost }}</td>
                                <td class="text-right tabular-nums">{{ r.new_cost }}</td>
                                <td class="text-red-700">{{ r.error }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <Button :disabled="!preview.valid" @click="apply">Apply {{ preview.valid }} change(s)</Button>
                </div>
            </section>

            <form class="flex flex-wrap items-center gap-3" @submit.prevent="search">
                <Input v-model="filter.q" placeholder="SKU or name" class="w-64" />
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="filter.zero" @change="search" /> Only cost = 0</label>
                <Button type="submit" variant="outline">Search</Button>
            </form>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">SKU</th><th>Name</th><th class="text-right">Cost / base unit</th><th v-if="canEdit">Set cost</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id" class="border-b">
                        <td class="py-2 font-mono">{{ p.sku }}</td>
                        <td>{{ p.name }}</td>
                        <td class="text-right tabular-nums">{{ Number(p.avg_cost).toFixed(4) }} / {{ p.base_unit }}</td>
                        <td v-if="canEdit">
                            <form class="flex items-center gap-1" @submit.prevent="save(p)">
                                <Input v-model="editFor(p).cost" type="number" min="0" step="0.0001" class="h-8 w-28" placeholder="0.00" />
                                <span class="text-xs">per</span>
                                <select v-model="editFor(p).unit" class="h-8 rounded-md border bg-background px-1 text-sm">
                                    <option v-for="u in p.units" :key="u.id" :value="u.id">{{ u.name }}{{ u.base_qty > 1 ? ` ×${u.base_qty}` : '' }}</option>
                                </select>
                                <Button size="sm" variant="outline" type="submit">Save</Button>
                            </form>
                        </td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="4" class="py-6 text-center text-muted-foreground">Nothing to show.</td></tr>
                </tbody>
            </table>
            <Pagination :links="products.links" />
        </div>
    </AppLayout>
</template>
