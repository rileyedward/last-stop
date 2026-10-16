<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ title: string; active: 'rules' | 'cards' }>();

const page = usePage();
const status = computed(() => page.props.status as string | null | undefined);
</script>

<template>
    <Head :title="`Admin · ${title}`" />
    <div class="ls-root">
        <div
            class="mx-auto flex min-h-dvh w-full max-w-[960px] flex-col gap-5 px-4 py-6"
        >
            <header class="flex flex-wrap items-center gap-4">
                <div class="flex flex-col gap-1">
                    <div class="label">Last Stop · Departure · admin</div>
                    <h1 class="disp m-0 text-[40px] leading-[.9] font-black">
                        {{ title }}
                    </h1>
                </div>
                <div class="flex-1" />
                <nav class="flex gap-2" aria-label="Admin sections">
                    <Link
                        href="/admin/rules"
                        class="btn btn-small"
                        :class="
                            active === 'rules' ? 'btn-primary' : 'btn-ghost'
                        "
                        >Rules</Link
                    >
                    <Link
                        href="/admin/cards"
                        class="btn btn-small"
                        :class="
                            active === 'cards' ? 'btn-primary' : 'btn-ghost'
                        "
                        >Cards</Link
                    >
                    <button
                        type="button"
                        class="btn btn-ghost btn-small"
                        @click="router.post('/admin/logout')"
                    >
                        Sign out
                    </button>
                </nav>
            </header>
            <div
                v-if="status"
                class="notice-amber px-[14px] py-3 text-[14px]"
                role="status"
            >
                {{ status }}
            </div>
            <slot />
        </div>
    </div>
</template>
