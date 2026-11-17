<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    startDate: {
        type: String,
        required: true,
    },
    endDate: {
        type: String,
        required: true,
    },
    dailyData: {
        type: Array,
        default: () => [],
    },
    userMetrics: {
        type: Array,
        default: () => [],
    },
    totals: {
        type: Object,
        required: true,
    },
    hasPixelforgeKey: {
        type: Boolean,
        default: false,
    },
});

const formStartDate = ref(props.startDate);
const formEndDate = ref(props.endDate);

const applyFilter = () => {
    router.get(route('admin.report'), {
        start_date: formStartDate.value,
        end_date: formEndDate.value,
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const setQuickRange = (days) => {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - days);

    formStartDate.value = start.toISOString().split('T')[0];
    formEndDate.value = end.toISOString().split('T')[0];
    applyFilter();
};

const formatExactMoney = (val) => {
    if (!val) return '$0.000000000';
    return '$' + parseFloat(val).toFixed(9);
};

const formatDisplayMoney = (val) => {
    if (!val) return '$0.00';
    return '$' + parseFloat(val).toFixed(4);
};

const profitMargin = (profit, spend) => {
    const s = parseFloat(spend || 0);
    const p = parseFloat(profit || 0);
    if (s <= 0) return '0.0%';
    return ((p / s) * 100).toFixed(1) + '%';
};
</script>

<template>
    <Head title="Admin Report & Reconciliation" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-3">
                        <span>Operator Financial Report</span>
                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                            Reconciliation Center
                        </span>
                    </h1>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Daily breakdown of user charges, Pixelforge provider costs, gross profit, and reconciliation with Pixelforge <code class="font-mono text-xs">GET /v1/usage</code>.
                    </p>
                </div>

                <!-- Date Range Filter -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-1 text-xs">
                        <button
                            type="button"
                            @click="setQuickRange(7)"
                            class="px-2.5 py-1 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition text-zinc-600 dark:text-zinc-400"
                        >
                            7D
                        </button>
                        <button
                            type="button"
                            @click="setQuickRange(14)"
                            class="px-2.5 py-1 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition text-zinc-600 dark:text-zinc-400"
                        >
                            14D
                        </button>
                        <button
                            type="button"
                            @click="setQuickRange(30)"
                            class="px-2.5 py-1 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition text-zinc-600 dark:text-zinc-400"
                        >
                            30D
                        </button>
                    </div>

                    <form @submit.prevent="applyFilter" class="flex items-center gap-2">
                        <input
                            type="date"
                            v-model="formStartDate"
                            class="rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-xs text-zinc-800 dark:text-zinc-200"
                        />
                        <span class="text-xs text-zinc-400">to</span>
                        <input
                            type="date"
                            v-model="formEndDate"
                            class="rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-1.5 text-xs text-zinc-800 dark:text-zinc-200"
                        />
                        <button
                            type="submit"
                            class="rounded-xl bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 px-3 py-1.5 text-xs font-semibold hover:opacity-90 transition"
                        >
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Notice if Pixelforge Key Missing -->
            <div
                v-if="!hasPixelforgeKey"
                class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 text-xs text-amber-800 dark:text-amber-300 flex items-center justify-between"
            >
                <div class="flex items-center gap-2">
                    <svg class="size-4 shrink-0 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <span>
                        <strong>No Pixelforge API Key set in .env:</strong> Reconciling live against <code class="font-mono">GET /v1/usage</code> requires setting <code class="font-mono">PIXELFORGE_API_KEY</code>.
                    </span>
                </div>
            </div>

            <!-- KPI Cards Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- User Spend (Gross Revenue) -->
                <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider block">
                        Credit Spend (User Charged)
                    </span>
                    <div class="mt-2 text-2xl font-bold font-mono text-zinc-900 dark:text-zinc-100">
                        {{ formatDisplayMoney(totals.spend) }}
                    </div>
                    <span class="text-[11px] font-mono text-zinc-400 block mt-1">
                        Exact: {{ formatExactMoney(totals.spend) }}
                    </span>
                </div>

                <!-- Provider Costs (Pixelforge) -->
                <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider block">
                        Provider Cost (Pixelforge)
                    </span>
                    <div class="mt-2 text-2xl font-bold font-mono text-zinc-900 dark:text-zinc-100">
                        {{ formatDisplayMoney(totals.provider_cost) }}
                    </div>
                    <span class="text-[11px] font-mono text-zinc-400 block mt-1">
                        API Reported: {{ formatExactMoney(totals.pf_reported_cost) }}
                    </span>
                </div>

                <!-- Gross Profit -->
                <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider block">
                        Gross Margin Profit
                    </span>
                    <div class="mt-2 text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400">
                        {{ formatDisplayMoney(totals.profit) }}
                    </div>
                    <span class="text-[11px] text-zinc-400 block mt-1">
                        Margin: <strong class="text-emerald-600 dark:text-emerald-400">{{ profitMargin(totals.profit, totals.spend) }}</strong> (25% markup)
                    </span>
                </div>

                <!-- Reconciliation Status -->
                <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs flex flex-col justify-between">
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider block">
                        Usage Reconciliation
                    </span>
                    <div class="mt-2 flex items-center gap-2">
                        <div v-if="totals.reconciled" class="size-3 rounded-full bg-emerald-500"></div>
                        <div v-else class="size-3 rounded-full bg-amber-500"></div>
                        <span class="text-base font-bold" :class="totals.reconciled ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                            {{ totals.reconciled ? '100% Reconciled' : 'Discrepancy Detected' }}
                        </span>
                    </div>
                    <span class="text-[11px] text-zinc-400 block mt-1">
                        Matched against Pixelforge /v1/usage
                    </span>
                </div>
            </div>

            <!-- Daily Reconciliation Table -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs space-y-4 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-zinc-900 dark:text-zinc-100">Daily Financial & Usage Reconciliation</h2>
                        <p class="text-xs text-zinc-400">Comparison of local database recorded costs vs Pixelforge GET /v1/usage report</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-100 dark:border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                            <tr>
                                <th class="py-3 px-3">Date (UTC)</th>
                                <th class="py-3 px-3">Generations (DB / PF)</th>
                                <th class="py-3 px-3">Succeeded / Failed</th>
                                <th class="py-3 px-3">DB Provider Cost</th>
                                <th class="py-3 px-3">Pixelforge /v1/usage</th>
                                <th class="py-3 px-3">Discrepancy</th>
                                <th class="py-3 px-3">User Spend</th>
                                <th class="py-3 px-3">Gross Profit</th>
                                <th class="py-3 px-3 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60 font-mono">
                            <tr
                                v-for="row in dailyData"
                                :key="row.date"
                                class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition"
                            >
                                <td class="py-3 px-3 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ row.date }}
                                </td>
                                <td class="py-3 px-3 text-zinc-600 dark:text-zinc-300">
                                    {{ row.total_generations }} / {{ row.pf_reported_generations }}
                                </td>
                                <td class="py-3 px-3 text-zinc-500">
                                    <span class="text-emerald-600 dark:text-emerald-400">{{ row.succeeded_generations }}</span> /
                                    <span class="text-rose-600 dark:text-rose-400">{{ row.failed_generations }}</span>
                                </td>
                                <td class="py-3 px-3 text-zinc-700 dark:text-zinc-300">
                                    {{ formatExactMoney(row.provider_cost) }}
                                </td>
                                <td class="py-3 px-3 text-zinc-700 dark:text-zinc-300">
                                    {{ formatExactMoney(row.pf_reported_cost) }}
                                </td>
                                <td class="py-3 px-3 font-semibold" :class="row.reconciled ? 'text-zinc-400' : 'text-rose-500'">
                                    {{ formatExactMoney(row.discrepancy) }}
                                </td>
                                <td class="py-3 px-3 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ formatExactMoney(row.user_spend) }}
                                </td>
                                <td class="py-3 px-3 font-semibold text-emerald-600 dark:text-emerald-400">
                                    {{ formatExactMoney(row.profit) }}
                                </td>
                                <td class="py-3 px-3 text-right font-sans">
                                    <span
                                        v-if="row.reconciled"
                                        class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400"
                                    >
                                        ✓ Reconciled
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-full bg-rose-500/10 px-2 py-0.5 text-[11px] font-semibold text-rose-600 dark:text-rose-400"
                                    >
                                        Mismatch
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Per-User Breakdown Table -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs space-y-4 p-6">
                <div>
                    <h2 class="text-base font-bold text-zinc-900 dark:text-zinc-100">Per-User Revenue & Consumption Breakdown</h2>
                    <p class="text-xs text-zinc-400">Individual user activity, credit balance, generation count, and profit contribution</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-100 dark:border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                            <tr>
                                <th class="py-3 px-3">User</th>
                                <th class="py-3 px-3">Balance</th>
                                <th class="py-3 px-3">Generations</th>
                                <th class="py-3 px-3">Success / Fail</th>
                                <th class="py-3 px-3">Credit Spend</th>
                                <th class="py-3 px-3">Pixelforge Cost</th>
                                <th class="py-3 px-3 text-right">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60 font-mono">
                            <tr
                                v-for="user in userMetrics"
                                :key="user.id"
                                class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition"
                            >
                                <td class="py-3 px-3 font-sans">
                                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ user.name }}</div>
                                    <div class="text-[11px] text-zinc-400">{{ user.email }}</div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ formatDisplayMoney(user.balance) }}
                                </td>
                                <td class="py-3 px-3 text-zinc-600 dark:text-zinc-300">
                                    {{ user.total_generations }}
                                </td>
                                <td class="py-3 px-3 text-zinc-500">
                                    <span class="text-emerald-600 dark:text-emerald-400">{{ user.succeeded_generations }}</span> /
                                    <span class="text-rose-600 dark:text-rose-400">{{ user.failed_generations }}</span>
                                </td>
                                <td class="py-3 px-3 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ formatExactMoney(user.credit_spend) }}
                                </td>
                                <td class="py-3 px-3 text-zinc-600 dark:text-zinc-300">
                                    {{ formatExactMoney(user.provider_cost) }}
                                </td>
                                <td class="py-3 px-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">
                                    {{ formatExactMoney(user.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
