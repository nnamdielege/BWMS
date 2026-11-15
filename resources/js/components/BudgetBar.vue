<script setup lang="ts">
import { type BudgetSummary } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ budget: BudgetSummary }>();

const money = (v: number) => v.toLocaleString('en-AU', { style: 'currency', currency: 'AUD' });
const day = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short' });
// Committed share of the budget, and this PO's share on top, as bar widths (capped at 100%).
const pct = (v: number) => (props.budget.budget ? Math.min(100, Math.max(0, (v / props.budget.budget) * 100)) : 0);
const committedPct = computed(() => pct(props.budget.committed));
const poPct = computed(() => Math.min(100 - committedPct.value, pct(props.budget.po_total ?? 0)));
</script>

<template>
    <div class="rounded-lg border p-3 text-sm" :class="{ 'border-red-300': budget.over }">
        <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
            <span class="font-semibold">Purchasing budget, week of {{ day(budget.week_start) }}–{{ day(budget.week_end) }}</span>
            <span
                ><span class="text-muted-foreground">Budget</span>
                <span class="tabular-nums">{{ budget.budget === null ? 'not set' : money(budget.budget) }}</span></span
            >
            <span
                ><span class="text-muted-foreground">Committed</span> <span class="tabular-nums">{{ money(budget.committed) }}</span></span
            >
            <span>
                <span class="text-muted-foreground">Remaining</span>
                <span class="font-semibold tabular-nums" :class="budget.remaining < 0 ? 'text-red-600' : 'text-green-700 dark:text-green-400'">{{
                    money(budget.remaining)
                }}</span>
            </span>
            <span v-if="budget.po_total !== undefined"
                ><span class="text-muted-foreground">This PO</span> <span class="tabular-nums">{{ money(budget.po_total) }}</span></span
            >
            <span class="text-xs text-muted-foreground">ex GST</span>
            <Link :href="route('purchasing-budgets.index')" class="ml-auto text-xs underline">Budgets</Link>
        </div>
        <div v-if="budget.budget" class="mt-2 flex h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
            <div class="h-full bg-primary" :style="{ width: `${committedPct}%` }" />
            <div class="h-full" :class="budget.over ? 'bg-red-500' : 'bg-primary/40'" :style="{ width: `${poPct}%` }" />
        </div>
    </div>
</template>
