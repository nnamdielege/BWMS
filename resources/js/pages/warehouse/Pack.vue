<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

type PackLine = {
    id: number;
    product: { sku: string; name: string; chilled: boolean; base_unit: string };
    pack: { name: string; base_qty: number };
    to_pick_base: number;
    to_pick_label: string;
    picked_base: number | null;
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
const s = (o: PackOrder) => (state[o.id] ??= { checked: [], cartons: o.pack?.cartons ?? null });
const toggle = (o: PackOrder, id: number) => {
    const st = s(o);
    st.checked = st.checked.includes(id) ? st.checked.filter((x) => x !== id) : [...st.checked, id];
};
const packed = (l: PackLine) =>
    l.picked_base === null ? '—' : l.picked_base % l.pack.base_qty === 0 ? `${l.picked_base / l.pack.base_qty} ${l.pack.name}` : `${l.picked_base} ${l.product.base_unit}`;
const ready = (o: PackOrder) => o.picking_done && s(o).checked.length === o.lines.length && s(o).cartons !== null;
const save = (o: PackOrder) => router.post(route('pick.pack.store', [props.pickList.id, o.id]), { cartons: s(o).cartons, checked: s(o).checked }, { preserveScroll: true });
const allPacked = computed(() => props.orders.every((o) => o.pack));
</script>

<template>
    <Head :title="`Pack ${pickList.number}`" />
    <WarehouseLayout :title="`Pack ${pickList.number}`" :back="route('pick.work', pickList.id)">
        <InputError :message="errors.pick" class="mb-2" />

        <div v-for="o in orders" :key="o.id" class="mb-4 rounded-xl border p-3" :class="{ 'border-green-400': o.pack }">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-mono font-semibold">{{ o.number }}</div>
                    <div class="text-sm text-muted-foreground">{{ o.customer }}</div>
                </div>
                <a :href="route('pick.slip', [pickList.id, o.id])" target="_blank" class="rounded-md border px-3 py-2 text-sm">Packing slip</a>
            </div>

            <p v-if="!o.picking_done" class="mt-2 text-sm text-amber-800">Some lines are not picked yet.</p>

            <ul class="mt-2 divide-y">
                <li v-for="l in o.lines" :key="l.id">
                    <label class="flex min-h-12 items-center gap-3 py-2">
                        <input type="checkbox" class="size-6" :checked="s(o).checked.includes(l.id)" :disabled="!!o.pack" @change="toggle(o, l.id)" />
                        <span class="flex-1">
                            {{ l.product.name }}
                            <span v-if="l.product.chilled" class="ml-1 rounded bg-sky-100 px-1.5 text-xs text-sky-800">CHILLED</span>
                            <span class="block font-mono text-xs text-muted-foreground">{{ l.product.sku }}</span>
                        </span>
                        <span class="text-right tabular-nums">
                            {{ packed(l) }}
                            <span v-if="l.picked_base !== null && l.picked_base < l.to_pick_base" class="block text-xs text-red-700">short of {{ l.to_pick_label }}</span>
                        </span>
                    </label>
                </li>
            </ul>

            <div v-if="o.pack" class="mt-2 text-sm text-green-800">
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
                    class="h-12 w-24 rounded-lg border px-3 text-xl tabular-nums"
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
