<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Week {
    id: number | null;
    week_start: string;
    week_end: string;
    budget: number | null;
    committed: number;
    remaining: number;
    notes: string | null;
    updated_by: string | null;
    is_current: boolean;
    is_past: boolean;
    estimate: { amount: number; sales: number; from: string; to: string };
}

const props = defineProps<{ weeks: Week[]; share: number; canManage: boolean }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchase-orders' },
    { title: 'Budgets', href: '/purchasing/budgets' },
];
const money = (v: number) => v.toLocaleString('en-AU', { style: 'currency', currency: 'AUD' });
const day = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short' });

// One week edited at a time; a week without a budget starts from the estimate.
const editing = ref<string | null>(null);
const form = useForm({ week_start: '', budget: '' as string | number, notes: '' });
const edit = (w: Week) => {
    editing.value = w.week_start;
    form.clearErrors();
    form.week_start = w.week_start;
    form.budget = w.budget ?? w.estimate.amount;
    form.notes = w.notes ?? '';
};
const save = () => form.post(route('purchasing-budgets.store'), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const remove = (w: Week) => {
    if (w.id && confirm(`Remove the budget for the week of ${day(w.week_start)}?`))
        router.delete(route('purchasing-budgets.destroy', w.id), { preserveScroll: true });
};
const sharePct = Math.round(props.share * 100);
</script>

<template>
    <Head title="Purchasing budgets" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Purchasing budgets</h1>
                <p class="text-sm text-muted-foreground">
                    The most to approve in POs each week (Monday to Sunday, Brisbane), ex GST. Approving a PO over the remaining budget needs an
                    override and a reason. A week without a budget is pre-filled with {{ sharePct }}% of last week's customer receipts, estimated from
                    dispatched orders (ex GST): an estimate, so check it against Xero receipts.
                </p>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Week</th>
                        <th class="text-right">Budget</th>
                        <th class="text-right">Committed</th>
                        <th class="text-right">Remaining</th>
                        <th class="pl-4">Estimate ({{ sharePct }}% of sales)</th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="w in weeks" :key="w.week_start">
                        <tr class="border-b" :class="{ 'bg-primary/5 font-medium': w.is_current, 'text-muted-foreground': w.is_past }">
                            <td class="whitespace-nowrap py-2">
                                {{ day(w.week_start) }} – {{ day(w.week_end) }}<span v-if="w.is_current" class="ml-1 text-xs">(this week)</span>
                            </td>
                            <td class="text-right tabular-nums">{{ w.budget === null ? '—' : money(w.budget) }}</td>
                            <td class="text-right tabular-nums">{{ money(w.committed) }}</td>
                            <td class="text-right tabular-nums" :class="{ 'text-red-600': w.budget !== null && w.remaining < 0 }">
                                {{ w.budget === null ? '—' : money(w.remaining) }}
                            </td>
                            <td
                                class="pl-4 tabular-nums"
                                :title="`${sharePct}% of ${money(w.estimate.sales)} dispatched ${day(w.estimate.from)}–${day(w.estimate.to)}`"
                            >
                                {{ money(w.estimate.amount) }}
                                <span class="text-xs text-muted-foreground">estimate – check against Xero receipts</span>
                            </td>
                            <td class="max-w-xs truncate" :title="w.notes ?? ''">{{ w.notes }}</td>
                            <td class="whitespace-nowrap text-right">
                                <template v-if="canManage">
                                    <Button size="sm" variant="outline" @click="edit(w)">{{ w.budget === null ? 'Set' : 'Edit' }}</Button>
                                    <Button v-if="w.id" size="sm" variant="ghost" @click="remove(w)">Remove</Button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="editing === w.week_start" class="border-b bg-muted/40">
                            <td colspan="7" class="p-3">
                                <form class="flex flex-wrap items-start gap-3" @submit.prevent="save">
                                    <div class="grid gap-1">
                                        <label class="text-xs text-muted-foreground" for="budget">Budget ($ ex GST)</label>
                                        <Input id="budget" v-model="form.budget" type="number" min="0" step="0.01" class="w-40" required />
                                        <span class="text-xs text-muted-foreground">Default: {{ sharePct }}% of last week's customer receipts</span>
                                        <InputError :message="form.errors.budget || form.errors.week_start" />
                                    </div>
                                    <div class="grid flex-1 gap-1">
                                        <label class="text-xs text-muted-foreground" for="notes">Notes</label>
                                        <Input id="notes" v-model="form.notes" maxlength="2000" placeholder="Optional" />
                                    </div>
                                    <div class="flex gap-2 self-center">
                                        <Button type="submit" :disabled="form.processing">Save</Button>
                                        <Button type="button" variant="ghost" @click="editing = null">Cancel</Button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
