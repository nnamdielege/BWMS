<template>
    <div class="admin-dashboard">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Admin Dashboard</h1>
                <p class="page-subtitle">Monitor subscriptions, users, and system metrics</p>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading dashboard...</p>
        </div>

        <!-- Dashboard Content -->
        <div v-else class="admin-content">
            <!-- Key Metrics -->
            <div class="metrics-grid">
                <!-- Users Metrics -->
                <div class="metric-card">
                    <div class="metric-header">
                        <h3>Total Users</h3>
                        <svg class="metric-icon users-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 12H9m4.5-7.5H9m0 15h6m0-5.5H9" />
                        </svg>
                    </div>
                    <div class="metric-value">{{ overview?.users?.total || 0 }}</div>
                    <div class="metric-breakdown">
                        <span class="breakdown-item">
                            <span class="dot active"></span>
                            Active: {{ overview?.users?.active || 0 }}
                        </span>
                        <span class="breakdown-item">
                            <span class="dot inactive"></span>
                            Inactive: {{ overview?.users?.inactive || 0 }}
                        </span>
                    </div>
                </div>

                <!-- Subscriptions Metrics -->
                <div class="metric-card">
                    <div class="metric-header">
                        <h3>Active Subscriptions</h3>
                        <svg class="metric-icon subscription-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                        </svg>
                    </div>
                    <div class="metric-value">{{ overview?.subscriptions?.active || 0 }}</div>
                    <div class="metric-breakdown">
                        <span class="breakdown-item">
                            <span class="dot trial"></span>
                            Trial: {{ overview?.subscriptions?.trial || 0 }}
                        </span>
                        <span class="breakdown-item">
                            <span class="dot suspended"></span>
                            Suspended: {{ overview?.subscriptions?.suspended || 0 }}
                        </span>
                    </div>
                </div>

                <!-- Revenue Metrics -->
                <div class="metric-card">
                    <div class="metric-header">
                        <h3>Monthly Recurring Revenue</h3>
                        <svg class="metric-icon revenue-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="metric-value">${{ formatCurrency(overview?.revenue?.mrr) }}</div>
                    <div class="metric-breakdown">
                        <span class="breakdown-item">
                            Churn Rate: {{ overview?.revenue?.churn_rate?.toFixed(2) }}%
                        </span>
                    </div>
                </div>

                <!-- Total Revenue -->
                <div class="metric-card">
                    <div class="metric-header">
                        <h3>Total Revenue</h3>
                        <svg class="metric-icon total-revenue-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div class="metric-value">${{ formatCurrency(overview?.revenue?.total) }}</div>
                </div>
            </div>

            <!-- Plan Breakdown -->
            <div v-if="overview?.plan_breakdown?.length > 0" class="card mt-4">
                <div class="card-header">
                    <h2 class="card-title">Subscription Plans Breakdown</h2>
                </div>
                <div class="card-body">
                    <div class="plan-breakdown-grid">
                        <div v-for="plan in overview.plan_breakdown" :key="plan.name" class="plan-item">
                            <div class="plan-name">{{ plan.name }}</div>
                            <div class="plan-stats">
                                <div class="stat">
                                    <span class="label">Subscriptions:</span>
                                    <span class="value">{{ plan.count }}</span>
                                </div>
                                <div class="stat">
                                    <span class="label">Revenue:</span>
                                    <span class="value">${{ formatCurrency(plan.revenue) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="admin-tabs mt-6">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="['tab-button', { active: activeTab === tab.id }]"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Subscriptions Tab -->
                <SubscriptionsManagement v-if="activeTab === 'subscriptions'" />

                <!-- Users Tab -->
                <UsersManagement v-if="activeTab === 'users'" />

                <!-- Revenue Analytics Tab -->
                <RevenueAnalytics v-if="activeTab === 'analytics'" />

                <!-- Audit Logs Tab -->
                <AuditLogs v-if="activeTab === 'logs'" />
            </div>
        </div>
    </div>
</template>

<script>
import { ref, onMounted } from 'vue';
import SubscriptionsManagement from './components/SubscriptionsManagement.vue';
import UsersManagement from './components/UsersManagement.vue';
import RevenueAnalytics from './components/RevenueAnalytics.vue';
import AuditLogs from './components/AuditLogs.vue';

export default {
    name: 'AdminDashboard',
    components: {
        SubscriptionsManagement,
        UsersManagement,
        RevenueAnalytics,
        AuditLogs,
    },
    setup() {
        const loading = ref(true);
        const overview = ref(null);
        const activeTab = ref('subscriptions');
        const tabs = [
            { id: 'subscriptions', label: 'Subscriptions' },
            { id: 'users', label: 'Users' },
            { id: 'analytics', label: 'Revenue Analytics' },
            { id: 'logs', label: 'Audit Logs' },
        ];

        const fetchOverview = async () => {
            try {
                const response = await fetch('/api/v1/admin/overview', {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    },
                });

                if (response.ok) {
                    const data = await response.json();
                    overview.value = data.data;
                } else {
                    console.error('Failed to fetch overview');
                }
            } catch (error) {
                console.error('Error fetching overview:', error);
            } finally {
                loading.value = false;
            }
        };

        const formatCurrency = (value) => {
            if (!value) return '0.00';
            return parseFloat(value).toFixed(2);
        };

        onMounted(() => {
            fetchOverview();
            // Refresh every 30 seconds
            setInterval(fetchOverview, 30000);
        });

        return {
            loading,
            overview,
            activeTab,
            tabs,
            formatCurrency,
        };
    },
};
</script>

<style scoped>
.admin-dashboard {
    padding: 2rem;
    background-color: #f9fafb;
    min-height: 100vh;
}

.page-header {
    margin-bottom: 2rem;
}

.page-title {
    font-size: 2rem;
    font-weight: bold;
    color: #111827;
    margin-bottom: 0.5rem;
}

.page-subtitle {
    color: #6b7280;
    font-size: 0.95rem;
}

.loading-container {
    text-align: center;
    padding: 4rem 2rem;
}

.spinner {
    border: 4px solid #e5e7eb;
    border-top: 4px solid #3b82f6;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 1rem;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.metric-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.metric-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.metric-header h3 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #6b7280;
    margin: 0;
}

.metric-icon {
    width: 24px;
    height: 24px;
    stroke: #9ca3af;
}

.metric-card.active .metric-icon {
    stroke: #10b981;
}

.metric-value {
    font-size: 2rem;
    font-weight: bold;
    color: #111827;
    margin-bottom: 0.5rem;
}

.metric-breakdown {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.breakdown-item {
    display: flex;
    align-items: center;
    font-size: 0.85rem;
    color: #6b7280;
    gap: 0.5rem;
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.dot.active {
    background-color: #10b981;
}

.dot.inactive {
    background-color: #ef4444;
}

.dot.trial {
    background-color: #f59e0b;
}

.dot.suspended {
    background-color: #8b5cf6;
}

.card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.card-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.card-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.card-body {
    padding: 1.5rem;
}

.plan-breakdown-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1.5rem;
}

.plan-item {
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background-color: #f9fafb;
}

.plan-name {
    font-weight: 600;
    color: #111827;
    margin-bottom: 0.75rem;
}

.plan-stats {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.stat {
    display: flex;
    justify-content: space-between;
    font-size: 0.9rem;
}

.stat .label {
    color: #6b7280;
}

.stat .value {
    font-weight: 600;
    color: #111827;
}

.mt-4 {
    margin-top: 1.5rem;
}

.mt-6 {
    margin-top: 2rem;
}

.admin-tabs {
    display: flex;
    gap: 1rem;
    border-bottom: 1px solid #e5e7eb;
    background: white;
    padding: 0 0 0 0;
    margin-bottom: 0;
}

.tab-button {
    padding: 1rem 1.5rem;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.95rem;
    font-weight: 500;
    color: #6b7280;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
}

.tab-button:hover {
    color: #3b82f6;
}

.tab-button.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}

.tab-content {
    background: white;
    border-radius: 0 0 8px 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
</style>