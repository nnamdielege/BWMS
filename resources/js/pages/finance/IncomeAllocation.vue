<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Transfer {
    role: string;
    name: string;
    xero_code: string | null;
    institution: string | null;
    last4: string | null;
    weight: number | null;
    percent: number | null;
    amount: number;
    reference: string;
}
interface Channel {
    channel: string;
    label: string;
    taxable_ex: number;
    gst: number;
    gst_free_ex: number;
}
interface Advice {
    role: string;
    title: string;
    level: 'ok' | 'warn' | 'info';
    lines: string[];
}
interface Calc {
    date: string;
    from: string;
    to: string;
    next_date: string;
    receipts: { count: number; total: number };
    sales: { taxable_ex: number; gst: number; gst_free_ex: number; total_ex: number; cogs: number; cogs_percent: number | null; channels: Channel[] };
    gst: {
        basis: 'accrual' | 'cash';
        collected: number;
        credits: number;
        net_estimate: number;
        net_off: boolean;
        set_aside: number;
        cash: { matched: number; unmatched: number; assumed_fully_taxable: number; period_share: number | null } | null;
    };
    company_tax: { rate: number; shown: boolean; sales_ex: number; cogs: number; opex_oc: number; profit: number; amount: number };
    tax: { gst: number; company: number; total: number; required: number; capped: boolean; short: number };
    transfers: Transfer[];
    total: number;
    warnings: string[];
    advice: Advice[];
    review: {
        quarter: string;
        rows: { role: string; name: string; current: number; percent: number; target: number | null; suggested: number | null }[];
    } | null;
}
interface Receipt {
    id: number;
    received_on: string;
    description: string;
    amount: number;
    source: string;
    order: string | null;
}
interface Account {
    role: string;
    name: string;
    xero_code: string | null;
    is_split: boolean;
    takes_share: boolean;
}
interface Run {
    id: number;
    date: string;
    from: string;
    to: string;
    receipts: number;
    gst_basis: string;
    done_by: string | null;
    done_at: string;
    lines: Record<string, number>;
}

const props = defineProps<{
    period: { date: string; from: string; to: string };
    nextDate: string;
    doneForDate: boolean;
    calc: Calc;
    receipts: Receipt[];
    accounts: Account[];
    history: { runs: Run[]; totals: Record<string, number> };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/allocation' },
    { title: 'Income allocation', href: '/finance/allocation' },
];
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const day = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });

// Period and opening balances: a GET, so the figures are a preview until marked done.
const query = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
const period = reactive({
    date: props.period.date,
    from: props.period.from,
    to: props.period.to,
    opening: Object.fromEntries(props.accounts.filter((a) => a.is_split).map((a) => [a.role, query.get(`opening[${a.role}]`) ?? ''])) as Record<
        string,
        string
    >,
});
const params = () => ({ ...period, opening: Object.fromEntries(Object.entries(period.opening).filter(([, v]) => v !== '')) });
const recalc = () => router.get(route('finance.allocation.index'), params(), { preserveScroll: true, preserveState: true });
const newDate = () => router.get(route('finance.allocation.index'), { date: period.date }, { preserveScroll: true });

// Receipts
const receiptForm = useForm({ received_on: props.period.to, description: '', amount: '', order_number: '' });
const addReceipt = () =>
    receiptForm.post(route('finance.allocation.receipts.store'), {
        preserveScroll: true,
        onSuccess: () => receiptForm.reset('description', 'amount', 'order_number'),
    });
const removeReceipt = (r: Receipt) => {
    if (confirm(`Remove the receipt of ${money(r.amount)} on ${day(r.received_on)}?`))
        router.delete(route('finance.allocation.receipts.destroy', r.id), { preserveScroll: true });
};
const importForm = useForm<{ file: File | null }>({ file: null });
const sendImport = (e: Event) => {
    const input = e.target as HTMLInputElement;
    importForm.file = input.files?.[0] ?? null;
    if (importForm.file)
        importForm.post(route('finance.allocation.receipts.import'), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => (input.value = ''),
        });
};

// Transfers
const copied = ref('');
const copy = async (text: string, what: string) => {
    await navigator.clipboard.writeText(text);
    copied.value = what;
    setTimeout(() => (copied.value = ''), 2000);
};
const paid = (t: Transfer) => t.amount > 0;
const copyAll = () =>
    copy(
        props.calc.transfers
            .filter(paid)
            .map((t) => `${t.name}${t.xero_code ? ` (${t.xero_code})` : ''}\t${t.amount.toFixed(2)}\t${t.reference}`)
            .join('\n'),
        'all',
    );
const exportUrl = computed(() => route('finance.allocation.export', params()));
const markDone = () => {
    if (
        !confirm(
            `Record these transfers for ${day(props.calc.date)} as done in internet banking? This takes the ${props.calc.receipts.count} waiting receipts.`,
        )
    )
        return;
    router.post(route('finance.allocation.done'), { ...params(), receipts_total: props.calc.receipts.total }, { preserveScroll: true });
};

const levelClass = (l: string) =>
    l === 'warn' ? 'border-amber-500/60 bg-amber-50 dark:bg-amber-950/30' : l === 'ok' ? 'border-green-600/40' : 'border-border';
const historyRoles = computed(() =>
    props.accounts.filter((a) => a.role !== 'income' && (a.takes_share || a.role === 'tax' || props.history.totals[a.role] !== undefined)),
);
</script>

<template>
    <Head title="Income allocation" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold">Income allocation</h1>
                    <p class="text-sm text-muted-foreground">
                        How to split the receipts in Income across the bank accounts on an allocation day: Tax first, then the rest by weight. Advice
                        only — this never moves money. Make the transfers in internet banking, then mark them done.
                    </p>
                </div>
                <Button variant="outline" as-child><Link :href="route('finance.allocation.settings')">Accounts &amp; settings</Link></Button>
            </div>

            <!-- Period -->
            <form class="flex flex-wrap items-end gap-3 rounded-lg border p-3" @submit.prevent="recalc">
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="date">Allocation day</label>
                    <Input id="date" v-model="period.date" type="date" class="w-40" @change="newDate" />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="from">Period from</label>
                    <Input id="from" v-model="period.from" type="date" class="w-40" />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-muted-foreground" for="to">to</label>
                    <Input id="to" v-model="period.to" type="date" class="w-40" />
                </div>
                <div v-for="a in accounts.filter((x) => x.is_split && (x.takes_share || x.role === 'vault'))" :key="a.role" class="grid gap-1">
                    <label class="text-xs text-muted-foreground" :for="`open-${a.role}`">{{ a.name }} balance</label>
                    <Input :id="`open-${a.role}`" v-model="period.opening[a.role]" type="number" step="0.01" class="w-32" placeholder="Optional" />
                </div>
                <Button type="submit">Calculate</Button>
                <InputError :message="errors.from || errors.date || errors.to" />
                <p class="w-full text-xs text-muted-foreground">
                    Next allocation day: {{ day(nextDate) }}. Balances are optional: they check the Stock overdraft, supplier payments and the Vault
                    target. A negative Stock balance is the overdraft.
                </p>
            </form>

            <div v-if="doneForDate" class="rounded-lg border border-green-600/40 p-3 text-sm">
                The allocation for {{ day(period.date) }} is already done (see history below). Pick another day to plan the next one.
            </div>

            <ul v-if="calc.warnings.length" class="space-y-1">
                <li v-for="w in calc.warnings" :key="w" class="rounded-md border border-amber-500/60 bg-amber-50 p-2 text-sm dark:bg-amber-950/30">
                    {{ w }}
                </li>
            </ul>

            <!-- Receipts -->
            <section class="space-y-2">
                <h2 class="font-semibold">Receipts waiting in Income · {{ money(calc.receipts.total) }}</h2>
                <p class="text-xs text-muted-foreground">
                    Everything that landed in Income since the last allocation. Import the bank statement (credits only; rows already imported are
                    skipped) or add receipts by hand — not both for the same money. An order number (SO-2026-00042) ties a receipt to its order for
                    the cash GST basis.
                </p>
                <table v-if="receipts.length" class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-1">Date</th>
                            <th>Description</th>
                            <th>Order</th>
                            <th>From</th>
                            <th class="text-right">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in receipts" :key="r.id" class="border-b">
                            <td class="whitespace-nowrap py-1">{{ day(r.received_on) }}</td>
                            <td class="max-w-md truncate" :title="r.description">{{ r.description }}</td>
                            <td>{{ r.order ?? '—' }}</td>
                            <td class="text-xs text-muted-foreground">{{ r.source === 'csv' ? 'Bank CSV' : 'Entered' }}</td>
                            <td class="text-right tabular-nums">{{ money(r.amount) }}</td>
                            <td class="text-right"><Button size="sm" variant="ghost" @click="removeReceipt(r)">Remove</Button></td>
                        </tr>
                    </tbody>
                </table>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="addReceipt">
                    <div class="grid gap-1">
                        <label class="text-xs text-muted-foreground" for="r-date">Date</label>
                        <Input id="r-date" v-model="receiptForm.received_on" type="date" class="w-40" required />
                    </div>
                    <div class="grid flex-1 gap-1">
                        <label class="text-xs text-muted-foreground" for="r-desc">Description</label>
                        <Input id="r-desc" v-model="receiptForm.description" maxlength="255" required />
                    </div>
                    <div class="grid gap-1">
                        <label class="text-xs text-muted-foreground" for="r-amount">Amount ($ inc GST)</label>
                        <Input id="r-amount" v-model="receiptForm.amount" type="number" min="0.01" step="0.01" class="w-36" required />
                    </div>
                    <div class="grid gap-1">
                        <label class="text-xs text-muted-foreground" for="r-order">Order (optional)</label>
                        <Input id="r-order" v-model="receiptForm.order_number" class="w-40" placeholder="SO-2026-00042" />
                    </div>
                    <Button type="submit" :disabled="receiptForm.processing">Add receipt</Button>
                    <label class="cursor-pointer text-sm underline">
                        Import bank CSV
                        <input type="file" accept=".csv,text/csv" class="hidden" :disabled="importForm.processing" @change="sendImport" />
                    </label>
                </form>
                <InputError
                    :message="
                        receiptForm.errors.received_on ||
                        receiptForm.errors.description ||
                        receiptForm.errors.amount ||
                        receiptForm.errors.order_number ||
                        importForm.errors.file ||
                        errors.receipt
                    "
                />
            </section>

            <!-- Tax -->
            <section class="space-y-2">
                <h2 class="font-semibold">Tax · {{ calc.transfers.find((t) => t.role === 'tax')?.name ?? 'Tax' }} · {{ money(calc.tax.total) }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-lg border p-3 text-sm">
                        <div class="mb-2 font-medium">
                            GST from sales dispatched {{ day(calc.from) }} – {{ day(calc.to) }}
                            <span class="text-xs font-normal text-muted-foreground"
                                >({{ calc.gst.basis === 'cash' ? 'cash basis' : 'accrual basis' }})</span
                            >
                        </div>
                        <table class="w-full text-left">
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th>Channel</th>
                                    <th class="text-right">Taxable ex GST</th>
                                    <th class="text-right">GST</th>
                                    <th class="text-right">GST-free</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in calc.sales.channels" :key="c.channel">
                                    <td>{{ c.label }}</td>
                                    <td class="text-right tabular-nums">{{ money(c.taxable_ex) }}</td>
                                    <td class="text-right tabular-nums">{{ money(c.gst) }}</td>
                                    <td class="text-right tabular-nums">{{ money(c.gst_free_ex) }}</td>
                                </tr>
                                <tr class="border-t font-medium">
                                    <td>All channels</td>
                                    <td class="text-right tabular-nums">{{ money(calc.sales.taxable_ex) }}</td>
                                    <td class="text-right tabular-nums">{{ money(calc.sales.gst) }}</td>
                                    <td class="text-right tabular-nums">{{ money(calc.sales.gst_free_ex) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                            <template v-if="calc.gst.basis === 'cash' && calc.gst.cash">
                                <dt>GST in the receipts</dt>
                                <dd class="text-right tabular-nums">{{ money(calc.gst.collected) }}</dd>
                                <dd class="col-span-2 text-xs text-muted-foreground">
                                    {{ calc.gst.cash.matched }} matched to an order, apportioned by its taxable share;
                                    {{ calc.gst.cash.unmatched }} by the period's taxable share<template v-if="calc.gst.cash.period_share !== null">
                                        ({{ calc.gst.cash.period_share }}%)</template
                                    >.
                                </dd>
                            </template>
                            <template v-else>
                                <dt>GST collected</dt>
                                <dd class="text-right tabular-nums">{{ money(calc.gst.collected) }}</dd>
                            </template>
                            <dt>GST credits on purchases received</dt>
                            <dd class="text-right tabular-nums">−{{ money(calc.gst.credits) }}</dd>
                            <dt>Net GST payable estimate</dt>
                            <dd class="text-right tabular-nums">{{ money(calc.gst.net_estimate) }}</dd>
                            <dt class="font-medium">GST set aside</dt>
                            <dd class="text-right font-medium tabular-nums">{{ money(calc.tax.gst) }}</dd>
                            <dd class="col-span-2 text-xs text-muted-foreground">
                                {{
                                    calc.gst.net_off
                                        ? 'Net of GST credits (set in settings).'
                                        : 'The full GST collected; credits are shown for information (tick "net off GST credits" in settings to set aside the net).'
                                }}
                            </dd>
                        </dl>
                    </div>
                    <div v-if="calc.company_tax.shown" class="rounded-lg border p-3 text-sm">
                        <div class="mb-2 font-medium">Company income tax provision · {{ calc.company_tax.rate }}%</div>
                        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                            <dt>Sales ex GST</dt>
                            <dd class="text-right tabular-nums">{{ money(calc.company_tax.sales_ex) }}</dd>
                            <dt>Cost of goods sold</dt>
                            <dd class="text-right tabular-nums">−{{ money(calc.company_tax.cogs) }}</dd>
                            <dt>OpEx and Owner's Comp transfers</dt>
                            <dd class="text-right tabular-nums">−{{ money(calc.company_tax.opex_oc) }}</dd>
                            <dt>Net profit estimate</dt>
                            <dd class="text-right tabular-nums">{{ money(calc.company_tax.profit) }}</dd>
                            <dt class="font-medium">Company tax set aside</dt>
                            <dd class="text-right font-medium tabular-nums">{{ money(calc.tax.company) }}</dd>
                        </dl>
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                            Company tax — confirm the rate and whether the company is in profit with your accountant.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Transfers -->
            <section class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">
                        Transfers for {{ day(calc.date) }} from {{ accounts.find((a) => a.role === 'income')?.name ?? 'Income' }}
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" :disabled="!calc.receipts.total" @click="copyAll">{{
                            copied === 'all' ? 'Copied' : 'Copy all'
                        }}</Button>
                        <Button variant="outline" size="sm" as-child><a :href="exportUrl">Download CSV</a></Button>
                        <Button size="sm" :disabled="!calc.receipts.total || doneForDate" @click="markDone">Mark transfers done</Button>
                    </div>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-1">To account</th>
                            <th>Xero</th>
                            <th>Bank</th>
                            <th class="text-right">Share</th>
                            <th class="text-right">Amount</th>
                            <th class="pl-4">Reference</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="t in calc.transfers" :key="t.role">
                            <tr class="border-b" :class="{ 'text-muted-foreground': !paid(t) }">
                                <td class="py-1 font-medium">{{ t.name }}</td>
                                <td>{{ t.xero_code ?? '—' }}</td>
                                <td class="text-xs">{{ [t.institution, t.last4 ? `…${t.last4}` : null].filter(Boolean).join(' ') || '—' }}</td>
                                <td class="text-right tabular-nums">{{ t.role === 'tax' ? 'calculated' : `${t.percent}%` }}</td>
                                <td class="text-right font-medium tabular-nums">{{ money(t.amount) }}</td>
                                <td class="pl-4 font-mono text-xs">{{ t.reference }}</td>
                                <td class="text-right">
                                    <Button v-if="paid(t)" size="sm" variant="ghost" @click="copy(t.amount.toFixed(2), t.role)">
                                        {{ copied === t.role ? 'Copied' : 'Copy $' }}
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="t.role === 'tax' && calc.company_tax.shown" class="border-b text-xs text-muted-foreground">
                                <td colspan="7" class="py-1 pl-4">GST {{ money(calc.tax.gst) }} + company tax {{ money(calc.tax.company) }}</td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-1" colspan="4">Total (equals receipts {{ money(calc.receipts.total) }})</td>
                            <td class="text-right tabular-nums">{{ money(calc.total) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                <InputError :message="errors.receipts" />
            </section>

            <!-- Advice -->
            <section v-if="calc.advice.length" class="space-y-2">
                <h2 class="font-semibold">Advice</h2>
                <p class="text-xs text-muted-foreground">Notes only: they never change the split. Change weights in settings if you agree.</p>
                <div class="grid gap-3 md:grid-cols-2">
                    <div v-for="a in calc.advice" :key="a.role" class="rounded-lg border p-3 text-sm" :class="levelClass(a.level)">
                        <div class="font-medium">{{ a.title }}</div>
                        <p v-for="l in a.lines" :key="l" class="mt-1">{{ l }}</p>
                    </div>
                </div>
            </section>

            <section v-if="calc.review" class="space-y-2">
                <h2 class="font-semibold">Quarterly review · {{ calc.review.quarter }}</h2>
                <p class="text-xs text-muted-foreground">
                    Current weights against their targets: move 1 point a quarter toward each target. Set targets in settings.
                </p>
                <table class="w-full max-w-2xl text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-1">Account</th>
                            <th class="text-right">Weight</th>
                            <th class="text-right">Share</th>
                            <th class="text-right">Target</th>
                            <th class="text-right">Next quarter</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in calc.review.rows" :key="r.role" class="border-b">
                            <td class="py-1">{{ r.name }}</td>
                            <td class="text-right tabular-nums">{{ r.current }}</td>
                            <td class="text-right tabular-nums">{{ r.percent }}%</td>
                            <td class="text-right tabular-nums">{{ r.target ?? '—' }}</td>
                            <td class="text-right tabular-nums" :class="{ 'font-medium': r.suggested !== null && r.suggested !== r.current }">
                                {{ r.suggested ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- History -->
            <section class="space-y-2">
                <h2 class="font-semibold">History</h2>
                <p v-if="!history.runs.length" class="text-sm text-muted-foreground">No allocations recorded yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr>
                                <th class="py-1">Allocation day</th>
                                <th>Period</th>
                                <th class="text-right">Receipts</th>
                                <th v-for="a in historyRoles" :key="a.role" class="text-right">{{ a.name }}</th>
                                <th class="pl-3">Done by</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in history.runs" :key="r.id" class="border-b">
                                <td class="whitespace-nowrap py-1">{{ day(r.date) }}</td>
                                <td class="whitespace-nowrap text-xs">{{ day(r.from) }} – {{ day(r.to) }} · {{ r.gst_basis }}</td>
                                <td class="text-right tabular-nums">{{ money(r.receipts) }}</td>
                                <td v-for="a in historyRoles" :key="a.role" class="text-right tabular-nums">{{ money(r.lines[a.role] ?? null) }}</td>
                                <td class="pl-3 text-xs">{{ r.done_by ?? '—' }}</td>
                                <td class="text-right"><a class="text-xs underline" :href="route('finance.allocation.runs.export', r.id)">CSV</a></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td class="py-1" colspan="2">Total</td>
                                <td class="text-right tabular-nums">{{ money(history.runs.reduce((s, r) => s + r.receipts, 0)) }}</td>
                                <td v-for="a in historyRoles" :key="a.role" class="text-right tabular-nums">
                                    {{ money(history.totals[a.role] ?? 0) }}
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
