<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Icon from '@/components/game/Icon.vue';
import { ICONS } from '@/components/game/icons';
import { initial } from '@/game/departments';

defineProps<{ name: string; rejoin: string | null }>();

const starting = ref(false);

function start(): void {
    starting.value = true;
    router.post('/lobbies', {}, { onFinish: () => (starting.value = false) });
}

const hour = new Date().getHours();
const greeting = hour < 12 ? 'Morning' : hour < 18 ? 'Afternoon' : 'Evening';
</script>

<template>
    <Head title="Last Stop" />
    <div class="ls-root">
        <div class="ls-phone gap-[22px] px-5 py-7">
            <div class="flex items-center justify-between">
                <div class="disp text-[24px] font-black">
                    Last <span class="text-[#f4b740]">Stop</span>
                </div>
                <Link
                    href="/name"
                    class="flex min-h-11 items-center gap-2 rounded-full border border-[#24324a] bg-[#111a2b] px-3 py-2 text-[14px] text-[#e8eef5]"
                >
                    <span
                        class="flex h-6 w-6 items-center justify-center rounded-full bg-[#e8eef5] text-[12px] font-bold text-[#0b1220]"
                        >{{ initial(name) }}</span
                    >
                    {{ name }}
                    <span class="text-[#93a2ba]"
                        ><Icon :paths="ICONS.pencil" :size="14" :stroke="2.2"
                    /></span>
                </Link>
            </div>

            <div class="mt-5 flex flex-col gap-[6px]">
                <div class="label">{{ greeting }}, {{ name }}</div>
                <h1 class="disp m-0 text-[50px] leading-[.88] font-black">
                    Where are<br />we headed?
                </h1>
            </div>

            <button
                type="button"
                class="relative flex min-h-[150px] flex-col gap-[10px] overflow-hidden rounded-2xl bg-[#f4b740] p-5 text-left text-[#1a1200]"
                :disabled="starting"
                @click="start"
            >
                <Icon :paths="ICONS.train" :size="34" />
                <div class="disp text-[34px] leading-[.9] font-black">
                    Start a lobby
                </div>
                <div class="text-[15px] leading-[1.4]">
                    Get a boarding code and an invite link to drop in your group
                    chat.
                </div>
            </button>

            <Link
                href="/join"
                class="flex min-h-[150px] flex-col gap-[10px] rounded-2xl border border-[#2c3d5c] bg-[#111a2b] p-5 text-[#e8eef5]"
            >
                <div class="flex gap-1" aria-hidden="true">
                    <span
                        v-for="i in 4"
                        :key="i"
                        class="mono flex h-[34px] w-[26px] items-center justify-center rounded bg-[#16213a] font-semibold text-[#f4b740]"
                        >?</span
                    >
                </div>
                <div class="disp text-[34px] leading-[.9] font-black">
                    Join with a code
                </div>
                <div class="text-[15px] leading-[1.4] text-[#b7c3d6]">
                    Someone already has a train waiting? Type their four
                    letters.
                </div>
            </Link>

            <div class="flex-1" />
            <Link
                v-if="rejoin"
                :href="`/board/${rejoin}`"
                class="flex items-center gap-3 rounded-xl border border-dashed border-[#3a4a66] p-[14px] text-[#e8eef5]"
            >
                <span class="online" />
                <span class="flex-1 text-[14px] leading-[1.35]"
                    >You still have a seat on
                    <b class="mono">{{ rejoin }}</b></span
                >
                <span class="text-[14px] text-[#f4b740]">Rejoin</span>
            </Link>
        </div>
    </div>
</template>
