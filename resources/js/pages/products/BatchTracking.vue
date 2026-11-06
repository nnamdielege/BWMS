<script setup lang="ts">
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import RangeSelector from '@/components/RangeSelector.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

type Row = {
    id: number;
    sku: string;
    name: string;
    range: string | null;
    base_unit: string;
    bins: { location_id: number; bin: string; qty: number }[];
    blocker: string | null;
};

const props = defineProps<{ rows: Row[]; ranges: { name: string; count: number; ids: number[] }[]; today: string }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Batch tracking', href: '/batch-tracking' },
];

const show = (ids: number[]) => router.get(route('batch-tracking.index'), { products: ids.join(',') }, { preserveScroll: true, preserveState: true });
const ids = () => props.rows.map((r) => r.id);

const selectedRange = ref('');
const rangeOptions = computed(() => props.ranges.map((r) => [r.name, r.count] as [string, number]));
const addRange = () => show([...new Set([...ids(), ...(props.ranges.find((r) => r.name === selectedRange.value)?.ids ?? [])])]);
const addProduct = (p: PickedProduct) => show([...new Set([...ids(), p.id])]);
const remove = (id: number) => show(ids().filter((i) => i !== id));

// Per bin: a date, or unknown. Survives adding more products to the preview.
const dates = reactive<Record<string, { unknown: boolean; best_before: string }>>({});
const key = (id: number, location: number) => `${id}-${location}`;
watch(
    () => props.rows,
    (rows) => rows.forEach((r) => r.bins.forEach((b) => (dates[key(r.id, b.location_id)] ??= { unknown: true, best_before: '' }))),
    { immediate: true },
);

const ready = computed(() => props.rows.filter((r) => !r.blocker));
const missing = computed(() => ready.value.flatMap((r) => r.bins.filter((b) => !dates[key(r.id, b.location_id)]?.unknown && !dates[key(r.id, b.location_id)]?.best_before)).length);
const units = (r: Row) => r.bins.reduce((n, b) => n + b.qty, 0);
const totalUnits = computed(() => ready.value.reduce((n, r) => n + units(r), 0));
const past = (k: string) => !dates[k]?.unknown && !!dates[k]?.best_before && dates[k].best_before < props.today;

const apply = () => {
    if (!confirm(`Turn on batch tracking for ${ready.value.length} product(s)? On-hand quantities don't change.`)) return;
    router.post(
        route('batch-tracking.apply'),
        {
            products: ready.value.map((r) => ({
                id: r.id,
                bins: r.bins.map((b) => {
                    const d = dates[key(r.id, b.location_id)];
                    return { location_id: b.location_id, best_before: d.unknown ? null : d.best_before };
                }),
            })),
        },
        { preserveScroll: true },
    );
};
const firstError = computed(() => Object.values((usePage().props.errors ?? {}) as Record<string, string>)[0]);
</script>

<template>
    <Head title="Batch tracking" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <section class="max-w-3xl space-y-2 text-sm">
                <h1 class="text-xl font-semibold">Turn on batch tracking</h1>
                <p>
                    For products that already hold stock. Each bin's stock goes into a batch with lot <code>UNKNOWN</code>, and
                    <strong>on-hand quantities don't change</strong>. Enter the best-before per bin, or leave it unknown: unknown stock never expires and is picked first.
                    After this, receiving asks for a lot and best-before as normal.
                </p>
            </section>

            <section class="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm">
                <RangeSelector v-model="selectedRange" :options="rangeOptions" label="Add a range" @apply="addRange" />
                <div class="w-full max-w-sm">
                    <span class="mb-1 block font-medium">Add one product</span>
                    <ProductPicker @pick="addProduct" />
                </div>
            </section>

            <p v-if="!rows.length" class="text-sm text-muted-foreground">
                Choose a range or a product. Products the <Link :href="route('product-import.index')" class="underline">product import</Link> skipped for having stock
                movements can be sent here from its preview.
            </p>

            <section v-else class="overflow-x-auto rounded-lg border [contain:inline-size]">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="px-3 py-2">Product</th><th>Bin</th><th class="text-right">On hand</th><th class="pl-4">Lot</th><th>Best-before</th><th class="pr-3"></th></tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows" :key="r.id">
                            <tr v-if="r.blocker || !r.bins.length" class="border-b align-top">
                                <td class="px-3 py-2">
                                    <span class="font-mono">{{ r.sku }}</span> {{ r.name }}
                                    <div class="text-xs text-muted-foreground">{{ r.range }}</div>
                                </td>
                                <td colspan="4" class="py-2" :class="r.blocker ? 'text-amber-900 dark:text-amber-200' : 'text-muted-foreground'">
                                    {{ r.blocker ?? 'No stock on hand: only the setting changes.' }}
                                </td>
                                <td class="pr-3 text-right"><button class="text-xs underline" @click="remove(r.id)">Remove</button></td>
                            </tr>
                            <template v-else>
                                <tr v-for="(b, i) in r.bins" :key="b.location_id" class="border-b align-top">
                                    <td class="px-3 py-2">
                                        <template v-if="i === 0">
                                            <span class="font-mono">{{ r.sku }}</span> {{ r.name }}
                                            <div class="text-xs text-muted-foreground">{{ r.range }}</div>
                                        </template>
                                    </td>
                                    <td class="py-2">{{ b.bin }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ b.qty }} {{ r.base_unit }}</td>
                                    <td class="py-2 pl-4 font-mono">UNKNOWN</td>
                                    <td class="py-2">
                                        <label class="mr-3 whitespace-nowrap">
                                            <input v-model="dates[key(r.id, b.location_id)].unknown" type="checkbox" :aria-label="`Unknown best-before ${r.sku} ${b.bin}`" /> Unknown
                                        </label>
                                        <input
                                            v-if="!dates[key(r.id, b.location_id)].unknown"
                                            v-model="dates[key(r.id, b.location_id)].best_before"
                                            type="date"
                                            class="h-8 rounded-md border border-input bg-background px-2"
                                            :aria-label="`Best-before ${r.sku} ${b.bin}`"
                                        />
                                        <div v-if="past(key(r.id, b.location_id))" class="text-xs text-amber-900 dark:text-amber-200">Already past: this stock can't be picked.</div>
                                    </td>
                                    <td class="pr-3 text-right"><button v-if="i === 0" class="text-xs underline" @click="remove(r.id)">Remove</button></td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </section>

            <section v-if="rows.length" class="sticky bottom-0 flex flex-wrap items-center gap-3 border-t bg-background py-3 text-sm">
                <Button :disabled="!ready.length || missing > 0" @click="apply">Turn on for {{ ready.length }} product(s)</Button>
                <span v-if="missing" class="text-red-700 dark:text-red-300">{{ missing }} bin(s) need a date, or tick Unknown.</span>
                <span v-else class="text-muted-foreground">{{ totalUnits }} unit(s) move into batches. Net change: zero.</span>
                <span v-if="firstError" class="text-red-700 dark:text-red-300">{{ firstError }}</span>
            </section>
        </div>
    </AppLayout>
</template>
