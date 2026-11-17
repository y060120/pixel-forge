<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BuyCreditsModal from '@/Components/BuyCreditsModal.vue';
import axios from 'axios';

const props = defineProps({
    generations: {
        type: Object,
        required: true,
    },
    activeCount: {
        type: Number,
        default: 0,
    },
    pricingConfig: {
        type: Object,
        default: () => ({}),
    },
    markupPercent: {
        type: Number,
        default: 25,
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const showBuyModal = ref(false);

// Generation Form
const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const refImageInputs = ref(['']);
const isEstimating = ref(false);
const estimateData = ref(null);

// Directives pills for test testing
const testDirectives = [
    { tag: '[succeed]', label: 'Succeed', desc: 'Guarantees successful generation without random failure' },
    { tag: '[fail]', label: 'Fail Test', desc: 'Accepted then fails during processing (billed $0.00)' },
    { tag: '[slow]', label: 'Slow (60-90s)', desc: 'Processes for 60 to 90 seconds' },
    { tag: '[duplicate]', label: 'Duplicate Webhook', desc: 'Every webhook event is delivered twice to test deduplication' },
    { tag: '[blocked]', label: 'Blocked (400)', desc: 'Rejects immediately at submission with 400 content policy' },
    { tag: '[overloaded]', label: 'Overload (503)', desc: 'Rejects at submission with 503 overloaded' },
];

const insertDirective = (tag) => {
    if (form.prompt.includes(tag)) {
        form.prompt = form.prompt.replace(tag, '').replace(/\s+/g, ' ').trim();
    } else {
        form.prompt = form.prompt ? `${form.prompt} ${tag}` : tag;
    }
    fetchEstimate();
};

const hasDirective = (tag) => form.prompt.includes(tag);

// Reference images input management
const addReferenceInput = () => {
    if (refImageInputs.value.length < 4) {
        refImageInputs.value.push('');
    }
};

const removeReferenceInput = (index) => {
    refImageInputs.value.splice(index, 1);
    syncReferenceImages();
};

const syncReferenceImages = () => {
    form.reference_images = refImageInputs.value.filter(url => url && url.trim().length > 0);
    fetchEstimate();
};

// Real-time Upfront Cost Estimation
let estimateTimeout = null;
const fetchEstimate = () => {
    clearTimeout(estimateTimeout);
    estimateTimeout = setTimeout(async () => {
        isEstimating.value = true;
        try {
            const activeRefs = refImageInputs.value.filter(url => url && url.trim().length > 0);
            const response = await axios.get(route('generations.estimate'), {
                params: {
                    prompt: form.prompt || 'Sample prompt preview',
                    size: form.size,
                    quality: form.quality,
                    reference_images: activeRefs,
                },
            });
            estimateData.value = response.data;
        } catch (e) {
            console.error('Estimation error:', e);
        } finally {
            isEstimating.value = false;
        }
    }, 150);
};

// Initial estimate on load
onMounted(() => {
    fetchEstimate();
    startPolling();
});

watch([() => form.prompt, () => form.size, () => form.quality], () => {
    fetchEstimate();
});

// Affordability check
const maxPotentialCost = computed(() => {
    if (!estimateData.value) return 0;
    return parseFloat(estimateData.value.user_cost?.max || 0);
});

const currentBalance = computed(() => {
    return parseFloat(user.value?.balance || 0);
});

const canAfford = computed(() => {
    return currentBalance.value >= maxPotentialCost.value;
});

const submitGeneration = () => {
    syncReferenceImages();
    if (!canAfford.value) {
        showBuyModal.value = true;
        return;
    }

    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.prompt = '';
            refImageInputs.value = [''];
            form.reference_images = [];
            fetchEstimate();
            startPolling();
        },
    });
};

// Generations List & Auto-Polling
const localGenerations = ref([...props.generations.data]);
watch(() => props.generations.data, (newData) => {
    localGenerations.value = [...newData];
});

let pollingTimer = null;
const isPolling = ref(false);

const hasActiveGenerations = computed(() => {
    return localGenerations.value.some(g => g.status === 'queued' || g.status === 'processing');
});

const pollUpdates = async () => {
    if (isPolling.value) return;
    isPolling.value = true;

    try {
        const res = await axios.get(route('generations.poll'));
        const updatedList = res.data.generations || [];

        // Update existing generations in local state
        updatedList.forEach(updated => {
            const index = localGenerations.value.findIndex(g => g.id === updated.id);
            if (index !== -1) {
                localGenerations.value[index] = {
                    ...localGenerations.value[index],
                    ...updated,
                };
            } else {
                // Prepend newly created generation if missing
                localGenerations.value.unshift(updated);
            }
        });

        // Update user balance if changed
        if (res.data.user_balance !== undefined && page.props.auth.user) {
            page.props.auth.user.balance = res.data.user_balance;
        }

        // If no active generations left, stop fast polling
        if (!hasActiveGenerations.value && pollingTimer) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    } catch (e) {
        console.error('Polling error:', e);
    } finally {
        isPolling.value = false;
    }
};

const startPolling = () => {
    if (pollingTimer) return;
    if (hasActiveGenerations.value || props.activeCount > 0) {
        pollingTimer = setInterval(pollUpdates, 2500);
    }
};

onUnmounted(() => {
    if (pollingTimer) clearInterval(pollingTimer);
});

// Format helpers
const formatMoney = (val, decimals = 4) => {
    if (val === null || val === undefined) return '$0.0000';
    return '$' + parseFloat(val).toFixed(decimals);
};

const formatExactMoney = (val) => {
    if (!val) return '$0.000000000';
    return '$' + parseFloat(val).toFixed(9);
};
</script>

<template>
    <Head title="Image Generation Studio" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-10">
            <!-- Studio Header -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-3">
                        <span>Image Studio</span>
                        <span class="inline-flex items-center rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-xs font-medium text-indigo-600 dark:text-indigo-400">
                            Pixelforge Powered (+25% markup)
                        </span>
                    </h1>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Create AI-generated images with exact token billing, live pricing estimation, and real-time status.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        @click="showBuyModal = true"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition"
                    >
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Buy Credits (${{ formatMoney(user?.balance, 2) }})
                    </button>
                </div>
            </div>

            <!-- Main Studio Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                <!-- Left Column: Generator Form & Live Cost (7 cols) -->
                <div class="lg:col-span-7 space-y-6">
                    <form @submit.prevent="submitGeneration" class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm space-y-6">
                        <!-- Prompt Input -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                    Prompt <span class="text-rose-500">*</span>
                                </label>
                                <span class="text-xs text-zinc-400">
                                    {{ form.prompt.length }} / 4,000 characters
                                </span>
                            </div>

                            <textarea
                                v-model="form.prompt"
                                rows="3"
                                maxlength="4000"
                                required
                                placeholder="Describe what you want to see... e.g., A futuristic cyberpunk street in neon rain, cinematic lighting, 8k"
                                class="w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 p-3.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-y"
                            ></textarea>

                            <!-- Testing Directives Pills -->
                            <div class="pt-1">
                                <div class="text-[11px] font-medium text-zinc-400 mb-1.5 flex items-center gap-1.5">
                                    <span>Pixelforge Testing Directives:</span>
                                    <span class="text-zinc-500 text-[10px]">(click to toggle)</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="d in testDirectives"
                                        :key="d.tag"
                                        type="button"
                                        @click="insertDirective(d.tag)"
                                        :title="d.desc"
                                        :class="[
                                            hasDirective(d.tag)
                                                ? 'bg-indigo-600 text-white border-indigo-600 font-semibold shadow-xs'
                                                : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border-zinc-200 dark:border-zinc-700 hover:border-zinc-400'
                                        ]"
                                        class="rounded-lg border px-2 py-1 text-xs font-mono transition"
                                    >
                                        {{ d.tag }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Size & Aspect Ratio Selection -->
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                Size & Aspect Ratio
                            </label>
                            <div class="grid grid-cols-3 gap-3">
                                <label
                                    v-for="opt in [
                                        { id: '1024x1024', name: 'Square', ratio: '1:1', icon: 'size-5 border-2 rounded' },
                                        { id: '1536x1024', name: 'Landscape', ratio: '3:2', icon: 'w-6 h-4 border-2 rounded' },
                                        { id: '1024x1536', name: 'Portrait', ratio: '2:3', icon: 'w-4 h-6 border-2 rounded' }
                                    ]"
                                    :key="opt.id"
                                    :class="[
                                        form.size === opt.id
                                            ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/20 text-indigo-900 dark:text-indigo-200 ring-2 ring-indigo-500/20'
                                            : 'border-zinc-200 dark:border-zinc-700/80 bg-zinc-50/30 dark:bg-zinc-800/30 hover:border-zinc-300 dark:hover:border-zinc-600'
                                    ]"
                                    class="relative flex flex-col items-center justify-center p-3 rounded-xl border cursor-pointer transition text-center"
                                >
                                    <input type="radio" v-model="form.size" :value="opt.id" class="sr-only" />
                                    <div :class="opt.icon" class="border-current mb-2 opacity-80"></div>
                                    <span class="text-xs font-semibold">{{ opt.name }}</span>
                                    <span class="text-[11px] text-zinc-400 font-mono">{{ opt.id }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Quality Selection -->
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                Quality Tier
                            </label>
                            <div class="grid grid-cols-3 gap-3">
                                <label
                                    v-for="q in [
                                        { id: 'low', name: 'Low (Draft)', desc: 'Fastest, ~280-420 tokens' },
                                        { id: 'medium', name: 'Medium', desc: 'Balanced, standard output' },
                                        { id: 'high', name: 'High (Detail)', desc: 'Maximum reasoning & fidelity' }
                                    ]"
                                    :key="q.id"
                                    :class="[
                                        form.quality === q.id
                                            ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/20 text-indigo-900 dark:text-indigo-200 ring-2 ring-indigo-500/20'
                                            : 'border-zinc-200 dark:border-zinc-700/80 bg-zinc-50/30 dark:bg-zinc-800/30 hover:border-zinc-300 dark:hover:border-zinc-600'
                                    ]"
                                    class="relative flex flex-col p-3 rounded-xl border cursor-pointer transition"
                                >
                                    <input type="radio" v-model="form.quality" :value="q.id" class="sr-only" />
                                    <span class="text-xs font-semibold capitalize">{{ q.name }}</span>
                                    <span class="text-[10px] text-zinc-400 mt-0.5">{{ q.desc }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Reference Images (Optional, max 4) -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 flex items-center gap-1.5">
                                    <span>Reference Images (Optional)</span>
                                    <span class="text-xs font-normal text-zinc-400">(max 4, +765 tokens each)</span>
                                </label>
                                <button
                                    v-if="refImageInputs.length < 4"
                                    type="button"
                                    @click="addReferenceInput"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                                >
                                    + Add URL
                                </button>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="(url, idx) in refImageInputs"
                                    :key="idx"
                                    class="flex items-center gap-2"
                                >
                                    <input
                                        v-model="refImageInputs[idx]"
                                        @input="syncReferenceImages"
                                        type="url"
                                        placeholder="https://example.com/reference-style.jpg"
                                        class="flex-1 rounded-xl border border-zinc-300 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 px-3 py-2 text-xs text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                    <button
                                        v-if="refImageInputs.length > 1 || url"
                                        type="button"
                                        @click="removeReferenceInput(idx)"
                                        class="rounded-lg p-1.5 text-zinc-400 hover:text-rose-500 hover:bg-rose-500/10 transition"
                                    >
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Upfront Live Price Preview Card -->
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 flex items-center gap-1.5">
                                    <svg class="size-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                    Live Cost Estimate
                                </span>
                                <span v-if="isEstimating" class="text-[11px] text-zinc-400 animate-pulse">Calculating...</span>
                            </div>

                            <div v-if="estimateData" class="space-y-2 text-xs">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 border-t border-zinc-200 dark:border-zinc-700/50 text-[11px]">
                                    <div>
                                        <span class="text-zinc-400 block">Text tokens:</span>
                                        <span class="font-mono text-zinc-700 dark:text-zinc-300">~{{ estimateData.tokens.text.base }}</span>
                                    </div>
                                    <div>
                                        <span class="text-zinc-400 block">Image tokens:</span>
                                        <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ estimateData.tokens.image }}</span>
                                    </div>
                                    <div>
                                        <span class="text-zinc-400 block">Output tokens:</span>
                                        <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ estimateData.tokens.output }}</span>
                                    </div>
                                    <div>
                                        <span class="text-zinc-400 block">Reasoning:</span>
                                        <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ estimateData.tokens.reasoning.min }}-{{ estimateData.tokens.reasoning.max }}</span>
                                    </div>
                                </div>

                                <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700/50 flex items-center justify-between">
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400 block text-[11px]">Provider cost:</span>
                                        <span class="font-mono text-zinc-600 dark:text-zinc-300 text-xs">
                                            {{ formatMoney(estimateData.provider_cost.expected, 4) }}
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-zinc-500 dark:text-zinc-400 block text-[11px]">Platform markup:</span>
                                        <span class="font-mono text-indigo-600 dark:text-indigo-400 text-xs font-medium">
                                            +{{ markupPercent }}%
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-zinc-500 dark:text-zinc-400 block text-[11px] font-semibold">Your Price Estimate:</span>
                                        <span class="font-mono text-base font-bold text-zinc-900 dark:text-white">
                                            {{ formatMoney(estimateData.user_cost.expected, 4) }}
                                        </span>
                                        <span class="text-[10px] text-zinc-400 block font-mono">
                                            (range: {{ formatMoney(estimateData.user_cost.min, 4) }} - {{ formatMoney(estimateData.user_cost.max, 4) }})
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Affordability Banner -->
                            <div
                                :class="[
                                    canAfford
                                        ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/20'
                                        : 'bg-rose-500/10 text-rose-800 dark:text-rose-300 border-rose-500/20'
                                ]"
                                class="rounded-lg border p-2.5 flex items-center justify-between text-xs"
                            >
                                <div class="flex items-center gap-2">
                                    <svg v-if="canAfford" class="size-4 text-emerald-500 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                    <svg v-else class="size-4 text-rose-500 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                    <span v-if="canAfford">
                                        Sufficient balance: ${{ formatMoney(user?.balance, 4) }} available
                                    </span>
                                    <span v-else>
                                        Insufficient credits! Need up to ${{ formatMoney(maxPotentialCost, 4) }}
                                    </span>
                                </div>

                                <button
                                    v-if="!canAfford"
                                    type="button"
                                    @click="showBuyModal = true"
                                    class="rounded-md bg-rose-600 text-white font-semibold px-2 py-1 text-[11px] hover:bg-rose-500 transition"
                                >
                                    Top Up Now
                                </button>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div>
                            <button
                                type="submit"
                                :disabled="form.processing || !form.prompt.trim() || !canAfford"
                                class="w-full rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white py-3.5 px-4 font-semibold text-sm shadow-md shadow-indigo-600/20 transition flex items-center justify-center gap-2"
                            >
                                <svg v-if="form.processing" class="size-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Generate Image</span>
                                <span v-if="estimateData" class="opacity-80 font-normal">
                                    (~{{ formatMoney(estimateData.user_cost.expected, 4) }})
                                </span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right Column: Generation History & Live Status (5 cols) -->
                <div class="lg:col-span-5 space-y-4">
                    <div class="flex items-center justify-between pb-2">
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-bold text-zinc-900 dark:text-zinc-100">Generation History</h2>
                            <span v-if="hasActiveGenerations" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
                                <span class="size-1.5 rounded-full bg-amber-500 animate-ping"></span>
                                Live polling
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="pollUpdates"
                            :disabled="isPolling"
                            class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 flex items-center gap-1"
                        >
                            <svg :class="{ 'animate-spin': isPolling }" class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Refresh
                        </button>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="localGenerations.length === 0"
                        class="rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-800 p-8 text-center space-y-3"
                    >
                        <div class="size-12 rounded-2xl bg-zinc-100 dark:bg-zinc-800 mx-auto flex items-center justify-center text-zinc-400">
                            <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">No generations yet</div>
                        <p class="text-xs text-zinc-400 max-w-xs mx-auto">
                            Submit a prompt using the studio on the left. Pixelforge will process it asynchronously and push updates via webhooks.
                        </p>
                    </div>

                    <!-- Generation Cards List -->
                    <div v-else class="space-y-4 max-h-[850px] overflow-y-auto pr-1">
                        <div
                            v-for="gen in localGenerations"
                            :key="gen.id"
                            class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs hover:border-zinc-300 dark:hover:border-zinc-700 transition"
                        >
                            <!-- Card Header: Status Badge & Costs -->
                            <div class="p-4 border-b border-zinc-100 dark:border-zinc-800/80 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <!-- Status Badges -->
                                    <span
                                        v-if="gen.status === 'succeeded'"
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                                    >
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                        Succeeded
                                    </span>
                                    <span
                                        v-else-if="gen.status === 'processing'"
                                        class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2.5 py-0.5 text-xs font-semibold text-sky-700 dark:text-sky-400"
                                    >
                                        <svg class="size-3 animate-spin text-sky-500" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Processing...
                                    </span>
                                    <span
                                        v-else-if="gen.status === 'queued'"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400"
                                    >
                                        <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                        Queued
                                    </span>
                                    <span
                                        v-else-if="gen.status === 'failed'"
                                        class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2.5 py-0.5 text-xs font-semibold text-rose-700 dark:text-rose-400"
                                    >
                                        <span class="size-1.5 rounded-full bg-rose-500"></span>
                                        Failed (Free)
                                    </span>

                                    <span class="text-[11px] text-zinc-400 font-mono">{{ gen.size }}</span>
                                    <span class="text-[11px] text-zinc-400 font-mono capitalize">{{ gen.quality }}</span>
                                </div>

                                <!-- Cost display -->
                                <div class="text-right">
                                    <div v-if="gen.status === 'succeeded'" class="font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                        Charged: {{ formatMoney(gen.user_cost, 4) }}
                                    </div>
                                    <div v-else-if="gen.status === 'failed'" class="font-mono text-xs font-semibold text-zinc-400 line-through">
                                        Charged: $0.00
                                    </div>
                                    <div v-else class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                        Est: {{ formatMoney(gen.estimated_cost, 4) }}
                                    </div>
                                </div>
                            </div>

                            <!-- Card Body: Prompt & Image -->
                            <div class="p-4 space-y-3">
                                <p class="text-xs text-zinc-800 dark:text-zinc-200 line-clamp-3 leading-relaxed">
                                    {{ gen.prompt }}
                                </p>

                                <!-- Image Viewer (if succeeded) -->
                                <div v-if="gen.status === 'succeeded' && gen.image_url" class="relative rounded-xl overflow-hidden bg-zinc-950 border border-zinc-800 group">
                                    <img
                                        :src="gen.image_url"
                                        :alt="gen.prompt"
                                        loading="lazy"
                                        class="w-full object-cover transition duration-300 group-hover:scale-102"
                                        style="max-height: 260px;"
                                    />
                                    <a
                                        :href="gen.image_url"
                                        target="_blank"
                                        class="absolute bottom-2 right-2 rounded-lg bg-black/75 backdrop-blur px-2.5 py-1 text-[11px] text-white opacity-0 group-hover:opacity-100 transition flex items-center gap-1"
                                    >
                                        <span>View full</span>
                                        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                    </a>
                                </div>

                                <!-- Processing Placeholder -->
                                <div
                                    v-else-if="gen.status === 'processing' || gen.status === 'queued'"
                                    class="rounded-xl border border-dashed border-zinc-200 dark:border-zinc-800 p-6 text-center bg-zinc-50/50 dark:bg-zinc-800/30 flex flex-col items-center justify-center space-y-2"
                                >
                                    <svg class="size-6 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span class="text-xs text-zinc-500">Pixelforge worker is drawing your placeholder...</span>
                                </div>

                                <!-- Failure Error Box -->
                                <div
                                    v-else-if="gen.status === 'failed'"
                                    class="rounded-xl border border-rose-500/20 bg-rose-500/10 p-3 text-xs text-rose-700 dark:text-rose-300 space-y-1"
                                >
                                    <div class="font-semibold flex items-center gap-1.5">
                                        <span>Error:</span>
                                        <span class="font-mono text-[11px]">{{ gen.error_code || 'generation_failed' }}</span>
                                    </div>
                                    <p class="text-[11px] opacity-90">{{ gen.error_message || 'The model could not produce an image.' }}</p>
                                    <p class="text-[10px] text-zinc-500 pt-1">You were not charged for this failed generation.</p>
                                </div>

                                <!-- Usage & Pricing Breakdown Details (if succeeded) -->
                                <div v-if="gen.status === 'succeeded' && gen.usage" class="pt-2 border-t border-zinc-100 dark:border-zinc-800 text-[11px] space-y-1">
                                    <div class="flex items-center justify-between text-zinc-400">
                                        <span>Tokens used:</span>
                                        <span class="font-mono text-zinc-600 dark:text-zinc-300">
                                            {{ gen.usage.total_tokens || 0 }} tokens ({{ gen.usage.input_text_tokens }} in, {{ gen.usage.output_tokens }} out, {{ gen.usage.reasoning_tokens }} reasoning)
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between text-zinc-400">
                                        <span>Provider Cost (Pixelforge):</span>
                                        <span class="font-mono text-zinc-600 dark:text-zinc-300">{{ formatExactMoney(gen.pf_cost) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-zinc-400">
                                        <span>User Cost (+25% markup):</span>
                                        <span class="font-mono font-semibold text-emerald-600 dark:text-emerald-400">{{ formatExactMoney(gen.user_cost) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Footer: Timestamps & IDs -->
                            <div class="px-4 py-2.5 bg-zinc-50/50 dark:bg-zinc-800/40 border-t border-zinc-100 dark:border-zinc-800/80 flex items-center justify-between text-[10px] text-zinc-400 font-mono">
                                <span>{{ gen.pf_generation_id || 'pending...' }}</span>
                                <span>{{ gen.created_at }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <BuyCreditsModal :show="showBuyModal" @close="showBuyModal = false" />
    </AuthenticatedLayout>
</template>
