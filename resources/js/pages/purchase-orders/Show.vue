<script setup lang="ts">
import BudgetBar from '@/components/BudgetBar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type BudgetSummary } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Line {
    id: number;
    product: { id: number; sku: string; name: string; base_unit: string };
    unit: string;
    base_qty_per_unit: number;
    qty_ordered: number;
    qty_ordered_base: number;
    qty_received_base: number;
    qty_rejected_base: number;
    unit_cost: string;
    tax_rate: string;
    line_subtotal: string;
    notes: string | null;
    sell_price: number | null;
    gm_percent: number | null;
}

const props = defineProps<{
    po: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        order_date: string;
        expected_date: string | null;
        sent_at: string | null;
        supplier_ref: string | null;
        notes: string | null;
        subtotal: string;
        tax_total: string;
        total: string;
        close_reason: string | null;
        legacy: boolean;
        legacy_status: string | null;
        supplier: { id: number; name: string; email: string | null; cc_emails: string | null; contact_name: string | null };
        site: string;
        created_by: string | null;
        payment_due_date: string | null;
        payment_terms: string | null;
        payment_terms_days: number;
        approved_at: string | null;
        approved_by: string | null;
        override_reason: string | null;
        paid_at: string | null;
        paid_by: string | null;
        min_order_value: number | null;
        below_minimum: boolean;
        lines: Line[];
        receipts: {
            id: number;
            number: string;
            received_at: string;
            received_by: string;
            supplier_ref: string | null;
            lines: {
                po_line_id: number;
                accepted: number;
                damaged: number;
                rejected: number;
                location: string | null;
                batch: string | null;
                reason: string | null;
                note: string | null;
            }[];
        }[];
        emails: {
            id: number;
            to: string;
            cc: string | null;
            status: string;
            error: string | null;
            queued_by: string | null;
            created_at: string;
            sent_at: string | null;
        }[];
    };
    can: { manage: boolean; receive: boolean; edit: boolean; approve: boolean; pay: boolean };
    budget: BudgetSummary;
    gmThreshold: number;
}>();

// Approval: under budget it's one click; over budget the override and a reason are required.
const approval = useForm({ override: false, override_reason: '' });
const approve = () => approval.post(route('purchase-orders.approve', props.po.id), { preserveScroll: true });
const markPaid = () => {
    const early = ['approved', 'sent', 'partially_received'].includes(props.po.status)
        ? ' It can still be sent and received; it shows as paid once received.'
        : '';
    if (confirm(`Mark ${props.po.number} as paid? Record the payment in Xero as usual.${early}`))
        router.post(route('purchase-orders.paid', props.po.id), {}, { preserveScroll: true });
};
const lowMargin = (l: Line) => l.gm_percent !== null && l.gm_percent < props.gmThreshold;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase orders', href: '/purchase-orders' },
    { title: props.po.number, href: '#' },
];
const errors = computed(() => usePage().props.errors as Record<string, string>);
const fmt = (iso: string) => new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' });
const productOf = (lineId: number) => props.po.lines.find((l) => l.id === lineId)?.product.sku;

const showEmail = ref(false);
const email = useForm({
    to: props.po.supplier.email ?? '',
    cc: props.po.supplier.cc_emails ?? '',
    subject: `Purchase order ${props.po.number}`,
    message: '',
});
const sendEmail = () => email.post(route('purchase-orders.email', props.po.id), { preserveScroll: true, onSuccess: () => (showEmail.value = false) });
const markSent = () => router.post(route('purchase-orders.sent', props.po.id), {}, { preserveScroll: true });
const cancel = () => {
    const reason = prompt('Reason for cancelling (optional)');
    if (reason !== null) router.post(route('purchase-orders.cancel', props.po.id), { reason }, { preserveScroll: true });
};
const closeShort = () => {
    const reason = prompt('Why will the rest not arrive? (required)');
    if (reason) router.post(route('purchase-orders.close', props.po.id), { reason }, { preserveScroll: true });
};
const retry = (id: number) => router.post(route('purchase-orders.email.retry', [props.po.id, id]), {}, { preserveScroll: true });
const cancellable = computed(() => ['suggested', 'draft', 'approved', 'sent'].includes(props.po.status));
// Emails come newest first. A failed attempt shows its reason at once, also while it is being retried.
const lastEmail = computed(() => props.po.emails[0] ?? null);
const lastEmailFailed = computed(() => !!lastEmail.value && lastEmail.value.status !== 'sent' && !!lastEmail.value.error);
const emailStatus = (e: { status: string; error: string | null }) =>
    e.status === 'sent' ? 'sent' : e.error ? `failed: ${e.error}${e.status === 'queued' ? ' (retrying automatically)' : ''}` : e.status;
</script>

<template>
    <Head :title="po.number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="font-mono text-xl font-semibold">{{ po.number }}</h1>
                <span class="rounded bg-muted px-2 py-0.5 text-sm">{{ po.status_label }}</span>
                <span v-if="po.legacy" class="text-sm text-muted-foreground"
                    >Imported from legacy BWMS (was "{{ po.legacy_status }}"); no stock was moved.</span
                >
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button variant="outline" as-child><a :href="route('purchase-orders.pdf', po.id)" target="_blank">PDF</a></Button>
                    <Button v-if="can.edit" variant="outline" as-child><Link :href="route('purchase-orders.edit', po.id)">Edit</Link></Button>
                    <Button
                        v-if="can.manage && !can.approve && po.status !== 'cancelled' && !po.legacy"
                        variant="outline"
                        @click="showEmail = !showEmail"
                        >Email supplier</Button
                    >
                    <Button v-if="can.manage && po.status === 'approved'" variant="outline" @click="markSent">Mark as sent</Button>
                    <Button v-if="can.receive" as-child><Link :href="route('purchase-orders.receive', po.id)">Receive</Link></Button>
                    <Button v-if="can.pay" @click="markPaid">Mark paid</Button>
                    <Button v-if="can.manage && po.status === 'partially_received'" variant="ghost" @click="closeShort">Close short</Button>
                    <Button v-if="can.manage && cancellable && !po.receipts.length" variant="ghost" @click="cancel">Cancel</Button>
                </div>
            </header>
            <InputError :message="errors.po" />

            <p
                v-if="po.status === 'suggested'"
                class="rounded-lg border border-purple-300 bg-purple-50 p-3 text-sm text-purple-900 dark:bg-purple-950 dark:text-purple-100"
            >
                Suggested from sales velocity. The next suggestion run replaces its lines until it's approved; editing it makes it a draft the run
                leaves alone.
            </p>
            <p
                v-if="po.below_minimum && po.min_order_value !== null"
                class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                Below {{ po.supplier.name }}'s minimum order of ${{ po.min_order_value.toFixed(2) }} ex GST.
            </p>

            <BudgetBar :budget="budget" />

            <form v-if="can.approve" class="space-y-2 rounded-lg border p-4" :class="{ 'border-red-300': budget.over }" @submit.prevent="approve">
                <template v-if="budget.over">
                    <p class="text-sm font-semibold text-red-700 dark:text-red-400">
                        {{
                            budget.budget === null
                                ? 'No purchasing budget is set for this week.'
                                : `This PO is over this week's remaining budget by $${(budget.po_total! - budget.remaining).toFixed(2)}.`
                        }}
                    </p>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="approval.override" type="checkbox" /> Approve over budget anyway</label
                    >
                    <textarea
                        v-if="approval.override"
                        v-model="approval.override_reason"
                        rows="2"
                        maxlength="500"
                        class="w-full max-w-2xl rounded-md border bg-background p-2 text-sm"
                        placeholder="Reason (required, kept on the PO)"
                    />
                    <InputError :message="approval.errors.override_reason" />
                </template>
                <InputError :message="errors.budget" />
                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="approval.processing || (budget.over && (!approval.override || !approval.override_reason.trim()))"
                        >Approve</Button
                    >
                    <span class="text-xs text-muted-foreground"
                        >Approve before sending. Sets the payment due date from the supplier's terms ({{
                            po.payment_terms_days ? `${po.payment_terms_days} days` : 'COD'
                        }}).</span
                    >
                </div>
            </form>
            <div
                v-if="lastEmailFailed && lastEmail"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
            >
                <span class="font-semibold">Email to {{ lastEmail.to }} {{ emailStatus(lastEmail) }}</span>
                <Button v-if="lastEmail.status === 'failed' && can.manage" size="sm" variant="outline" class="ml-auto" @click="retry(lastEmail.id)"
                    >Retry</Button
                >
            </div>

            <form v-if="showEmail" class="max-w-2xl space-y-2 rounded-lg border p-4" @submit.prevent="sendEmail">
                <p class="text-sm text-muted-foreground">
                    The PDF is attached automatically. {{ po.status === 'approved' ? 'Sending marks this PO as sent.' : '' }}
                </p>
                <Input v-model="email.to" type="email" placeholder="To" required />
                <InputError :message="email.errors.to" />
                <Input v-model="email.cc" placeholder="CC (comma-separated)" />
                <Input v-model="email.subject" required />
                <textarea
                    v-model="email.message"
                    rows="3"
                    class="w-full rounded-md border bg-background p-2 text-sm"
                    placeholder="Message (optional)"
                />
                <Button type="submit" :disabled="email.processing">Queue email</Button>
            </form>

            <div class="grid gap-4 text-sm sm:grid-cols-4">
                <div>
                    <div class="text-muted-foreground">Supplier</div>
                    <Link :href="route('suppliers.edit', po.supplier.id)" class="underline">{{ po.supplier.name }}</Link>
                </div>
                <div>
                    <div class="text-muted-foreground">Deliver to</div>
                    {{ po.site }}
                </div>
                <div>
                    <div class="text-muted-foreground">Ordered / required</div>
                    {{ po.order_date }} / {{ po.expected_date ?? '—' }}
                </div>
                <div>
                    <div class="text-muted-foreground">Sent</div>
                    {{ po.sent_at ? fmt(po.sent_at) : '—' }}
                </div>
                <div>
                    <div class="text-muted-foreground">Approved</div>
                    {{ po.approved_at ? `${fmt(po.approved_at)} · ${po.approved_by ?? ''}` : '—' }}
                </div>
                <div>
                    <div class="text-muted-foreground">Payment due</div>
                    {{ po.payment_due_date ?? '—' }}
                    <span class="text-muted-foreground"
                        >({{ po.payment_terms || (po.payment_terms_days ? `${po.payment_terms_days} days` : 'COD') }})</span
                    >
                </div>
                <div>
                    <div class="text-muted-foreground">Paid</div>
                    {{ po.paid_at ? `${fmt(po.paid_at)} · ${po.paid_by ?? ''}` : '—' }}
                </div>
                <div v-if="po.override_reason" class="text-amber-800 dark:text-amber-300 sm:col-span-4">
                    <span class="font-semibold">Approved over budget:</span> {{ po.override_reason }}
                </div>
                <div v-if="po.close_reason" class="sm:col-span-4">
                    <span class="text-muted-foreground">Closed/cancelled because:</span> {{ po.close_reason }}
                </div>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Product</th>
                        <th>Pack</th>
                        <th class="text-right">Ordered</th>
                        <th class="text-right">Received</th>
                        <th class="text-right">Rejected</th>
                        <th class="text-right">Outstanding</th>
                        <th class="text-right">Cost/pack</th>
                        <th class="text-right">Sell/pack</th>
                        <th class="text-right">GM %</th>
                        <th class="text-right">Line</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in po.lines" :key="l.id" class="border-b" :class="{ 'bg-red-50 dark:bg-red-950/40': lowMargin(l) }">
                        <td class="py-2">
                            <span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}
                        </td>
                        <td>{{ l.unit }}{{ l.base_qty_per_unit > 1 ? ` ×${l.base_qty_per_unit}` : '' }}</td>
                        <td class="text-right tabular-nums">
                            {{ l.qty_ordered }} <span class="text-xs text-muted-foreground">({{ l.qty_ordered_base }})</span>
                        </td>
                        <td class="text-right tabular-nums">{{ l.qty_received_base }}</td>
                        <td class="text-right tabular-nums" :class="{ 'text-red-600': l.qty_rejected_base }">{{ l.qty_rejected_base || '' }}</td>
                        <td class="text-right tabular-nums">{{ Math.max(0, l.qty_ordered_base - l.qty_received_base) }}</td>
                        <td class="text-right tabular-nums">${{ Number(l.unit_cost).toFixed(2) }}</td>
                        <td class="text-right tabular-nums">{{ l.sell_price === null ? '—' : `$${l.sell_price.toFixed(2)}` }}</td>
                        <td class="text-right tabular-nums" :class="{ 'font-semibold text-red-600': lowMargin(l) }">
                            {{ l.gm_percent === null ? '—' : `${l.gm_percent.toFixed(1)}%` }}
                        </td>
                        <td class="text-right tabular-nums">${{ Number(l.line_subtotal).toFixed(2) }}</td>
                    </tr>
                </tbody>
                <tfoot class="text-right">
                    <tr>
                        <td colspan="9" class="pt-2">Subtotal</td>
                        <td class="pt-2 tabular-nums">${{ Number(po.subtotal).toFixed(2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="9">GST</td>
                        <td class="tabular-nums">${{ Number(po.tax_total).toFixed(2) }}</td>
                    </tr>
                    <tr class="font-semibold">
                        <td colspan="9">Total</td>
                        <td class="tabular-nums">${{ Number(po.total).toFixed(2) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p class="text-xs text-muted-foreground">
                Received / rejected / outstanding are in base units. Sell is the product's default sell price for the pack, ex GST; lines under
                {{ gmThreshold }}% GM are highlighted.
            </p>

            <section v-if="po.receipts.length">
                <h2 class="mb-2 font-semibold">Receipts</h2>
                <div v-for="r in po.receipts" :key="r.id" class="mb-3 rounded-md border p-3 text-sm">
                    <div class="mb-1 font-medium">
                        <span class="font-mono">{{ r.number }}</span> · {{ fmt(r.received_at) }} · {{ r.received_by }}
                        <span v-if="r.supplier_ref" class="text-muted-foreground">· docket {{ r.supplier_ref }}</span>
                    </div>
                    <ul class="space-y-0.5">
                        <li v-for="(rl, i) in r.lines" :key="i">
                            <span class="font-mono">{{ productOf(rl.po_line_id) }}</span
                            >: {{ rl.accepted }} accepted<span v-if="rl.location"> → {{ rl.location }}</span>
                            <span v-if="rl.damaged" class="text-amber-700">, {{ rl.damaged }} damaged → quarantine</span>
                            <span v-if="rl.rejected" class="text-red-700">, {{ rl.rejected }} rejected</span>
                            <span v-if="rl.batch" class="text-muted-foreground"> · {{ rl.batch }}</span>
                            <span v-if="rl.reason" class="text-muted-foreground"> · {{ rl.reason.replace('_', ' ') }}</span>
                            <span v-if="rl.note" class="text-muted-foreground"> — {{ rl.note }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-if="po.emails.length">
                <h2 class="mb-2 font-semibold">Emails</h2>
                <table class="w-full text-left text-sm">
                    <tbody>
                        <tr v-for="e in po.emails" :key="e.id" class="border-b">
                            <td class="py-1">{{ fmt(e.created_at) }}</td>
                            <td>
                                {{ e.to }}<span v-if="e.cc" class="text-muted-foreground"> (cc {{ e.cc }})</span>
                            </td>
                            <td :class="{ 'text-red-600': e.status !== 'sent' && e.error, 'text-green-700': e.status === 'sent' }">
                                {{ emailStatus(e) }}
                            </td>
                            <td>{{ e.queued_by }}</td>
                            <td class="text-right">
                                <Button v-if="e.status === 'failed' && can.manage" size="sm" variant="outline" @click="retry(e.id)">Retry</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <p v-if="po.notes" class="whitespace-pre-line text-sm"><span class="text-muted-foreground">Notes:</span> {{ po.notes }}</p>
        </div>
    </AppLayout>
</template>
