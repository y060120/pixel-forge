<script setup>
import { ref, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const page = usePage();
const user = computed(() => page.props.auth.user);

const presetAmounts = [5, 10, 25, 50, 100];
const selectedAmount = ref(10);
const customAmount = ref('');
const isCustom = ref(false);

const effectiveAmount = computed(() => {
    if (isCustom.value) {
        const val = parseFloat(customAmount.value);
        return isNaN(val) || val <= 0 ? 0 : val;
    }
    return selectedAmount.value;
});

const newBalancePreview = computed(() => {
    const current = parseFloat(user.value?.balance || 0);
    return (current + effectiveAmount.value).toFixed(4);
});

const form = useForm({
    amount: 10,
});

const selectPreset = (amt) => {
    isCustom.value = false;
    selectedAmount.value = amt;
    form.amount = amt;
};

const chooseCustom = () => {
    isCustom.value = true;
    form.amount = customAmount.value || 0;
};

const updateCustomAmount = (e) => {
    customAmount.value = e.target.value;
    form.amount = parseFloat(e.target.value) || 0;
};

const submit = () => {
    if (form.amount <= 0) return;

    form.post(route('credits.buy'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};

const close = () => {
    emit('close');
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="relative w-full max-w-md rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl transition-all">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-3">
                    <div class="size-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-lg">
                        $
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Top Up Credits</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Add simulated test credits to your account</p>
                    </div>
                </div>
                <button
                    @click="close"
                    class="rounded-lg p-1.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                >
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Current Balance Status -->
            <div class="my-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 p-4 border border-zinc-200/60 dark:border-zinc-700/60">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">Current Balance:</span>
                    <span class="font-mono font-medium text-zinc-900 dark:text-zinc-100">${{ parseFloat(user?.balance || 0).toFixed(4) }}</span>
                </div>
                <div class="flex items-center justify-between text-sm mt-2 pt-2 border-t border-zinc-200 dark:border-zinc-700/50">
                    <span class="text-zinc-500 dark:text-zinc-400">New Balance after top-up:</span>
                    <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">${{ newBalancePreview }}</span>
                </div>
            </div>

            <!-- Preset Buttons -->
            <div class="space-y-3">
                <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider">
                    Select Amount (USD)
                </label>
                <div class="grid grid-cols-5 gap-2">
                    <button
                        v-for="amt in presetAmounts"
                        :key="amt"
                        type="button"
                        @click="selectPreset(amt)"
                        :class="[
                            !isCustom && selectedAmount === amt
                                ? 'bg-primary border-primary text-white shadow-md ring-2 ring-primary/30'
                                : 'bg-zinc-50 dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-800 dark:text-zinc-200 hover:border-zinc-400'
                        ]"
                        class="rounded-xl border py-2.5 font-semibold text-sm transition"
                    >
                        ${{ amt }}
                    </button>
                </div>

                <!-- Custom Amount Input -->
                <div class="pt-2">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="chooseCustom"
                            :class="[
                                isCustom
                                    ? 'border-primary ring-2 ring-primary/30'
                                    : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400'
                            ]"
                            class="flex-1 flex items-center rounded-xl border bg-zinc-50 dark:bg-zinc-800 px-3 py-2 text-sm"
                        >
                            <span class="text-zinc-400 dark:text-zinc-500 mr-1.5 font-mono">$</span>
                            <input
                                type="number"
                                step="1"
                                min="1"
                                max="1000"
                                placeholder="Custom amount"
                                :value="customAmount"
                                @focus="chooseCustom"
                                @input="updateCustomAmount"
                                class="w-full bg-transparent border-none p-0 text-sm focus:ring-0 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400"
                            />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Notice -->
            <p class="mt-4 text-xs text-zinc-400 leading-relaxed">
                Pixelforge is a simulated sandbox. No actual credit card or real money will be charged.
            </p>

            <!-- Actions -->
            <div class="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <button
                    type="button"
                    @click="close"
                    class="rounded-xl px-4 py-2.5 text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="submit"
                    :disabled="form.processing || effectiveAmount <= 0"
                    class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50 transition flex items-center gap-2"
                >
                    <svg v-if="form.processing" class="size-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Confirm & Add ${{ effectiveAmount.toFixed(2) }}
                </button>
            </div>
        </div>
    </div>
</template>
