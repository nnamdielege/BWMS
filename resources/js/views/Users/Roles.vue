<template>
    <div class="user-roles-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">User Roles Management</h1>
                <p class="page-subtitle">Assign roles to users and manage access</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-grid">
                <FormInput
                    v-model="filters.search"
                    placeholder="Search users..."
                    @input="debouncedSearch"
                >
                    <template #append>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </template>
                </FormInput>

                <FormSelect
                    v-model="filters.role"
                    label="Filter by Role"
                    :options="roleOptions"
                    placeholder="All Roles"
                    @update:modelValue="applyFilters"
                />

                <div class="flex items-end">
                    <button @click="resetFilters" class="btn btn-secondary w-full">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <Alert
            v-if="successMessage"
            type="success"
            :message="successMessage"
            @close="successMessage = ''"
        />

        <Alert
            v-if="roleStore.error"
            type="error"
            :message="roleStore.error"
            @close="roleStore.clearError()"
        />

        <!-- Users Table -->
        <div class="table-card">
            <div v-if="roleStore.loading" class="loading-state">
                <div class="spinner"></div>
                <p>Loading users...</p>
            </div>

            <div v-else-if="roleStore.usersWithRoles.length === 0" class="empty-state">
                <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <h3 class="empty-title">No users found</h3>
                <p class="empty-description">Try adjusting your filters</p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Current Roles</th>
                            <th>Last Activity</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in roleStore.usersWithRoles" :key="user.id">
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar">
                                        {{ getInitials(user.name) }}
                                    </div>
                                    <span class="user-name">{{ user.name }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="user-email">{{ user.email }}</span>
                            </td>
                            <td>
                                <div class="roles-cell">
                                    <Badge
                                        v-for="role in user.roles"
                                        :key="role.id"
                                        :variant="getRoleBadgeVariant(role.name)"
                                        size="sm"
                                    >
                                        {{ role.name }}
                                    </Badge>
                                    <span v-if="!user.roles || user.roles.length === 0" class="text-gray-500 text-sm">
                                        No roles assigned
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="text-sm text-gray-600">
                                    {{ formatDate(user.updated_at) }}
                                </span>
                            </td>
                            <td class="text-right">
                                <button
                                    @click="openEditRolesModal(user)"
                                    class="action-btn edit"
                                    title="Edit Roles"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <Pagination
                v-if="roleStore.usersWithRoles.length > 0"
                :current-page="roleStore.pagination.current_page"
                :total-pages="roleStore.pagination.last_page"
                :total-items="roleStore.pagination.total"
                @page-change="handlePageChange"
            />
        </div>

        <!-- Edit Roles Modal -->
        <Modal
            :show="showEditModal"
            title="Edit User Roles"
            size="medium"
            @close="closeEditModal"
        >
            <div v-if="selectedUser" class="edit-roles-modal">
                <!-- User Info -->
                <div class="user-info-section">
                    <div class="user-avatar large">
                        {{ getInitials(selectedUser.name) }}
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ selectedUser.name }}</h3>
                        <p class="text-sm text-gray-600">{{ selectedUser.email }}</p>
                    </div>
                </div>

                <!-- Roles Selection -->
                <div class="roles-selection">
                    <label class="form-label">Select Roles</label>
                    <div class="roles-checkboxes">
                        <label
                            v-for="role in roleStore.roles"
                            :key="role.id"
                            class="role-checkbox-item"
                        >
                            <input
                                type="checkbox"
                                :value="role.name"
                                v-model="editingRoles"
                                class="checkbox"
                            />
                            <div class="role-info">
                                <span class="role-name">{{ role.name }}</span>
                                <span class="role-permissions">{{ role.permissions?.length || 0 }} permissions</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Current vs New Comparison -->
                <div v-if="hasRoleChanges" class="changes-preview">
                    <h4 class="changes-title">Changes Preview</h4>
                    <div class="changes-grid">
                        <div>
                            <p class="text-xs text-gray-500 mb-2">Current Roles</p>
                            <div class="roles-list">
                                <Badge
                                    v-for="role in selectedUser.roles"
                                    :key="role.id"
                                    variant="secondary"
                                    size="sm"
                                >
                                    {{ role.name }}
                                </Badge>
                            </div>
                        </div>
                        <div class="arrow-icon">
                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-2">New Roles</p>
                            <div class="roles-list">
                                <Badge
                                    v-for="roleName in editingRoles"
                                    :key="roleName"
                                    :variant="getRoleBadgeVariant(roleName)"
                                    size="sm"
                                >
                                    {{ roleName }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <button @click="closeEditModal" class="btn btn-secondary">
                    Cancel
                </button>
                <button
                    @click="saveUserRoles"
                    class="btn btn-primary"
                    :disabled="savingRoles || !hasRoleChanges"
                >
                    <span v-if="savingRoles" class="spinner-small"></span>
                    <span>{{ savingRoles ? 'Saving...' : 'Save Changes' }}</span>
                </button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoleStore } from '../../stores/role';
import FormInput from '../../components/common/FormInput.vue';
import FormSelect from '../../components/common/FormSelect.vue';
import Badge from '../../components/common/Badge.vue';
import Alert from '../../components/common/Alert.vue';
import Modal from '../../components/common/Modal.vue';
import Pagination from '../../components/common/Pagination.vue';

const roleStore = useRoleStore();

const filters = ref({
    search: '',
    role: '',
});

const successMessage = ref('');
const showEditModal = ref(false);
const selectedUser = ref(null);
const editingRoles = ref([]);
const savingRoles = ref(false);

const roleOptions = computed(() => {
    return roleStore.roles.map(role => ({
        id: role.name,
        name: role.name.charAt(0).toUpperCase() + role.name.slice(1)
    }));
});

const hasRoleChanges = computed(() => {
    if (!selectedUser.value) return false;
    
    const currentRoles = selectedUser.value.roles?.map(r => r.name).sort() || [];
    const newRoles = [...editingRoles.value].sort();
    
    return JSON.stringify(currentRoles) !== JSON.stringify(newRoles);
});

const getInitials = (name) => {
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
};

const getRoleBadgeVariant = (roleName) => {
    const variants = {
        'super-admin': 'error',
        'admin': 'warning',
        'manager': 'info',
        'staff': 'success',
        'viewer': 'secondary',
    };
    return variants[roleName] || 'secondary';
};

const formatDate = (date) => {
    if (!date) return 'Never';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

let searchTimeout;
const debouncedSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 500);
};

const applyFilters = async () => {
    await roleStore.fetchUsersWithRoles(filters.value);
};

const resetFilters = async () => {
    filters.value = {
        search: '',
        role: '',
    };
    await roleStore.fetchUsersWithRoles();
};

const handlePageChange = async (page) => {
    await roleStore.fetchUsersWithRoles({ ...filters.value, page });
};

const openEditRolesModal = (user) => {
    selectedUser.value = user;
    editingRoles.value = user.roles?.map(r => r.name) || [];
    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
    selectedUser.value = null;
    editingRoles.value = [];
};

const saveUserRoles = async () => {
    if (!selectedUser.value || !hasRoleChanges.value) return;

    savingRoles.value = true;
    try {
        await roleStore.updateUserRoles(selectedUser.value.id, editingRoles.value);
        successMessage.value = `Roles updated successfully for ${selectedUser.value.name}`;
        closeEditModal();
        await roleStore.fetchUsersWithRoles(filters.value);
    } catch (error) {
        console.error('Error updating roles:', error);
    } finally {
        savingRoles.value = false;
    }
};

onMounted(async () => {
    await Promise.all([
        roleStore.fetchRoles(),
        roleStore.fetchUsersWithRoles()
    ]);
});
</script>

<style scoped>
.user-roles-page {
    @apply space-y-6;
}

.page-header {
    @apply flex items-center justify-between;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.filters-card {
    @apply bg-white rounded-lg shadow-sm border border-gray-200 p-6;
}

.filters-grid {
    @apply grid grid-cols-1 md:grid-cols-3 gap-4;
}

.btn {
    @apply flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium transition-all;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.table-card {
    @apply bg-white rounded-lg shadow-sm border border-gray-200;
}

.loading-state {
    @apply flex flex-col items-center justify-center py-12;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.empty-state {
    @apply flex flex-col items-center justify-center py-12;
}

.empty-icon {
    @apply w-16 h-16 text-gray-400 mb-4;
}

.empty-title {
    @apply text-xl font-semibold text-gray-900 mb-2;
}

.empty-description {
    @apply text-gray-600;
}

.data-table {
    @apply w-full;
}

.data-table thead {
    @apply bg-gray-50 border-b border-gray-200;
}

.data-table th {
    @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider;
}

.data-table td {
    @apply px-6 py-4 whitespace-nowrap border-b border-gray-200;
}

.user-cell {
    @apply flex items-center gap-3;
}

.user-avatar {
    @apply w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-semibold text-sm;
}

.user-avatar.large {
    @apply w-16 h-16 text-xl;
}

.user-name {
    @apply font-medium text-gray-900;
}

.user-email {
    @apply text-sm text-gray-600;
}

.roles-cell {
    @apply flex flex-wrap gap-2;
}

.action-btn {
    @apply p-2 rounded-lg transition-colors;
}

.action-btn.edit {
    @apply text-indigo-600 hover:bg-indigo-50;
}

.edit-roles-modal {
    @apply space-y-6;
}

.user-info-section {
    @apply flex items-center gap-4 pb-6 border-b border-gray-200;
}

.roles-selection {
    @apply space-y-3;
}

.form-label {
    @apply block text-sm font-medium text-gray-700;
}

.roles-checkboxes {
    @apply space-y-2 max-h-80 overflow-y-auto p-2 border border-gray-200 rounded-lg;
}

.role-checkbox-item {
    @apply flex items-start gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors;
}

.checkbox {
    @apply w-4 h-4 mt-1 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500;
}

.role-info {
    @apply flex-1;
}

.role-name {
    @apply block font-medium text-gray-900 capitalize;
}

.role-permissions {
    @apply block text-xs text-gray-500 mt-0.5;
}

.changes-preview {
    @apply p-4 bg-blue-50 border border-blue-200 rounded-lg;
}

.changes-title {
    @apply text-sm font-semibold text-gray-900 mb-3;
}

.changes-grid {
    @apply grid grid-cols-3 gap-4 items-center;
}

.arrow-icon {
    @apply flex justify-center;
}

.roles-list {
    @apply flex flex-wrap gap-2;
}

.spinner-small {
    @apply inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin;
}
</style>