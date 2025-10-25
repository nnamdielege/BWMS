<template>
    <div class="subscriptions-management">
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
                <select v-model="statusFilter" class="select-input" @change="fetchSubscriptions">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="trial">Trial</option>
                    <option value="suspended">Suspended</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <button @click="exportToCSV" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Export CSV
            </button>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading">
            <div class="spinner"></div>
        </div>

        <!-- Subscriptions Table -->
        <div v-else class="table-container">
            <table class="subscriptions-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Period</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="subscription in subscriptions" :key="subscription.id" class="table-row">
                        <td class="user-cell">
                            <div class="user-info">
                                <div class="user-avatar">{{ subscription.user_name.charAt(0).toUpperCase() }}</div>
                                <span>{{ subscription.user_name }}</span>
                            </div>
                        </td>
                        <td>{{ subscription.user_email }}</td>
                        <td class="plan-cell">{{ subscription.plan_name }}</td>
                        <td>
                            <span :class="['status-badge', `status-${subscription.status}`]">
                                {{ formatStatus(subscription.status) }}
                            </span>
                        </td>
                        <td class="amount-cell">${{ formatCurrency(subscription.amount) }}/{{ subscription.interval }}</td>
                        <td class="period-cell">
                            <div class="period-info">
                                <small>{{ formatDate(subscription.current_period_start) }}</small>
                                <small>to</small>
                                <small>{{ formatDate(subscription.current_period_end) }}</small>
                            </div>
                        </td>
                        <td class="actions-cell">
                            <div class="action-buttons">
                                <button
                                    @click="openDetailModal(subscription)"
                                    class="btn-icon"
                                    title="View Details"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>

                                <button
                                    v-if="subscription.status === 'active'"
                                    @click="suspendSubscription(subscription)"
                                    class="btn-icon btn-warning"
                                    title="Suspend"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </button>

                                <button
                                    v-if="subscription.status === 'suspended'"
                                    @click="resumeSubscription(subscription)"
                                    class="btn-icon btn-success"
                                    title="Resume"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </button>

                                <button
                                    @click="openCancelModal(subscription)"
                                    class="btn-icon btn-danger"
                                    title="Cancel"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
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
                    <h3>Subscription Details</h3>
                    <button @click="showDetailModal = false" class="btn-close">&times;</button>
                </div>
                <div class="modal-body" v-if="selectedSubscription">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>User Name</label>
                            <p>{{ selectedSubscription.user_name }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Email</label>
                            <p>{{ selectedSubscription.user_email }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Plan</label>
                            <p>{{ selectedSubscription.plan_name }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Status</label>
                            <p>
                                <span :class="['status-badge', `status-${selectedSubscription.status}`]">
                                    {{ formatStatus(selectedSubscription.status) }}
                                </span>
                            </p>
                        </div>
                        <div class="detail-item">
                            <label>Monthly Amount</label>
                            <p>${{ formatCurrency(selectedSubscription.amount) }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Period Start</label>
                            <p>{{ formatDate(selectedSubscription.current_period_start) }}</p>
                        </div>
                        <div class="detail-item">
                            <label>Period End</label>
                            <p>{{ formatDate(selectedSubscription.current_period_end) }}</p>
                        </div>
                        <div v-if="selectedSubscription.trial_ends_at" class="detail-item">
                            <label>Trial Ends</label>
                            <p>{{ formatDate(selectedSubscription.trial_ends_at) }}</p>
                        </div>
                        <div class="detail-item">
                            <label>User Status</label>
                            <p>
                                <span :class="['status-badge', selectedSubscription.user_is_active ? 'status-active' : 'status-suspended']">
                                    {{ selectedSubscription.user_is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cancel Subscription Modal -->
        <div v-if="showCancelModal" class="modal" @click.self="showCancelModal = false">
            <div class="modal-content modal-sm">
                <div class="modal-header">
                    <h3>Cancel Subscription</h3>
                    <button @click="showCancelModal = false" class="btn-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this subscription?</p>
                    <textarea
                        v-model="cancellationReason"
                        placeholder="Enter reason for cancellation (optional)"
                        class="textarea-input"
                    ></textarea>
                </div>
                <div class="modal-footer">
                    <button @click="showCancelModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="confirmCancel" class="btn btn-danger">Cancel Subscription</button>
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
    name: 'SubscriptionsManagement',
    setup() {
        const subscriptions = ref([]);
        const loading = ref(true);
        const searchQuery = ref('');
        const statusFilter = ref('');
        const currentPage = ref(1);
        const pagination = ref(null);
        const showDetailModal = ref(false);
        const showCancelModal = ref(false);
        const selectedSubscription = ref(null);
        const cancellationReason = ref('');
        const notification = ref(null);
        let searchTimeout;

        const token = localStorage.getItem('token');

        const fetchSubscriptions = async () => {
            loading.value = true;
            try {
                const params = new URLSearchParams({
                    page: currentPage.value,
                    per_page: 15,
                    status: statusFilter.value,
                    search: searchQuery.value,
                });

                const response = await fetch(`/api/v1/admin/subscriptions?${params}`, {
                    headers: { 'Authorization': `Bearer ${token}` },
                });

                if (response.ok) {
                    const data = await response.json();
                    subscriptions.value = data.data;
                    pagination.value = data.pagination;
                }
            } catch (error) {
                console.error('Error fetching subscriptions:', error);
                showNotification('Failed to load subscriptions', 'error');
            } finally {
                loading.value = false;
            }
        };

        const debounceSearch = () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentPage.value = 1;
                fetchSubscriptions();
            }, 300);
        };

        const openDetailModal = (subscription) => {
            selectedSubscription.value = subscription;
            showDetailModal.value = true;
        };

        const openCancelModal = (subscription) => {
            selectedSubscription.value = subscription;
            cancellationReason.value = '';
            showCancelModal.value = true;
        };

        const suspendSubscription = async (subscription) => {
            try {
                const response = await fetch(
                    `/api/v1/admin/subscriptions/${subscription.id}/toggle-status`,
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ action: 'suspend' }),
                    }
                );

                if (response.ok) {
                    showNotification('Subscription suspended successfully', 'success');
                    fetchSubscriptions();
                }
            } catch (error) {
                console.error('Error suspending subscription:', error);
                showNotification('Failed to suspend subscription', 'error');
            }
        };

        const resumeSubscription = async (subscription) => {
            try {
                const response = await fetch(
                    `/api/v1/admin/subscriptions/${subscription.id}/toggle-status`,
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ action: 'resume' }),
                    }
                );

                if (response.ok) {
                    showNotification('Subscription resumed successfully', 'success');
                    fetchSubscriptions();
                }
            } catch (error) {
                console.error('Error resuming subscription:', error);
                showNotification('Failed to resume subscription', 'error');
            }
        };

        const confirmCancel = async () => {
            try {
                const response = await fetch(
                    `/api/v1/admin/subscriptions/${selectedSubscription.value.id}/cancel`,
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ reason: cancellationReason.value }),
                    }
                );

                if (response.ok) {
                    showNotification('Subscription cancelled successfully', 'success');
                    showCancelModal.value = false;
                    fetchSubscriptions();
                }
            } catch (error) {
                console.error('Error cancelling subscription:', error);
                showNotification('Failed to cancel subscription', 'error');
            }
        };

        const exportToCSV = async () => {
            try {
                const response = await fetch('/api/v1/admin/subscriptions/export', {
                    headers: { 'Authorization': `Bearer ${token}` },
                });

                if (response.ok) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'subscriptions.csv';
                    a.click();
                    window.URL.revokeObjectURL(url);
                }
            } catch (error) {
                console.error('Error exporting subscriptions:', error);
                showNotification('Failed to export subscriptions', 'error');
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
                fetchSubscriptions();
            }
        };

        const nextPage = () => {
            if (pagination.value && currentPage.value < pagination.value.last_page) {
                currentPage.value++;
                fetchSubscriptions();
            }
        };

        const showNotification = (message, type = 'info') => {
            notification.value = { message, type };
            setTimeout(() => {
                notification.value = null;
            }, 3000);
        };

        onMounted(() => {
            fetchSubscriptions();
        });

        return {
            subscriptions,
            loading,
            searchQuery,
            statusFilter,
            pagination,
            showDetailModal,
            showCancelModal,
            selectedSubscription,
            cancellationReason,
            notification,
            fetchSubscriptions,
            debounceSearch,
            openDetailModal,
            openCancelModal,
            suspendSubscription,
            resumeSubscription,
            confirmCancel,
            exportToCSV,
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
.subscriptions-management {
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

.subscriptions-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.subscriptions-table thead {
    background-color: #f3f4f6;
}

.subscriptions-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.subscriptions-table td {
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

.amount-cell,
.period-cell {
    font-size: 0.85rem;
    color: #6b7280;
}

.period-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.period-info small {
    color: #9ca3af;
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

.btn-icon.btn-danger {
    border-color: #ef4444;
    color: #ef4444;
}

.btn-icon.btn-danger:hover {
    background-color: #fef2f2;
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
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-sm {
    max-width: 400px;
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

.textarea-input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    margin-top: 1rem;
    min-height: 100px;
    resize: vertical;
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
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

.btn-secondary {
    background-color: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background-color: #e5e7eb;
}

.btn-danger {
    background-color: #ef4444;
    color: white;
}

.btn-danger:hover {
    background-color: #dc2626;
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