<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { ref, watch } from 'vue';

export type PickedUnit = {
    id: number;
    name: string;
    base_qty: number;
    purchasable: boolean;
    sellable: boolean;
    default_purchase: boolean;
    default_sale: boolean;
};
export type PickedProduct = {
    id: number;
    sku: string;
    name: string;
    base_unit: string;
    avg_cost: string;
    sell_price: string | null;
    is_batch_tracked: boolean;
    units: PickedUnit[];
};

const emit = defineEmits<{ pick: [product: PickedProduct] }>();
defineProps<{ placeholder?: string }>();

const q = ref('');
const results = ref<PickedProduct[]>([]);
const open = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(q, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const res = await fetch(route('products.search', { q: value.trim() }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        results.value = res.ok ? await res.json() : [];
        open.value = true;
        // A barcode scan that matches exactly one product picks it straight away.
        if (results.value.length === 1 && /^\d{8,14}$/.test(value.trim())) choose(results.value[0]);
    }, 200);
});

const choose = (p: PickedProduct) => {
    emit('pick', p);
    q.value = '';
    results.value = [];
    open.value = false;
};
</script>

<template>
    <div class="relative">
        <Input v-model="q" :placeholder="placeholder ?? 'Add product: SKU, name or scan'" @keydown.enter.prevent="results[0] && choose(results[0])" @blur="open = false" @focus="open = results.length > 0" />
        <ul v-if="open && results.length" class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-background shadow">
            <li v-for="p in results" :key="p.id">
                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-muted" @mousedown.prevent="choose(p)">
                    <span class="font-mono">{{ p.sku }}</span> — {{ p.name }}
                </button>
            </li>
        </ul>
    </div>
</template>
