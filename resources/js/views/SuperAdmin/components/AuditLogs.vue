<template>
    <div class="audit-logs">
        <!-- Filters -->
        <div class="filters-section">
            <div class="filter-group">
                <select v-model="actionFilter" @change="fetchLogs" class="select-input">
                    <option value="">All Actions</option>
                    <option value="user_created">User Created</option>
                    <option value="user_updated">User Updated</option>
                    <option value="subscription_created">Subscription Created</option>
                    <option value="subscription_updated">Subscription Updated</option>
                    <option value="subscription_cancelled">Subscription Cancelled</option>
                    <option value="payment_processed">Payment Processed</option>
                    <option value="payment_failed">Payment Failed</option>
                    <option value="admin_action">Admin Action</option>
                </select>
            </div>

            <button @click="refreshLogs" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Refresh
            </button>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading">
            <div class="spinner"></div>
        </div>

        <!-- Logs Timeline -->
        <div v-else class="logs-content">
            <div v-if="logs.length > 0" class="logs-timeline">
                <div v-for="log in logs" :key="log.id" class="log-item">
                    <div class="log-icon" :class="`icon-${log.action}`">
                        <svg v-if="isUserAction(log.action)" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <svg v-else-if="isSubscriptionAction(log.action)" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else-if="isPaymentAction(log.action)" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>

                    <div class="log-content">
                        <div class="log-header">
                            <h4 class="log-action">{{ formatAction(log.action) }}</h4>
                            <span class="log-date">{{ formatDateTime(log.created_at) }}</span>
                        </div>

                        <div class="log-details">
                            <div v-if="log.user_id" class="detail">
                                <span class="label">User:</span>
                                <span class="value">{{ log.user_email || `ID: ${log.user_id}` }}</span>
                            </div>

                            <div v-if="log.model_type" class="detail">
                                <span class="label">Model:</span>
                                <span class="value">{{ log.model_type }}</span>
                            </div>

                            <div v-if="log.old_values" class="detail">
                                <span class="label">Changes:</span>
                                <div class="changes">
                                    <div v-for="(newVal, key) in log.new_values" :key="key" class="change-item">
                                        <span class="change-key">{{ key }}:</span>
                                        <span class="old-value">{{ log.old_values[key] || 'N/A' }}</span>
                                        <svg class="arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                        <span class="new-value">{{ newVal }}</span>
                                    </div>
                                </div>
                            </div>

                            <div v-if="log.description" class="detail">
                                <span class="label">Description:</span>
                                <span class="value">{{ log.description }}</span>
                            </div>
                        </div>

                        <div class="log-footer">
                            <span v-if="log.ip_address" class="ip-badge">{{ log.ip_address }}</span>
                            <span v-if="log.user_agent" class="agent-badge">{{ truncate(log.user_agent, 50) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="no-logs">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <p>No audit logs found</p>
            </div>
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
    </div>
</template>

<script>
import { ref, onMounted } from 'vue';

export default {
    name: 'AuditLogs',
    setup() {
        const logs = ref([]);
        const loading = ref(true);
        const actionFilter = ref('');
        const currentPage = ref(1);
        const pagination = ref(null);

        const token = localStorage.getItem('token');

        const fetchLogs = async () => {
            loading.value = true;
            try {
                const params = new URLSearchParams({
                    page: currentPage.value,
                    per_page: 20,
                    action: actionFilter.value,
                });

                const response = await fetch(`/api/v1/admin/audit-logs?${params}`, {
                    headers: { 'Authorization': `Bearer ${token}` },
                });

                if (response.ok) {
                    const data = await response.json();
                    logs.value = data.data;
                    pagination.value = data.pagination;
                }
            } catch (error) {
                console.error('Error fetching audit logs:', error);
            } finally {
                loading.value = false;
            }
        };

        const refreshLogs = () => {
            currentPage.value = 1;
            fetchLogs();
        };

        const previousPage = () => {
            if (currentPage.value > 1) {
                currentPage.value--;
                fetchLogs();
            }
        };

        const nextPage = () => {
            if (pagination.value && currentPage.value < pagination.value.last_page) {
                currentPage.value++;
                fetchLogs();
            }
        };

        const formatAction = (action) => {
            return action
                .split('_')
                .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                .join(' ');
        };

        const formatDateTime = (dateTime) => {
            const date = new Date(dateTime);
            const now = new Date();
            const diff = now - date;

            // Less than a minute
            if (diff < 60000) {
                return 'Just now';
            }

            // Less than an hour
            if (diff < 3600000) {
                const minutes = Math.floor(diff / 60000);
                return `${minutes}m ago`;
            }

            // Less than a day
            if (diff < 86400000) {
                const hours = Math.floor(diff / 3600000);
                return `${hours}h ago`;
            }

            // More than a day
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        };

        const isUserAction = (action) => {
            return action.includes('user');
        };

        const isSubscriptionAction = (action) => {
            return action.includes('subscription');
        };

        const isPaymentAction = (action) => {
            return action.includes('payment');
        };

        const truncate = (str, length) => {
            if (!str || str.length <= length) return str;
            return str.substring(0, length) + '...';
        };

        onMounted(() => {
            fetchLogs();
            // Refresh every 30 seconds
            setInterval(fetchLogs, 30000);
        });

        return {
            logs,
            loading,
            actionFilter,
            pagination,
            fetchLogs,
            refreshLogs,
            previousPage,
            nextPage,
            formatAction,
            formatDateTime,
            isUserAction,
            isSubscriptionAction,
            isPaymentAction,
            truncate,
        };
    },
};
</script>

<style scoped>
.audit-logs {
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

.select-input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    background-color: white;
    cursor: pointer;
}

.select-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-secondary {
    background-color: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background-color: #e5e7eb;
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

.logs-content {
    margin-bottom: 2rem;
}

.logs-timeline {
    position: relative;
    padding: 1rem 0;
}

.logs-timeline::before {
    content: '';
    position: absolute;
    left: 19px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, #3b82f6, #e5e7eb);
}

.log-item {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    position: relative;
}

.log-icon {
    position: relative;
    z-index: 1;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background-color: white;
    border: 2px solid #3b82f6;
}

.log-icon svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
}

.icon-user_created,
.icon-user_updated {
    border-color: #3b82f6;
    color: #3b82f6;
}

.icon-subscription_created,
.icon-subscription_updated,
.icon-subscription_cancelled {
    border-color: #10b981;
    color: #10b981;
}

.icon-payment_processed {
    border-color: #8b5cf6;
    color: #8b5cf6;
}

.icon-payment_failed {
    border-color: #ef4444;
    color: #ef4444;
}

.icon-admin_action {
    border-color: #f59e0b;
    color: #f59e0b;
}

.log-content {
    flex: 1;
    background: white;
    border-radius: 8px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border-left: 4px solid #3b82f6;
}

.log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.log-action {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
}

.log-date {
    font-size: 0.85rem;
    color: #9ca3af;
}

.log-details {
    margin-bottom: 1rem;
    border-top: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    padding: 1rem 0;
}

.detail {
    display: grid;
    grid-template-columns: 100px 1fr;
    gap: 1rem;
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
}

.detail .label {
    font-weight: 600;
    color: #6b7280;
}

.detail .value {
    color: #374151;
    word-break: break-all;
}

.changes {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.change-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    background-color: #f9fafb;
    padding: 0.5rem;
    border-radius: 4px;
}

.change-key {
    font-weight: 600;
    color: #6b7280;
}

.old-value {
    color: #ef4444;
    text-decoration: line-through;
}

.arrow {
    width: 16px;
    height: 16px;
    color: #9ca3af;
}

.new-value {
    color: #10b981;
    font-weight: 600;
}

.log-footer {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.ip-badge,
.agent-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background-color: #f3f4f6;
    color: #6b7280;
    border-radius: 4px;
    font-size: 0.8rem;
    font-family: 'Monaco', 'Menlo', monospace;
}

.no-logs {
    text-align: center;
    padding: 3rem 2rem;
    color: #9ca3af;
}

.no-logs svg {
    width: 64px;
    height: 64px;
    margin: 0 auto 1rem;
    opacity: 0.5;
}

.no-logs p {
    font-size: 1rem;
    margin: 0;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem 0;
    border-top: 1px solid #e5e7eb;
}

.page-info {
    font-size: 0.9rem;
    color: #6b7280;
    min-width: 150px;
    text-align: center;
}

.btn-small {
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

@media (max-width: 768px) {
    .log-item {
        gap: 1rem;
    }

    .log-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .detail {
        grid-template-columns: 1fr;
    }
}
</style>