<script setup lang="ts">
import ScanInput from '@/components/ScanInput.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { catchVisitFailures } from '@/lib/visitFailures';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Unit = { id: number; name: string; base_qty: number; default_sale: boolean; barcodes: string[] };
type Product = { id: number; sku: string; name: string; units: Unit[] };

const props = defineProps<{
    progress: { have: number; total: number };
    missing: { id: number; sku: string; name: string; bin: string | null }[];
    product: Product | null;
    results: { id: number; sku: string; name: string }[];
    query: string | null;
}>();

const message = ref<{ kind: 'ok' | 'error'; text: string } | null>(null);
const busy = ref(false);
const waiting: string[] = [];
const say = (kind: 'ok' | 'error', text: string) => {
    message.value = { kind, text };
    navigator.vibrate?.(kind === 'ok' ? 40 : [80, 60, 80]);
};

// The pack the next scan goes on: the one sold first, then any still without a barcode. None left, and the next scan finds a product.
const nextUnit = (p: Product | null) => {
    const open = p?.units.filter((u) => !u.barcodes.length) ?? [];
    return open.find((u) => u.default_sale)?.id ?? open[0]?.id ?? null;
};
const activeId = ref(nextUnit(props.product));
const active = computed(() => props.product?.units.find((u) => u.id === activeId.value) ?? null);
const label = (u: Unit) => (u.base_qty > 1 ? `${u.name} ×${u.base_qty}` : u.name);

watch(
    () => props.product,
    (now, before) => {
        activeId.value = nextUnit(now);
        if (now?.id !== before?.id) message.value = null;
    },
);

const visitOptions = { preserveState: true, preserveScroll: true, only: ['product', 'results', 'query', 'progress', 'missing', 'errors'] };

const settle = () => {
    busy.value = false;
    const next = waiting.shift();
    if (next) onScan(next);
};

const find = (data: Record<string, string | number>) => {
    message.value = null;
    busy.value = true;
    router.get(route('barcode-capture.index'), data, { ...visitOptions, replace: true, onFinish: settle });
};

const save = (code: string) => {
    const unit = active.value!;
    busy.value = true;
    const stop = catchVisitFailures((text) => say('error', text));
    router.post(
        route('barcode-capture.store'),
        { product_unit_id: unit.id, barcode: code },
        {
            ...visitOptions,
            onSuccess: () => say('ok', `${code} saved on ${label(unit)}.${nextUnit(props.product) ? '' : ' All packs done: scan the next product.'}`),
            onError: (errors) => say('error', Object.values(errors)[0] ?? 'Not saved.'),
            onFinish: () => {
                stop();
                settle();
            },
        },
    );
};

// The box stays live while a save or lookup runs: a scan in that moment waits its turn, rather than vanishing on a disabled input.
const onScan = (code: string) => {
    if (busy.value) waiting.push(code);
    else if (active.value) save(code);
    else find({ q: code });
};
const placeholder = computed(() => (active.value ? `Scan barcode for ${label(active.value)}` : 'Scan or search a product'));
const percent = computed(() => (props.progress.total ? Math.round((props.progress.have / props.progress.total) * 100) : 0));
</script>

<template>
    <Head title="Capture barcodes" />
    <WarehouseLayout>
        <div class="mb-3">
            <div class="mb-1 text-sm font-semibold">{{ progress.have }} of {{ progress.total }} have barcodes</div>
            <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full bg-primary" :style="{ width: `${percent}%` }" /></div>
        </div>

        <div v-if="product" class="mb-3 rounded-xl border p-3">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <div class="font-semibold">{{ product.name }}</div>
                    <div class="font-mono text-sm text-muted-foreground">{{ product.sku }}</div>
                </div>
                <button class="shrink-0 text-sm underline" @mousedown.prevent @click="find({})">Done</button>
            </div>
            <div class="mt-2 space-y-2">
                <button
                    v-for="u in product.units"
                    :key="u.id"
                    class="block w-full rounded-lg border p-2 text-left"
                    :class="{ 'border-primary bg-primary/10': u.id === activeId }"
                    :aria-pressed="u.id === activeId"
                    @mousedown.prevent
                    @click="activeId = u.id"
                >
                    <span class="font-semibold">{{ label(u) }}</span>
                    <span v-if="u.barcodes.length" class="block font-mono text-sm break-all text-muted-foreground">{{ u.barcodes.join(', ') }}</span>
                    <span v-else class="block text-sm text-muted-foreground">No barcode yet</span>
                </button>
            </div>
        </div>

        <ScanInput :placeholder="placeholder" @scan="onScan" />

        <div
            v-if="message"
            class="mt-2 rounded-lg px-3 py-2 text-sm font-medium"
            :class="message.kind === 'ok' ? 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200' : 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200'"
            :role="message.kind === 'ok' ? 'status' : 'alert'"
        >
            {{ message.text }}
        </div>

        <section v-if="!product && query" class="mt-3">
            <h2 class="mb-1 text-sm font-semibold">{{ results.length ? `Found for "${query}"` : `Nothing found for "${query}"` }}</h2>
            <ul v-if="results.length" class="divide-y rounded-xl border text-sm">
                <li v-for="r in results" :key="r.id">
                    <button class="w-full p-3 text-left" @mousedown.prevent @click="find({ product: r.id })"><span class="font-mono">{{ r.sku }}</span> {{ r.name }}</button>
                </li>
            </ul>
        </section>

        <section class="mt-5">
            <h2 class="mb-1 text-sm font-semibold">Missing barcodes ({{ missing.length }}), in walk order</h2>
            <ul v-if="missing.length" class="divide-y rounded-xl border text-sm">
                <li v-for="m in missing" :key="m.id">
                    <button class="flex w-full items-center gap-3 p-3 text-left" @mousedown.prevent @click="find({ product: m.id })">
                        <span class="w-16 shrink-0 font-mono font-semibold">{{ m.bin ?? 'No stock' }}</span>
                        <span class="min-w-0 truncate"><span class="font-mono">{{ m.sku }}</span> {{ m.name }}</span>
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Every product has a barcode.</p>
        </section>
    </WarehouseLayout>
</template>
