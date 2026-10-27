<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import WarehouseLayout from '@/layouts/WarehouseLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    run: { id: number; label: string; status: string };
    orders: { id: number; number: string; status: string; status_label: string; customer: string | null; address: string; cartons: number; packed: boolean }[];
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
    <WarehouseLayout :title="run.label" :back="route('pick.queue')">
        <InputError :message="errors.pick" class="mb-2" />
        <div class="mb-3 flex items-center justify-between text-sm">
            <span>{{ orders.length }} order(s) · {{ cartons }} carton(s)</span>
            <span class="rounded bg-muted px-2 py-0.5 capitalize">{{ run.status }}</span>
        </div>

        <ul class="divide-y rounded-xl border">
            <li v-for="o in orders" :key="o.id" class="flex items-start gap-3 p-3">
                <div class="flex-1">
                    <div class="font-mono font-semibold">
                        {{ o.number }} <span class="ml-1 text-xs font-normal text-muted-foreground">{{ o.status_label }}</span>
                    </div>
                    <div class="text-sm">{{ o.customer }}</div>
                    <div class="text-xs text-muted-foreground">{{ o.address || 'No address' }}</div>
                </div>
                <div class="text-right text-sm">
                    <div class="tabular-nums">{{ o.cartons }} ctn</div>
                    <div :class="o.packed ? 'text-green-700' : 'text-amber-700'">{{ o.packed ? 'packed' : 'not packed' }}</div>
                </div>
            </li>
        </ul>

        <div v-if="openLists.length" class="mt-3 space-y-2">
            <Link v-for="l in openLists" :key="l.id" :href="route('pick.pack', l.id)" class="block rounded-lg border p-3 text-sm">Pack {{ l.number }}</Link>
            <button class="h-12 w-full rounded-lg bg-primary font-semibold text-primary-foreground" @click="dispatchRun">Dispatch run</button>
        </div>
    </WarehouseLayout>
</template>
