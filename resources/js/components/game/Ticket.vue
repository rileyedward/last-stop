<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { dept } from '@/game/departments';
import type { GameView, Me } from '@/game/types';

const props = defineProps<{
    me: Me;
    game: GameView;
    name: string;
    seat: number;
}>();

const open = ref(false);
let timer: number | undefined;

function toggle(): void {
    open.value = !open.value;
    window.clearTimeout(timer);
    if (open.value) {
        timer = window.setTimeout(() => (open.value = false), 5000);
    }
}
onBeforeUnmount(() => window.clearTimeout(timer));

const meta = computed(() => dept(props.me.department));
</script>

<template>
    <div class="flex flex-1 flex-col gap-4">
        <button
            v-if="!open"
            type="button"
            class="flex min-h-[320px] flex-1 cursor-pointer flex-col items-center justify-center gap-[18px] rounded-2xl border-2 border-dashed border-[#3a4a66] bg-[#111a2b] text-[#e8eef5]"
            @click="toggle"
        >
            <div
                class="flex h-[120px] w-[120px] items-center justify-center rounded-full border-[3px] border-[#f4b740] bg-[#0b1220]"
            >
                <div
                    class="disp text-center text-[34px] leading-[.85] font-black text-[#f4b740]"
                >
                    LAST<br />STOP
                </div>
            </div>
            <div class="disp text-[28px] font-extrabold">Tap to open</div>
            <div
                class="max-w-[240px] text-center text-[14px] leading-[1.4] text-[#93a2ba]"
            >
                It closes again on its own after five seconds.
            </div>
        </button>

        <template v-else>
            <button
                type="button"
                class="relative flex flex-col rounded-2xl text-left shadow-[0_14px_30px_rgba(0,0,0,.5)]"
                :style="
                    me.traitor
                        ? 'background:#1A0B0E;border:2px solid #E5484D;color:#F6E3E4'
                        : 'background:#F3EDE0;color:#1A2230'
                "
                @click="toggle"
            >
                <div
                    class="flex flex-col gap-[10px] px-[22px] pt-[22px] pb-[18px]"
                >
                    <div
                        class="mono text-[10px] tracking-[.16em]"
                        :style="{ color: me.traitor ? '#F09A9D' : '#5E6878' }"
                    >
                        {{
                            me.traitor
                                ? 'NORTHERN LANTERN No. 9 · ONE WAY'
                                : 'NORTHERN LANTERN No. 9 · HOLLOW PINES → LAST STOP'
                        }}
                    </div>
                    <div
                        class="disp text-[76px] leading-[.85] font-black"
                        :style="{ color: me.traitor ? '#FF6B70' : undefined }"
                    >
                        {{ me.traitor ? 'Traitor' : 'Crew' }}
                    </div>
                    <div class="text-[17px] leading-[1.45]">
                        <template v-if="me.traitor"
                            >This train must not arrive. Put two departments in
                            crisis or run out the clock, and look like crew
                            while you do it.</template
                        >
                        <template v-else
                            >Get the train to Last Stop within
                            {{ game.days }} days. Somebody aboard is working
                            against you.</template
                        >
                    </div>
                </div>
                <div
                    class="mx-[14px] border-t-2 border-dashed"
                    :style="{ borderColor: me.traitor ? '#6B2A30' : '#B8A27A' }"
                />
                <div
                    class="flex items-end justify-between px-[22px] pt-[18px] pb-[22px]"
                >
                    <div class="flex flex-col gap-[6px]">
                        <div
                            class="mono text-[10px] tracking-[.14em]"
                            :style="{
                                color: me.traitor ? '#F09A9D' : '#5E6878',
                            }"
                        >
                            {{ me.traitor ? 'YOUR COVER' : 'DEPARTMENT' }}
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                class="h-4 w-4 rounded-[3px]"
                                :style="{ background: meta.color }"
                            />
                            <span
                                class="disp text-[28px] leading-[.9] font-extrabold"
                                >{{ meta.short }}</span
                            >
                        </div>
                    </div>
                    <div
                        class="mono text-right text-[12px]"
                        :style="{ color: me.traitor ? '#F09A9D' : '#5E6878' }"
                    >
                        SEAT {{ seat }}<br />{{ name.toUpperCase() }}
                    </div>
                </div>
            </button>
            <div
                v-if="me.traitor"
                class="flex flex-col gap-[6px] rounded-xl border border-[#5a2228] bg-[#1a0b0e] px-4 py-[14px]"
            >
                <div class="label text-[#f09a9d]">Your edge</div>
                <div class="text-[15px] leading-[1.4] text-[#f6e3e4]">
                    <template v-if="me.partners.length"
                        >You're working with {{ me.partners.join(' and ') }}.
                        They know about you too.
                    </template>
                    <template v-if="me.forecastOn"
                        >Each night you'll see the first of tomorrow's weather
                        cards. Nobody else will.</template
                    >
                    <template v-else>Nobody knows. Keep it that way.</template>
                </div>
            </div>
            <div v-else class="panel flex flex-col gap-[6px] px-4 py-[14px]">
                <div class="label">You share a deck with</div>
                <div class="text-[15px] leading-[1.4] text-[#c5d0e0]">
                    <template v-if="me.teammates.length"
                        >{{ me.teammates.join(' and ') }}. You can never hold
                        the same card on the same day.</template
                    >
                    <template v-else
                        >Nobody. You are {{ meta.name }}' only crew member this
                        trip.</template
                    >
                </div>
            </div>
        </template>
    </div>
</template>
