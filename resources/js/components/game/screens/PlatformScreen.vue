<script setup lang="ts">
import { computed, toRef } from 'vue';
import type { BoardState } from '@/game/types';
import { useClock } from '@/game/useClock';
import DeptRow from '../DeptRow.vue';
import Flaps from '../Flaps.vue';
import Rail from '../Rail.vue';

const props = defineProps<{ state: BoardState; refresh: () => void }>();
const lobby = computed(() => props.state.lobby);
const game = computed(() => props.state.game);
const myName = computed(() => props.state.me.name ?? 'passenger');
const seatNext = computed(
    () =>
        lobby.value.seats.length +
        lobby.value.platform.findIndex((p) => p.isMe) +
        1,
);

const clock = useClock(
    toRef(() => game.value?.deadline ?? null),
    toRef(() => props.state.serverNow),
    toRef(() => (game.value?.paused ? game.value.remaining : null)),
    () => props.refresh(),
);

const crisis = computed(
    () => game.value?.departments.filter((d) => d.crisis) ?? [],
);
</script>

<template>
    <div class="ls-phone gap-[18px] px-5 pt-6 pb-7">
        <div class="flex items-center justify-between">
            <div class="disp text-[22px] font-black">
                Last <span class="text-[#f4b740]">Stop</span>
            </div>
            <span
                class="mono text-[18px] font-semibold tracking-[.18em] text-[#f4b740]"
                >{{ lobby.code }}</span
            >
        </div>

        <div class="flex flex-col gap-2">
            <div class="label text-[#8fd3f0]">
                You're on the platform, {{ myName }}
            </div>
            <h1 class="disp m-0 text-[46px] leading-[.88] font-black">
                This train<br />already left
            </h1>
            <div class="text-[15px] leading-[1.45] text-[#b7c3d6]">
                You'll take seat {{ seatNext }} as soon as this trip ends. No
                spoilers here: you only see what the whole table can see.
            </div>
        </div>

        <div v-if="game" class="panel flex flex-col gap-[14px] p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1">
                    <Flaps :text="game.day" :pad="2" /><span
                        class="mono ml-[6px] text-[12px] text-[#93a2ba]"
                        >OF {{ game.days }}</span
                    >
                </div>
                <span
                    class="mono rounded-md bg-[#16213a] px-[9px] py-[6px] text-[12px] tracking-[.12em]"
                    >{{ game.phase.toUpperCase() }} ·
                    {{ clock.label.value || '—' }}</span
                >
            </div>
            <div class="flex flex-col gap-[6px]">
                <Rail :distance="game.distance" :route="game.route" />
                <div
                    class="mono flex justify-between text-[11px] text-[#93a2ba]"
                >
                    <span class="text-[#8fd3f0]"
                        >{{ game.distance }} OF {{ game.route }}</span
                    ><span>LAST STOP</span>
                </div>
            </div>
            <DeptRow
                :departments="game.departments"
                :crisis-at="game.crisisAt"
            />
            <div v-if="crisis.length" class="text-[13px] text-[#ff8e92]">
                {{ crisis.map((d) => d.name).join(' and ') }}
                {{ crisis.length === 1 ? 'is' : 'are' }} in crisis.{{
                    crisis.length === 1 ? ' One more and the traitor wins.' : ''
                }}
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <div class="label">Aboard this trip</div>
            <div class="flex flex-wrap gap-[6px]">
                <span
                    v-for="p in game?.players ?? []"
                    :key="p.id"
                    class="rounded-full border border-[#24324a] bg-[#111a2b] px-[10px] py-[6px] text-[14px]"
                    >{{ p.name }}</span
                >
                <span
                    v-for="p in lobby.platform"
                    :key="p.id"
                    class="rounded-full border border-dashed border-[#f4b740] px-[10px] py-[6px] text-[14px] text-[#f4b740]"
                    >{{ p.isMe ? 'You' : p.name }} · next trip</span
                >
            </div>
        </div>

        <div class="flex-1" />
        <div class="flex flex-col gap-[10px]">
            <a
                :href="`/board/${lobby.code}/station`"
                target="_blank"
                class="btn btn-ghost"
                >Watch the station board</a
            >
            <div class="text-center text-[13px] text-[#93a2ba]">
                This screen moves you into the lobby by itself when the trip
                ends.
            </div>
        </div>
    </div>
</template>
