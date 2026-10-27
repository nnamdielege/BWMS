<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';

type Plan = {
    product_id: number;
    sku: string;
    name: string;
    base_unit_before: string;
    base_unit: string;
    base_pack_name: string;
    packs: { name: string; qty: number }[];
    legacy_pack: string | null;
    factor: number;
    avg_cost_before: string;
    avg_cost_after: string;
    listings: number;
    po_lines: number;
};

const props = defineProps<{
    defaultCount: number;
    defaultProducts: { id: number; sku: string; name: string }[];
    preview: { token: string; plans: Plan[]; errors: string[] } | null;
    canEdit: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Pack setup', href: '/product-packs' },
];

const upload = useForm<{ file: File | null }>({ file: null });
const sendFile = () => upload.post(route('product-packs.preview'), { forceFormData: true, preserveScroll: true });
const apply = () =>
    props.preview &&
    confirm(`Set up packs for ${props.preview.plans.length} product(s)? Rows with errors are skipped.`) &&
    router.post(route('product-packs.apply'), { token: props.preview.token }, { preserveScroll: true });
</script>

<template>
    <Head title="Pack setup" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <section class="max-w-3xl space-y-2 text-sm">
                <h1 class="text-xl font-semibold">Pack setup (before the stocktake)</h1>
                <p>
                    Migrated products start with one pack called "Unit": the Ordermentum sell unit, often a carton. Set each product's real base unit and packs
                    <strong>before</strong> importing the stocktake: pack sizes are fixed once stock exists.
                </p>
                <p class="text-muted-foreground">
                    CSV columns: <code>sku, base_unit, pack_name, qty_per_pack, legacy_unit</code>. One row per pack. Mark with
                    <code>legacy_unit=yes</code> the pack the old "Unit" really was. Its Ordermentum listing, legacy PO lines and barcode move to that pack, and
                    the cost per base unit is divided accordingly. Example: <code>DASH-VAN, bottle, Bottle, 1</code> and
                    <code>DASH-VAN, bottle, Carton, 6, yes</code>.
                </p>
                <a :href="route('product-packs.export')" class="inline-block underline">Download the current packs as a template</a>
            </section>

            <section v-if="defaultCount" class="rounded-lg border border-amber-300 p-4">
                <h2 class="mb-2 font-semibold text-amber-800">{{ defaultCount }} product(s) still on the default single "Unit" pack</h2>
                <p class="mb-2 text-sm text-muted-foreground">Fine if they really are bought, counted and sold as one unit. Otherwise set them up before the stocktake.</p>
                <ul class="columns-2 text-sm md:columns-3">
                    <li v-for="p in defaultProducts" :key="p.id"><span class="font-mono">{{ p.sku }}</span> {{ p.name }}</li>
                </ul>
            </section>

            <form v-if="canEdit" class="flex flex-wrap items-center gap-2" @submit.prevent="sendFile">
                <input type="file" accept=".csv,text/csv" @input="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                <Button type="submit" size="sm" :disabled="!upload.file || upload.processing">Preview</Button>
                <span v-if="upload.errors.file" class="text-sm text-red-700">{{ upload.errors.file }}</span>
            </form>

            <section v-if="preview" class="rounded-lg border p-4">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Preview: {{ preview.plans.length }} product(s), {{ preview.errors.length }} error(s)</h2>
                    <Button v-if="canEdit && preview.plans.length" size="sm" @click="apply">Apply</Button>
                </div>
                <ul v-if="preview.errors.length" class="mb-3 list-disc pl-5 text-sm text-red-700">
                    <li v-for="(e, i) in preview.errors" :key="i">{{ e }}</li>
                </ul>
                <table v-if="preview.plans.length" class="w-full text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr><th class="py-1">SKU</th><th>Base unit</th><th>Packs</th><th>Old "Unit" becomes</th><th class="text-right">Cost / base</th><th>Moves</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in preview.plans" :key="p.product_id" class="border-t">
                            <td class="py-1 font-mono">{{ p.sku }}</td>
                            <td>{{ p.base_unit_before }} → {{ p.base_unit }} ({{ p.base_pack_name }})</td>
                            <td>{{ p.packs.map((k) => `${k.name} ×${k.qty}`).join(', ') || '—' }}</td>
                            <td>{{ p.legacy_pack ? `${p.legacy_pack} (×${p.factor})` : 'the base unit' }}</td>
                            <td class="text-right tabular-nums">{{ p.avg_cost_before }} → {{ p.avg_cost_after }}</td>
                            <td class="text-muted-foreground">{{ p.factor > 1 ? `${p.listings} listing(s), ${p.po_lines} PO line(s)` : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
