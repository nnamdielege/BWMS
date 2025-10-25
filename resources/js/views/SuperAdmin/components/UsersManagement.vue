<template>
    <div class="users-management">
        <!-- Filters -->
        <div class="filters-section">
            <div class="filter-group">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by email or name..."
                    class="search-input"
                    @input="debounceSearch"
                />
            </div>

            <div class="filter-group">
                <select v-model="statusFilter" class="select-input" @change="fetchUsers">
                    <option value="">All Users</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading">
            <div class="spinner"></div>
        </div>

        <!-- Users Table -->
        <div v-else class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Plan</th>
                        <th>Subscription Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id" class="table-row">
                        <td class="user-cell">
                            <div class="user-info">
                                <div class="user-avatar">{{ user.name.charAt(0).toUpperCase() }}</div>
                                <span>{{ user.name }}</span>
                            </div>
                        </td>
                        <td>{{ user.email }}</td>
                        <td>
                            <span :class="['status-badge', user.is_active ? 'status-active' : 'status-inactive']">
                                {{ user.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td>
                            <span v-if="user.subscription" class="plan-badge">
                                {{ user.subscription.plan_name }}
                            </span>
                            <span v-else class="plan-badge plan-none">
                                No Plan
                            </span>
                        </td>
                        <td>
                            <span
                                v-if="user.subscription"
                                :class="['status-badge', `status-${user.subscription.status}`]"
                            >
                                {{ formatStatus(user.subscription.status) }}
                            </span>
                            <span v-else class="status-badge status-none">
                                N/A
                            </span>
                        </td>
                        <td class="date-cell">{{ formatDate(user.created_at) }}</td>
                        <td class="actions-cell">
                            <div class="action-buttons">
                                <button
                                    @click="openDetailModal(user)"
                                    class="btn-icon"
                                    title="View Details"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>

                                <button
                                    @click="toggleUserStatus(user)"
                                    :class="['btn-icon', user.is_active ? 'btn-warning' : 'btn-success']"
                                    :title="user.is_active ? 'Deactivate' : 'Activate'"
                                >
                                    <svg
                                        v-if="user.is_active"
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="!loading && pagination" class="pagination">
            <button
                @click="previousPage"
                :disabled="pagination.current_page === 1"
                class="btn btn-small"
            >
                Previous
            </button>
            <span class="page-info">
                Page {{ pagination.current_page }} of {{ pagination.last_page }}
            </span>
            <button
                @click="nextPage"
                :disabled="pagination.current_page === pagination.last_page"
                class="btn btn-small"
            >
                Next
            </button>
        </div>

        <!-- Detail Modal -->
        <div v-if="showDetailModal" class="modal" @click.self="showDetailModal = false">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>User Details</h3>
                    <button @click="showDetailModal = false" class="btn-close">&times;</button>
                </div>
                <div class="modal-body" v-if="selectedUserDetail">
                    <div class="loading-modal" v-if="loadingDetail">
                        <div class="spinner"></div>
                    </div>

                    <div v-else>
                        <!-- User Info -->
                        <div class="detail-section">
                            <h4>User Information</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Name</label>
                                    <p>{{ selectedUserDetail.name }}</p>
                                </div>
                                <div class="detail-item">
                                    <label>Email</label>
                                    <p>{{ selectedUserDetail.email }}</p>
                                </div>
                                <div class="detail-item">
                                    <label>Status</label>
                                    <p>
                                        <span :class="['status-badge', selectedUserDetail.is_active ? 'status-active' : 'status-inactive']">
                                            {{ selectedUserDetail.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </p>
                                </div>
                                <div class="detail-item">
                                    <label>Joined</label>
                                    <p>{{ formatDate(selectedUserDetail.created_at) }}</p>
                                </div>
                                <div class="detail-item">
                                    <label>Roles</label>
                                    <p>
                                        <span v-for="role in selectedUserDetail.roles" :key="role" class="role-badge">
                                            {{ role }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Subscription Info -->
                        <div v-if="selectedUserDetail.subscription" class="detail-section">
                            <h4>Subscription Information</h4>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Plan</label>
                                    <p>{{ selectedUserDetail.subscription.plan.name }}</p>
                                </div>
                                <div class="detail-item">
                                    <label>Amount</label>
                                    <p>${{ formatCurrency(selectedUserDetail.subscription.plan.amount) }}/{{ selectedUserDetail.subscription.plan.interval }}</p>
                                </div>
                                <div class="detail-item">
                                    <label>Status</label>
                                    <p>
                                        <span :class="['status-badge', `status-${selectedUserDetail.subscription.status}`]">
                                            {{ formatStatus(selectedUserDetail.subscription.status) }}
                                        </span>
                                    </p>
                                </div>
                                <div class="detail-item">
                                    <label>Period Start</label>
                                    <p>{{ formatDate(selectedUserDetail.subscription.current_period_start) }}</p>
                                </div>
                                <div class="detail-item">
                                    <label>Period End</label>
                                    <p>{{ formatDate(selectedUserDetail.subscription.current_period_end) }}</p>
                                </div>
                                <div v-if="selectedUserDetail.subscription.trial_ends_at" class="detail-item">
                                    <label>Trial Ends</label>
                                    <p>{{ formatDate(selectedUserDetail.subscription.trial_ends_at) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Usage Stats -->
                        <div v-if="selectedUserDetail.usage_stats?.length > 0" class="detail-section">
                            <h4>Feature Usage</h4>
                            <div class="usage-stats">
                                <div v-for="stat in selectedUserDetail.usage_stats" :key="stat.feature" class="usage-item">
                                    <div class="usage-header">
                                        <span class="feature-name">{{ stat.feature }}</span>
                                        <span class="usage-value">{{ stat.used }} / {{ stat.limit }}</span>
                                    </div>
                                    <div class="usage-bar">
                                        <div class="usage-fill" :style="{ width: stat.percentage + '%' }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Activity Log -->
                        <div v-if="selectedUserDetail.activity_log?.length > 0" class="detail-section">
                            <h4>Recent Activity</h4>
                            <div class="activity-log">
                                <div v-for="log in selectedUserDetail.activity_log" :key="log.id" class="activity-item">
                                    <div class="activity-time">{{ formatDate(log.created_at) }}</div>
                                    <div class="activity-action">{{ log.action }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notifications -->
        <div v-if="notification" :class="['notification', `notification-${notification.type}`]">
            {{ notification.message }}
        </div>
    </div>
</template>

<script>
import { ref, onMounted } from 'vue';

export default {
    name: 'UsersManagement',
    setup() {
        const users = ref([]);
        const loading = ref(true);
        const loadingDetail = ref(false);
        const searchQuery = ref('');
        const statusFilter = ref('');
        const currentPage = ref(1);
        const pagination = ref(null);
        const showDetailModal = ref(false);
        const selectedUserDetail = ref(null);
        const notification = ref(null);
        let searchTimeout;

        const token = localStorage.getItem('token');

        const fetchUsers = async () => {
            loading.value = true;
            try {
                const params = new URLSearchParams({
                    page: currentPage.value,
                    per_page: 15,
                    status: statusFilter.value,
                    search: searchQuery.value,
                });

                const response = await fetch(`/api/v1/admin/users?${params}`, {
                    headers: { 'Authorization': `Bearer ${token}` },
                });

                if (response.ok) {
                    const data = await response.json();
                    users.value = data.data;
                    pagination.value = data.pagination;
                }
            } catch (error) {
                console.error('Error fetching users:', error);
                showNotification('Failed to load users', 'error');
            } finally {
                loading.value = false;
            }
        };

        const debounceSearch = () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentPage.value = 1;
                fetchUsers();
            }, 300);
        };

        const openDetailModal = async (user) => {
            loadingDetail.value = true;
            showDetailModal.value = true;

            try {
                const response = await fetch(`/api/v1/admin/users/${user.id}`, {
                    headers: { 'Authorization': `Bearer ${token}` },
                });

                if (response.ok) {
                    const data = await response.json();
                    selectedUserDetail.value = data.data;
                }
            } catch (error) {
                console.error('Error fetching user detail:', error);
                showNotification('Failed to load user details', 'error');
            } finally {
                loadingDetail.value = false;
            }
        };

        const toggleUserStatus = async (user) => {
            try {
                const response = await fetch(`/api/v1/admin/users/${user.id}/toggle-status`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json',
                    },
                });

                if (response.ok) {
                    const statusText = user.is_active ? 'deactivated' : 'activated';
                    showNotification(`User ${statusText} successfully`, 'success');
                    fetchUsers();
                }
            } catch (error) {
                console.error('Error toggling user status:', error);
                showNotification('Failed to update user status', 'error');
            }
        };

        const formatStatus = (status) => {
            return status.charAt(0).toUpperCase() + status.slice(1);
        };

        const formatCurrency = (value) => {
            return parseFloat(value).toFixed(2);
        };

        const formatDate = (date) => {
            if (!date) return 'N/A';
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            });
        };

        const previousPage = () => {
            if (currentPage.value > 1) {
                currentPage.value--;
                fetchUsers();
            }
        };

        const nextPage = () => {
            if (pagination.value && currentPage.value < pagination.value.last_page) {
                currentPage.value++;
                fetchUsers();
            }
        };

        const showNotification = (message, type = 'info') => {
            notification.value = { message, type };
            setTimeout(() => {
                notification.value = null;
            }, 3000);
        };

        onMounted(() => {
            fetchUsers();
        });

        return {
            users,
            loading,
            loadingDetail,
            searchQuery,
            statusFilter,
            pagination,
            showDetailModal,
            selectedUserDetail,
            notification,
            fetchUsers,
            debounceSearch,
            openDetailModal,
            toggleUserStatus,
            formatStatus,
            formatCurrency,
            formatDate,
            previousPage,
            nextPage,
        };
    },
};
</script>

<style scoped>
.users-management {
    width: 100%;
}

.filters-section {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.search-input,
.select-input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
}

.search-input:focus,
.select-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.table-container {
    overflow-x: auto;
}

.users-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.users-table thead {
    background-color: #f3f4f6;
}

.users-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.users-table td {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.table-row:hover {
    background-color: #f9fafb;
}

.user-cell {
    display: flex;
    align-items: center;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: #3b82f6;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.85rem;
}

.status-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.status-active {
    background-color: #d1fae5;
    color: #065f46;
}

.status-inactive {
    background-color: #fee2e2;
    color: #7f1d1d;
}

.status-trial {
    background-color: #fef3c7;
    color: #92400e;
}

.status-suspended {
    background-color: #ede9fe;
    color: #6d28d9;
}

.status-cancelled {
    background-color: #fee2e2;
    color: #7f1d1d;
}

.status-none {
    background-color: #f3f4f6;
    color: #6b7280;
}

.plan-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.85rem;
    background-color: #dbeafe;
    color: #1e40af;
}

.plan-none {
    background-color: #f3f4f6;
    color: #6b7280;
}

.date-cell {
    font-size: 0.85rem;
    color: #6b7280;
}

.actions-cell {
    text-align: center;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
}

.btn-icon {
    background: none;
    border: 1px solid #d1d5db;
    padding: 0.5rem;
    border-radius: 4px;
    cursor: pointer;
    color: #6b7280;
    transition: all 0.2s;
}

.btn-icon:hover {
    background-color: #f3f4f6;
    color: #111827;
}

.btn-icon.btn-warning {
    border-color: #f59e0b;
    color: #f59e0b;
}

.btn-icon.btn-warning:hover {
    background-color: #fffbeb;
}

.btn-icon.btn-success {
    border-color: #10b981;
    color: #10b981;
}

.btn-icon.btn-success:hover {
    background-color: #f0fdf4;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.page-info {
    font-size: 0.9rem;
    color: #6b7280;
    min-width: 150px;
    text-align: center;
}

.modal {
    display: fixed;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.btn-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6b7280;
}

.btn-close:hover {
    color: #111827;
}

.modal-body {
    padding: 1.5rem;
}

.loading-modal {
    text-align: center;
    padding: 2rem;
}

.detail-section {
    margin-bottom: 2rem;
}

.detail-section h4 {
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f3f4f6;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.detail-item label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 0.5rem;
}

.detail-item p {
    margin: 0;
    color: #111827;
    font-size: 0.95rem;
}

.role-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background-color: #dbeafe;
    color: #1e40af;
    border-radius: 4px;
    font-size: 0.8rem;
    margin-right: 0.5rem;
    margin-bottom: 0.25rem;
}

.usage-stats {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.usage-item {
    padding: 1rem;
    background-color: #f9fafb;
    border-radius: 6px;
}

.usage-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.feature-name {
    font-weight: 600;
    color: #111827;
}

.usage-value {
    color: #6b7280;
}

.usage-bar {
    height: 8px;
    background-color: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.usage-fill {
    height: 100%;
    background-color: #3b82f6;
    transition: width 0.3s ease;
}

.activity-log {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-height: 300px;
    overflow-y: auto;
}

.activity-item {
    padding: 0.75rem;
    background-color: #f9fafb;
    border-radius: 4px;
    border-left: 3px solid #3b82f6;
    font-size: 0.9rem;
}

.activity-time {
    font-size: 0.8rem;
    color: #9ca3af;
    margin-bottom: 0.25rem;
}

.activity-action {
    color: #374151;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-small {
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.notification {
    position: fixed;
    bottom: 1rem;
    right: 1rem;
    padding: 1rem 1.5rem;
    border-radius: 6px;
    color: white;
    font-size: 0.9rem;
    z-index: 2000;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.notification-success {
    background-color: #10b981;
}

.notification-error {
    background-color: #ef4444;
}

.notification-info {
    background-color: #3b82f6;
}

.loading {
    text-align: center;
    padding: 2rem;
}

.spinner {
    border: 4px solid #e5e7eb;
    border-top: 4px solid #3b82f6;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>