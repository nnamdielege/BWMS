<template>
    <div class="revenue-analytics">
        <!-- Filter -->
        <div class="filters-section">
            <div class="filter-group">
                <label>Time Period</label>
                <select v-model="selectedDays" @change="fetchAnalytics" class="select-input">
                    <option value="7">Last 7 Days</option>
                    <option value="14">Last 14 Days</option>
                    <option value="30">Last 30 Days</option>
                    <option value="60">Last 60 Days</option>
                    <option value="90">Last 90 Days</option>
                </select>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="loading">
            <div class="spinner"></div>
        </div>

        <!-- Analytics Content -->
        <div v-else class="analytics-content">
            <!-- Daily Revenue Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3>Daily Revenue Trend</h3>
                    <div class="chart-stats">
                        <div class="stat">
                            <span class="label">Total Revenue</span>
                            <span class="value">${{ formatCurrency(totalDailyRevenue) }}</span>
                        </div>
                        <div class="stat">
                            <span class="label">Avg Daily</span>
                            <span class="value">${{ formatCurrency(avgDailyRevenue) }}</span>
                        </div>
                        <div class="stat">
                            <span class="label">Subscriptions Added</span>
                            <span class="value">{{ totalSubscriptionsAdded }}</span>
                        </div>
                    </div>
                </div>
                <div class="chart-canvas">
                    <svg v-if="dailyRevenueData.length > 0" class="revenue-chart" viewBox="0 0 800 300">
                        <!-- Grid lines -->
                        <g class="grid">
                            <line v-for="i in 5" :key="`h-${i}`" :x1="0" :y1="(i * 60)" :x2="800" :y2="(i * 60)" stroke="#f3f4f6" />
                        </g>

                        <!-- Y-axis labels -->
                        <g class="axis-labels">
                            <text x="30" y="295" font-size="12" fill="#9ca3af">$0</text>
                            <text x="30" y="245" font-size="12" fill="#9ca3af">${{ (maxRevenue / 4).toFixed(0) }}</text>
                            <text x="30" y="195" font-size="12" fill="#9ca3af">${{ (maxRevenue / 2).toFixed(0) }}</text>
                            <text x="30" y="145" font-size="12" fill="#9ca3af">${{ (maxRevenue * 3 / 4).toFixed(0) }}</text>
                            <text x="30" y="95" font-size="12" fill="#9ca3af">${{ maxRevenue.toFixed(0) }}</text>
                        </g>

                        <!-- Bars -->
                        <g class="bars">
                            <rect
                                v-for="(item, index) in dailyRevenueData"
                                :key="`bar-${index}`"
                                :x="50 + (index * (700 / dailyRevenueData.length))"
                                :y="300 - (item.revenue / maxRevenue * 250)"
                                :width="(700 / dailyRevenueData.length) * 0.8"
                                height="250"
                                :transform="`scale(${(item.revenue / maxRevenue)}, 1)`"
                                fill="#3b82f6"
                                class="bar"
                                @mouseenter="hoveredBar = index"
                                @mouseleave="hoveredBar = null"
                            />
                        </g>

                        <!-- X-axis labels -->
                        <g class="x-axis-labels">
                            <text
                                v-for="(item, index) in dailyRevenueData"
                                :key="`label-${index}`"
                                :x="50 + (index * (700 / dailyRevenueData.length)) + ((700 / dailyRevenueData.length) * 0.4)"
                                y="320"
                                font-size="11"
                                fill="#9ca3af"
                                text-anchor="middle"
                            >
                                {{ formatDateShort(item.date) }}
                            </text>
                        </g>

                        <!-- Hover tooltip -->
                        <g v-if="hoveredBar !== null" class="tooltip">
                            <rect
                                :x="50 + (hoveredBar * (700 / dailyRevenueData.length))"
                                :y="300 - (dailyRevenueData[hoveredBar].revenue / maxRevenue * 250) - 50"
                                width="120"
                                height="45"
                                fill="#1f2937"
                                rx="4"
                            />
                            <text
                                :x="50 + (hoveredBar * (700 / dailyRevenueData.length)) + 60"
                                :y="300 - (dailyRevenueData[hoveredBar].revenue / maxRevenue * 250) - 25"
                                fill="white"
                                font-size="14"
                                font-weight="bold"
                                text-anchor="middle"
                            >
                                ${{ formatCurrency(dailyRevenueData[hoveredBar].revenue) }}
                            </text>
                            <text
                                :x="50 + (hoveredBar * (700 / dailyRevenueData.length)) + 60"
                                :y="300 - (dailyRevenueData[hoveredBar].revenue / maxRevenue * 250) - 5"
                                fill="white"
                                font-size="12"
                                text-anchor="middle"
                            >
                                {{ dailyRevenueData[hoveredBar].count }} new
                            </text>
                        </g>
                    </svg>
                    <div v-else class="no-data">
                        No revenue data available for this period
                    </div>
                </div>
            </div>

            <!-- Plan Comparison -->
            <div class="plans-grid">
                <div class="plan-card" v-for="plan in planComparison" :key="plan.plan">
                    <div class="plan-header">
                        <h4>{{ plan.plan }}</h4>
                    </div>
                    <div class="plan-body">
                        <div class="plan-stat">
                            <span class="label">Active Subscriptions</span>
                            <span class="value">{{ plan.count }}</span>
                        </div>
                        <div class="plan-stat">
                            <span class="label">Total Revenue</span>
                            <span class="value">${{ formatCurrency(plan.revenue) }}</span>
                        </div>
                        <div class="plan-stat">
                            <span class="label">Avg per Subscription</span>
                            <span class="value">${{ formatCurrency(plan.revenue / plan.count) }}</span>
                        </div>
                    </div>
                    <div class="plan-footer">
                        <div class="percentage">
                            {{ ((plan.revenue / totalPlanRevenue) * 100).toFixed(1) }}%
                        </div>
                        of total revenue
                    </div>
                </div>
            </div>

            <!-- Detailed Table -->
            <div class="detailed-table">
                <div class="table-header">
                    <h3>Daily Breakdown</h3>
                </div>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>New Subscriptions</th>
                            <th>Revenue</th>
                            <th>Avg per Sub</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in dailyRevenueData" :key="item.date">
                            <td>{{ formatDate(item.date) }}</td>
                            <td>{{ item.count }}</td>
                            <td>${{ formatCurrency(item.revenue) }}</td>
                            <td>${{ formatCurrency(item.revenue / item.count) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
import { ref, onMounted, computed } from 'vue';

export default {
    name: 'RevenueAnalytics',
    setup() {
        const loading = ref(true);
        const selectedDays = ref('30');
        const dailyRevenueData = ref([]);
        const planComparison = ref([]);
        const hoveredBar = ref(null);

        const token = localStorage.getItem('token');

        const fetchAnalytics = async () => {
            loading.value = true;
            try {
                const response = await fetch(
                    `/api/v1/admin/analytics/revenue?days=${selectedDays.value}`,
                    {
                        headers: { 'Authorization': `Bearer ${token}` },
                    }
                );

                if (response.ok) {
                    const data = await response.json();
                    dailyRevenueData.value = data.data.daily_revenue;
                    planComparison.value = data.data.plan_comparison;
                }
            } catch (error) {
                console.error('Error fetching analytics:', error);
            } finally {
                loading.value = false;
            }
        };

        const totalDailyRevenue = computed(() => {
            return dailyRevenueData.value.reduce((sum, item) => sum + item.revenue, 0);
        });

        const totalSubscriptionsAdded = computed(() => {
            return dailyRevenueData.value.reduce((sum, item) => sum + item.count, 0);
        });

        const avgDailyRevenue = computed(() => {
            if (dailyRevenueData.value.length === 0) return 0;
            return totalDailyRevenue.value / dailyRevenueData.value.length;
        });

        const maxRevenue = computed(() => {
            if (dailyRevenueData.value.length === 0) return 100;
            return Math.max(...dailyRevenueData.value.map(item => item.revenue)) * 1.1;
        });

        const totalPlanRevenue = computed(() => {
            return planComparison.value.reduce((sum, plan) => sum + plan.revenue, 0);
        });

        const formatCurrency = (value) => {
            return parseFloat(value).toFixed(2);
        };

        const formatDate = (date) => {
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            });
        };

        const formatDateShort = (date) => {
            return new Date(date).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
            });
        };

        onMounted(() => {
            fetchAnalytics();
        });

        return {
            loading,
            selectedDays,
            dailyRevenueData,
            planComparison,
            hoveredBar,
            totalDailyRevenue,
            totalSubscriptionsAdded,
            avgDailyRevenue,
            maxRevenue,
            totalPlanRevenue,
            fetchAnalytics,
            formatCurrency,
            formatDate,
            formatDateShort,
        };
    },
};
</script>

<style scoped>
.revenue-analytics {
    width: 100%;
}

.filters-section {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
}

.select-input {
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

.analytics-content {
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

.chart-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.chart-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.chart-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #111827;
}

.chart-stats {
    display: flex;
    gap: 2rem;
    flex-wrap: wrap;
}

.stat {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

.stat .label {
    font-size: 0.8rem;
    color: #6b7280;
    margin-bottom: 0.25rem;
}

.stat .value {
    font-size: 1.1rem;
    font-weight: 600;
    color: #111827;
}

.chart-canvas {
    padding: 1.5rem;
}

.revenue-chart {
    width: 100%;
    height: auto;
    min-height: 350px;
}

.grid line {
    stroke-width: 1;
}

.axis-labels text {
    dominant-baseline: middle;
}

.bars rect {
    transition: fill 0.2s;
    cursor: pointer;
}

.bars rect:hover {
    fill: #2563eb;
}

.x-axis-labels text {
    dominant-baseline: hanging;
}

.tooltip rect {
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

.no-data {
    text-align: center;
    padding: 2rem;
    color: #9ca3af;
}

.plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.plan-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.plan-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.plan-header {
    padding: 1rem;
    background-color: #f3f4f6;
    border-bottom: 2px solid #e5e7eb;
}

.plan-header h4 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
}

.plan-body {
    padding: 1.5rem;
}

.plan-stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
}

.plan-stat .label {
    font-size: 0.9rem;
    color: #6b7280;
}

.plan-stat .value {
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
}

.plan-footer {
    padding: 1rem;
    background-color: #f9fafb;
    text-align: center;
    border-top: 1px solid #e5e7eb;
}

.percentage {
    font-size: 1.25rem;
    font-weight: 600;
    color: #3b82f6;
}

.plan-footer {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 0.5rem;
}

.detailed-table {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.table-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.table-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #111827;
}

.breakdown-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.breakdown-table thead {
    background-color: #f3f4f6;
}

.breakdown-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.breakdown-table td {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.breakdown-table tbody tr:hover {
    background-color: #f9fafb;
}
</style>