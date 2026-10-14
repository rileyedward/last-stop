<script setup lang="ts">
import { computed, ref } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';
import Rail from '../Rail.vue';

const props = defineProps<{ game: GameView }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
}>();

const me = computed(() => props.game.me);
const chosen = ref<string | null>(null);
const name = (id: string | null) =>
    props.game.players.find((p) => p.id === id)?.name ?? '';
const crewCount = computed(
    () => props.game.players.length - props.game.traitorCount,
);
const guessed = computed(
    () => props.game.players.filter((p) => p.guessed).length,
);

const headline = computed(
    () =>
        ({
            arrived: ['Arrived at', 'Last Stop'],
            crisis: ['Two departments', 'in crisis'],
            clock: ['Out of', 'days'],
            ended: ['Trip', 'ended'],
        })[props.game.cause ?? 'ended'] ?? ['Trip', 'over'],
);
</script>

<template>
    <div class="flex flex-col gap-[14px]">
        <div class="flex flex-col gap-2">
            <div class="label text-[#f4b740]">
                Day {{ game.day }} · {{ game.distance }} of {{ game.route }}
            </div>
            <h1 class="disp m-0 text-[54px] leading-[.85] font-black">
                {{ headline[0] }}<br /><span
                    :class="
                        game.winner === 'crew'
                            ? 'text-[#f4b740]'
                            : 'text-[#ff6b70]'
                    "
                    >{{ headline[1] }}</span
                >
            </h1>
            <Rail :distance="game.distance" :route="game.route" />
            <div class="text-[15px] text-[#c5d0e0]">
                {{
                    game.winner === 'crew'
                        ? 'The crew wins.'
                        : 'The traitor wins.'
                }}
                Before the reveal: who was it?
            </div>
        </div>

        <template v-if="me && !me.traitor">
            <template v-if="me.guess === null">
                <div class="label">Arrival inquiry · name your suspect</div>
                <div class="flex flex-col gap-2">
                    <button
                        v-for="p in game.players.filter((x) => !x.isMe)"
                        :key="p.id"
                        type="button"
                        class="seat"
                        :style="
                            chosen === p.id
                                ? 'border-color:#F4B740;background:#2A2110'
                                : ''
                        "
                        :aria-pressed="chosen === p.id"
                        @click="chosen = p.id"
                    >
                        <Avatar :name="p.name" />
                        <span class="flex-1 text-[15px] font-bold">{{
                            p.name
                        }}</span>
                        <span
                            class="mono text-[11px]"
                            :style="{ color: dept(p.department).color }"
                            >{{ dept(p.department).code }}</span
                        >
                    </button>
                </div>
                <div class="text-[13px] text-[#93a2ba]">
                    Your guess is secret until the reveal. It doesn't change who
                    won.
                </div>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="!chosen"
                    @click="chosen && emit('act', 'guess', { suspect: chosen })"
                >
                    {{ chosen ? `Name ${name(chosen)}` : 'Pick a suspect' }}
                </button>
            </template>
            <div v-else class="panel flex flex-col gap-2 p-4">
                <div class="label">Your suspect</div>
                <div class="disp text-[32px] leading-none font-black">
                    {{ name(me.guess) }}
                </div>
                <div class="text-[14px] text-[#b7c3d6]">
                    Waiting for the rest of the crew · {{ guessed }} of
                    {{ crewCount }} named.
                </div>
            </div>
        </template>
        <div
            v-else
            class="flex flex-col gap-2 rounded-xl border border-[#5a2228] bg-[#1a0b0e] p-4"
        >
            <div class="label text-[#f09a9d]">The crew is naming suspects</div>
            <div class="text-[15px] leading-[1.4] text-[#f6e3e4]">
                Keep a straight face. Everything is revealed in a moment ·
                {{ guessed }} of {{ crewCount }} named.
            </div>
        </div>
    </div>
</template>
