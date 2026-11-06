<script setup lang="ts">
import ManifestOrder, { type ManifestRow } from '@/components/ManifestOrder.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    label: string;
    trips: { label: string; cartons: number; orders: number; runs: { id: number; area: string; status: string; orders: ManifestRow[] }[] }[];
}>();
</script>

<template>
    <Head :title="`Trips · ${label}`" />
    <WarehouseLayout>
        <section v-for="trip in trips" :key="trip.label" class="mb-6">
            <h2 class="text-base font-semibold">{{ trip.label }}</h2>
            <p class="mb-2 text-sm text-muted-foreground">{{ trip.orders }} order(s) · {{ trip.cartons }} carton(s)</p>
            <div v-for="run in trip.runs" :key="run.id" class="mb-3">
                <div class="mb-1 flex items-center justify-between text-sm font-medium">
                    <span>{{ run.area }}</span>
                    <Link :href="route('pick.manifest', run.id)" class="text-xs underline">Run manifest</Link>
                </div>
                <ul class="divide-y rounded-xl border">
                    <ManifestOrder v-for="o in run.orders" :key="o.id" :order="o" />
                </ul>
            </div>
        </section>
        <p v-if="!trips.length" class="py-8 text-center text-muted-foreground">No runs on this day.</p>
    </WarehouseLayout>
</template>
