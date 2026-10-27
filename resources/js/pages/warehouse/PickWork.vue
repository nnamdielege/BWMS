<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ScanInput from '@/components/ScanInput.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Line = {
    id: number;
    order: { id: number; number: string; customer: string | null };
    bin: { code: string; path: string; barcode: string | null };
    product: { sku: string; name: string; chilled: boolean; base_unit: string };
    pack: { name: string; base_qty: number };
    barcodes: { code: string; base_qty: number }[];
    batch: { lot: string; best_before: string | null } | null;
    to_pick_base: number;
    to_pick_label: string;
    picked_base: number | null;
    scanned: boolean;
    override_reason: string | null;
};
const props = defineProps<{
    pickList: { id: number; number: string; status: string; run: string | null; run_id: number | null; claimed_by: string | null; mine: boolean };
    lines: Line[];
    overrideReasons: string[];
}>();

const errors = computed(() => usePage().props.errors as Record<string, string>);
const firstOpen = () => Math.max(0, props.lines.findIndex((l) => l.picked_base === null));
const index = ref(firstOpen());
const line = computed<Line | undefined>(() => props.lines[index.value]);
const done = computed(() => props.lines.filter((l) => l.picked_base !== null).length);
const allDone = computed(() => done.value === props.lines.length);

// Scan state for the current line.
const binCode = ref<string | null>(null);
const productCodes = ref<string[]>([]);
const countBase = ref(0);
const message = ref<{ kind: 'ok' | 'error'; text: string } | null>(null);
const mode = ref<'scan' | 'manual' | 'short'>('scan');
const manualQty = ref<number | null>(null);
const reason = ref<string>('');
const saving = ref(false);
const showList = ref(false);

const reset = () => {
    binCode.value = null;
    productCodes.value = [];
    countBase.value = 0;
    mode.value = 'scan';
    manualQty.value = null;
    reason.value = '';
};
watch(index, () => {
    reset();
    message.value = null;
});

const buzz = (ok: boolean) => navigator.vibrate?.(ok ? 40 : [80, 60, 80]);
const say = (kind: 'ok' | 'error', text: string) => {
    message.value = { kind, text };
    buzz(kind === 'ok');
};
const inPacks = (base: number, l: Line) => (base % l.pack.base_qty === 0 ? `${base / l.pack.base_qty} ${l.pack.name}` : `${base} ${l.product.base_unit}`);

const onScan = (code: string) => {
    const l = line.value;
    if (!l || props.pickList.status !== 'open') return;
    if (l.bin.barcode && code === l.bin.barcode) {
        binCode.value = code;
        return say('ok', `Bin ${l.bin.code} ✓`);
    }
    const match = l.barcodes.find((b) => b.code === code);
    if (match) {
        if (!binCode.value) return say('error', `Scan bin ${l.bin.code} first`);
        if (countBase.value + match.base_qty > l.to_pick_base) return say('error', `Too many: only ${l.to_pick_label} needed`);
        productCodes.value.push(code);
        countBase.value += match.base_qty;
        say('ok', `${l.product.sku} ✓ ${inPacks(countBase.value, l)} of ${l.to_pick_label}`);
        if (countBase.value === l.to_pick_base) submit(countBase.value);
        return;
    }
    const otherBin = props.lines.find((x) => x.bin.barcode === code);
    if (otherBin) return say('error', `That is bin ${otherBin.bin.code}; this line is in ${l.bin.code}`);
    say('error', `Not this product: ${code}`);
};

const manualBase = computed(() => {
    const l = line.value;
    if (!l || manualQty.value === null) return null;
    return l.to_pick_base % l.pack.base_qty === 0 ? manualQty.value * l.pack.base_qty : manualQty.value;
});
const manualUnit = computed(() => (line.value && line.value.to_pick_base % line.value.pack.base_qty === 0 ? line.value.pack.name : line.value?.product.base_unit));
const needsReason = computed(() => !(binCode.value && productCodes.value.length));

const submit = (qtyBase: number) => {
    const l = line.value;
    if (!l) return;
    saving.value = true;
    router.put(
        route('pick.record', [props.pickList.id, l.id]),
        { qty_picked_base: qtyBase, bin_barcode: binCode.value, product_barcodes: productCodes.value, override_reason: needsReason.value && qtyBase > 0 ? reason.value || null : null },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['lines', 'errors', 'flash'],
            onSuccess: () => {
                const next = props.lines.findIndex((x, i) => i > index.value && x.picked_base === null);
                index.value = next >= 0 ? next : firstOpen();
                reset();
            },
            onError: () => buzz(false),
            onFinish: () => (saving.value = false),
        },
    );
};
const reasonLabel = (r: string) => ({ label_missing: 'Label missing', label_damaged: 'Label damaged', scanner_fault: 'Scanner not working', other: 'Other' })[r] ?? r;
</script>

<template>
    <Head :title="pickList.number" />
    <WarehouseLayout :title="`${pickList.number} · ${done}/${lines.length}`" :back="route('pick.queue')">
        <div v-if="!pickList.mine && pickList.status === 'open'" class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            {{ pickList.claimed_by ? `Being picked by ${pickList.claimed_by}.` : 'Not claimed.' }}
            <button class="ml-2 underline" @click="router.post(route('pick.claim', pickList.id), { take_over: true })">Take over</button>
        </div>
        <p class="mb-2 text-sm text-muted-foreground">{{ pickList.run }}</p>
        <InputError :message="errors.pick" class="mb-2" />

        <template v-if="line && !allDone">
            <div class="rounded-xl border p-4">
                <div class="text-xs uppercase tracking-wide text-muted-foreground">Bin</div>
                <div class="flex items-center gap-2">
                    <span class="font-mono text-4xl font-bold">{{ line.bin.code }}</span>
                    <span v-if="binCode" class="rounded bg-green-100 px-2 text-sm text-green-800">✓</span>
                </div>
                <div class="text-xs text-muted-foreground">{{ line.bin.path }}</div>

                <div class="mt-3 flex items-center gap-2 text-lg font-semibold">
                    {{ line.product.name }}
                    <span v-if="line.product.chilled" class="rounded bg-sky-100 px-2 text-xs font-semibold text-sky-800">CHILLED</span>
                </div>
                <div class="font-mono text-sm text-muted-foreground">{{ line.product.sku }}</div>
                <div v-if="line.batch" class="mt-1 text-sm">Lot {{ line.batch.lot }} · BB {{ line.batch.best_before ?? '—' }}</div>

                <div class="mt-3 text-3xl font-bold tabular-nums">Pick {{ line.to_pick_label }}</div>
                <div class="text-sm text-muted-foreground">Scanned {{ inPacks(countBase, line) }} · {{ line.order.number }} {{ line.order.customer }}</div>
            </div>

            <div
                v-if="message"
                class="mt-2 rounded-lg px-3 py-2 text-sm font-medium"
                :class="message.kind === 'ok' ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'"
                role="status"
            >
                {{ message.text }}
            </div>

            <div v-if="mode === 'scan'" class="mt-3">
                <ScanInput :placeholder="binCode ? 'Scan product' : 'Scan bin label'" :disabled="saving || !pickList.mine" @scan="onScan" />
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button class="h-12 rounded-lg border text-sm" @click="mode = 'manual'">Enter quantity</button>
                    <button class="h-12 rounded-lg border text-sm text-red-700" @click="mode = 'short'">Short / not found</button>
                </div>
            </div>

            <form v-else class="mt-3 space-y-3 rounded-xl border p-3" @submit.prevent="manualBase !== null && submit(manualBase)">
                <div class="font-semibold">{{ mode === 'short' ? 'How many did you find?' : 'Quantity picked' }}</div>
                <div class="flex items-center gap-2">
                    <input v-model.number="manualQty" type="number" inputmode="numeric" min="0" class="h-14 w-32 rounded-lg border px-3 text-2xl tabular-nums" autofocus />
                    <span>{{ manualUnit }}</span>
                </div>
                <div v-if="needsReason && (manualQty ?? 0) > 0">
                    <div class="mb-1 text-sm">Why no scan?</div>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="r in overrideReasons"
                            :key="r"
                            type="button"
                            class="h-12 rounded-lg border text-sm"
                            :class="{ 'border-primary bg-primary/10 font-semibold': reason === r }"
                            @click="reason = r"
                        >
                            {{ reasonLabel(r) }}
                        </button>
                    </div>
                </div>
                <p v-if="mode === 'short'" class="text-xs text-muted-foreground">The rest follows the order's backorder/cancel rule at dispatch, and this bin gets a count task.</p>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="h-12 rounded-lg border" @click="mode = 'scan'">Back</button>
                    <button
                        type="submit"
                        class="h-12 rounded-lg bg-primary font-semibold text-primary-foreground disabled:opacity-50"
                        :disabled="saving || manualBase === null || manualBase > line.to_pick_base || (needsReason && (manualQty ?? 0) > 0 && !reason)"
                    >
                        Confirm
                    </button>
                </div>
            </form>
        </template>

        <div v-if="allDone" class="rounded-xl border border-green-300 bg-green-50 p-4 text-center">
            <div class="text-lg font-semibold text-green-900">All lines picked</div>
            <Link :href="route('pick.pack', pickList.id)" class="mt-3 inline-flex h-12 items-center rounded-lg bg-primary px-6 font-semibold text-primary-foreground">Go to packing</Link>
        </div>

        <button class="mt-4 text-sm underline" @click="showList = !showList">{{ showList ? 'Hide' : 'Show' }} all lines</button>
        <ol v-if="showList" class="mt-2 divide-y rounded-lg border text-sm">
            <li v-for="(l, i) in lines" :key="l.id" class="flex items-center gap-2 p-2" :class="{ 'bg-muted': i === index }" @click="index = i">
                <span class="w-16 font-mono">{{ l.bin.code }}</span>
                <span class="flex-1 truncate">{{ l.product.name }}</span>
                <span v-if="l.product.chilled" class="text-xs text-sky-700">chilled</span>
                <span class="tabular-nums">{{ l.picked_base === null ? l.to_pick_label : l.picked_base < l.to_pick_base ? 'short' : '✓' }}</span>
            </li>
        </ol>
    </WarehouseLayout>
</template>
