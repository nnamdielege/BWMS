<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
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
        lines: Line[];
        receipts: {
            id: number;
            number: string;
            received_at: string;
            received_by: string;
            supplier_ref: string | null;
            lines: { po_line_id: number; accepted: number; damaged: number; rejected: number; location: string | null; batch: string | null; reason: string | null; note: string | null }[];
        }[];
        emails: { id: number; to: string; cc: string | null; status: string; error: string | null; queued_by: string | null; created_at: string; sent_at: string | null }[];
    };
    can: { manage: boolean; receive: boolean; edit: boolean };
}>();

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
const isDraftOrSent = computed(() => ['draft', 'sent'].includes(props.po.status));
</script>

<template>
    <Head :title="po.number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="font-mono text-xl font-semibold">{{ po.number }}</h1>
                <span class="rounded bg-muted px-2 py-0.5 text-sm">{{ po.status_label }}</span>
                <span v-if="po.legacy" class="text-sm text-muted-foreground">Imported from legacy BWMS (was "{{ po.legacy_status }}"); no stock was moved.</span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button variant="outline" as-child><a :href="route('purchase-orders.pdf', po.id)" target="_blank">PDF</a></Button>
                    <Button v-if="can.edit" variant="outline" as-child><Link :href="route('purchase-orders.edit', po.id)">Edit</Link></Button>
                    <Button v-if="can.manage && po.status !== 'cancelled' && !po.legacy" variant="outline" @click="showEmail = !showEmail">Email supplier</Button>
                    <Button v-if="can.manage && po.status === 'draft'" variant="outline" @click="markSent">Mark as sent</Button>
                    <Button v-if="can.receive" as-child><Link :href="route('purchase-orders.receive', po.id)">Receive</Link></Button>
                    <Button v-if="can.manage && po.status === 'partially_received'" variant="ghost" @click="closeShort">Close short</Button>
                    <Button v-if="can.manage && isDraftOrSent && !po.receipts.length" variant="ghost" @click="cancel">Cancel</Button>
                </div>
            </header>
            <InputError :message="errors.po" />

            <form v-if="showEmail" class="max-w-2xl space-y-2 rounded-lg border p-4" @submit.prevent="sendEmail">
                <p class="text-sm text-muted-foreground">The PDF is attached automatically. {{ po.status === 'draft' ? 'Sending marks this PO as sent.' : '' }}</p>
                <Input v-model="email.to" type="email" placeholder="To" required />
                <InputError :message="email.errors.to" />
                <Input v-model="email.cc" placeholder="CC (comma-separated)" />
                <Input v-model="email.subject" required />
                <textarea v-model="email.message" rows="3" class="w-full rounded-md border bg-background p-2 text-sm" placeholder="Message (optional)" />
                <Button type="submit" :disabled="email.processing">Queue email</Button>
            </form>

            <div class="grid gap-4 text-sm sm:grid-cols-4">
                <div><div class="text-muted-foreground">Supplier</div><Link :href="route('suppliers.edit', po.supplier.id)" class="underline">{{ po.supplier.name }}</Link></div>
                <div><div class="text-muted-foreground">Deliver to</div>{{ po.site }}</div>
                <div><div class="text-muted-foreground">Ordered / required</div>{{ po.order_date }} / {{ po.expected_date ?? '—' }}</div>
                <div><div class="text-muted-foreground">Sent</div>{{ po.sent_at ? fmt(po.sent_at) : '—' }}</div>
                <div v-if="po.close_reason" class="sm:col-span-4"><span class="text-muted-foreground">Closed/cancelled because:</span> {{ po.close_reason }}</div>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th class="py-2">Product</th><th>Pack</th><th class="text-right">Ordered</th><th class="text-right">Received</th>
                        <th class="text-right">Rejected</th><th class="text-right">Outstanding</th><th class="text-right">Cost/pack</th><th class="text-right">Line</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in po.lines" :key="l.id" class="border-b">
                        <td class="py-2"><span class="font-mono">{{ l.product.sku }}</span> {{ l.product.name }}</td>
                        <td>{{ l.unit }}{{ l.base_qty_per_unit > 1 ? ` ×${l.base_qty_per_unit}` : '' }}</td>
                        <td class="text-right tabular-nums">{{ l.qty_ordered }} <span class="text-xs text-muted-foreground">({{ l.qty_ordered_base }})</span></td>
                        <td class="text-right tabular-nums">{{ l.qty_received_base }}</td>
                        <td class="text-right tabular-nums" :class="{ 'text-red-600': l.qty_rejected_base }">{{ l.qty_rejected_base || '' }}</td>
                        <td class="text-right tabular-nums">{{ Math.max(0, l.qty_ordered_base - l.qty_received_base) }}</td>
                        <td class="text-right tabular-nums">${{ Number(l.unit_cost).toFixed(2) }}</td>
                        <td class="text-right tabular-nums">${{ Number(l.line_subtotal).toFixed(2) }}</td>
                    </tr>
                </tbody>
                <tfoot class="text-right">
                    <tr><td colspan="7" class="pt-2">Subtotal</td><td class="pt-2 tabular-nums">${{ Number(po.subtotal).toFixed(2) }}</td></tr>
                    <tr><td colspan="7">GST</td><td class="tabular-nums">${{ Number(po.tax_total).toFixed(2) }}</td></tr>
                    <tr class="font-semibold"><td colspan="7">Total</td><td class="tabular-nums">${{ Number(po.total).toFixed(2) }}</td></tr>
                </tfoot>
            </table>
            <p class="text-xs text-muted-foreground">Received / rejected / outstanding are in base units.</p>

            <section v-if="po.receipts.length">
                <h2 class="mb-2 font-semibold">Receipts</h2>
                <div v-for="r in po.receipts" :key="r.id" class="mb-3 rounded-md border p-3 text-sm">
                    <div class="mb-1 font-medium">
                        <span class="font-mono">{{ r.number }}</span> · {{ fmt(r.received_at) }} · {{ r.received_by }}
                        <span v-if="r.supplier_ref" class="text-muted-foreground">· docket {{ r.supplier_ref }}</span>
                    </div>
                    <ul class="space-y-0.5">
                        <li v-for="(rl, i) in r.lines" :key="i">
                            <span class="font-mono">{{ productOf(rl.po_line_id) }}</span>:
                            {{ rl.accepted }} accepted<span v-if="rl.location"> → {{ rl.location }}</span>
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
                            <td>{{ e.to }}<span v-if="e.cc" class="text-muted-foreground"> (cc {{ e.cc }})</span></td>
                            <td :class="{ 'text-red-600': e.status === 'failed', 'text-green-700': e.status === 'sent' }">{{ e.status }}{{ e.error ? `: ${e.error}` : '' }}</td>
                            <td>{{ e.queued_by }}</td>
                            <td class="text-right"><Button v-if="e.status === 'failed' && can.manage" size="sm" variant="outline" @click="retry(e.id)">Retry</Button></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <p v-if="po.notes" class="whitespace-pre-line text-sm"><span class="text-muted-foreground">Notes:</span> {{ po.notes }}</p>
        </div>
    </AppLayout>
</template>
