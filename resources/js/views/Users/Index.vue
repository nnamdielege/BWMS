<template>
    <div class="users-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">User Management</h1>
                <p class="page-subtitle">Manage users and their role assignments</p>
            </div>
        </div>

        <!-- Users List -->
        <div class="card">
            <div class="card-body">
                <div v-if="loading" class="text-center py-8">
                    <div class="loading-spinner"></div>
                </div>

                <div v-else>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Current Role</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users" :key="user.id">
                                <td>
                                    <div class="font-semibold">{{ user.name }}</div>
                                </td>
                                <td>{{ user.email }}</td>
                                <td>
                                    <div v-if="user.roles && user.roles.length > 0" class="roles-container">
                                        <span 
                                            v-for="role in user.roles" 
                                            :key="role.id"
                                            class="role-badge">
                                            <span class="capitalize">{{ role.name }}</span>
                                            <button 
                                                @click="removeRoleFromUser(user, role.id)"
                                                class="remove-role-btn"
                                                title="Remove this role">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </span>
                                    </div>
                                    <span v-else class="badge badge-secondary">No Role</span>
                                </td>
                                <td>{{ formatDate(user.created_at) }}</td>
                                <td>
                                    <button 
                                        @click="editUserRole(user)" 
                                        class="btn-sm btn-primary">
                                        Assign Role
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Edit User Role Modal -->
        <div v-if="showEditModal" class="modal-overlay" @click.self="showEditModal = false">
            <div class="modal">
                <div class="modal-header">
                    <h3>Assign Roles to {{ editingUser.name }}</h3>
                    <button @click="showEditModal = false" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Current Roles Section -->
                    <div v-if="currentUserRoles.length > 0" class="current-roles-section">
                        <div class="section-header">
                            <label>Current Roles ({{ currentUserRoles.length }})</label>
                            <button 
                                @click="removeAllRolesFromUser" 
                                class="btn-sm btn-danger"
                                title="Remove All Roles">
                                Remove All
                            </button>
                        </div>
                        <div class="roles-list">
                            <span 
                                v-for="role in currentUserRoles" 
                                :key="role.id"
                                class="role-badge-large">
                                <span class="capitalize">{{ role.name }}</span>
                                <button 
                                    @click="removeRoleFromUserInModal(role.id)"
                                    class="remove-role-btn"
                                    title="Remove this role">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        </div>
                    </div>

                    <!-- Available Roles Section -->
                    <div class="form-group">
                        <label>Select Roles (hold Ctrl/Cmd to select multiple)</label>
                        <select v-model="selectedRoles" class="form-control" multiple size="6">
                            <option v-for="role in roles" :key="role.id" :value="role.name">
                                {{ role.name }}
                            </option>
                        </select>
                        <small class="text-muted">
                            Hold Ctrl (Windows) or Cmd (Mac) to select multiple roles
                        </small>
                    </div>
                    
                    <!-- Show currently selected roles -->
                    <div v-if="selectedRoles.length > 0" class="selected-roles-preview">
                        <label>Selected Roles:</label>
                        <div class="roles-container">
                            <span 
                                v-for="roleName in selectedRoles" 
                                :key="roleName"
                                class="badge badge-info capitalize">
                                {{ roleName }}
                            </span>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Note:</strong> This will replace all existing roles assigned to this user.
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showEditModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="updateUserRole" class="btn btn-primary">Update Roles</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'UsersIndex',
    data() {
        return {
            users: [],
            roles: [],
            loading: false,
            showEditModal: false,
            editingUser: null,
            selectedRoles: [],
            currentUserRoles: []
        };
    },
    mounted() {
        this.fetchUsers();
        this.fetchRoles();
    },
    methods: {
        async fetchUsers() {
    this.loading = true;
    try {
        const response = await axios.get('/api/v1/users');
        this.users = response.data.data;
    } catch (error) {
        console.error('Error fetching users:', error);
        alert('Failed to load users: ' + (error.response?.data?.message || error.message));
    } finally {
        this.loading = false;
    }
        },
        async fetchRoles() {
            try {
                const response = await axios.get('/api/v1/roles');
                this.roles = response.data.data;
            } catch (error) {
                console.error('Error fetching roles:', error);
                alert('Failed to load roles: ' + (error.response?.data?.message || error.message));
            }
        },
        editUserRole(user) {
            this.editingUser = user;
            // Set current roles
            this.currentUserRoles = user.roles && user.roles.length > 0 ? user.roles : [];
            // Set selected roles from user's current roles
            this.selectedRoles = user.roles && user.roles.length > 0 
                ? user.roles.map(role => role.name)
                : [];
            this.showEditModal = true;
        },
        async updateUserRole() {
    try {
        await axios.post(`/api/v1/users/${this.editingUser.id}/assign-roles`, {
            roles: this.selectedRoles
        });
        this.showEditModal = false;
        this.fetchUsers();
        alert('Roles updated successfully');
    } catch (error) {
        console.error('Error updating user roles:', error);
        alert('Failed to update roles: ' + (error.response?.data?.message || error.message));
    }
        },
        async removeRoleFromUser(user, roleId) {
            if (!confirm(`Are you sure you want to remove this role from ${user.name}?`)) {
                return;
            }

            try {
                await axios.delete(`/api/v1/users/${user.id}/roles/${roleId}`);
                this.fetchUsers();
                alert('Role removed successfully');
            } catch (error) {
                console.error('Error removing role:', error);
                alert('Failed to remove role: ' + (error.response?.data?.message || error.message));
            }
        },
        async removeRoleFromUserInModal(roleId) {
    if (!confirm('Are you sure you want to remove this role?')) {
        return;
    }

    try {
        await axios.delete(`/api/v1/users/${this.editingUser.id}/roles/${roleId}`);
        
        // Update current roles list
        this.currentUserRoles = this.currentUserRoles.filter(r => r.id !== roleId);
        
        // Update selected roles
        const removedRole = this.currentUserRoles.find(r => r.id === roleId);
        if (removedRole) {
            this.selectedRoles = this.selectedRoles.filter(name => name !== removedRole.name);
        }
        
        // Refresh users list
        this.fetchUsers();
        
        alert('Role removed successfully');
    } catch (error) {
        console.error('Error removing role:', error);
        alert('Failed to remove role: ' + (error.response?.data?.message || error.message));
    }
        },
        async removeAllRolesFromUser() {
    if (!confirm(`Are you sure you want to remove ALL roles from ${this.editingUser.name}?`)) {
        return;
    }

    try {
        await axios.delete(`/api/v1/users/${this.editingUser.id}/roles`);
        
        // Clear current and selected roles
        this.currentUserRoles = [];
        this.selectedRoles = [];
        
        // Refresh users list
        this.fetchUsers();
        
        alert('All roles removed successfully');
    } catch (error) {
        console.error('Error removing all roles:', error);
        alert('Failed to remove all roles: ' + (error.response?.data?.message || error.message));
    }
        },
        formatDate(date) {
            return new Date(date).toLocaleDateString();
        }
    }
};
</script>

<style scoped>
.users-page {
    padding: 2rem;
}

.page-header {
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

.btn-sm {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
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

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
    margin-right: 0.5rem;
    margin-bottom: 0.25rem;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.badge-secondary {
    background: #f3f4f6;
    color: #4b5563;
}

.roles-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem 0.25rem 0.75rem;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
}

.role-badge-large {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.5rem 0.5rem 0.75rem;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
}

.remove-role-btn {
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

.remove-role-btn:hover {
    background: rgba(220, 38, 38, 0.1);
}

.current-roles-section {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 0.5rem;
    border: 1px solid #e5e7eb;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.section-header label {
    margin: 0;
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

.roles-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
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

.text-muted {
    color: #6b7280;
    font-size: 0.875rem;
    margin-top: 0.25rem;
    display: block;
}

.selected-roles-preview {
    margin-top: 1rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 0.5rem;
}

.selected-roles-preview label {
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
    display: block;
}

.alert {
    padding: 0.75rem;
    border-radius: 0.5rem;
    margin-top: 1rem;
}

.alert-info {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #93c5fd;
}
</style>