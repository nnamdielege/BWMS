<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type Run = {
    id: number;
    date: string;
    label: string;
    area: string;
    waiting: number;
    active: number;
    lists: { id: number; number: string; claimed_by: string | null; mine: boolean; lines: number; done: number }[];
};
const props = defineProps<{
    today: string;
    runs: Run[];
    unassigned: { id: number; number: string; customer: string | null; postcode: string | null; suburb: string | null }[];
}>();

const errors = computed(() => usePage().props.errors as Record<string, string>);
const groups = computed(() => {
    const out: Record<string, Run[]> = {};
    for (const r of props.runs) (out[r.date] ??= []).push(r);
    return out;
});
const dayLabel = (d: string) => (d === props.today ? 'Today' : new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short' }));
const start = (run: Run) => router.post(route('pick.start', run.id));
const claim = (id: number, takeOver = false) => {
    if (takeOver && !confirm('Someone else is picking this list. Take it over?')) return;
    router.post(route('pick.claim', id), { take_over: takeOver });
};
</script>

<template>
    <Head title="Pick queue" />
    <WarehouseLayout title="Pick queue">
        <InputError :message="errors.pick" class="mb-3" />

        <section v-for="(runs, date) in groups" :key="date" class="mb-5">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{{ dayLabel(String(date)) }}</h2>
            <div v-for="run in runs" :key="run.id" class="mb-3 rounded-xl border p-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-base font-semibold">{{ run.area }}</div>
                        <div class="text-sm text-muted-foreground">{{ run.active }} order(s) · {{ run.waiting }} waiting to pick</div>
                    </div>
                    <Link :href="route('pick.manifest', run.id)" class="rounded-md border px-3 py-2 text-sm">Manifest</Link>
                </div>

                <div v-for="l in run.lists" :key="l.id" class="mt-3 flex items-center justify-between gap-2 rounded-lg bg-muted/60 p-2">
                    <div class="text-sm">
                        <span class="font-mono">{{ l.number }}</span> · {{ l.done }}/{{ l.lines }} lines
                        <div class="text-xs text-muted-foreground">{{ l.claimed_by ? (l.mine ? 'You' : l.claimed_by) : 'Not claimed' }}</div>
                    </div>
                    <button v-if="!l.claimed_by || l.mine" class="h-12 rounded-lg bg-primary px-4 font-semibold text-primary-foreground" @click="claim(l.id)">
                        {{ l.mine ? 'Continue' : 'Start' }}
                    </button>
                    <button v-else class="h-12 rounded-lg border px-4 text-sm" @click="claim(l.id, true)">Take over</button>
                </div>

                <button v-if="run.waiting" class="mt-3 h-12 w-full rounded-lg bg-primary font-semibold text-primary-foreground" @click="start(run)">
                    Pick {{ run.waiting }} new order(s)
                </button>
            </div>
        </section>

        <p v-if="!runs.length" class="py-8 text-center text-muted-foreground">No runs waiting.</p>

        <section v-if="unassigned.length" class="mt-6 rounded-xl border border-amber-300 p-3">
            <h2 class="mb-1 font-semibold text-amber-800">Orders without a run ({{ unassigned.length }})</h2>
            <p class="mb-2 text-sm text-muted-foreground">Their address matches no delivery area. Set the run on the order, or add the postcode in Settings → Delivery areas.</p>
            <ul class="space-y-1 text-sm">
                <li v-for="o in unassigned" :key="o.id">
                    <Link :href="route('orders.show', o.id)" class="font-mono underline">{{ o.number }}</Link>
                    {{ o.customer }} <span class="text-muted-foreground">{{ o.suburb }} {{ o.postcode }}</span>
                </li>
            </ul>
        </section>
    </WarehouseLayout>
</template>
