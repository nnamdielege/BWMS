<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

type PackLine = {
    id: number;
    product: { sku: string; name: string; chilled: boolean; base_unit: string };
    pack: { name: string; base_qty: number };
    to_pick_base: number;
    to_pick_label: string;
    picked_base: number | null;
    ticked: boolean;
};
type PackOrder = {
    id: number;
    number: string;
    customer: string | null;
    channel: string;
    picking_done: boolean;
    lines: PackLine[];
    cartons: number | null;
    pack: { cartons: number; packed_by: string; packed_at: string } | null;
};
const props = defineProps<{ pickList: { id: number; number: string; status: string; run_id: number | null; run: string | null }; orders: PackOrder[] }>();

const errors = computed(() => usePage().props.errors as Record<string, string>);
const state = reactive<Record<number, { checked: number[]; cartons: number | '' | null }>>({});
const savedTicks = (o: PackOrder) => o.lines.filter((l) => l.ticked).map((l) => l.id);
const s = (o: PackOrder) => (state[o.id] ??= { checked: savedTicks(o), cartons: o.cartons });

// Ticks and carton counts save in the background with a plain fetch: no Inertia visit, so nothing
// moves focus or interrupts a navigation. Rapid changes are batched, the last change to a line or
// order wins, and batches go one at a time so an untick can't overtake its tick.
const SAVE_DEBOUNCE_MS = 300;
const SAVE_RETRY_MS = 3000;
const pending = new Map<number, boolean>();
const pendingCartons = new Map<number, number | null>();
const saveError = ref('');
let timer: number | undefined;
let sending: Promise<boolean> | null = null;
let left = false;

// Back and Forward restore Inertia's copy of the page, not what the server holds, so re-read it.
const refresh = () => {
    if (left) return;
    router.reload({
        only: ['orders'],
        showProgress: false,
        onSuccess: () => {
            for (const o of props.orders) {
                const checked = new Set(savedTicks(o));
                for (const l of o.lines) {
                    if (pending.has(l.id)) checked[pending.get(l.id) ? 'add' : 'delete'](l.id);
                }
                s(o).checked = [...checked];
                if (!pendingCartons.has(o.id)) s(o).cartons = o.cartons;
            }
        },
    });
};
const putProgress = async (ticks: Map<number, boolean>, cartons: Map<number, number | null>): Promise<boolean> => {
    const ids = (ticked: boolean) => [...ticks].filter(([, value]) => value === ticked).map(([id]) => id);
    const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
    const res = await fetch(route('pick.progress', props.pickList.id), {
        method: 'PUT',
        keepalive: true, // still sends if the page is closing
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf) },
        body: JSON.stringify({ ticked: ids(true), unticked: ids(false), cartons: Object.fromEntries(cartons) }),
    }).catch(() => null);

    if (res?.ok) {
        saveError.value = '';
        return true;
    }
    if (res && res.status < 500) {
        const body = await res.json().catch(() => null);
        saveError.value = body?.errors?.pick?.[0] ?? 'Changes could not be saved. Reload and try again.';
        refresh();
        return false;
    }
    ticks.forEach((value, id) => pending.has(id) || pending.set(id, value));
    cartons.forEach((value, id) => pendingCartons.has(id) || pendingCartons.set(id, value));
    saveError.value = 'Changes not saved yet. Retrying…';
    timer = window.setTimeout(flush, SAVE_RETRY_MS);
    return false;
};
const flush = async (): Promise<boolean> => {
    window.clearTimeout(timer);
    while (sending) await sending;
    if (!pending.size && !pendingCartons.size) return true;
    const ticks = new Map(pending);
    const cartons = new Map(pendingCartons);
    pending.clear();
    pendingCartons.clear();
    sending = putProgress(ticks, cartons).finally(() => (sending = null));
    return sending;
};
const saveSoon = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(flush, SAVE_DEBOUNCE_MS);
};
const toggle = (o: PackOrder, id: number) => {
    const st = s(o);
    const ticked = !st.checked.includes(id);
    st.checked = ticked ? [...st.checked, id] : st.checked.filter((x) => x !== id);
    pending.set(id, ticked);
    saveSoon();
};
const validCartons = (value: number | '' | null): value is number => typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 500;
// A count the server would refuse is never sent, or it would take the batch's ticks with it.
const cartonsChanged = (o: PackOrder, event: Event) => {
    const value = (event.target as HTMLInputElement).value;
    const count = value === '' ? null : Number(value);
    pendingCartons.set(o.id, validCartons(count) ? count : null);
    saveSoon();
};
onMounted(() => {
    window.addEventListener('pagehide', flush);
    refresh();
});
onBeforeUnmount(() => {
    left = true;
    window.removeEventListener('pagehide', flush);
    flush();
});

const packed = (l: PackLine) =>
    l.picked_base === null ? '—' : l.picked_base % l.pack.base_qty === 0 ? `${l.picked_base / l.pack.base_qty} ${l.pack.name}` : `${l.picked_base} ${l.product.base_unit}`;
const ready = (o: PackOrder) => o.picking_done && s(o).checked.length === o.lines.length && validCartons(s(o).cartons);
const save = async (o: PackOrder) => {
    if (await flush()) router.post(route('pick.pack.store', [props.pickList.id, o.id]), { cartons: s(o).cartons }, { preserveScroll: true });
};
const allPacked = computed(() => props.orders.every((o) => o.pack));
</script>

<template>
    <Head :title="`Pack ${pickList.number}`" />
    <WarehouseLayout>
        <p v-if="pickList.run" class="mb-2 text-sm font-semibold">{{ pickList.run }}</p>
        <InputError :message="errors.pick" class="mb-2" />
        <InputError :message="saveError" class="sticky top-14 z-[5] mb-2 bg-background py-1" />

        <div v-for="o in orders" :key="o.id" class="mb-4 rounded-xl border p-3" :class="{ 'border-green-400': o.pack }">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-mono font-semibold">{{ o.number }} <SourceBadge :channel="o.channel" /></div>
                    <div class="text-sm text-muted-foreground">{{ o.customer }}</div>
                </div>
                <a :href="route('pick.slip', [pickList.id, o.id])" target="_blank" class="rounded-md border px-3 py-2 text-sm">Packing slip</a>
            </div>

            <p v-if="!o.picking_done" class="mt-2 text-sm text-amber-800 dark:text-amber-200">Some lines are not picked yet.</p>

            <ul class="mt-2 divide-y">
                <li v-for="l in o.lines" :key="l.id">
                    <label class="flex min-h-12 items-center gap-3 py-2">
                        <input type="checkbox" class="size-6" :checked="!!o.pack || s(o).checked.includes(l.id)" :disabled="!!o.pack" @change="toggle(o, l.id)" />
                        <span class="flex-1">
                            {{ l.product.name }}
                            <span v-if="l.product.chilled" class="ml-1 rounded bg-sky-100 px-1.5 text-xs text-sky-800 dark:bg-sky-950 dark:text-sky-200">CHILLED</span>
                            <span class="block font-mono text-xs text-muted-foreground">{{ l.product.sku }}</span>
                        </span>
                        <span class="text-right tabular-nums">
                            {{ packed(l) }}
                            <span v-if="l.picked_base !== null && l.picked_base < l.to_pick_base" class="block text-xs text-red-700 dark:text-destructive">short of {{ l.to_pick_label }}</span>
                        </span>
                    </label>
                </li>
            </ul>

            <div v-if="o.pack" class="mt-2 text-sm text-green-800 dark:text-green-200">
                Packed: {{ o.pack.cartons }} carton(s) by {{ o.pack.packed_by }} · {{ new Date(o.pack.packed_at).toLocaleTimeString() }}
            </div>
            <div v-else class="mt-3 flex items-center gap-2">
                <label class="text-sm" :for="`cartons-${o.id}`">Cartons</label>
                <input
                    :id="`cartons-${o.id}`"
                    v-model.number="s(o).cartons"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    max="500"
                    class="h-12 w-24 rounded-lg border bg-background px-3 text-xl tabular-nums"
                    @input="cartonsChanged(o, $event)"
                />
                <button class="ml-auto h-12 rounded-lg bg-primary px-5 font-semibold text-primary-foreground disabled:opacity-50" :disabled="!ready(o)" @click="save(o)">
                    Packed
                </button>
            </div>
        </div>

        <Link
            v-if="allPacked && pickList.run_id"
            :href="route('pick.manifest', pickList.run_id)"
            class="flex h-12 items-center justify-center rounded-lg bg-primary font-semibold text-primary-foreground"
        >
            To dispatch
        </Link>
    </WarehouseLayout>
</template>
