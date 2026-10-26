<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface LocationRow {
    id: number;
    parent_id: number | null;
    receive_location_id: number | null;
    type: 'site' | 'zone' | 'bin';
    code: string;
    name: string;
    is_sellable: boolean;
    is_quarantine: boolean;
    is_receiving: boolean;
    is_active: boolean;
    sort_order: number;
}

const props = defineProps<{ locations: LocationRow[]; canManage: boolean }>();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Locations', href: '/locations' }];

// Flatten the tree depth-first for display.
const rows = computed(() => {
    const out: (LocationRow & { depth: number })[] = [];
    const walk = (parentId: number | null, depth: number) =>
        props.locations
            .filter((l) => l.parent_id === parentId)
            .forEach((l) => {
                out.push({ ...l, depth });
                walk(l.id, depth + 1);
            });
    walk(null, 0);
    return out;
});
const parents = computed(() => props.locations.filter((l) => l.type !== 'bin'));

const blank = { type: 'bin', parent_id: null as number | null, receive_location_id: null as number | null, code: '', name: '', is_sellable: true, is_quarantine: false, is_receiving: false, is_active: true, sort_order: 0 };
const form = useForm({ ...blank });
const editingId = ref<number | null>(null);

const edit = (l: LocationRow) => {
    editingId.value = l.id;
    Object.assign(form, { ...l });
};
const reset = () => {
    editingId.value = null;
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
};
const submit = () =>
    editingId.value
        ? form.put(route('locations.update', editingId.value), { preserveScroll: true, onSuccess: reset })
        : form.post(route('locations.store'), { preserveScroll: true, onSuccess: reset });
// Bins under the site being edited, for its "receive into" setting.
const siteBins = computed(() => {
    const out: LocationRow[] = [];
    const walk = (parentId: number) =>
        props.locations
            .filter((l) => l.parent_id === parentId)
            .forEach((l) => {
                if (l.type === 'bin' && l.is_active && !l.is_quarantine) out.push(l);
                walk(l.id);
            });
    if (editingId.value) walk(editingId.value);
    return out;
});
const binCode = (id: number | null) => props.locations.find((l) => l.id === id)?.code;
const flags = (l: LocationRow) =>
    [l.is_sellable && 'sellable', l.is_receiving && 'receiving', l.is_quarantine && 'quarantine', !l.is_active && 'inactive'].filter(Boolean).join(', ');
</script>

<template>
    <Head title="Locations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid gap-6 p-4 lg:grid-cols-3">
            <table class="w-full text-left text-sm lg:col-span-2">
                <thead class="border-b text-muted-foreground">
                    <tr><th class="py-2">Code</th><th>Name</th><th>Type</th><th>Flags</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="l in rows" :key="l.id" class="border-b">
                        <td class="py-2 font-mono" :style="{ paddingLeft: `${l.depth * 1.25}rem` }">{{ l.code }}</td>
                        <td>{{ l.name }}</td>
                        <td class="capitalize">{{ l.type }}</td>
                        <td class="text-muted-foreground">
                            {{ l.type === 'bin' ? flags(l) : l.type === 'site' && l.receive_location_id ? `receives into ${binCode(l.receive_location_id)}` : '' }}
                        </td>
                        <td class="text-right"><Button v-if="canManage" size="sm" variant="ghost" @click="edit(l)">Edit</Button></td>
                    </tr>
                </tbody>
            </table>

            <form v-if="canManage" class="space-y-3 rounded-lg border p-4" @submit.prevent="submit">
                <h2 class="font-semibold">{{ editingId ? 'Edit location' : 'Add location' }}</h2>
                <select v-model="form.type" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
                    <option value="site">Site</option>
                    <option value="zone">Zone</option>
                    <option value="bin">Bin</option>
                </select>
                <select v-if="form.type !== 'site'" v-model="form.parent_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
                    <option :value="null" disabled>Parent…</option>
                    <option v-for="p in parents" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                </select>
                <InputError :message="form.errors.parent_id || form.errors.type" />
                <Input v-model="form.code" placeholder="Code (e.g. COOL-A1)" class="font-mono uppercase" required />
                <InputError :message="form.errors.code" />
                <Input v-model="form.name" placeholder="Name" required />
                <div v-if="form.type === 'bin'" class="space-y-1 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_sellable" :disabled="form.is_quarantine" /> Stock here is sellable</label>
                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_receiving" /> Receiving bin</label>
                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_quarantine" /> Quarantine (never sellable)</label>
                </div>
                <div v-if="form.type === 'site' && editingId" class="space-y-1 text-sm">
                    <label for="receive_location_id" class="text-muted-foreground">Received stock goes into</label>
                    <select id="receive_location_id" v-model="form.receive_location_id" class="h-9 w-full rounded-md border bg-background px-2">
                        <option :value="null">Automatic (first sellable bin)</option>
                        <option v-for="b in siteBins" :key="b.id" :value="b.id">{{ b.code }} — {{ b.name }}{{ b.is_sellable ? '' : ' (not sellable: needs put-away)' }}</option>
                    </select>
                    <InputError :message="form.errors.receive_location_id" />
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" /> Active</label>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="form.processing">{{ editingId ? 'Save' : 'Add' }}</Button>
                    <Button v-if="editingId" type="button" variant="ghost" @click="reset">Cancel</Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
