<template>
    <div class="roles-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Roles & Permissions</h1>
                <p class="page-subtitle">Manage user roles and their permissions</p>
            </div>
            <div class="page-actions">
                <button @click="showCreateModal = true" class="btn btn-primary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Create Role
                </button>
            </div>
        </div>

        <!-- Roles List -->
        <div class="card">
            <div class="card-body">
                <div v-if="loading" class="text-center py-8">
                    <div class="loading-spinner"></div>
                </div>

                <div v-else>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Role Name</th>
                                <th>Permissions</th>
                                <th>Users</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="role in roles" :key="role.id">
                                <td>
                                    <div class="font-semibold capitalize">{{ role.name }}</div>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        {{ role.permissions_count || 0 }} permissions
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">
                                        {{ role.users_count || 0 }} users
                                    </span>
                                </td>
                                <td>{{ formatDate(role.created_at) }}</td>
                                <td>
                                    <div class="action-buttons">
                                        <button 
                                            @click="editRole(role)" 
                                            class="btn-icon btn-icon-primary"
                                            title="Edit Permissions">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button 
                                            v-if="!isSystemRole(role.name)"
                                            @click="deleteRole(role)" 
                                            class="btn-icon btn-icon-danger"
                                            title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create Role Modal -->
        <div v-if="showCreateModal" class="modal-overlay" @click.self="showCreateModal = false">
            <div class="modal">
                <div class="modal-header">
                    <h3>Create New Role</h3>
                    <button @click="showCreateModal = false" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Role Name</label>
                        <input 
                            v-model="newRole.name" 
                            type="text" 
                            class="form-control"
                            placeholder="e.g., warehouse-manager">
                        <small class="text-gray-500">Use lowercase with hyphens (e.g., warehouse-manager)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showCreateModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="createRole" class="btn btn-primary">Create Role</button>
                </div>
            </div>
        </div>

        <!-- Edit Role Permissions Modal -->
        <div v-if="showEditModal" class="modal-overlay" @click.self="showEditModal = false">
            <div class="modal modal-lg">
                <div class="modal-header">
                    <h3>Edit Permissions for "{{ editingRole.name }}"</h3>
                    <button @click="showEditModal = false" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div v-if="loadingPermissions" class="text-center py-8">
                        <div class="loading-spinner"></div>
                    </div>
                    <div v-else>
                        <!-- Current Permissions Section -->
                        <div v-if="currentRolePermissions.length > 0" class="current-permissions-section">
                            <div class="section-header">
                                <h4>Current Permissions ({{ currentRolePermissions.length }})</h4>
                                <button 
                                    @click="removeAllPermissions" 
                                    class="btn-sm btn-danger"
                                    title="Remove All Permissions">
                                    Remove All
                                </button>
                            </div>
                            <div class="permissions-list">
                                <div 
                                    v-for="permission in currentRolePermissions" 
                                    :key="permission.id"
                                    class="permission-badge">
                                    <span class="capitalize">{{ permission.name.replace(/-/g, ' ') }}</span>
                                    <button 
                                        @click="removePermission(permission.id)"
                                        class="remove-btn"
                                        title="Remove this permission">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Available Permissions Section -->
                        <div class="available-permissions-section">
                            <h4>Available Permissions</h4>
                            <div class="permissions-grid">
                                <div 
                                    v-for="permission in allPermissions" 
                                    :key="permission.id"
                                    class="permission-item">
                                    <label class="checkbox-label">
                                        <input 
                                            type="checkbox"
                                            :value="permission.id"
                                            v-model="selectedPermissions"
                                            class="checkbox">
                                        <span class="capitalize">{{ permission.name.replace(/-/g, ' ') }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showEditModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="updateRolePermissions" class="btn btn-primary">Save Permissions</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'RolesIndex',
    data() {
        return {
            roles: [],
            allPermissions: [],
            currentRolePermissions: [],
            loading: false,
            loadingPermissions: false,
            showCreateModal: false,
            showEditModal: false,
            newRole: {
                name: ''
            },
            editingRole: null,
            selectedPermissions: []
        };
    },
    mounted() {
        this.fetchRoles();
    },
    methods: {
        async fetchRoles() {
            this.loading = true;
            try {
                const response = await axios.get('roles');
                this.roles = response.data.data;
            } catch (error) {
                console.error('Error fetching roles:', error);
                alert('Failed to load roles');
            } finally {
                this.loading = false;
            }
        },
        async createRole() {
            if (!this.newRole.name) {
                alert('Please enter a role name');
                return;
            }

            try {
                await axios.post('roles', this.newRole);
                this.showCreateModal = false;
                this.newRole.name = '';
                this.fetchRoles();
                alert('Role created successfully');
            } catch (error) {
                console.error('Error creating role:', error);
                alert('Failed to create role');
            }
        },
        async editRole(role) {
            this.editingRole = role;
            this.showEditModal = true;
            this.loadingPermissions = true;

            try {
                // Fetch all permissions
                const permissionsResponse = await axios.get('permissions');
                this.allPermissions = permissionsResponse.data.data;

                // Fetch role's current permissions
                const roleResponse = await axios.get(`roles/${role.id}`);
                this.currentRolePermissions = roleResponse.data.data.permissions;
                this.selectedPermissions = this.currentRolePermissions.map(p => p.id);
            } catch (error) {
                console.error('Error loading permissions:', error);
                alert('Failed to load permissions');
            } finally {
                this.loadingPermissions = false;
            }
        },
        async updateRolePermissions() {
            try {
                await axios.put(`roles/${this.editingRole.id}/permissions`, {
                    permissions: this.selectedPermissions
                });
                this.showEditModal = false;
                this.fetchRoles();
                alert('Permissions updated successfully');
            } catch (error) {
                console.error('Error updating permissions:', error);
                alert('Failed to update permissions');
            }
        },
        async removePermission(permissionId) {
            if (!confirm('Are you sure you want to remove this permission?')) {
                return;
            }

            try {
                await axios.delete(`roles/${this.editingRole.id}/permissions/${permissionId}`);
                
                // Remove from current permissions list
                this.currentRolePermissions = this.currentRolePermissions.filter(p => p.id !== permissionId);
                
                // Uncheck in selected permissions
                this.selectedPermissions = this.selectedPermissions.filter(id => id !== permissionId);
                
                // Refresh roles list
                this.fetchRoles();
                
                alert('Permission removed successfully');
            } catch (error) {
                console.error('Error removing permission:', error);
                alert('Failed to remove permission');
            }
        },
        async removeAllPermissions() {
            if (!confirm('Are you sure you want to remove ALL permissions from this role?')) {
                return;
            }

            try {
                await axios.delete(`roles/${this.editingRole.id}/permissions`);
                
                // Clear current and selected permissions
                this.currentRolePermissions = [];
                this.selectedPermissions = [];
                
                // Refresh roles list
                this.fetchRoles();
                
                alert('All permissions removed successfully');
            } catch (error) {
                console.error('Error removing all permissions:', error);
                alert('Failed to remove all permissions');
            }
        },
        async deleteRole(role) {
            if (!confirm(`Are you sure you want to delete the "${role.name}" role?`)) {
                return;
            }

            try {
                await axios.delete(`roles/${role.id}`);
                this.fetchRoles();
                alert('Role deleted successfully');
            } catch (error) {
                console.error('Error deleting role:', error);
                alert('Failed to delete role');
            }
        },
        isSystemRole(name) {
            return ['admin', 'user'].includes(name);
        },
        formatDate(date) {
            return new Date(date).toLocaleDateString();
        }
    }
};
</script>

<style scoped>
.roles-page {
    padding: 2rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.page-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.page-subtitle {
    color: #6b7280;
    margin: 0.25rem 0 0 0;
}

.page-actions {
    display: flex;
    gap: 0.75rem;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    border-radius: 0.5rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #e5e7eb;
    color: #374151;
}

.btn-secondary:hover {
    background: #d1d5db;
}

.card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.card-body {
    padding: 1.5rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: left;
    padding: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 1rem 0.75rem;
    border-bottom: 1px solid #f3f4f6;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    padding: 0.5rem;
    border-radius: 0.375rem;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    background: transparent;
}

.btn-icon-primary {
    color: #3b82f6;
}

.btn-icon-primary:hover {
    background: #eff6ff;
}

.btn-icon-danger {
    color: #ef4444;
}

.btn-icon-danger:hover {
    background: #fef2f2;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.badge-secondary {
    background: #f3f4f6;
    color: #4b5563;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f4f6;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal {
    background: white;
    border-radius: 0.75rem;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-lg {
    max-width: 700px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6b7280;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #374151;
}

.form-control {
    width: 100%;
    padding: 0.625rem;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    font-size: 1rem;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.text-gray-500 {
    color: #6b7280;
    font-size: 0.875rem;
}

.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
}

.permission-item {
    padding: 0.75rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.checkbox {
    width: 1.125rem;
    height: 1.125rem;
    cursor: pointer;
}

.current-permissions-section {
    margin-bottom: 2rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 0.5rem;
    border: 1px solid #e5e7eb;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-header h4 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #374151;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.permissions-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.permission-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
}

.remove-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: #dc2626;
    padding: 0.125rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.remove-btn:hover {
    background: rgba(220, 38, 38, 0.1);
}

.available-permissions-section {
    margin-top: 1.5rem;
}

.available-permissions-section h4 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
    font-weight: 600;
    color: #374151;
}

.capitalize {
    text-transform: capitalize;
}

.font-semibold {
    font-weight: 600;
}

.text-center {
    text-align: center;
}

.py-8 {
    padding-top: 2rem;
    padding-bottom: 2rem;
}
</style>