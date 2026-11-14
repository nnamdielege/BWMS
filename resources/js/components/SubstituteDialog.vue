<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PackQtyInput from '@/components/PackQtyInput.vue';
import ProductPicker, { type PickedProduct, type PickedUnit } from '@/components/ProductPicker.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { fromBase, packLabel, toBase, type Pack, type PackEntry } from '@/lib/packs';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * A customer accepts another product instead of (part of) a line. Used on the order
 * page and, for a short pick, on the handheld. The server works out the price when
 * it's left blank: the substitute's sell price for the pack chosen.
 */
export type SubstituteTarget = {
    orderId: number;
    lineId: number;
    sku: string;
    name: string;
    pack: Pack;
    unitPrice: string | null;
    outstanding: number;
    // How much to replace to start with, and the short pick it's for, if any.
    replaceBase: number;
    pickListLineId?: number | null;
};

const props = defineProps<{ target: SubstituteTarget | null }>();
const emit = defineEmits<{ close: [] }>();

const product = ref<PickedProduct | null>(null);
const unit = ref<PickedUnit | null>(null);
const form = useForm({
    replace: { packs: null, each: null } as PackEntry,
    product_id: null as number | null,
    product_unit_id: null as number | null,
    qty: { packs: null, each: null } as PackEntry,
    unit_price: '' as string,
    keep_original_price: false,
    note: '',
    pick_list_line_id: null as number | null,
});

watch(
    () => props.target,
    (t) => {
        form.reset();
        form.clearErrors();
        product.value = null;
        unit.value = null;
        if (!t) return;
        form.replace = fromBase(t.replaceBase, t.pack.per);
        form.pick_list_line_id = t.pickListLineId ?? null;
    },
    { immediate: true },
);

const subPack = computed<Pack | null>(() =>
    product.value && unit.value ? { per: unit.value.base_qty, name: unit.value.name, base_unit: product.value.base_unit } : null,
);
const sellable = computed(() => product.value?.units.filter((u) => u.sellable) ?? []);

/** The default sell price restated for a pack, as the server would. */
const defaultPrice = (p: PickedProduct, u: PickedUnit): string => {
    if (!p.sell_price || Number(p.sell_price) <= 0) return '';
    const def = p.units.find((x) => x.default_sale) ?? [...p.units].filter((x) => x.sellable).sort((a, b) => b.base_qty - a.base_qty)[0];
    return ((Number(p.sell_price) / Math.max(1, def?.base_qty ?? 1)) * u.base_qty).toFixed(2);
};

const pick = (p: PickedProduct) => {
    product.value = p;
    const u = p.units.find((x) => x.default_sale && x.sellable) ?? p.units.find((x) => x.sellable) ?? null;
    chooseUnit(u);
    // Same quantity of the substitute, to start with.
    if (u && props.target) form.qty = fromBase(toBase(form.replace, props.target.pack.per), u.base_qty);
};
const chooseUnit = (u: PickedUnit | null) => {
    unit.value = u;
    form.product_id = product.value?.id ?? null;
    form.product_unit_id = u?.id ?? null;
    form.unit_price = product.value && u ? defaultPrice(product.value, u) : '';
};

const replaceBase = computed(() => (props.target ? toBase(form.replace, props.target.pack.per) : 0));
const qtyBase = computed(() => (subPack.value ? toBase(form.qty, subPack.value.per) : 0));
const value = (base: number, per: number, price: string | null) => (price === null || price === '' ? null : (base / Math.max(1, per)) * Number(price));
const originalValue = computed(() => (props.target ? value(replaceBase.value, props.target.pack.per, props.target.unitPrice) : null));
const keptPrice = computed(() =>
    originalValue.value !== null && subPack.value && qtyBase.value > 0 ? (originalValue.value / (qtyBase.value / subPack.value.per)).toFixed(4) : null,
);
const substituteValue = computed(() =>
    subPack.value ? value(qtyBase.value, subPack.value.per, form.keep_original_price ? keptPrice.value : form.unit_price) : null,
);
const difference = computed(() => (originalValue.value !== null && substituteValue.value !== null ? substituteValue.value - originalValue.value : null));
const money = (n: number) => `${n < 0 ? '−' : ''}$${Math.abs(n).toFixed(2)}`;

const blocked = computed(() => {
    if (!props.target) return 'No line.';
    if (replaceBase.value <= 0) return 'Enter how much of the original to replace.';
    if (replaceBase.value > props.target.outstanding) return `Only ${packLabel(props.target.outstanding, props.target.pack)} is left to replace.`;
    if (!form.product_id || !form.product_unit_id) return 'Choose the substitute.';
    if (qtyBase.value <= 0) return 'Enter how much of the substitute.';
    if (!form.note.trim()) return 'Say who accepted it, and how.';
    return null;
});

const submit = () => {
    if (!props.target || blocked.value) return;
    form.transform((d) => ({ ...d, unit_price: d.keep_original_price || d.unit_price === '' ? null : d.unit_price })).post(
        route('orders.substitute', [props.target.orderId, props.target.lineId]),
        { preserveScroll: true, onSuccess: () => emit('close') },
    );
};
</script>

<template>
    <Dialog :open="target !== null" @update:open="(o) => !o && emit('close')">
        <DialogContent v-if="target" class="max-h-[95vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Substitute {{ target.sku }}</DialogTitle>
                <DialogDescription>{{ target.name }}. The customer gets another product instead; the replaced quantity is cancelled as substituted, not short.</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" novalidate @submit.prevent="submit">
                <div>
                    <div class="mb-1 text-sm font-medium">Replace</div>
                    <PackQtyInput v-model="form.replace" :pack="target.pack" />
                    <div class="text-xs text-muted-foreground">of {{ packLabel(target.outstanding, target.pack) }} still to send</div>
                </div>

                <div>
                    <div class="mb-1 text-sm font-medium">With</div>
                    <div v-if="product" class="mb-2 flex items-center gap-2 text-sm">
                        <span class="font-mono">{{ product.sku }}</span> {{ product.name }}
                        <button type="button" class="ml-auto text-xs underline" @click="product = null; chooseUnit(null)">Change</button>
                    </div>
                    <ProductPicker v-else placeholder="Substitute: SKU, name or scan" @pick="pick" />
                    <InputError :message="form.errors.product_id" />
                </div>

                <template v-if="product && subPack">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Pack</span>
                            <select
                                class="h-9 rounded-md border bg-background px-2"
                                :value="unit?.id"
                                @change="chooseUnit(sellable.find((u) => u.id === Number(($event.target as HTMLSelectElement).value)) ?? null)"
                            >
                                <option v-for="u in sellable" :key="u.id" :value="u.id">{{ u.name }}{{ u.base_qty > 1 ? ` (${u.base_qty})` : '' }}</option>
                            </select>
                        </label>
                        <div>
                            <div class="mb-1 text-sm font-medium">Quantity</div>
                            <PackQtyInput v-model="form.qty" :pack="subPack" />
                        </div>
                    </div>
                    <InputError :message="form.errors.product_unit_id" />

                    <div class="space-y-1">
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Price per {{ subPack.name }} (ex GST)</span>
                            <input
                                v-model="form.unit_price"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                :disabled="form.keep_original_price"
                                placeholder="sell price"
                                class="h-9 w-32 rounded-md border bg-background px-2 text-right tabular-nums disabled:opacity-50"
                            />
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.keep_original_price" type="checkbox" :disabled="target.unitPrice === null" />
                            Keep original price<span v-if="form.keep_original_price && keptPrice" class="text-muted-foreground">: ${{ Number(keptPrice).toFixed(2) }} per {{ subPack.name }}</span>
                            <span v-if="target.unitPrice === null" class="text-xs text-muted-foreground">(the original has no price)</span>
                        </label>
                        <div v-if="difference !== null" class="text-sm" :class="difference > 0 ? 'text-amber-700 dark:text-amber-300' : ''">
                            Price difference: {{ money(difference) }}
                        </div>
                        <InputError :message="form.errors.unit_price" />
                    </div>
                </template>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Note</span>
                    <input v-model="form.note" type="text" maxlength="500" required placeholder="Accepted by Jane by phone 14/11" class="h-9 w-full rounded-md border bg-background px-2" />
                    <InputError :message="form.errors.note" />
                </label>

                <InputError :message="(form.errors as Record<string, string>).substitute" />
                <p v-if="blocked && (form.product_id || form.note)" class="text-xs text-muted-foreground">{{ blocked }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="h-10 rounded-md border px-4" @click="emit('close')">Cancel</button>
                    <button type="submit" class="h-10 rounded-md bg-primary px-4 font-semibold text-primary-foreground disabled:opacity-50" :disabled="!!blocked || form.processing">
                        Substitute
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
