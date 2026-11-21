<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Account {
    id: number;
    role: string;
    name: string;
    xero_code: string | null;
    purpose: string | null;
    weight: number | null;
    target_weight: number | null;
    institution: string | null;
    last4: string | null;
    is_active: boolean;
    is_split: boolean;
}

const props = defineProps<{
    accounts: Account[];
    percent: Record<string, number>;
    settings: {
        days: number[];
        gst_basis: 'accrual' | 'cash';
        net_off_credits: boolean;
        company_tax_rate: number;
        fixed_costs_weekly: number | null;
        vault_weeks_min: number;
        vault_weeks_max: number;
        director_salaries_fortnight: number | null;
        debt_priority: string[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/allocation' },
    { title: 'Income allocation', href: '/finance/allocation' },
    { title: 'Settings', href: '/finance/allocation/settings' },
];

const form = useForm({
    accounts: props.accounts.map((a) => ({ ...a })),
    days: [...props.settings.days],
    gst_basis: props.settings.gst_basis,
    net_off_credits: props.settings.net_off_credits,
    company_tax_rate: props.settings.company_tax_rate,
    fixed_costs_weekly: props.settings.fixed_costs_weekly,
    vault_weeks_min: props.settings.vault_weeks_min,
    vault_weeks_max: props.settings.vault_weeks_max,
    director_salaries_fortnight: props.settings.director_salaries_fortnight,
    debt_priority: [...props.settings.debt_priority],
});
const daysText = computed({
    get: () => form.days.join(', '),
    set: (v: string) =>
        (form.days = v
            .split(',')
            .map((d) => parseInt(d.trim(), 10))
            .filter((d) => !Number.isNaN(d))),
});
const debtText = computed({
    get: () => form.debt_priority.join('\n'),
    set: (v: string) => (form.debt_priority = v.split('\n')),
});

// Live rescaled shares, as the allocation will use them.
const liveShare = computed(() => {
    const takes = form.accounts.filter((a) => a.is_split && a.is_active && Number(a.weight) > 0);
    const total = takes.reduce((s, a) => s + Number(a.weight), 0);
    return Object.fromEntries(takes.map((a) => [a.role, total > 0 ? Math.round((Number(a.weight) / total) * 10000) / 100 : 0]));
});
const roleLabel: Record<string, string> = { income: 'Source: receipts land here', tax: 'Calculated: GST + company tax' };
const errorFor = (i: number, f: string) => (form.errors as Record<string, string>)[`accounts.${i}.${f}`];
const save = () => form.put(route('finance.allocation.settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Income allocation settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="space-y-6 p-4" @submit.prevent="save">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold">Income allocation settings</h1>
                    <p class="text-sm text-muted-foreground">
                        The bank accounts and their weights. Weights are rescaled to 100% of what's left after Tax. Store only the last 4 digits of an
                        account number — never the full number. Every change is in the audit log.
                    </p>
                </div>
                <Button variant="outline" as-child><Link :href="route('finance.allocation.index')">Back to allocation</Link></Button>
            </div>

            <section class="space-y-2">
                <h2 class="font-semibold">Bank accounts</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-xs text-muted-foreground">
                            <tr>
                                <th class="py-1">Name</th>
                                <th>Xero code</th>
                                <th>Purpose</th>
                                <th>Bank</th>
                                <th>Last 4</th>
                                <th>Weight</th>
                                <th class="text-right">Share</th>
                                <th>Target weight</th>
                                <th>On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(a, i) in form.accounts" :key="a.id" class="border-b align-top">
                                <td class="py-1 pr-2">
                                    <Input v-model="a.name" class="w-40" maxlength="100" required />
                                    <InputError :message="errorFor(i, 'name')" />
                                </td>
                                <td class="pr-2"><Input v-model="a.xero_code" class="w-20" maxlength="20" /></td>
                                <td class="pr-2"><Input v-model="a.purpose" class="w-64" maxlength="255" /></td>
                                <td class="pr-2"><Input v-model="a.institution" class="w-32" maxlength="100" /></td>
                                <td class="pr-2">
                                    <Input v-model="a.last4" class="w-20" maxlength="4" inputmode="numeric" pattern="\d{4}" placeholder="1234" />
                                    <InputError :message="errorFor(i, 'last4')" />
                                </td>
                                <td class="pr-2">
                                    <Input v-if="a.is_split" v-model="a.weight" type="number" min="0" step="0.01" class="w-24" />
                                    <span v-else class="text-xs text-muted-foreground">{{ roleLabel[a.role] }}</span>
                                    <InputError :message="errorFor(i, 'weight')" />
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ a.is_split && liveShare[a.role] !== undefined ? `${liveShare[a.role]}%` : '' }}
                                </td>
                                <td class="pl-2 pr-2">
                                    <Input
                                        v-if="a.is_split"
                                        v-model="a.target_weight"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="w-24"
                                        placeholder="None"
                                    />
                                </td>
                                <td>
                                    <input v-if="a.role === 'debt_service'" v-model="a.is_active" type="checkbox" :aria-label="`${a.name} on`" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <InputError :message="(form.errors as Record<string, string>).accounts" />
                <p class="text-xs text-muted-foreground">
                    Debt Service is temporary: tick it and give it a weight while paying debt down, in the order below. Target weights feed the
                    quarterly review, which suggests moving 1 point a quarter toward them.
                </p>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <div class="space-y-3 rounded-lg border p-3">
                    <h2 class="font-semibold">Allocation and tax</h2>
                    <div class="grid gap-1">
                        <label class="text-sm" for="days">Allocation days (day of the month, comma-separated)</label>
                        <Input id="days" v-model="daysText" class="w-48" />
                        <InputError :message="form.errors.days" />
                    </div>
                    <div class="grid gap-1">
                        <span class="text-sm">GST basis</span>
                        <label class="text-sm"
                            ><input v-model="form.gst_basis" type="radio" value="accrual" /> Accrual: GST on sales dispatched in the period</label
                        >
                        <label class="text-sm">
                            <input v-model="form.gst_basis" type="radio" value="cash" /> Cash: GST in the receipts (by the taxable share of each
                            receipt's order, or of the period's sales)
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.net_off_credits" type="checkbox" /> Net off GST credits on purchases (set aside GST collected less
                        credits)
                    </label>
                    <div class="grid gap-1">
                        <label class="text-sm" for="ctax">Company income tax provision (% of net profit estimate)</label>
                        <Input id="ctax" v-model="form.company_tax_rate" type="number" min="0" max="100" step="0.01" class="w-32" />
                        <span class="text-xs text-amber-700 dark:text-amber-400">
                            Company tax — confirm the rate and whether the company is in profit with your accountant. 0 = no provision.
                        </span>
                        <InputError :message="form.errors.company_tax_rate" />
                    </div>
                </div>

                <div class="space-y-3 rounded-lg border p-3">
                    <h2 class="font-semibold">Figures for the advice</h2>
                    <div class="grid gap-1">
                        <label class="text-sm" for="fixed">Fixed costs a week ($)</label>
                        <Input id="fixed" v-model="form.fixed_costs_weekly" type="number" min="0" step="0.01" class="w-40" placeholder="Not set" />
                    </div>
                    <div class="flex items-end gap-2">
                        <div class="grid gap-1">
                            <label class="text-sm" for="vmin">Vault target, weeks: from</label>
                            <Input id="vmin" v-model="form.vault_weeks_min" type="number" min="0" step="0.5" class="w-24" />
                        </div>
                        <div class="grid gap-1">
                            <label class="text-sm" for="vmax">to</label>
                            <Input id="vmax" v-model="form.vault_weeks_max" type="number" min="0" step="0.5" class="w-24" />
                        </div>
                    </div>
                    <InputError :message="form.errors.vault_weeks_max" />
                    <div class="grid gap-1">
                        <label class="text-sm" for="salaries">Director salaries a fortnight, all directors ($)</label>
                        <Input
                            id="salaries"
                            v-model="form.director_salaries_fortnight"
                            type="number"
                            min="0"
                            step="0.01"
                            class="w-40"
                            placeholder="Not set"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label class="text-sm" for="debt">Debt Service priority (one per line, first paid first)</label>
                        <textarea id="debt" v-model="debtText" rows="4" class="w-64 rounded-md border bg-transparent p-2 text-sm"></textarea>
                    </div>
                </div>
            </section>

            <Button type="submit" :disabled="form.processing">Save settings</Button>
        </form>
    </AppLayout>
</template>
