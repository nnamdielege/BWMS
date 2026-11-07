<script setup lang="ts">
import HeldBadge, { type Hold } from '@/components/HeldBadge.vue';
import InputError from '@/components/InputError.vue';
import MovedBadge from '@/components/MovedBadge.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dayLabel } from '@/lib/deliveryDays';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

type Row = {
    id: number;
    number: string;
    customer: string | null;
    suburb: string | null;
    channel: string;
    date: string;
    area: string;
    moved_from: string | null;
    pick_list: string | null;
    needs_review: boolean;
    hold: Hold | null;
    warnings: string[];
    packs: number;
    value: string | null;
};
type Run = { id: number; area: string; status: string; trip_label: string | null; orders: number; packs: number; value: string | null };

const props = defineProps<{
    target: string;
    days: number;
    today: string;
    runs: Run[];
    moved: Row[];
    candidates: Row[];
    noticeEnabled: boolean;
    notice: { order: string; customer: string | null; phone: string | null; email: string | null; moved_from: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: 'Consolidate', href: '/orders/consolidate' },
];

const filter = reactive({ date: props.target, days: props.days });
const reload = () => router.get(route('orders.consolidate.index'), { ...filter }, { preserveState: true, preserveScroll: true, replace: true });

const money = (v: string | null) => (v === null ? '' : `$${Number(v).toFixed(2)}`);
const targetLabel = computed(() => dayLabel(props.target));
const totals = computed(() => {
    const priced = props.runs.filter((r) => r.value !== null);
    return {
        orders: props.runs.reduce((n, r) => n + r.orders, 0),
        packs: props.runs.reduce((n, r) => n + r.packs, 0),
        value: priced.length ? priced.reduce((n, r) => n + Number(r.value), 0).toFixed(2) : null,
    };
});

// Date → area, as the candidates arrive sorted by date.
const groups = computed(() => {
    const days = new Map<string, Map<string, Row[]>>();
    for (const row of props.candidates) {
        if (!days.has(row.date)) days.set(row.date, new Map());
        const areas = days.get(row.date)!;
        if (!areas.has(row.area)) areas.set(row.area, []);
        areas.get(row.area)!.push(row);
    }
    return [...days.entries()].map(([date, areas]) => ({ date, runs: [...areas.entries()].map(([area, rows]) => ({ area, rows })) }));
});

const selected = ref<number[]>([]);
watch(
    () => props.candidates,
    () => (selected.value = selected.value.filter((id) => props.candidates.some((c) => c.id === id && !c.pick_list))),
);
const selectable = (rows: Row[]) => rows.filter((r) => !r.pick_list);
const allOn = (rows: Row[]) => selectable(rows).length > 0 && selectable(rows).every((r) => selected.value.includes(r.id));
const toggleRun = (rows: Row[]) => {
    const ids = selectable(rows).map((r) => r.id);
    selected.value = allOn(rows) ? selected.value.filter((id) => !ids.includes(id)) : [...new Set([...selected.value, ...ids])];
};
const selectedWarnings = computed(() => [...new Set(props.candidates.filter((c) => selected.value.includes(c.id)).flatMap((c) => c.warnings))]);

const move = () => {
    const warn = selectedWarnings.value.length ? `\n\n${selectedWarnings.value.join('\n')}` : '';
    if (!confirm(`Move ${selected.value.length} order(s) to ${targetLabel.value}?${warn}`)) return;
    router.post(route('orders.consolidate.move'), { date: props.target, order_ids: selected.value }, { preserveScroll: true, onSuccess: () => (selected.value = []) });
};
const undo = (o: Row) => {
    if (confirm(`Put ${o.number} back on ${dayLabel(o.moved_from!)}?`)) router.post(route('orders.consolidate.undo', o.id), {}, { preserveScroll: true });
};

const trips = reactive<Record<number, string>>({});
watch(
    () => props.runs,
    (runs) => runs.forEach((r) => (trips[r.id] = r.trip_label ?? '')),
    { immediate: true },
);
const saveTrips = () => router.put(route('orders.consolidate.trips'), { labels: trips }, { preserveScroll: true });
const setNotice = (enabled: boolean) => router.put(route('orders.consolidate.notice'), { enabled }, { preserveScroll: true });
const errors = computed(() => usePage().props.errors as Record<string, string>);
</script>

<template>
    <Head title="Consolidate runs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 pb-24">
            <section class="space-y-2 text-sm">
                <h1 class="text-xl font-semibold">Consolidate runs</h1>
                <p class="max-w-3xl">Pull orders from later runs onto an earlier day. Each order keeps its area, and stock doesn't change.</p>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="reload">
                    <label class="flex flex-col gap-1">
                        <span class="font-medium">Target date</span>
                        <Input v-model="filter.date" type="date" :min="today" class="w-44" aria-label="Target date" @change="reload" />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="font-medium">Look ahead (days)</span>
                        <Input v-model.number="filter.days" type="number" min="1" max="60" class="w-28" aria-label="Look ahead days" @change="reload" />
                    </label>
                </form>
            </section>

            <section class="rounded-lg border">
                <h2 class="border-b p-3 font-semibold">
                    {{ targetLabel }}: {{ totals.orders }} order(s), {{ totals.packs }} pack(s)<span v-if="totals.value"> · {{ money(totals.value) }}</span>
                </h2>
                <div class="overflow-x-auto [contain:inline-size]">
                    <table class="w-full min-w-[560px] text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr><th class="px-3 py-1">Run</th><th class="text-right">Orders</th><th class="text-right">Packs</th><th class="text-right">Value</th><th class="pr-3 pl-6">Trip</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in runs" :key="r.id" class="border-b last:border-0">
                                <td class="px-3 py-2">
                                    <Link :href="route('pick.manifest', r.id)" class="underline">{{ r.area }}</Link>
                                    <span v-if="r.status === 'dispatched'" class="ml-2 text-xs text-muted-foreground">dispatched</span>
                                </td>
                                <td class="text-right tabular-nums">{{ r.orders }}</td>
                                <td class="text-right tabular-nums">{{ r.packs }}</td>
                                <td class="text-right tabular-nums">{{ money(r.value) }}</td>
                                <td class="pr-3 pl-6"><Input v-model="trips[r.id]" class="h-8 w-40" placeholder="None" :aria-label="`Trip for ${r.area}`" /></td>
                            </tr>
                            <tr v-if="!runs.length"><td colspan="5" class="px-3 py-4 text-muted-foreground">No runs on this day yet.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="runs.length" class="flex flex-wrap items-center gap-3 border-t p-3 text-sm">
                    <Button size="sm" variant="outline" @click="saveTrips">Save trips</Button>
                    <span class="text-muted-foreground">Give runs the same trip to put them together on the driver's manifest. Runs and pick lists don't change.</span>
                    <Link :href="route('pick.trips', target)" class="underline">Trip manifest</Link>
                </div>
            </section>

            <InputError :message="errors.consolidate" />

            <section v-if="moved.length" class="rounded-lg border">
                <h2 class="border-b p-3 font-semibold">Moved to {{ targetLabel }} ({{ moved.length }})</h2>
                <ul class="divide-y text-sm">
                    <li v-for="o in moved" :key="o.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 p-3">
                        <Link :href="route('orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                        <span>{{ o.customer }}</span>
                        <span class="text-muted-foreground">{{ o.area }}</span>
                        <MovedBadge :from="o.moved_from" />
                        <SourceBadge :channel="o.channel" />
                        <span v-if="o.needs_review" class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">review</span>
                        <span class="ml-auto">
                            <span v-if="o.pick_list" class="text-muted-foreground">On {{ o.pick_list }}</span>
                            <button v-else class="underline" @click="undo(o)">Undo</button>
                        </span>
                    </li>
                </ul>
            </section>

            <section class="space-y-4">
                <h2 class="font-semibold">Orders in the next {{ days }} day(s) after {{ targetLabel }}</h2>
                <p v-if="!groups.length" class="text-sm text-muted-foreground">No later orders in this window.</p>

                <div v-for="g in groups" :key="g.date" class="rounded-lg border">
                    <h3 class="border-b p-3 text-sm font-semibold">{{ dayLabel(g.date) }}</h3>
                    <div v-for="run in g.runs" :key="run.area">
                        <label class="flex items-center gap-2 bg-muted/50 px-3 py-2 text-sm font-medium">
                            <input type="checkbox" :checked="allOn(run.rows)" :disabled="!selectable(run.rows).length" :aria-label="`Select ${dayLabel(g.date)} ${run.area}`" @change="toggleRun(run.rows)" />
                            {{ run.area }} ({{ run.rows.length }})
                        </label>
                        <ul class="divide-y text-sm">
                            <li v-for="o in run.rows" :key="o.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <input v-model="selected" type="checkbox" :value="o.id" :disabled="!!o.pick_list" :aria-label="`Select ${o.number}`" />
                                <span class="min-w-40">{{ o.customer }} <span class="ml-1 text-muted-foreground">{{ o.suburb }}</span></span>
                                <SourceBadge :channel="o.channel" />
                                <MovedBadge :from="o.moved_from" />
                                <HeldBadge :hold="o.hold" />
                                <span class="tabular-nums">{{ o.packs }} pack(s)</span>
                                <span class="tabular-nums">{{ money(o.value) }}</span>
                                <span v-if="o.pick_list" class="text-muted-foreground">On {{ o.pick_list }}</span>
                                <Link :href="route('orders.show', o.id)" class="ml-auto font-mono text-xs underline">{{ o.number }}</Link>
                                <p v-for="w in o.warnings" :key="w" class="w-full text-xs text-amber-900 dark:text-amber-200">{{ w }}</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border p-3 text-sm">
                <label class="flex items-center gap-2 font-medium">
                    <input type="checkbox" :checked="noticeEnabled" aria-label="List moved customers" @change="setNotice(($event.target as HTMLInputElement).checked)" />
                    List moved customers, so I can tell them their delivery is coming early
                </label>
                <p class="text-muted-foreground">Nothing is ever sent automatically.</p>
                <ul v-if="noticeEnabled && notice.length" class="mt-2 divide-y">
                    <li v-for="n in notice" :key="n.order" class="flex flex-wrap gap-x-4 py-1">
                        <span>{{ n.customer }}</span>
                        <span>{{ n.phone ?? 'No phone' }}</span>
                        <span>{{ n.email ?? 'No email' }}</span>
                        <MovedBadge :from="n.moved_from" />
                    </li>
                </ul>
                <p v-else-if="noticeEnabled" class="mt-2 text-muted-foreground">Nobody has been moved to this day.</p>
            </section>

            <section v-if="selected.length" class="fixed right-0 bottom-0 left-0 z-10 flex flex-wrap items-center justify-end gap-3 border-t bg-background p-3 text-sm">
                <span v-if="selectedWarnings.length" class="text-amber-900 dark:text-amber-200">{{ selectedWarnings.length }} stock warning(s)</span>
                <Button @click="move">Move {{ selected.length }} to {{ targetLabel }}</Button>
            </section>
        </div>
    </AppLayout>
</template>
