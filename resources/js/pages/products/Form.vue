<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type UnitInput = {
    id?: number;
    name: string;
    code: string | null;
    base_qty: number;
    purchasable: boolean;
    sellable: boolean;
    default_purchase: boolean;
    default_sale: boolean;
    barcodes: string[];
};

interface ProductData {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    category_id: number | null;
    range_id: number | null;
    base_unit: string;
    is_batch_tracked: boolean;
    shelf_life_days: number | null;
    short_dated_days: number | null;
    reorder_point: number | null;
    reorder_qty: number | null;
    supplier_id: number | null;
    lead_time_days: number | null;
    order_multiple_base: number | null;
    safety_stock_days: number | null;
    auto_reorder: boolean;
    gst_free: boolean;
    sell_price: string | null;
    is_active: boolean;
    sell_channels: string;
    is_stock_tracked: boolean;
    is_chilled: boolean;
    units: (Omit<UnitInput, 'barcodes'> & { barcodes: { id: number; barcode: string }[] })[];
}

const props = defineProps<{
    product: ProductData | null;
    categories: { id: number; name: string }[];
    ranges: { id: number; name: string }[];
    suppliers: { id: number; name: string }[];
    holdsStock?: boolean;
    openPickLists?: number;
}>();

const can = useCan();
const readOnly = computed(() => !can('products.manage'));
const isNew = !props.product;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: props.product ? props.product.sku : 'New product', href: '#' },
];

const p = props.product;
const form = useForm({
    sku: p?.sku ?? '',
    name: p?.name ?? '',
    description: p?.description ?? '',
    category_id: p?.category_id ?? null,
    range_id: p?.range_id ?? null,
    base_unit: p?.base_unit ?? 'each',
    is_batch_tracked: p?.is_batch_tracked ?? false,
    shelf_life_days: p?.shelf_life_days ?? null,
    short_dated_days: p?.short_dated_days ?? null,
    reorder_point: p?.reorder_point ?? null,
    reorder_qty: p?.reorder_qty ?? null,
    supplier_id: p?.supplier_id ?? null,
    lead_time_days: p?.lead_time_days ?? null,
    order_multiple_base: p?.order_multiple_base ?? null,
    safety_stock_days: p?.safety_stock_days ?? null,
    auto_reorder: p?.auto_reorder ?? true,
    gst_free: p?.gst_free ?? false,
    sell_price: p?.sell_price ?? null,
    is_active: p?.is_active ?? true,
    sell_channels: p?.sell_channels ?? 'ordermentum',
    is_stock_tracked: p?.is_stock_tracked ?? true,
    is_chilled: p?.is_chilled ?? false,
    units: (p?.units.map((u) => ({ ...u, barcodes: u.barcodes.map((b) => b.barcode) })) ?? [
        { name: 'Each', code: null, base_qty: 1, purchasable: true, sellable: true, default_purchase: true, default_sale: true, barcodes: [] },
    ]) as UnitInput[],
});

const addRange = () => {
    const name = prompt('New range name (e.g. "Dash syrups")')?.trim();
    if (name) router.post(route('ranges.store'), { name }, { preserveState: true, preserveScroll: true, only: ['ranges', 'flash', 'errors'] });
};

const addUnit = () =>
    form.units.push({
        name: 'Carton',
        code: null,
        base_qty: 12,
        purchasable: true,
        sellable: true,
        default_purchase: false,
        default_sale: false,
        barcodes: [],
    });
const removeUnit = (i: number) => form.units.splice(i, 1);

// The sell price is for one sale pack (as on Ordermentum): the default sale pack, else the largest
// sellable pack, else the smallest. Same choice as the reports (PackPricing::reportPack).
const salePack = computed(
    () =>
        form.units.find((u) => u.default_sale) ??
        [...form.units].filter((u) => u.sellable).sort((a, b) => b.base_qty - a.base_qty)[0] ??
        [...form.units].sort((a, b) => a.base_qty - b.base_qty)[0],
);
const salePackLabel = computed(() => {
    const u = salePack.value;
    if (!u || !u.name) return form.base_unit || 'unit';
    return u.base_qty > 1 ? `${u.name} (${u.base_qty})` : u.name;
});
// Per base unit, read-only; only worth showing when the sale pack holds more than one. 4 places, trailing zeros past 2 dropped.
const perUnit = computed(() => {
    const price = Number(form.sell_price);
    const qty = salePack.value?.base_qty ?? 1;
    if (qty <= 1 || !(price > 0)) return null;
    return (price / qty)
        .toFixed(4)
        .replace(/(\.\d\d\d?)0+$/, '$1')
        .replace(/(\.\d\d)0$/, '$1');
});
const addBarcode = (u: UnitInput) => u.barcodes.push('');

// Saved barcodes are changed or removed one at a time (managers, admins), straight away and audited;
// new ones typed in here save with the product.
const savedId = (u: UnitInput, code: string) => props.product?.units.find((x) => x.id === u.id)?.barcodes.find((b) => b.barcode === code)?.id ?? null;
const editing = ref<{ id: number; value: string } | null>(null);
const barcodeError = ref<string | null>(null);
const savedCount = (u: UnitInput) => u.barcodes.filter((c) => savedId(u, c)).length;
const pickWarning = (u: UnitInput) =>
    savedCount(u) === 1 && props.openPickLists
        ? `\n\nWarning: ${u.name} will have no barcode, and ${props.openPickLists} open pick list(s) still need this product. Pickers will have to enter the quantity.`
        : '';
const barcodeVisit = {
    preserveScroll: true,
    preserveState: true,
    onError: (e: Record<string, string>) => (barcodeError.value = e.barcode ?? Object.values(e)[0]),
};
const changeBarcode = (u: UnitInput, b: number) => {
    const id = savedId(u, u.barcodes[b]);
    const value = editing.value?.value.trim() ?? '';
    if (!id || !value || value === u.barcodes[b]) return (editing.value = null);
    if (!confirm(`Change barcode ${u.barcodes[b]} to ${value} on ${u.name}?`)) return;
    barcodeError.value = null;
    router.put(
        route('barcodes.update', id),
        { barcode: value },
        { ...barcodeVisit, onSuccess: () => ((u.barcodes[b] = value), (editing.value = null)) },
    );
};
const removeBarcode = (u: UnitInput, b: number) => {
    const id = savedId(u, u.barcodes[b]);
    if (!id) return u.barcodes.splice(b, 1);
    if (!confirm(`Remove barcode ${u.barcodes[b]} from ${u.name}?${pickWarning(u)}`)) return;
    barcodeError.value = null;
    router.delete(route('barcodes.destroy', id), { ...barcodeVisit, onSuccess: () => u.barcodes.splice(b, 1) });
};
const setDefault = (field: 'default_purchase' | 'default_sale', i: number) => form.units.forEach((u, j) => (u[field] = i === j));

const submit = () => (isNew ? form.post(route('products.store')) : form.put(route('products.update', p!.id), { preserveScroll: true }));
const unitErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([k]) => k.startsWith('units'))
        .map(([, v]) => v),
);
</script>

<template>
    <Head :title="product ? product.sku : 'New product'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="max-w-4xl space-y-6 p-4" @submit.prevent="submit">
            <fieldset :disabled="readOnly" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="sku">SKU</Label>
                    <Input id="sku" v-model="form.sku" class="font-mono uppercase" required />
                    <InputError :message="form.errors.sku" />
                </div>
                <div class="grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1">
                    <Label for="category">Category</Label>
                    <select id="category" v-model="form.category_id" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option :value="null">—</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <Label for="range">Range (optional, groups flavours in lists)</Label>
                    <div class="flex gap-2">
                        <select id="range" v-model="form.range_id" class="h-9 flex-1 rounded-md border bg-background px-2 text-sm">
                            <option :value="null">—</option>
                            <option v-for="r in ranges" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <Button v-if="!readOnly" type="button" variant="outline" size="sm" @click="addRange">New</Button>
                    </div>
                </div>
                <div class="grid gap-1">
                    <Label for="base_unit">Base unit (what one stock unit is)</Label>
                    <Input id="base_unit" v-model="form.base_unit" placeholder="each, bottle, carton…" required />
                    <InputError :message="form.errors.base_unit" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="description">Description</Label>
                    <textarea id="description" v-model="form.description" rows="2" class="rounded-md border bg-background p-2 text-sm" />
                </div>
                <div class="grid gap-1">
                    <Label for="reorder_point">Reorder point (base units)</Label>
                    <Input id="reorder_point" v-model.number="form.reorder_point" type="number" min="0" />
                </div>
                <div class="grid gap-1">
                    <Label for="reorder_qty">Reorder quantity (base units)</Label>
                    <Input id="reorder_qty" v-model.number="form.reorder_qty" type="number" min="0" />
                </div>
                <div class="grid gap-1">
                    <Label for="supplier_id">Preferred supplier</Label>
                    <select id="supplier_id" v-model="form.supplier_id" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option :value="null">— (use the last PO's)</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>
                <div class="grid gap-1">
                    <Label for="lead_time_days">Lead time override (days)</Label>
                    <Input id="lead_time_days" v-model.number="form.lead_time_days" type="number" min="0" placeholder="the supplier's" />
                    <InputError :message="form.errors.lead_time_days" />
                </div>
                <div class="grid gap-1">
                    <Label for="order_multiple_base">Supplier pack / order multiple ({{ form.base_unit }})</Label>
                    <Input
                        id="order_multiple_base"
                        v-model.number="form.order_multiple_base"
                        type="number"
                        min="1"
                        placeholder="the default purchase pack"
                    />
                    <span class="text-xs text-muted-foreground">Reorder suggestions round up to this.</span>
                    <InputError :message="form.errors.order_multiple_base" />
                </div>
                <div class="grid gap-1">
                    <Label for="safety_stock_days">Safety stock override (days)</Label>
                    <Input id="safety_stock_days" v-model.number="form.safety_stock_days" type="number" min="0" placeholder="the reorder setting" />
                    <InputError :message="form.errors.safety_stock_days" />
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm"
                        ><input type="checkbox" v-model="form.auto_reorder" /> Include in suggested POs</label
                    >
                </div>
                <div class="grid content-end gap-1">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.gst_free" /> GST-free</label>
                    <span class="text-xs text-muted-foreground">New PO lines start at 0% GST instead of 10%.</span>
                    <InputError :message="form.errors.gst_free" />
                </div>
                <div class="grid gap-1">
                    <Label for="sell_price">Sell price per {{ salePackLabel }}</Label>
                    <div class="flex items-center gap-3">
                        <Input id="sell_price" v-model="form.sell_price" type="number" min="0" step="0.01" class="max-w-40" />
                        <span class="text-sm tabular-nums text-muted-foreground" aria-live="polite">
                            {{ perUnit !== null ? `= $${perUnit} per ${form.base_unit || 'unit'}` : '' }}
                        </span>
                    </div>
                    <span class="text-xs text-muted-foreground"
                        >Ex GST, for the whole {{ salePackLabel }} (as Ordermentum's base price), not one {{ form.base_unit || 'unit' }}.</span
                    >
                    <InputError :message="form.errors.sell_price" />
                </div>
                <div class="flex flex-wrap items-end gap-x-6 gap-y-2 sm:col-span-2">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" /> Active</label>
                    <Link
                        v-if="holdsStock && !product?.is_batch_tracked"
                        :href="route('batch-tracking.index', { products: product?.id })"
                        class="text-sm underline"
                        >Turn on batch tracking</Link
                    >
                    <label v-else class="flex items-center gap-2 text-sm"
                        ><input type="checkbox" v-model="form.is_batch_tracked" /> Track batches &amp; best-before</label
                    >
                    <InputError :message="form.errors.is_batch_tracked" />
                    <label class="flex items-center gap-2 text-sm"
                        ><input type="checkbox" v-model="form.is_stock_tracked" /> Stock-tracked (untick for services: no stock, stocktakes, picking
                        or pushes)</label
                    >
                    <InputError :message="form.errors.is_stock_tracked" />
                    <label class="flex items-center gap-2 text-sm"
                        ><input type="checkbox" v-model="form.is_chilled" /> Chilled (milks, juices, kombucha: picked last, no refrigerated
                        transport)</label
                    >
                    <div class="grid gap-1">
                        <Label for="sell_channels">Sold on</Label>
                        <select id="sell_channels" v-model="form.sell_channels" class="h-9 rounded-md border bg-background px-2 text-sm">
                            <option value="none">None (purchase / internal)</option>
                            <option value="ordermentum">Ordermentum</option>
                            <option value="portal">Portal</option>
                            <option value="both">Ordermentum and portal</option>
                        </select>
                    </div>
                </div>
                <template v-if="form.is_batch_tracked">
                    <div class="grid gap-1">
                        <Label for="shelf_life_days">Typical shelf life (days)</Label>
                        <Input id="shelf_life_days" v-model.number="form.shelf_life_days" type="number" min="1" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="short_dated_days">Flag as short-dated within (days)</Label>
                        <Input id="short_dated_days" v-model.number="form.short_dated_days" type="number" min="0" />
                    </div>
                </template>
            </fieldset>

            <section>
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Pack sizes &amp; barcodes</h2>
                    <Button v-if="!readOnly" type="button" variant="outline" size="sm" @click="addUnit">Add pack size</Button>
                </div>
                <p class="mb-2 text-xs text-muted-foreground">
                    Quantity is how many base units ({{ form.base_unit }}) are in the pack. Exactly one pack size has quantity 1. A saved pack's
                    quantity cannot be changed.
                </p>
                <InputError v-for="(e, i) in unitErrors" :key="i" :message="e" />
                <InputError v-if="barcodeError" :message="barcodeError" />

                <fieldset :disabled="readOnly" class="space-y-3">
                    <div v-for="(u, i) in form.units" :key="u.id ?? `new-${i}`" class="grid gap-2 rounded-md border p-3 sm:grid-cols-6">
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Name</Label>
                            <Input v-model="u.name" required />
                        </div>
                        <div class="grid gap-1">
                            <Label>Qty in base units</Label>
                            <Input v-model.number="u.base_qty" type="number" min="1" :disabled="!!u.id" required />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Pack code (optional)</Label>
                            <Input v-model="u.code" class="font-mono" />
                        </div>
                        <div class="flex items-end justify-end">
                            <Button v-if="!readOnly && u.base_qty !== 1" type="button" variant="ghost" size="sm" @click="removeUnit(i)"
                                >Remove</Button
                            >
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm sm:col-span-6">
                            <label class="flex items-center gap-1"><input type="checkbox" v-model="u.purchasable" /> Purchasable</label>
                            <label class="flex items-center gap-1"><input type="checkbox" v-model="u.sellable" /> Sellable</label>
                            <label class="flex items-center gap-1"
                                ><input type="radio" :checked="u.default_purchase" @change="setDefault('default_purchase', i)" /> Default for
                                POs</label
                            >
                            <label class="flex items-center gap-1"
                                ><input type="radio" :checked="u.default_sale" @change="setDefault('default_sale', i)" /> Default for sales</label
                            >
                        </div>
                        <div class="flex flex-wrap items-center gap-2 sm:col-span-6">
                            <span class="text-sm text-muted-foreground">Barcodes:</span>
                            <template v-for="(code, b) in u.barcodes" :key="b">
                                <!-- Saved: change or remove it on its own, straight away. -->
                                <span v-if="savedId(u, code)" class="flex items-center gap-1 rounded-md border px-2 py-1 text-sm">
                                    <template v-if="editing && editing.id === savedId(u, code)">
                                        <Input
                                            v-model="editing.value"
                                            class="h-7 w-44 font-mono"
                                            aria-label="New barcode"
                                            @keydown.enter.prevent="changeBarcode(u, b)"
                                        />
                                        <Button type="button" size="sm" variant="outline" class="h-7" @click="changeBarcode(u, b)">Save</Button>
                                        <Button type="button" size="sm" variant="ghost" class="h-7" @click="editing = null">Cancel</Button>
                                    </template>
                                    <template v-else>
                                        <span class="font-mono">{{ code }}</span>
                                        <template v-if="!readOnly">
                                            <button type="button" class="text-xs underline" @click="editing = { id: savedId(u, code)!, value: code }">
                                                Change
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs text-red-700 underline dark:text-red-400"
                                                @click="removeBarcode(u, b)"
                                            >
                                                Remove
                                            </button>
                                        </template>
                                    </template>
                                </span>
                                <Input v-else v-model="u.barcodes[b]" class="w-44 font-mono" placeholder="Scan or type" />
                            </template>
                            <Button v-if="!readOnly" type="button" variant="ghost" size="sm" @click="addBarcode(u)">+ barcode</Button>
                        </div>
                    </div>
                </fieldset>
            </section>

            <div class="flex gap-2">
                <Button v-if="!readOnly" type="submit" :disabled="form.processing">{{ isNew ? 'Create product' : 'Save' }}</Button>
                <Button variant="ghost" as-child><Link :href="route('products.index')">Back</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
