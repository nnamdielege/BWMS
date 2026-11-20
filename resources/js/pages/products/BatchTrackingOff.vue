<script setup lang="ts">
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import RangeSelector from '@/components/RangeSelector.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Batch = { batch_id: number | null; lot: string | null; best_before: string | null; qty: number; expired: boolean };
type Row = {
    id: number;
    sku: string;
    name: string;
    range: string | null;
    base_unit: string;
    bins: { location_id: number; bin: string; qty: number; batches: Batch[] }[];
    expired: { bin: string; lot: string | null; best_before: string | null; qty: number }[];
    blocker: string | null;
};

const props = defineProps<{ rows: Row[]; ranges: { name: string; count: number; ids: number[] }[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Batch tracking', href: '/batch-tracking' },
];

const show = (ids: number[]) =>
    router.get(route('batch-tracking.index'), { mode: 'off', products: ids.join(',') }, { preserveScroll: true, preserveState: true });
const ids = () => props.rows.map((r) => r.id);
const selectedRange = ref('');
const rangeOptions = computed(() => props.ranges.map((r) => [r.name, r.count] as [string, number]));
const addRange = () => show([...new Set([...ids(), ...(props.ranges.find((r) => r.name === selectedRange.value)?.ids ?? [])])]);
const addProduct = (p: PickedProduct) => show([...new Set([...ids(), p.id])]);
const remove = (id: number) => show(ids().filter((i) => i !== id));

const includeExpired = ref(false);
const ready = computed(() => props.rows.filter((r) => !r.blocker));
const withExpired = computed(() => ready.value.filter((r) => r.expired.length));
const going = computed(() => (includeExpired.value ? ready.value : ready.value.filter((r) => !r.expired.length)));
const label = (b: Batch) => (b.batch_id === null ? 'no batch' : `${b.lot}${b.best_before ? ` · BB ${b.best_before}` : ''}`);

const apply = () => {
    const skipped = ready.value.length - going.value.length;
    if (
        !confirm(
            `Turn off batch tracking for ${going.value.length} product(s)? Each bin's batches merge into one balance; on-hand doesn't change.${skipped ? ` ${skipped} with expired stock will be skipped.` : ''}`,
        )
    )
        return;
    router.post(
        route('batch-tracking.disable'),
        { products: going.value.map((r) => r.id), include_expired: includeExpired.value },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Turn off batch tracking" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <section class="max-w-3xl space-y-2 text-sm">
                <div class="flex flex-wrap items-baseline gap-3">
                    <h1 class="text-xl font-semibold">Turn off batch tracking</h1>
                    <Link :href="route('batch-tracking.index')" class="underline">Turn it on instead</Link>
                </div>
                <p>
                    In each bin, all the product's batches merge into one balance with no lot or best-before.
                    <strong>On-hand quantities don't change.</strong>
                    Afterwards receiving stops asking for a lot and best-before, and picking no longer goes by best-before.
                </p>
            </section>

            <section class="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm">
                <RangeSelector v-model="selectedRange" :options="rangeOptions" label="Add a range" @apply="addRange" />
                <div class="w-full max-w-sm">
                    <span class="mb-1 block font-medium">Add one product</span>
                    <ProductPicker @pick="addProduct" />
                </div>
            </section>

            <p v-if="!rows.length" class="text-sm text-muted-foreground">Choose a range or a batch-tracked product.</p>

            <section v-else class="overflow-x-auto rounded-lg border [contain:inline-size]">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2">Product</th>
                            <th>Bin</th>
                            <th>Batches merged</th>
                            <th class="text-right">On hand after</th>
                            <th class="pr-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows" :key="r.id">
                            <tr v-if="r.blocker || !r.bins.length" class="border-b align-top">
                                <td class="px-3 py-2">
                                    <span class="font-mono">{{ r.sku }}</span> {{ r.name }}
                                    <div class="text-xs text-muted-foreground">{{ r.range }}</div>
                                </td>
                                <td colspan="3" class="py-2" :class="r.blocker ? 'text-amber-900 dark:text-amber-200' : 'text-muted-foreground'">
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
                                            <div v-if="r.expired.length" class="mt-1 text-xs text-amber-900 dark:text-amber-200">
                                                Has expired stock: skipped unless "include expired" is ticked.
                                            </div>
                                        </template>
                                    </td>
                                    <td class="py-2">{{ b.bin }}</td>
                                    <td class="py-2">
                                        <div
                                            v-for="x in b.batches"
                                            :key="x.batch_id ?? 0"
                                            :class="x.expired ? 'text-amber-900 dark:text-amber-200' : ''"
                                        >
                                            {{ x.qty }} {{ r.base_unit }} · {{ label(x) }}<template v-if="x.expired"> (expired)</template>
                                        </div>
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ b.qty }} {{ r.base_unit }}</td>
                                    <td class="pr-3 text-right">
                                        <button v-if="i === 0" class="text-xs underline" @click="remove(r.id)">Remove</button>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </section>

            <section v-if="withExpired.length" class="max-w-3xl rounded-lg border border-amber-400 p-4 text-sm dark:border-amber-600">
                <h2 class="font-semibold">Expired stock</h2>
                <p class="mb-2">
                    Merging hides the best-before, so expired stock would become sellable again. Write it off first, or include it on purpose.
                </p>
                <ul class="mb-2 list-disc pl-5">
                    <template v-for="r in withExpired" :key="r.id">
                        <li v-for="(e, i) in r.expired" :key="i">
                            <span class="font-mono">{{ r.sku }}</span> {{ e.qty }} {{ r.base_unit }} in {{ e.bin }}, lot {{ e.lot }}, best-before
                            {{ e.best_before }}
                        </li>
                    </template>
                </ul>
                <label class="flex items-center gap-2"><input v-model="includeExpired" type="checkbox" /> Include expired stock in the merge</label>
            </section>

            <section v-if="rows.length" class="sticky bottom-0 flex flex-wrap items-center gap-3 border-t bg-background py-3 text-sm">
                <Button :disabled="!going.length" @click="apply">Turn off for {{ going.length }} product(s)</Button>
                <span class="text-muted-foreground">Net change: zero.</span>
            </section>
        </div>
    </AppLayout>
</template>
