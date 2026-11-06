<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

type Group = 'new' | 'update' | 'mapping' | 'conflict' | 'skipped';
type Row = {
    key: string;
    line: number | null;
    sku: string;
    name: string;
    product: { id: number | null; sku: string; name: string } | null;
    matched_by: string | null;
    group: Group;
    reason: string;
    pack_name: string | null;
    pack_size: number | null;
    base_unit: string | null;
    range: string | null;
    categories: string[];
    batch_tracked: boolean;
    sell_ordermentum: boolean;
    sell_portal: boolean;
    included: boolean;
    overwrite: boolean;
    check_pack: boolean;
    suggested: { pack_name: string; pack_size: number; base_unit: string } | null;
    changes: string[];
    diffs: { field: string; label: string; wms: string; incoming: string }[];
    notes: string[];
};
type Summary = Record<Group | 'total' | 'check_pack' | 'needs_tick' | 'barcodes_ignored', number>;
type Preview = {
    token: string;
    source: 'ordermentum' | 'file';
    status: 'fetching' | 'ready' | 'failed';
    error: string | null;
    rows: Row[];
    summary: Summary | null;
    edits: Record<string, Partial<Draft>>;
    expires_at: number;
};
type Draft = Pick<Row, 'pack_name' | 'pack_size' | 'base_unit' | 'range' | 'batch_tracked' | 'sell_ordermentum' | 'sell_portal' | 'included' | 'overwrite'>;

const props = defineProps<{ preview: Preview | null; ranges: string[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Import products', href: '/product-import' },
];

const GROUPS: { key: Group; title: string; hint: string }[] = [
    { key: 'conflict', title: 'Conflict', hint: 'Not imported. Resolve each one, or exclude it and re-check.' },
    { key: 'new', title: 'New', hint: 'Creates the product, its packs and the channel mapping.' },
    { key: 'update', title: 'Update', hint: 'Fills empty WMS fields and adds what is missing. WMS values stay unless the row’s overwrite is ticked.' },
    { key: 'mapping', title: 'Mapping only', hint: 'Adds a channel mapping to an existing product.' },
    { key: 'skipped', title: 'Skipped', hint: 'Nothing to do. Tick Include to bring a row in.' },
];
const FIELDS = ['pack_name', 'pack_size', 'base_unit', 'range', 'batch_tracked', 'sell_ordermentum', 'sell_portal', 'included', 'overwrite'] as const;

const upload = useForm<{ file: File | null }>({ file: null });
const sendFile = () => upload.post(route('product-import.upload'), { forceFormData: true, preserveScroll: true });
const fetchOrdermentum = () => router.post(route('product-import.fetch'), {}, { preserveScroll: true });

const draft = reactive<Record<string, Draft>>({});
const seed = () => {
    Object.keys(draft).forEach((k) => delete draft[k]);
    props.preview?.rows.forEach((r) => {
        draft[r.key] = Object.fromEntries(FIELDS.map((f) => [f, r[f]])) as Draft;
    });
};
watch(() => props.preview?.rows, seed, { immediate: true });

/** Only what differs from the source, plus what earlier re-checks already carry. */
const edits = () => {
    const out: Record<string, Partial<Draft>> = {};
    props.preview?.rows.forEach((r) => {
        const changed: Record<string, unknown> = { ...(props.preview?.edits[r.key] ?? {}) };
        FIELDS.forEach((f) => {
            if ((draft[r.key][f] ?? '') !== (r[f] ?? '')) changed[f] = draft[r.key][f] === '' ? null : draft[r.key][f];
        });
        if (Object.keys(changed).length) out[r.key] = changed as Partial<Draft>;
    });
    return out;
};

const rowsIn = (g: Group) => props.preview?.rows.filter((r) => r.group === g) ?? [];
const needsPack = (r: Row) => r.check_pack && draft[r.key]?.included && !draft[r.key]?.pack_size;
const packEditable = (r: Row) => r.check_pack || r.group === 'new' || r.group === 'conflict';
const missingPacks = computed(() => props.preview?.rows.filter(needsPack).length ?? 0);
const suggestions = computed(() => props.preview?.rows.filter((r) => needsPack(r) && r.suggested) ?? []);
const acceptSuggestions = () =>
    suggestions.value.forEach((r) => {
        draft[r.key].pack_name = r.suggested!.pack_name;
        draft[r.key].pack_size = r.suggested!.pack_size;
        draft[r.key].base_unit = r.suggested!.base_unit;
    });
const willApply = computed(() => props.preview?.rows.filter((r) => r.group !== 'conflict' && draft[r.key]?.included && (r.group !== 'skipped' || r.changes.length)).length ?? 0);

// Bulk batch tracking. A range or any channel category, so "Authentic Kombucha" works though its range reads "OK Drinks".
const rangeNames = computed(() => {
    const counts = new Map<string, number>();
    props.preview?.rows.forEach((r) => new Set([r.range, ...r.categories].filter(Boolean) as string[]).forEach((n) => counts.set(n, (counts.get(n) ?? 0) + 1)));
    return [...counts.entries()].sort((a, b) => a[0].localeCompare(b[0]));
});
const selectedRange = ref('');
const setBatch = (value: boolean) =>
    props.preview?.rows
        .filter((r) => r.range === selectedRange.value || r.categories.includes(selectedRange.value))
        .forEach((r) => {
            draft[r.key].batch_tracked = value;
        });

const recheck = () => props.preview && router.put(route('product-import.revise'), { token: props.preview.token, edits: edits() }, { preserveScroll: true });
const apply = () =>
    props.preview &&
    confirm(`Apply ${willApply.value} row(s)? This changes the catalogue only. Stock doesn't change, and listings start with sync off.`) &&
    router.post(route('product-import.apply'), { token: props.preview.token, edits: edits() }, { preserveScroll: true });

const expiresAt = computed(() => (props.preview ? new Date(props.preview.expires_at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''));
// A bad cell fails as edits.{key}.{field}, a row still short of a pack as edits.
const editError = computed(() => Object.entries((usePage().props.errors ?? {}) as Record<string, string>).find(([k]) => k.startsWith('edits'))?.[1]);

// The fetch runs in a queued job; ask for the preview until it lands.
let poll: ReturnType<typeof setInterval> | undefined;
watch(
    () => props.preview?.status,
    (status) => {
        clearInterval(poll);
        if (status === 'fetching') poll = setInterval(() => router.reload({ only: ['preview'], showProgress: false }), 2000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <Head title="Import products" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <section class="max-w-3xl space-y-2 text-sm">
                <h1 class="text-xl font-semibold">Import products</h1>
                <p>
                    Brings products, packs and channel mappings into the catalogue. You see a preview first, and <strong>stock never changes</strong>. Listings
                    start with sync off: pushing stock stays a decision on the Sync screen.
                </p>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="space-y-2 rounded-lg border p-4 text-sm">
                    <h2 class="font-semibold">Ordermentum</h2>
                    <p class="text-muted-foreground">Reads the live catalogue. Read-only: nothing is sent to Ordermentum.</p>
                    <Button size="sm" :disabled="preview?.status === 'fetching'" @click="fetchOrdermentum">Fetch from Ordermentum</Button>
                </div>
                <div class="space-y-2 rounded-lg border p-4 text-sm">
                    <h2 class="font-semibold">CSV or XLSX</h2>
                    <form class="space-y-2" @submit.prevent="sendFile">
                        <input
                            type="file"
                            class="w-full"
                            accept=".csv,.xlsx,text/csv"
                            @input="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        />
                        <div class="flex items-center gap-3">
                            <Button type="submit" size="sm" :disabled="!upload.file || upload.processing">Preview file</Button>
                            <a :href="route('product-import.template')" class="underline">Download template</a>
                        </div>
                        <p v-if="upload.errors.file" class="text-red-700 dark:text-red-300">{{ upload.errors.file }}</p>
                    </form>
                </div>
                <div class="space-y-2 rounded-lg border p-4 text-sm">
                    <h2 class="font-semibold">Portal</h2>
                    <p class="text-muted-foreground">
                        The WMS can't read the portal catalogue directly. Export it to the template, with the portal product ID in
                        <code>portal_item_id</code>, and upload that.
                    </p>
                </div>
            </section>

            <p v-if="preview?.status === 'fetching'" class="text-sm" role="status">Fetching from Ordermentum… this needs the queue worker running.</p>
            <p v-if="preview?.status === 'failed'" class="rounded-lg border border-red-300 p-3 text-sm text-red-700 dark:border-red-800 dark:text-red-300">
                {{ preview.error }}
            </p>

            <template v-if="preview?.status === 'ready' && preview.summary">
                <section class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <strong>{{ preview.summary.total }} row(s) from {{ preview.source === 'ordermentum' ? 'Ordermentum' : 'the file' }}</strong>
                    <span v-for="g in GROUPS" :key="g.key">{{ g.title }}: {{ preview.summary[g.key] }}</span>
                    <span class="text-muted-foreground">Expires at {{ expiresAt }}</span>
                </section>
                <p v-if="preview.summary.barcodes_ignored" class="text-sm text-muted-foreground">
                    {{ preview.summary.barcodes_ignored }} barcode(s) ignored: they aren't real product barcodes (GTINs).
                </p>

                <section class="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm">
                    <label class="flex flex-col gap-1">
                        <span class="font-medium">Batch-tracked by range</span>
                        <select v-model="selectedRange" class="h-9 rounded-md border border-input bg-background px-2">
                            <option value="">Choose a range</option>
                            <option v-for="[name, n] in rangeNames" :key="name" :value="name">{{ name }} ({{ n }})</option>
                        </select>
                    </label>
                    <Button size="sm" variant="outline" :disabled="!selectedRange" @click="setBatch(true)">Tick all</Button>
                    <Button size="sm" variant="outline" :disabled="!selectedRange" @click="setBatch(false)">Untick all</Button>
                    <span class="text-muted-foreground">An existing product changes only if its overwrite is ticked too.</span>
                    <Button v-if="suggestions.length" size="sm" variant="outline" class="ml-auto" @click="acceptSuggestions">
                        Accept {{ suggestions.length }} suggested pack(s)
                    </Button>
                </section>

                <details v-for="g in GROUPS" v-show="rowsIn(g.key).length" :key="g.key" class="rounded-lg border" :open="g.key !== 'skipped'">
                    <summary class="cursor-pointer p-3 font-semibold">
                        {{ g.title }} ({{ rowsIn(g.key).length }})
                        <span class="ml-2 text-sm font-normal text-muted-foreground">{{ g.hint }}</span>
                    </summary>
                    <!-- inline-size containment: the wide table scrolls here instead of stretching the page -->
                    <div class="overflow-x-auto [contain:inline-size]">
                        <table class="w-full min-w-[1040px] text-left text-sm">
                            <thead class="border-y text-muted-foreground">
                                <tr>
                                    <th class="pl-3 py-1">Include</th>
                                    <th class="px-3">Overwrite</th>
                                    <th>Item</th>
                                    <th>Pack name</th>
                                    <th>Pack size</th>
                                    <th>Base unit</th>
                                    <th>Range</th>
                                    <th>Batch</th>
                                    <th class="pr-3">Sold on</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in rowsIn(g.key)"
                                    :key="r.key"
                                    class="border-b align-top last:border-0"
                                    :class="{ 'bg-amber-50 dark:bg-amber-950': needsPack(r), 'bg-red-50 dark:bg-red-950': r.group === 'conflict' }"
                                >
                                    <td class="py-2 pl-3"><input v-model="draft[r.key].included" type="checkbox" :aria-label="`Include ${r.sku}`" /></td>
                                    <td class="px-3 py-2">
                                        <input v-if="r.diffs.length" v-model="draft[r.key].overwrite" type="checkbox" :aria-label="`Overwrite ${r.sku}`" />
                                    </td>
                                    <td class="max-w-[22rem] py-2 pr-3">
                                        <div>
                                            <span class="font-mono">{{ r.sku || '(no SKU)' }}</span>
                                            <span v-if="r.line" class="text-muted-foreground"> line {{ r.line }}</span>
                                        </div>
                                        <div>{{ r.name }}</div>
                                        <div
                                            class="text-xs"
                                            :class="r.group === 'conflict' || needsPack(r) ? 'text-red-800 dark:text-red-200' : 'text-muted-foreground'"
                                        >
                                            {{ r.reason }}
                                        </div>
                                        <ul v-if="r.changes.length && r.group !== 'skipped'" class="text-xs">
                                            <li v-for="c in r.changes" :key="c">+ {{ c }}</li>
                                        </ul>
                                        <ul v-if="r.diffs.length" class="text-xs">
                                            <li v-for="d in r.diffs" :key="d.field">{{ d.label }}: {{ d.wms || '(empty)' }} → {{ d.incoming }}</li>
                                        </ul>
                                        <ul v-if="r.notes.length" class="text-xs text-amber-900 dark:text-amber-200">
                                            <li v-for="n in r.notes" :key="n">{{ n }}</li>
                                        </ul>
                                    </td>
                                    <template v-if="packEditable(r)">
                                        <td class="py-2 pr-2">
                                            <Input v-model="draft[r.key].pack_name" class="h-8 w-24" :placeholder="r.suggested?.pack_name" :aria-label="`Pack name of ${r.sku}`" />
                                        </td>
                                        <td class="py-2 pr-2">
                                            <Input
                                                v-model.number="draft[r.key].pack_size"
                                                type="number"
                                                min="1"
                                                class="h-8 w-20"
                                                :placeholder="r.suggested ? String(r.suggested.pack_size) : ''"
                                                :aria-label="`Pack size of ${r.sku}`"
                                            />
                                        </td>
                                        <td class="py-2 pr-2">
                                            <Input v-model="draft[r.key].base_unit" class="h-8 w-32" :placeholder="r.suggested?.base_unit" :aria-label="`Base unit of ${r.sku}`" />
                                        </td>
                                    </template>
                                    <template v-else>
                                        <td class="py-2 pr-2">{{ r.pack_name }}</td>
                                        <td class="py-2 pr-2">{{ r.pack_size }}</td>
                                        <td class="py-2 pr-2 text-muted-foreground">{{ r.product ? '' : r.base_unit }}</td>
                                    </template>
                                    <td class="py-2 pr-2">
                                        <Input v-model="draft[r.key].range" class="h-8 w-40" list="import-ranges" :aria-label="`Range of ${r.sku}`" />
                                    </td>
                                    <td class="py-2 pr-2"><input v-model="draft[r.key].batch_tracked" type="checkbox" :aria-label="`Batch-tracked ${r.sku}`" /></td>
                                    <td class="py-2 pr-3 whitespace-nowrap">
                                        <label class="block"><input v-model="draft[r.key].sell_ordermentum" type="checkbox" /> Ordermentum</label>
                                        <label class="block"><input v-model="draft[r.key].sell_portal" type="checkbox" /> Portal</label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <datalist id="import-ranges">
                    <option v-for="name in ranges" :key="name" :value="name" />
                </datalist>

                <section class="sticky bottom-0 flex flex-wrap items-center gap-3 border-t bg-background py-3">
                    <Button variant="outline" @click="recheck">Re-check</Button>
                    <Button :disabled="missingPacks > 0 || !willApply" @click="apply">Apply {{ willApply }} row(s)</Button>
                    <span v-if="missingPacks" class="text-sm text-red-700 dark:text-red-300">
                        {{ missingPacks }} row(s) need a pack size. Fill them in or exclude them.
                    </span>
                    <span v-else class="text-sm text-muted-foreground">Re-check shows what your edits do. Apply checks everything again first.</span>
                    <span v-if="editError" class="text-sm text-red-700 dark:text-red-300">{{ editError }}</span>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
