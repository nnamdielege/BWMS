<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
    roles: string[];
    can_manage: boolean;
}

interface InvitationRow {
    id: number;
    email: string;
    name: string;
    role: string;
    expires_at: string;
    invited_by: string | null;
}

const props = defineProps<{
    users: UserRow[];
    invitations: InvitationRow[];
    roles: string[];
    canInvite: boolean;
    canGrantAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

const grantableRoles = computed(() => props.roles.filter((r) => props.canGrantAdmin || r !== 'admin'));
const label = (role: string) => role.replace(/_/g, ' ');
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-AU', { timeZone: 'Australia/Brisbane' }) : '—');

const invite = useForm({ name: '', email: '', role: 'warehouse_staff' });
const sendInvite = () => invite.post(route('admin.invitations.store'), { preserveScroll: true, onSuccess: () => invite.reset() });

const editing = ref<number | null>(null);
const editRoles = ref<string[]>([]);
const startEdit = (u: UserRow) => {
    editing.value = u.id;
    editRoles.value = [...u.roles];
};
const saveRoles = (u: UserRow) =>
    router.put(route('admin.users.roles', u.id), { roles: editRoles.value }, { preserveScroll: true, onSuccess: () => (editing.value = null) });
const toggleActive = (u: UserRow) => {
    if (u.is_active && !confirm(`Deactivate ${u.name}? They will be signed out immediately.`)) return;
    router.put(route('admin.users.active', u.id), { is_active: !u.is_active }, { preserveScroll: true });
};
const resend = (i: InvitationRow) => router.post(route('admin.invitations.resend', i.id), {}, { preserveScroll: true });
const revoke = (i: InvitationRow) => confirm(`Revoke invitation for ${i.email}?`) && router.delete(route('admin.invitations.destroy', i.id), { preserveScroll: true });
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 p-4">
            <section v-if="canInvite" class="max-w-3xl rounded-lg border p-4">
                <h2 class="mb-3 font-semibold">Invite a user</h2>
                <form class="grid gap-3 sm:grid-cols-4 sm:items-end" @submit.prevent="sendInvite">
                    <div class="grid gap-1">
                        <Label for="inv-name">Name</Label>
                        <Input id="inv-name" v-model="invite.name" required />
                        <InputError :message="invite.errors.name" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="inv-email">Email</Label>
                        <Input id="inv-email" v-model="invite.email" type="email" required />
                        <InputError :message="invite.errors.email" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="inv-role">Role</Label>
                        <select id="inv-role" v-model="invite.role" class="h-9 rounded-md border bg-background px-2 text-sm">
                            <option v-for="r in grantableRoles" :key="r" :value="r">{{ label(r) }}</option>
                        </select>
                        <InputError :message="invite.errors.role" />
                    </div>
                    <Button type="submit" :disabled="invite.processing">Send invitation</Button>
                </form>
            </section>

            <section v-if="invitations.length">
                <h2 class="mb-2 font-semibold">Pending invitations</h2>
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-2">Name</th><th>Email</th><th>Role</th><th>Expires</th><th>Invited by</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in invitations" :key="i.id" class="border-b">
                            <td class="py-2">{{ i.name }}</td>
                            <td>{{ i.email }}</td>
                            <td class="capitalize">{{ label(i.role) }}</td>
                            <td>{{ fmt(i.expires_at) }}</td>
                            <td>{{ i.invited_by ?? 'console' }}</td>
                            <td class="space-x-2 text-right">
                                <Button v-if="canInvite" size="sm" variant="outline" @click="resend(i)">Resend</Button>
                                <Button v-if="canInvite" size="sm" variant="ghost" @click="revoke(i)">Revoke</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section>
                <h2 class="mb-2 font-semibold">Users</h2>
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr><th class="py-2">Name</th><th>Email</th><th>Roles</th><th>Last login</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in users" :key="u.id" class="border-b align-top">
                            <td class="py-2">{{ u.name }}</td>
                            <td>{{ u.email }}</td>
                            <td>
                                <div v-if="editing === u.id" class="flex flex-wrap gap-3">
                                    <label v-for="r in grantableRoles" :key="r" class="flex items-center gap-1 capitalize">
                                        <input type="checkbox" :value="r" v-model="editRoles" /> {{ label(r) }}
                                    </label>
                                </div>
                                <span v-else class="capitalize">{{ u.roles.map(label).join(', ') || '—' }}</span>
                            </td>
                            <td>{{ fmt(u.last_login_at) }}</td>
                            <td>{{ u.is_active ? 'Active' : 'Deactivated' }}</td>
                            <td class="space-x-2 whitespace-nowrap text-right">
                                <template v-if="u.can_manage">
                                    <template v-if="editing === u.id">
                                        <Button size="sm" @click="saveRoles(u)">Save</Button>
                                        <Button size="sm" variant="ghost" @click="editing = null">Cancel</Button>
                                    </template>
                                    <Button v-else size="sm" variant="outline" @click="startEdit(u)">Edit roles</Button>
                                    <Button size="sm" variant="ghost" @click="toggleActive(u)">{{ u.is_active ? 'Deactivate' : 'Activate' }}</Button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
