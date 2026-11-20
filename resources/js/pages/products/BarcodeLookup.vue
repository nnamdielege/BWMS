<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ProductPicker, { type PickedProduct } from '@/components/ProductPicker.vue';
import ScanInput from '@/components/ScanInput.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Match {
    id: number;
    barcode: string;
    type: string;
    product: { id: number; sku: string; name: string };
    unit: { id: number; name: string; base_qty: number };
    last_on_pack: boolean;
    open_pick_lists: number;
}
const props = defineProps<{ code: string | null; match: Match | null; can: { manage: boolean } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Barcode lookup', href: '/barcode-lookup' }];
const error = ref<string | null>(null);
const target = ref<PickedProduct | null>(null);
const targetUnit = ref<number | null>(null);
watch(
    () => props.match?.id,
    () => {
        target.value = null;
        targetUnit.value = null;
        error.value = null;
    },
);

const look = (code: string) => router.get(route('barcodes.index'), { code }, { preserveScroll: true, replace: true });
const label = (u: { name: string; base_qty: number }) => (u.base_qty > 1 ? `${u.name} ×${u.base_qty}` : u.name);
const pickWarning = (m: Match) =>
    m.last_on_pack && m.open_pick_lists
        ? `\n\nWarning: ${m.product.sku} ${m.unit.name} will have no barcode, and ${m.open_pick_lists} open pick list(s) still need it. Pickers will have to enter the quantity.`
        : '';
const visit = { preserveScroll: true, onError: (e: Record<string, string>) => (error.value = e.barcode ?? Object.values(e)[0]) };

const remove = (m: Match) => {
    if (!confirm(`Remove barcode ${m.barcode} from ${m.product.sku} ${m.product.name} (${m.unit.name})?${pickWarning(m)}`)) return;
    router.delete(route('barcodes.destroy', m.id), { ...visit, onSuccess: () => look(m.barcode) });
};
const choose = (p: PickedProduct) => {
    target.value = p;
    targetUnit.value = p.units.find((u) => u.default_sale)?.id ?? p.units[0]?.id ?? null;
};
const move = (m: Match) => {
    const unit = target.value?.units.find((u) => u.id === targetUnit.value);
    if (!target.value || !unit) return;
    if (!confirm(`Move barcode ${m.barcode} from ${m.product.sku} (${m.unit.name}) to ${target.value.sku} (${unit.name})?${pickWarning(m)}`)) return;
    router.post(route('barcodes.move', m.id), { product_unit_id: unit.id }, { ...visit, onSuccess: () => look(m.barcode) });
};
</script>

<template>
    <Head title="Barcode lookup" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-2xl space-y-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Barcode lookup</h1>
                <p class="text-sm text-muted-foreground">Scan or type a barcode to see which product and pack it's on.</p>
            </div>
            <ScanInput placeholder="Scan or type a barcode" @scan="look" />

            <p v-if="code && !match" class="rounded-md border px-3 py-2 text-sm">
                No product or pack has barcode <span class="font-mono">{{ code }}</span
                >.
            </p>

            <section v-if="match" class="space-y-3 rounded-lg border p-4">
                <div>
                    <div class="font-mono text-lg">{{ match.barcode }}</div>
                    <div class="text-sm">
                        is on
                        <Link :href="route('products.edit', match.product.id)" class="font-medium underline"
                            >{{ match.product.sku }} {{ match.product.name }}</Link
                        >
                        ({{ label(match.unit) }})
                    </div>
                    <p v-if="match.last_on_pack && match.open_pick_lists" class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        It's the only barcode on this pack, and {{ match.open_pick_lists }} open pick list(s) still need the product.
                    </p>
                </div>

                <template v-if="can.manage">
                    <div class="flex flex-wrap items-end gap-2 border-t pt-3">
                        <div class="min-w-56 flex-1">
                            <span class="mb-1 block text-sm font-medium">Move it to</span>
                            <div v-if="target" class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-sm">
                                <span class="font-mono">{{ target.sku }}</span> <span class="truncate">{{ target.name }}</span>
                                <button type="button" class="ml-auto text-xs underline" @click="target = null">Change</button>
                            </div>
                            <ProductPicker v-else placeholder="Product: SKU, name or scan" @pick="choose" />
                        </div>
                        <select v-if="target" v-model="targetUnit" class="h-9 rounded-md border bg-background px-2 text-sm" aria-label="Pack">
                            <option v-for="u in target.units" :key="u.id" :value="u.id">{{ label({ name: u.name, base_qty: u.base_qty }) }}</option>
                        </select>
                        <Button type="button" variant="outline" :disabled="!target || !targetUnit" @click="move(match)">Move</Button>
                    </div>
                    <Button type="button" variant="destructive" @click="remove(match)">Remove barcode</Button>
                </template>
                <InputError :message="error ?? undefined" />
            </section>
        </div>
    </AppLayout>
</template>
