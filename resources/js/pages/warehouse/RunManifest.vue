<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ManifestOrder, { type ManifestRow } from '@/components/ManifestOrder.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    run: { id: number; label: string; status: string; date: string; trip: string | null };
    orders: ManifestRow[];
    openLists: { id: number; number: string }[];
}>();

const errors = computed(() => usePage().props.errors as Record<string, string>);
const cartons = computed(() => props.orders.reduce((n, o) => n + o.cartons, 0));
const dispatchRun = () => {
    if (confirm('Dispatch this run? Stock is deducted for everything picked.')) router.post(route('pick.dispatch', props.run.id));
};
</script>

<template>
    <Head :title="run.label" />
    <WarehouseLayout>
        <InputError :message="errors.pick" class="mb-2" />
        <div class="mb-3 flex items-center justify-between text-sm">
            <span>{{ orders.length }} order(s) · {{ cartons }} carton(s)</span>
            <span class="rounded bg-muted px-2 py-0.5 capitalize">{{ run.status }}</span>
        </div>

        <Link v-if="run.trip" :href="route('pick.trips', run.date)" class="mb-3 block text-sm underline">Trip: {{ run.trip }}</Link>

        <ul class="divide-y rounded-xl border">
            <ManifestOrder v-for="o in orders" :key="o.id" :order="o" />
        </ul>

        <div v-if="openLists.length" class="mt-3 space-y-2">
            <Link v-for="l in openLists" :key="l.id" :href="route('pick.pack', l.id)" class="block rounded-lg border p-3 text-sm">Pack {{ l.number }}</Link>
            <button class="h-12 w-full rounded-lg bg-primary font-semibold text-primary-foreground" @click="dispatchRun">Dispatch run</button>
        </div>
    </WarehouseLayout>
</template>
