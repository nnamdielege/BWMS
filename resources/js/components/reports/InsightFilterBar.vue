<script setup lang="ts">
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import type { InsightFilters, InsightOptions } from './types';

const props = defineProps<{
    filters: InsightFilters;
    options: InsightOptions;
    /** Route names: the page, and its export (takes {format}). */
    indexRoute: string;
    exportRoute: string;
    /** Other query values the page keeps (e.g. a grouping). */
    extra?: Record<string, string | number | null>;
    supplierLabel?: string;
}>();

const f = reactive({ ...props.filters });
const product = ref(props.options.product);
const query = () => Object.fromEntries(Object.entries({ ...f, ...(props.extra ?? {}) }).filter(([, v]) => v !== null && v !== ''));
const reload = () => router.get(route(props.indexRoute), query(), { preserveState: true, preserveScroll: true, replace: true });
const pick = (p: PickedProduct) => {
    product.value = { id: p.id, sku: p.sku, name: p.name };
    f.product_id = p.id;
    reload();
};
const clearProduct = () => {
    product.value = null;
    f.product_id = null;
    reload();
};
const exportUrl = (format: 'xlsx' | 'csv') => route(props.exportRoute, { format, ...query() });
defineExpose({ reload });
</script>

<template>
    <div class="flex flex-wrap items-end gap-3 text-sm">
        <label>
            <span class="block text-muted-foreground">From</span>
            <input v-model="f.from" type="date" class="h-9 rounded-md border bg-background px-2" @change="reload" />
        </label>
        <label>
            <span class="block text-muted-foreground">To</span>
            <input v-model="f.to" type="date" class="h-9 rounded-md border bg-background px-2" @change="reload" />
        </label>
        <label v-if="options.sites.length > 1">
            <span class="block text-muted-foreground">Site</span>
            <select v-model="f.site_id" class="h-9 rounded-md border bg-background px-2" @change="reload">
                <option :value="null">All sites</option>
                <option v-for="s in options.sites" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
        </label>
        <label>
            <span class="block text-muted-foreground">Range</span>
            <select v-model="f.range_id" class="h-9 max-w-40 rounded-md border bg-background px-2" @change="reload">
                <option :value="null">All ranges</option>
                <option v-for="r in options.ranges" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
        </label>
        <label>
            <span class="block text-muted-foreground">{{ supplierLabel ?? 'Supplier' }}</span>
            <select v-model="f.supplier_id" class="h-9 max-w-44 rounded-md border bg-background px-2" @change="reload">
                <option :value="null">All suppliers</option>
                <option v-for="s in options.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
        </label>
        <div>
            <span class="block text-muted-foreground">SKU</span>
            <div v-if="product" class="flex h-9 items-center gap-2 rounded-md border px-2">
                <span class="font-mono">{{ product.sku }}</span>
                <span class="max-w-40 truncate">{{ product.name }}</span>
                <button type="button" class="text-muted-foreground hover:text-foreground" aria-label="Clear SKU" @click="clearProduct">×</button>
            </div>
            <ProductPicker v-else class="w-56" placeholder="Any SKU: search or scan" @pick="pick" />
        </div>
        <slot />
        <div class="ml-auto">
            Export: <a :href="exportUrl('xlsx')" class="underline">XLSX</a> <a :href="exportUrl('csv')" class="underline">CSV</a>
        </div>
    </div>
</template>
