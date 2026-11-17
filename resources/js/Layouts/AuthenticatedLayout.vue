<script setup>
import { ref, computed } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import BuyCreditsModal from '@/Components/BuyCreditsModal.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
const showBuyModal = ref(false);

const page = usePage();
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash || {});

const formattedBalance = computed(() => {
    return parseFloat(user.value?.balance || 0).toFixed(4);
});
</script>

<template>
    <div class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex flex-col">
        <nav class="sticky top-0 z-40 border-b border-zinc-200 dark:border-zinc-800 bg-white/85 dark:bg-zinc-900/85 backdrop-blur">
            <!-- Primary Navigation Menu -->
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between items-center">
                    <div class="flex items-center gap-8">
                        <!-- Brand Logo -->
                        <Link :href="route('generations.index')" class="flex items-center gap-2.5 font-bold text-lg tracking-tight">
                            <div class="size-8 rounded-lg bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-md shadow-indigo-500/20">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                                </svg>
                            </div>
                            <span class="bg-gradient-to-r from-zinc-900 via-zinc-800 to-zinc-600 dark:from-white dark:via-zinc-200 dark:to-zinc-400 bg-clip-text text-transparent">
                                PixelForge
                            </span>
                        </Link>

                        <!-- Navigation Links -->
                        <div class="hidden sm:flex sm:items-center sm:gap-1">
                            <NavLink
                                :href="route('generations.index')"
                                :active="route().current('generations.index')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                            >
                                Studio & History
                            </NavLink>
                            <NavLink
                                :href="route('admin.report')"
                                :active="route().current('admin.report')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                            >
                                Admin Report
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden sm:flex sm:items-center sm:gap-4">
                        <!-- Credit Balance Badge -->
                        <div class="flex items-center gap-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/90 px-3 py-1.5 shadow-sm">
                            <div class="size-2 rounded-full bg-emerald-500 animate-pulse"></div>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">Credits:</span>
                            <span class="font-mono font-semibold text-sm text-zinc-900 dark:text-zinc-100">
                                ${{ formattedBalance }}
                            </span>
                            <button
                                @click="showBuyModal = true"
                                type="button"
                                class="ml-1 rounded-md bg-emerald-600/10 hover:bg-emerald-600/20 text-emerald-700 dark:text-emerald-400 px-2 py-0.5 text-xs font-semibold transition"
                            >
                                + Buy
                            </button>
                        </div>

                        <!-- Settings Dropdown -->
                        <div class="relative">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-1.5 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:border-zinc-300 dark:hover:border-zinc-700 transition"
                                    >
                                        <div class="size-6 rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-bold uppercase">
                                            {{ user.name.charAt(0) }}
                                        </div>
                                        <span>{{ user.name }}</span>
                                        <svg class="size-4 text-zinc-400" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="px-4 py-2 border-b border-zinc-100 dark:border-zinc-800 text-xs text-zinc-500">
                                        Signed in as <strong class="text-zinc-700 dark:text-zinc-300 block truncate">{{ user.email }}</strong>
                                    </div>
                                    <DropdownLink :href="route('profile.edit')">
                                        Account Profile
                                    </DropdownLink>
                                    <DropdownLink :href="route('admin.report')">
                                        Operator Dashboard
                                    </DropdownLink>
                                    <div class="border-t border-zinc-100 dark:border-zinc-800"></div>
                                    <DropdownLink :href="route('logout')" method="post" as="button">
                                        Sign Out
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>

                    <!-- Mobile Hamburger -->
                    <div class="flex items-center sm:hidden gap-2">
                        <button
                            @click="showBuyModal = true"
                            class="rounded-lg bg-emerald-600/10 text-emerald-600 text-xs font-semibold px-2.5 py-1"
                        >
                            ${{ formattedBalance }}
                        </button>
                        <button
                            @click="showingNavigationDropdown = !showingNavigationDropdown"
                            class="inline-flex items-center justify-center rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 focus:outline-none"
                        >
                            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path
                                    :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }"
                                    stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"
                                />
                                <path
                                    :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                    stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Navigation Menu -->
            <div :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }" class="sm:hidden border-t border-zinc-200 dark:border-zinc-800">
                <div class="space-y-1 px-4 py-3">
                    <ResponsiveNavLink :href="route('generations.index')" :active="route().current('generations.index')">
                        Studio & History
                    </ResponsiveNavLink>
                    <ResponsiveNavLink :href="route('admin.report')" :active="route().current('admin.report')">
                        Admin Report
                    </ResponsiveNavLink>
                </div>
                <div class="border-t border-zinc-200 dark:border-zinc-800 px-4 py-3">
                    <div class="text-sm font-medium">{{ user.name }} ({{ user.email }})</div>
                    <div class="mt-3 flex gap-2">
                        <button
                            @click="showBuyModal = true"
                            class="flex-1 rounded-lg bg-emerald-600 py-1.5 text-xs font-semibold text-white"
                        >
                            Top Up Credits (${{ formattedBalance }})
                        </button>
                    </div>
                    <div class="mt-3 space-y-1">
                        <ResponsiveNavLink :href="route('profile.edit')">Profile</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('logout')" method="post" as="button">Sign Out</ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Flash Messages Banner -->
        <div v-if="flash?.success || flash?.error" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-4">
            <div
                v-if="flash?.success"
                class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300 flex items-center justify-between"
            >
                <div class="flex items-center gap-2">
                    <svg class="size-4 shrink-0 text-emerald-500" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ flash.success }}</span>
                </div>
            </div>
            <div
                v-if="flash?.error"
                class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-800 dark:text-rose-300 flex items-center justify-between"
            >
                <div class="flex items-center gap-2">
                    <svg class="size-4 shrink-0 text-rose-500" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ flash.error }}</span>
                </div>
            </div>
        </div>

        <!-- Page Content -->
        <main class="flex-1 py-8">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="border-t border-zinc-200 dark:border-zinc-800 py-6 text-center text-xs text-zinc-500">
            <div class="mx-auto max-w-7xl px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                <span>PixelForge — Integrated with <a href="https://pixelforge.promptedit.dev/docs" target="_blank" class="underline hover:text-zinc-700 dark:hover:text-zinc-300">Pixelforge API</a>. Rates + 25% markup.</span>
                <div class="flex items-center gap-4">
                    <Link :href="route('generations.index')" class="hover:underline">Studio</Link>
                    <Link :href="route('admin.report')" class="hover:underline">Reconciliation</Link>
                    <a href="https://pixelforge.promptedit.dev/assessment" target="_blank" class="hover:underline">Assessment Docs</a>
                </div>
            </div>
        </footer>

        <!-- Buy Credits Modal -->
        <BuyCreditsModal :show="showBuyModal" @close="showBuyModal = false" />
    </div>
</template>
