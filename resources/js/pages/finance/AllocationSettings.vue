<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
        payg_rate: number;
    };
    staff: Staff[];
}>();

interface Staff {
    id: number;
    name: string;
    role: string | null;
    pay_type: 'hourly' | 'salary';
    rate: number;
    hours_per_week: number | null;
    super_percent: number;
    start_date: string;
    end_date: string | null;
    is_active: boolean;
}

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
    payg_rate: props.settings.payg_rate,
});

// Staff: one form for adding and editing. Pay facts only — no TFN, bank details or personal data.
const editingStaff = ref<number | null>(null);
const blankStaff = () => ({
    name: '',
    role: '',
    pay_type: 'hourly' as 'hourly' | 'salary',
    rate: '' as string | number,
    hours_per_week: '' as string | number | null,
    super_percent: 12 as string | number,
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '' as string | null,
    is_active: true,
});
const staffForm = useForm(blankStaff());
const editStaff = (s: Staff) => {
    editingStaff.value = s.id;
    staffForm.clearErrors();
    Object.assign(staffForm, { ...s, role: s.role ?? '', hours_per_week: s.hours_per_week ?? '', end_date: s.end_date ?? '' });
};
const resetStaff = () => {
    editingStaff.value = null;
    staffForm.clearErrors();
    Object.assign(staffForm, blankStaff());
};
const saveStaff = () =>
    editingStaff.value === null
        ? staffForm.post(route('finance.allocation.staff.store'), { preserveScroll: true, onSuccess: resetStaff })
        : staffForm.put(route('finance.allocation.staff.update', editingStaff.value), { preserveScroll: true, onSuccess: resetStaff });
const removeStaff = (s: Staff) => {
    if (confirm(`Remove ${s.name} from payroll? Untick Active instead to keep the record.`))
        router.delete(route('finance.allocation.staff.destroy', s.id), { preserveScroll: true });
};
const payLabel = (s: Staff) =>
    s.pay_type === 'salary'
        ? `$${s.rate.toLocaleString('en-AU')} a year`
        : `$${s.rate.toLocaleString('en-AU', { minimumFractionDigits: 2 })} an hour × ${s.hours_per_week} h`;
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
const roleLabel: Record<string, string> = {
    income: 'Source: receipts land here',
    tax: 'Calculated: GST + company tax',
    payroll: 'Calculated: wages + super',
};
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
                                    <span v-else-if="a.role === 'payroll'" class="text-xs text-muted-foreground">
                                        {{ a.is_active ? 'On' : 'Off until a staff member is active' }}
                                    </span>
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
                    <div class="grid gap-1">
                        <label class="text-sm" for="payg">Estimated PAYG withholding (% of gross wages)</label>
                        <Input id="payg" v-model="form.payg_rate" type="number" min="0" max="100" step="0.01" class="w-32" />
                        <span class="text-xs text-muted-foreground">
                            0 = don't show it. PAYG is paid to the ATO from Payroll out of gross wages, so it doesn't change the Payroll transfer.
                        </span>
                        <InputError :message="form.errors.payg_rate" />
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

        <section class="space-y-2 p-4 pt-0">
            <h2 class="font-semibold">Staff</h2>
            <p class="text-xs text-muted-foreground">
                For the Payroll estimate only: each period's wages for the days someone is employed, plus super, go to Payroll after Tax and before
                the split. Keep no TFNs, bank details or other personal data here — payroll itself stays in Xero Payroll. Hourly: rate × hours a week
                × days ÷ 7. Salary: a year's pay × days ÷ 365.
            </p>
            <table v-if="staff.length" class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-1">Name</th>
                        <th>Role</th>
                        <th>Pay</th>
                        <th class="text-right">Super</th>
                        <th>Dates</th>
                        <th>Active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in staff" :key="s.id" class="border-b" :class="{ 'text-muted-foreground': !s.is_active }">
                        <td class="py-1 font-medium">{{ s.name }}</td>
                        <td>{{ s.role ?? '—' }}</td>
                        <td>{{ payLabel(s) }}</td>
                        <td class="text-right tabular-nums">{{ s.super_percent }}%</td>
                        <td class="whitespace-nowrap text-xs">{{ s.start_date }} – {{ s.end_date ?? 'ongoing' }}</td>
                        <td>{{ s.is_active ? 'Yes' : 'No' }}</td>
                        <td class="whitespace-nowrap text-right">
                            <Button size="sm" variant="outline" @click="editStaff(s)">Edit</Button>
                            <Button size="sm" variant="ghost" @click="removeStaff(s)">Remove</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <form class="flex flex-wrap items-end gap-2 rounded-lg border p-3" @submit.prevent="saveStaff">
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-name">Name</label>
                    <Input id="s-name" v-model="staffForm.name" class="w-40" maxlength="100" required />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-role">Role</label>
                    <Input id="s-role" v-model="staffForm.role" class="w-36" maxlength="100" />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-type">Pay type</label>
                    <select id="s-type" v-model="staffForm.pay_type" class="h-9 rounded-md border bg-transparent px-2 text-sm">
                        <option value="hourly">Hourly</option>
                        <option value="salary">Salary</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-rate">{{
                        staffForm.pay_type === 'salary' ? 'Salary a year ($)' : 'Rate an hour ($)'
                    }}</label>
                    <Input id="s-rate" v-model="staffForm.rate" type="number" min="0.01" step="0.01" class="w-32" required />
                </div>
                <div v-if="staffForm.pay_type === 'hourly'" class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-hours">Hours a week</label>
                    <Input id="s-hours" v-model="staffForm.hours_per_week" type="number" min="0.25" step="0.25" class="w-24" required />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-super">Super %</label>
                    <Input id="s-super" v-model="staffForm.super_percent" type="number" min="0" max="100" step="0.01" class="w-20" required />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-start">Start</label>
                    <Input id="s-start" v-model="staffForm.start_date" type="date" class="w-40" required />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="s-end">End (optional)</label>
                    <Input id="s-end" v-model="staffForm.end_date" type="date" class="w-40" />
                </div>
                <label class="flex items-center gap-1 text-sm"><input v-model="staffForm.is_active" type="checkbox" /> Active</label>
                <Button type="submit" :disabled="staffForm.processing">{{ editingStaff === null ? 'Add staff member' : 'Save' }}</Button>
                <Button v-if="editingStaff !== null" type="button" variant="ghost" @click="resetStaff">Cancel</Button>
            </form>
            <InputError
                :message="
                    staffForm.errors.name ||
                    staffForm.errors.pay_type ||
                    staffForm.errors.rate ||
                    staffForm.errors.hours_per_week ||
                    staffForm.errors.super_percent ||
                    staffForm.errors.start_date ||
                    staffForm.errors.end_date
                "
            />
        </section>
    </AppLayout>
</template>
