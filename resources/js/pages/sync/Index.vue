<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Listing = {
    id: number;
    product: { id: number; sku: string; name: string } | null;
    unit: { name: string; base_qty: number } | null;
    external_variant_id: string;
    sync_enabled: boolean;
    safety_buffer: number;
    last_pushed_qty: number | null;
    last_pushed_at: string | null;
    last_response_code: number | null;
    last_error: string | null;
    last_remote_qty: number | null;
    last_checked_at: string | null;
    remote_status: string | null;
    pending: boolean;
};
type Suggestion = { id: number; sku: string; name: string; units: { id: number; name: string; base_qty: number; sellable: boolean }[] };

const props = defineProps<{
    switches: { push_enabled: boolean; ingest_enabled: boolean; webhook_configured: boolean; api_key_set: boolean; signature_mode: string; variant_fields: string[]; variant_confirmed: boolean };
    health: { last_push_ok: string | null; last_order_ok: string | null; failures_24h: number; queue_depth: number; listings: number; listings_enabled: number };
    failures: Listing[];
    mismatches: Listing[];
    untracked: Listing[];
    inbox: { id: number; event: string; external_id: string | null; status: string; signature_status: string | null; attempts: number; error: string | null; created_at: string }[];
    inboxCounts: Record<string, number>;
    unmappedVariants: { id: number; channel: string; external_variant_id: string; external_sku: string | null; name: string | null; occurrences: number; last_seen_at: string; suggestion: Suggestion | null }[];
    unmappedProducts: { total: number; rows: { id: number; sku: string; name: string }[] };
    listings: { data: Listing[]; links: { url: string | null; label: string; active: boolean }[] };
    portal: {
        api_enabled: boolean;
        inbound_keys: number;
        push_enabled: boolean;
        events_enabled: boolean;
        outbound_configured: boolean;
        listings: number;
        listings_enabled: number;
        last_push_ok: string | null;
        failures_24h: number;
        push_failures: Listing[];
        refused: { id: number; event: string; external_id: string | null; status: string; error: string | null; created_at: string }[];
    };
    filters: { status?: string; q?: string };
    canManage: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sync', href: '/sync' }];
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : 'never');
const q = ref(props.filters.q ?? '');
const search = () => router.get(route('sync.index'), { ...props.filters, q: q.value || undefined }, { preserveState: true, preserveScroll: true });
const filterInbox = (status?: string) => router.get(route('sync.index'), { ...props.filters, status }, { preserveState: true, preserveScroll: true });
const post = (url: string) => router.post(url, {}, { preserveScroll: true });
const toggle = (l: Listing) => router.put(route('sync.listings.update', l.id), { sync_enabled: !l.sync_enabled }, { preserveScroll: true });

// Mapping an unmapped Ordermentum variant to a product + pack.
const mapping = ref<number | null>(null);
const picked = ref<Suggestion | PickedProduct | null>(null);
const mapForm = useForm({ product_id: null as number | null, product_unit_id: null as number | null, sync_enabled: false });
const startMap = (v: (typeof props.unmappedVariants)[number]) => {
    mapping.value = v.id;
    picked.value = v.suggestion;
    mapForm.product_id = v.suggestion?.id ?? null;
    mapForm.product_unit_id = v.suggestion?.units.find((u) => u.sellable)?.id ?? null;
};
const pick = (p: PickedProduct) => {
    picked.value = p;
    mapForm.product_id = p.id;
    mapForm.product_unit_id = p.units.find((u) => u.sellable)?.id ?? null;
};
const submitMap = () => mapForm.post(route('sync.unmapped.map', mapping.value!), { preserveScroll: true, onSuccess: () => (mapping.value = null) });
</script>

<template>
    <Head title="Sync" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <section class="grid gap-3 md:grid-cols-4">
                <div class="rounded-lg border p-3">
                    <div class="text-sm text-muted-foreground">Stock push</div>
                    <div class="font-semibold" :class="switches.push_enabled ? 'text-green-700' : 'text-amber-700'">{{ switches.push_enabled ? 'On' : 'Off' }}</div>
                    <div class="text-xs text-muted-foreground">Last success: {{ when(health.last_push_ok) }}</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-sm text-muted-foreground">Order ingestion</div>
                    <div class="font-semibold" :class="switches.ingest_enabled ? 'text-green-700' : 'text-amber-700'">{{ switches.ingest_enabled ? 'On' : 'Off' }}</div>
                    <div class="text-xs text-muted-foreground">Last order applied: {{ when(health.last_order_ok) }}</div>
                    <div v-if="!switches.webhook_configured" class="text-xs text-amber-700">Webhook token not set</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-sm text-muted-foreground">Failures (24h) / queue</div>
                    <div class="font-semibold tabular-nums" :class="{ 'text-red-700': health.failures_24h }">{{ health.failures_24h }} / {{ health.queue_depth }}</div>
                    <div class="text-xs" :class="switches.api_key_set ? 'text-muted-foreground' : 'text-red-700'">API token: {{ switches.api_key_set ? 'set' : 'not set' }}</div>
                    <div class="text-xs text-muted-foreground">Webhook signatures: {{ switches.signature_mode }}</div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-sm text-muted-foreground">Listings enabled</div>
                    <div class="font-semibold tabular-nums">{{ health.listings_enabled }} / {{ health.listings }}</div>
                    <div class="text-xs text-muted-foreground" :title="switches.variant_fields.join(', ')">Variant field: {{ switches.variant_fields[0] ?? '—' }}
                        <span :class="{ 'text-amber-700': !switches.variant_confirmed }">({{ switches.variant_confirmed ? 'confirmed' : 'unconfirmed' }})</span>
                    </div>
                </div>
            </section>

            <section v-if="failures.length" class="rounded-lg border border-red-300 p-4">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold text-red-700">Push failures</h2>
                    <Button v-if="canManage" size="sm" variant="outline" @click="post(route('sync.retry-failed'))">Retry all</Button>
                </div>
                <table class="w-full text-left text-sm">
                    <tbody>
                        <tr v-for="l in failures" :key="l.id" class="border-t">
                            <td class="py-1 font-mono">{{ l.product?.sku }}</td>
                            <td class="text-red-700">{{ l.last_error }}</td>
                            <td class="text-right"><Button v-if="canManage" size="sm" variant="ghost" @click="post(route('sync.push', l.id))">Retry</Button></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="mismatches.length" class="rounded-lg border border-amber-300 p-4">
                <h2 class="mb-2 font-semibold text-amber-800">Reconciliation mismatches (Ordermentum vs last push)</h2>
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground"><tr><th>SKU</th><th class="text-right">Ordermentum</th><th class="text-right">BWMS pushed</th><th>Checked</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="l in mismatches" :key="l.id" class="border-t">
                            <td class="py-1 font-mono">{{ l.product?.sku }}</td>
                            <td class="text-right tabular-nums">{{ l.last_remote_qty }}</td>
                            <td class="text-right tabular-nums">{{ l.last_pushed_qty }}</td>
                            <td>{{ when(l.last_checked_at) }}</td>
                            <td class="text-right"><Button v-if="canManage" size="sm" variant="ghost" @click="post(route('sync.push', l.id))">Push now</Button></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="untracked.length" class="rounded-lg border border-amber-300 p-4">
                <h2 class="mb-1 font-semibold text-amber-800">Stock tracking off in Ordermentum ({{ untracked.length }})</h2>
                <p class="mb-2 text-sm text-muted-foreground">
                    Ordermentum only decrements tracked items when an order is placed. Turn tracking on for these in Ordermentum (found by
                    <code>om:probe tracking</code> and the nightly check).
                </p>
                <ul class="columns-2 text-sm md:columns-3">
                    <li v-for="l in untracked" :key="l.id">
                        <span class="font-mono">{{ l.product?.sku }}</span>
                        <span class="ml-1 text-muted-foreground">{{ l.remote_status === 'missing' ? '(not found)' : '' }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="unmappedVariants.length" class="rounded-lg border border-amber-300 p-4">
                <h2 class="mb-2 font-semibold text-amber-800">Channel items needing mapping</h2>
                <p class="mb-2 text-sm text-muted-foreground">Ordermentum orders containing these are held and replay once mapped. Portal calls with them are refused (422) until mapped; the portal retries.</p>
                <div v-for="v in unmappedVariants" :key="v.id" class="border-t py-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <span class="mr-2 rounded bg-muted px-1.5 text-xs capitalize">{{ v.channel }}</span>
                            <span class="font-mono">{{ v.external_variant_id }}</span>
                            <span v-if="v.external_sku" class="ml-2 font-mono text-muted-foreground">{{ v.external_sku }}</span>
                            <span v-if="v.name" class="ml-2">{{ v.name }}</span>
                            <span class="ml-2 text-muted-foreground">seen {{ v.occurrences }}×</span>
                            <span v-if="v.suggestion" class="ml-2 rounded bg-green-100 px-1.5 text-xs text-green-800">suggest {{ v.suggestion.sku }}</span>
                        </div>
                        <Button v-if="canManage && mapping !== v.id" size="sm" variant="outline" @click="startMap(v)">Map</Button>
                    </div>
                    <form v-if="mapping === v.id" class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="submitMap">
                        <div class="w-72"><ProductPicker placeholder="Find product…" @pick="pick" /></div>
                        <span v-if="picked" class="font-mono">{{ picked.sku }}</span>
                        <select v-if="picked" v-model="mapForm.product_unit_id" class="h-9 rounded-md border bg-background px-2">
                            <option v-for="u in picked.units.filter((u) => u.sellable)" :key="u.id" :value="u.id">{{ u.name }} ({{ u.base_qty }})</option>
                        </select>
                        <label class="flex items-center gap-1"><input type="checkbox" v-model="mapForm.sync_enabled" /> push stock</label>
                        <Button type="submit" size="sm" :disabled="!mapForm.product_unit_id || mapForm.processing">Confirm</Button>
                        <Button type="button" size="sm" variant="ghost" @click="mapping = null">Cancel</Button>
                        <InputError :message="mapForm.errors.product_unit_id || mapForm.errors.product_id" />
                    </form>
                </div>
            </section>

            <section class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-semibold">Inbound events</h2>
                    <Button size="sm" :variant="!filters.status ? 'default' : 'ghost'" @click="filterInbox(undefined)">All</Button>
                    <Button v-for="(n, s) in inboxCounts" :key="s" size="sm" :variant="filters.status === s ? 'default' : 'ghost'" @click="filterInbox(String(s))">
                        {{ s }} ({{ n }})
                    </Button>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground"><tr><th class="py-1">#</th><th>Event</th><th>Order</th><th>Status</th><th>Signature</th><th>Tries</th><th>Error</th><th>Received</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="i in inbox" :key="i.id" class="border-t align-top">
                            <td class="py-1 tabular-nums">{{ i.id }}</td>
                            <td>{{ i.event }}</td>
                            <td class="font-mono text-xs">{{ i.external_id }}</td>
                            <td>{{ i.status }}</td>
                            <td class="text-xs" :class="{ 'text-red-700': i.signature_status === 'invalid' }">{{ i.signature_status ?? '' }}</td>
                            <td class="tabular-nums">{{ i.attempts }}</td>
                            <td class="max-w-md text-xs text-red-700">{{ i.error }}</td>
                            <td class="whitespace-nowrap">{{ when(i.created_at) }}</td>
                            <td class="text-right">
                                <Button v-if="canManage && i.external_id && i.status !== 'ignored'" size="sm" variant="ghost" @click="post(route('sync.inbox.replay', i.id))">Replay</Button>
                            </td>
                        </tr>
                        <tr v-if="!inbox.length"><td colspan="9" class="py-2 text-muted-foreground">Nothing received yet.</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-semibold">Listings</h2>
                    <form class="flex gap-2" @submit.prevent="search"><Input v-model="q" placeholder="SKU, name or variant" class="h-8 w-56" /></form>
                    <Button v-if="canManage && health.listings_enabled < health.listings" size="sm" variant="outline" @click="post(route('sync.listings.enable-all'))">Enable all</Button>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr><th class="py-1">SKU</th><th>Pack</th><th>Variant</th><th>Sync</th><th class="text-right">Last pushed</th><th>When</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in listings.data" :key="l.id" class="border-t">
                            <td class="py-1 font-mono">{{ l.product?.sku }}</td>
                            <td>{{ l.unit?.name }}</td>
                            <td class="font-mono text-xs">{{ l.external_variant_id }}</td>
                            <td>
                                <button v-if="canManage" type="button" class="rounded px-1.5 text-xs" :class="l.sync_enabled ? 'bg-green-100 text-green-800' : 'bg-muted'" @click="toggle(l)">
                                    {{ l.sync_enabled ? 'on' : 'off' }}
                                </button>
                                <span v-else>{{ l.sync_enabled ? 'on' : 'off' }}</span>
                            </td>
                            <td class="text-right tabular-nums">{{ l.last_pushed_qty ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ when(l.last_pushed_at) }}</td>
                            <td :class="{ 'text-red-700': l.last_error }" :title="l.last_error ?? ''">
                                {{ l.pending ? 'pending' : l.last_error ? 'error' : (l.last_response_code ?? '') }}
                                <span v-if="l.remote_status === 'untracked'" class="ml-1 rounded bg-amber-100 px-1 text-xs text-amber-900">untracked</span>
                            </td>
                            <td class="text-right"><Button v-if="canManage && l.sync_enabled" size="sm" variant="ghost" @click="post(route('sync.push', l.id))">Push</Button></td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :links="listings.links" class="mt-2" />
            </section>

            <section class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-semibold">Portal (shop.localselection.com.au)</h2>
                    <Button
                        v-if="canManage && portal.listings_enabled < portal.listings"
                        size="sm"
                        variant="outline"
                        @click="router.post(route('sync.listings.enable-all'), { channel: 'portal' }, { preserveScroll: true })"
                        >Enable all portal listings</Button
                    >
                </div>
                <div class="grid gap-2 text-sm md:grid-cols-4">
                    <div>
                        API in: <strong :class="portal.api_enabled ? 'text-green-700' : 'text-amber-700'">{{ portal.api_enabled ? 'on' : 'off' }}</strong>
                        ({{ portal.inbound_keys }} key(s))
                    </div>
                    <div>
                        Stock push: <strong :class="portal.push_enabled ? 'text-green-700' : 'text-amber-700'">{{ portal.push_enabled ? 'on' : 'off' }}</strong>,
                        last OK {{ when(portal.last_push_ok) }}
                    </div>
                    <div>
                        Dispatch events: <strong>{{ portal.events_enabled ? 'on' : 'off' }}</strong>
                        <span v-if="!portal.outbound_configured" class="text-amber-700"> (outbound not configured)</span>
                    </div>
                    <div>Listings {{ portal.listings_enabled }} / {{ portal.listings }} · failures 24h {{ portal.failures_24h }}</div>
                </div>
                <div v-if="portal.push_failures.length" class="mt-3 text-sm">
                    <h3 class="font-semibold text-red-700">Push failures</h3>
                    <div v-for="l in portal.push_failures" :key="l.id">
                        <span class="font-mono">{{ l.product?.sku }}</span> <span class="text-red-700">{{ l.last_error }}</span>
                    </div>
                </div>
                <div v-if="portal.refused.length" class="mt-3 text-sm">
                    <h3 class="font-semibold text-red-700">Refused or invalid portal calls</h3>
                    <p class="text-muted-foreground">
                        A refused reservation may be for an order the portal already accepted while BWMS was unreachable: decide short-ship, substitute or contact
                        the café (B4.3).
                    </p>
                    <table class="mt-1 w-full text-left">
                        <tbody>
                            <tr v-for="r in portal.refused" :key="r.id" class="border-t align-top">
                                <td class="py-1 font-mono">{{ r.external_id }}</td>
                                <td>{{ r.event }}</td>
                                <td>{{ r.status }}</td>
                                <td class="text-xs text-red-700">{{ r.error }}</td>
                                <td class="whitespace-nowrap">{{ when(r.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="unmappedProducts.total" class="rounded-lg border p-4">
                <h2 class="mb-2 font-semibold">Active products not on Ordermentum ({{ unmappedProducts.total }})</h2>
                <ul class="columns-2 text-sm md:columns-3">
                    <li v-for="p in unmappedProducts.rows" :key="p.id"><span class="font-mono">{{ p.sku }}</span> {{ p.name }}</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
