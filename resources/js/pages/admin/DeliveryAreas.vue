<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Area = { id: number; code: string; name: string; weekdays: number[]; next_day_eligible: boolean; is_active: boolean; places: string };
const props = defineProps<{ areas: Area[]; cutoff: string }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Delivery areas', href: '/admin/delivery-areas' }];
const days = [
    { n: 1, label: 'Mon' },
    { n: 2, label: 'Tue' },
    { n: 3, label: 'Wed' },
    { n: 4, label: 'Thu' },
    { n: 5, label: 'Fri' },
];
const blank = { code: '', name: '', weekdays: [] as number[], next_day_eligible: false, is_active: true, places: '' };
const editing = ref<number | null>(null);
const form = useForm({ ...blank });

const edit = (a: Area) => {
    editing.value = a.id;
    form.defaults({ code: a.code, name: a.name, weekdays: [...a.weekdays], next_day_eligible: a.next_day_eligible, is_active: a.is_active, places: a.places });
    form.reset();
    form.clearErrors();
};
const add = () => {
    editing.value = 0;
    form.defaults({ ...blank, weekdays: [] });
    form.reset();
    form.clearErrors();
};
const toggleDay = (n: number) => (form.weekdays = form.weekdays.includes(n) ? form.weekdays.filter((d) => d !== n) : [...form.weekdays, n].sort());
const save = () =>
    editing.value
        ? form.put(route('admin.delivery-areas.update', editing.value), { preserveScroll: true, onSuccess: () => (editing.value = null) })
        : form.post(route('admin.delivery-areas.store'), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const dayNames = (a: Area) => days.filter((d) => a.weekdays.includes(d.n)).map((d) => d.label).join(', ');
const placeCount = (a: Area) => a.places.split('\n').filter((l) => l.trim()).length;
</script>

<template>
    <Head title="Delivery areas" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid gap-6 p-4 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <p class="mb-3 text-sm text-muted-foreground">
                    A run is a delivery date plus an area. Orders must be in by {{ cutoff }} on the business day before delivery. An order with no requested date gets the
                    next eligible run after the cutoff. Areas marked next-day also deliver on any weekday.
                </p>
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-2">Area</th><th>Days</th><th>Next-day</th><th class="text-right">Places</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in props.areas" :key="a.id" class="border-b" :class="{ 'opacity-50': !a.is_active }">
                            <td class="py-2">{{ a.name }} <span class="font-mono text-xs text-muted-foreground">{{ a.code }}</span></td>
                            <td>{{ dayNames(a) || '—' }}</td>
                            <td>{{ a.next_day_eligible ? 'yes' : '' }}</td>
                            <td class="text-right tabular-nums">{{ placeCount(a) }}</td>
                            <td class="text-right"><Button size="sm" variant="ghost" @click="edit(a)">Edit</Button></td>
                        </tr>
                    </tbody>
                </table>
                <Button class="mt-3" size="sm" variant="outline" @click="add">Add area</Button>
            </div>

            <form v-if="editing !== null" class="space-y-3 rounded-lg border p-4 lg:col-span-2" @submit.prevent="save">
                <h2 class="font-semibold">{{ editing ? 'Edit area' : 'Add area' }}</h2>
                <div class="grid grid-cols-3 gap-2">
                    <Input v-model="form.code" placeholder="CODE" class="font-mono uppercase" />
                    <Input v-model="form.name" placeholder="Name" class="col-span-2" />
                </div>
                <InputError :message="form.errors.code || form.errors.name" />
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="d in days"
                        :key="d.n"
                        type="button"
                        class="h-9 rounded-md border px-3 text-sm"
                        :class="{ 'border-primary bg-primary/10 font-semibold': form.weekdays.includes(d.n) }"
                        @click="toggleDay(d.n)"
                    >
                        {{ d.label }}
                    </button>
                </div>
                <InputError :message="form.errors.weekdays" />
                <label class="flex items-center gap-2 text-sm"><input v-model="form.next_day_eligible" type="checkbox" /> Next-day delivery on any weekday</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> Active</label>
                <div>
                    <label for="places" class="text-sm">Postcodes / suburbs, one per line: <code>4019</code> or <code>4019 Clontarf</code></label>
                    <textarea id="places" v-model="form.places" rows="12" class="mt-1 w-full rounded-md border bg-background p-2 font-mono text-sm" />
                    <InputError :message="form.errors.places" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="form.processing">Save</Button>
                    <Button type="button" variant="ghost" @click="editing = null">Cancel</Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
