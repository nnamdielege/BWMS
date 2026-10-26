<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

interface Line {
    id: number;
    location: string;
    product: { id: number; sku: string; name: string; base_unit: string };
    batch: string | null;
    order: string;
    customer: string | null;
    pack: string;
    pack_qty: number;
    qty_to_pick_base: number;
    qty_picked_base: number | null;
}

const props = defineProps<{
    pickList: { id: number; number: string; status: string; site: string; dispatched_at: string | null; lines: Line[] };
    can: { pick: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pick lists', href: '/pick-lists' },
    { title: props.pickList.number, href: '#' },
];
const errors = computed(() => usePage().props.errors as Record<string, string>);
const picked = reactive<Record<number, number | null>>(Object.fromEntries(props.pickList.lines.map((l) => [l.id, l.qty_picked_base])));
const unconfirmed = computed(() => props.pickList.lines.filter((l) => l.qty_picked_base === null).length);
const shorts = computed(() => props.pickList.lines.filter((l) => l.qty_picked_base !== null && l.qty_picked_base < l.qty_to_pick_base).length);

const save = (values: Record<number, number | null>) =>
    router.put(route('pick-lists.record', props.pickList.id), { picked: Object.fromEntries(Object.entries(values).filter(([, v]) => v !== null && v !== undefined)) }, { preserveScroll: true, preserveState: true });
const saveLine = (l: Line) => picked[l.id] !== null && picked[l.id] !== l.qty_picked_base && save({ [l.id]: picked[l.id] });
const markAllPicked = () => {
    props.pickList.lines.forEach((l) => (picked[l.id] = picked[l.id] ?? l.qty_to_pick_base));
    save({ ...picked });
};
const dispatch = () =>
    confirm(`Dispatch ${props.pickList.number}? Stock is deducted now.${shorts.value ? ` ${shorts.value} line(s) are short; each order's short-ship rule applies.` : ''}`) &&
    router.post(route('pick-lists.dispatch', props.pickList.id), {}, { preserveScroll: true });
const print = () => window.print();
const cancel = () => confirm('Cancel this pick list? Orders go back to ready-to-pick.') && router.post(route('pick-lists.cancel', props.pickList.id));
const packs = (l: Line, base: number) => (l.pack_qty > 1 ? `${Math.floor(base / l.pack_qty)} × ${l.pack}${base % l.pack_qty ? ` + ${base % l.pack_qty}` : ''}` : '');
</script>

<template>
    <Head :title="pickList.number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="font-mono text-xl font-semibold">{{ pickList.number }}</h1>
                <span class="rounded bg-muted px-2 py-0.5 text-sm capitalize">{{ pickList.status }}</span>
                <span class="text-sm">{{ pickList.site }}</span>
                <div class="ml-auto flex gap-2 print:hidden">
                    <Button variant="outline" @click="print">Print</Button>
                    <template v-if="can.pick">
                        <Button variant="outline" @click="markAllPicked">Mark all picked</Button>
                        <Button :disabled="unconfirmed > 0" @click="dispatch">Dispatch</Button>
                        <Button variant="ghost" @click="cancel">Cancel</Button>
                    </template>
                </div>
            </header>
            <p v-if="can.pick" class="text-xs text-muted-foreground print:hidden">
                Walk order: by bin, earliest best-before first. Enter what you actually picked; less than asked is a short pick. {{ unconfirmed }} line(s) still to confirm.
            </p>
            <InputError :message="errors.pick" />

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Bin</th><th>Product</th><th>Batch</th><th>Order</th><th class="text-right">To pick</th><th class="w-32 text-right">Picked</th></tr>
                </thead>
                <tbody>
                    <tr v-for="l in pickList.lines" :key="l.id" class="border-b" :class="{ 'bg-amber-50 dark:bg-amber-950': l.qty_picked_base !== null && l.qty_picked_base < l.qty_to_pick_base }">
                        <td class="py-2 font-mono">{{ l.location }}</td>
                        <td><span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}</td>
                        <td>{{ l.batch ?? '' }}</td>
                        <td>{{ l.order }}<div class="text-xs text-muted-foreground">{{ l.customer }}</div></td>
                        <td class="text-right tabular-nums">
                            {{ l.qty_to_pick_base }} {{ l.product.base_unit }}
                            <div class="text-xs text-muted-foreground">{{ packs(l, l.qty_to_pick_base) }}</div>
                        </td>
                        <td class="text-right">
                            <Input
                                v-if="can.pick"
                                v-model.number="picked[l.id]"
                                type="number"
                                min="0"
                                :max="l.qty_to_pick_base"
                                class="h-8 w-24 text-right"
                                @blur="saveLine(l)"
                                @keydown.enter.prevent="saveLine(l)"
                            />
                            <span v-else class="tabular-nums">{{ l.qty_picked_base ?? '—' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
