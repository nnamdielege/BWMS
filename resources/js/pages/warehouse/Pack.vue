<script setup lang="ts">
import InputError from '@/components/InputError.vue';
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
    picking_done: boolean;
    lines: PackLine[];
    pack: { cartons: number; packed_by: string; packed_at: string } | null;
};
const props = defineProps<{ pickList: { id: number; number: string; status: string; run_id: number | null }; orders: PackOrder[] }>();

const errors = computed(() => usePage().props.errors as Record<string, string>);
const state = reactive<Record<number, { checked: number[]; cartons: number | null }>>({});
const savedTicks = (o: PackOrder) => o.lines.filter((l) => l.ticked).map((l) => l.id);
const s = (o: PackOrder) => (state[o.id] ??= { checked: savedTicks(o), cartons: o.pack?.cartons ?? null });

// Ticks save in the background with a plain fetch: no Inertia visit, so nothing moves focus or
// interrupts a navigation. Rapid changes are batched, the last change to a line wins, and
// batches go one at a time so an untick can't overtake its tick.
const TICK_DEBOUNCE_MS = 300;
const TICK_RETRY_MS = 3000;
const pending = new Map<number, boolean>();
const tickError = ref('');
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
            }
        },
    });
};
const putTicks = async (batch: Map<number, boolean>): Promise<boolean> => {
    const ids = (ticked: boolean) => [...batch].filter(([, value]) => value === ticked).map(([id]) => id);
    const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
    const res = await fetch(route('pick.ticks', props.pickList.id), {
        method: 'PUT',
        keepalive: true, // still sends if the page is closing
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf) },
        body: JSON.stringify({ ticked: ids(true), unticked: ids(false) }),
    }).catch(() => null);

    if (res?.ok) {
        tickError.value = '';
        return true;
    }
    if (res && res.status < 500) {
        const body = await res.json().catch(() => null);
        tickError.value = body?.errors?.pick?.[0] ?? 'Ticks could not be saved. Reload and try again.';
        refresh();
        return false;
    }
    batch.forEach((value, id) => pending.has(id) || pending.set(id, value));
    tickError.value = 'Ticks not saved yet. Retrying…';
    timer = window.setTimeout(flush, TICK_RETRY_MS);
    return false;
};
const flush = async (): Promise<boolean> => {
    window.clearTimeout(timer);
    while (sending) await sending;
    if (!pending.size) return true;
    const batch = new Map(pending);
    pending.clear();
    sending = putTicks(batch).finally(() => (sending = null));
    return sending;
};
const toggle = (o: PackOrder, id: number) => {
    const st = s(o);
    const ticked = !st.checked.includes(id);
    st.checked = ticked ? [...st.checked, id] : st.checked.filter((x) => x !== id);
    pending.set(id, ticked);
    window.clearTimeout(timer);
    timer = window.setTimeout(flush, TICK_DEBOUNCE_MS);
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
const ready = (o: PackOrder) => o.picking_done && s(o).checked.length === o.lines.length && s(o).cartons !== null;
const save = async (o: PackOrder) => {
    if (await flush()) router.post(route('pick.pack.store', [props.pickList.id, o.id]), { cartons: s(o).cartons }, { preserveScroll: true });
};
const allPacked = computed(() => props.orders.every((o) => o.pack));
</script>

<template>
    <Head :title="`Pack ${pickList.number}`" />
    <WarehouseLayout>
        <InputError :message="errors.pick" class="mb-2" />
        <InputError :message="tickError" class="sticky top-14 z-[5] mb-2 bg-background py-1" />

        <div v-for="o in orders" :key="o.id" class="mb-4 rounded-xl border p-3" :class="{ 'border-green-400': o.pack }">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-mono font-semibold">{{ o.number }}</div>
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
                    class="h-12 w-24 rounded-lg border bg-background px-3 text-xl tabular-nums"
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
