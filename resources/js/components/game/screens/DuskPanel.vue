<script setup lang="ts">
import { computed } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';
import Icon from '../Icon.vue';
import { ICONS } from '../icons';
import StationStrip from '../StationStrip.vue';

const props = defineProps<{ game: GameView; clock: string }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
}>();

const me = computed(() => props.game.me!);
const dusk = computed(() => props.game.dusk!);
const waiting = computed(() => props.game.phase === 'dusk');
const name = (id: string) =>
    props.game.players.find((p) => p.id === id)?.name ?? '?';
const readyCount = computed(
    () => props.game.players.filter((p) => p.ready).length,
);

// The check as it stood at dusk, department by department.
const checks = computed(() =>
    props.game.departments.map((d) => {
        const score = dusk.value.scoresBefore[d.key] ?? d.score;

        return {
            key: d.key,
            code: d.code,
            score,
            ok: score >= dusk.value.line,
        };
    }),
);

const lastDay = computed(() => props.game.day >= props.game.days);
const to = computed(
    () => props.game.stations[props.game.leg]?.name ?? 'the next station',
);
const readyLabel = computed(() => {
    if (dusk.value.arrived) {
        return props.game.accusation ? 'To the arrival vote' : 'Pull in';
    }

    return lastDay.value ? 'See how it ends' : 'Night falls';
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <div class="label" style="color: #f4b740">
                Dusk · day {{ game.day }} · the departure check
            </div>
            <h1 class="disp m-0 text-[40px] leading-[.9] font-black">
                <template v-if="waiting">Signal green</template>
                <template v-else-if="dusk.outcome === 'ran'">{{
                    dusk.arrived ? 'Last Stop' : 'Train departs'
                }}</template>
                <template v-else-if="dusk.outcome === 'pulled'"
                    >Cord pulled</template
                >
                <template v-else>Signal red</template>
            </h1>
        </div>

        <div class="panel flex flex-col gap-2 p-[14px]">
            <div class="flex items-center justify-between">
                <span class="label"
                    >Every department needs {{ dusk.line }}</span
                >
                <span class="flex gap-[6px]">
                    <span class="lamp" :class="dusk.green ? 'off' : 'red'" />
                    <span class="lamp" :class="dusk.green ? 'green' : 'off'" />
                </span>
            </div>
            <div
                v-for="(c, i) in checks"
                :key="c.key"
                class="check-in flex items-center gap-3"
                :style="{ animationDelay: `${i * 0.25}s` }"
            >
                <span
                    class="mono w-[44px] text-[12px] font-semibold"
                    :style="{ color: dept(c.key).color }"
                    >{{ c.code }}</span
                >
                <span class="mono flex-1 text-[15px]"
                    >{{ c.score }}
                    <span class="text-[#93a2ba]">/ {{ dusk.line }}</span></span
                >
                <span
                    class="flex h-6 w-6 items-center justify-center rounded-full"
                    :style="{
                        background: c.ok ? '#0F2A1E' : '#2A0F14',
                        color: c.ok ? '#8EE6B8' : '#FF8E92',
                    }"
                    ><Icon
                        :paths="c.ok ? ICONS.check : ICONS.close"
                        :size="14"
                        :stroke="2.6"
                /></span>
            </div>
        </div>

        <!-- The cord window: the same for everyone, plus the traitor's lever. -->
        <template v-if="waiting">
            <div
                class="panel flex flex-col items-center gap-2 px-4 py-6 text-center"
            >
                <div class="mono text-[44px] font-semibold text-[#8ee6b8]">
                    {{ clock }}
                </div>
                <div class="disp text-[26px] leading-none font-extrabold">
                    Waiting for the platform
                </div>
                <div class="text-[14px] leading-[1.4] text-[#b7c3d6]">
                    The train leaves when the clock runs out, unless someone
                    pulls the cord.
                    {{ game.cordLeft }} of {{ game.cordPulls }}
                    {{ game.cordPulls === 1 ? 'pull' : 'pulls' }} left.
                </div>
            </div>

            <div
                v-if="me.traitor"
                class="flex flex-col gap-[10px] rounded-2xl border-2 border-[#e5484d] bg-[#1a0b0e] p-[14px]"
            >
                <div class="label text-[#f09a9d]">Only you can see this</div>
                <template v-if="me.cordPulled">
                    <div class="disp text-[26px] leading-none font-extrabold">
                        {{
                            me.pulledByMe
                                ? 'You pulled the cord'
                                : 'Your partner pulled the cord'
                        }}
                    </div>
                    <div class="text-[14px] leading-[1.4] text-[#f6e3e4]">
                        When the clock runs out the train stays put and the
                        board names {{ game.clueSize }} players, one of them the
                        puller.
                    </div>
                </template>
                <template v-else-if="me.canPull">
                    <div class="text-[14px] leading-[1.4] text-[#f6e3e4]">
                        Stop the train here. No run, no run cost, and tomorrow's
                        weather hits again.
                        <template v-if="game.clueSize > 0"
                            >The board will name {{ game.clueSize }} players,
                            you among them.</template
                        >
                        <template
                            v-if="game.mustPull && me.traitorsPulled === 0"
                        >
                            You must pull at least once to win at Last
                            Stop.</template
                        >
                    </div>
                    <button
                        type="button"
                        class="btn btn-danger"
                        @click="emit('act', 'cord', {})"
                    >
                        Pull the cord
                    </button>
                </template>
                <div v-else class="text-[14px] leading-[1.4] text-[#f6e3e4]">
                    You're detained, so you can't pull the cord tonight. Keep
                    your face still.
                </div>
            </div>
        </template>

        <!-- The result. -->
        <template v-else>
            <div
                v-if="dusk.outcome === 'ran'"
                class="panel flex flex-col gap-3 p-[14px]"
            >
                <StationStrip :stations="game.stations" :leg="game.leg" names />
                <div class="text-[15px] leading-[1.45] text-[#c5d0e0]">
                    <template v-if="dusk.arrived"
                        >The train pulls into <b>Last Stop</b>.
                        <template v-if="game.accusation"
                            >Now everyone names the traitor.</template
                        ></template
                    >
                    <template v-else
                        >The train runs to <b>{{ to }}</b
                        >.</template
                    >
                    <template v-if="dusk.free">
                        Tailwind: the run was free.</template
                    >
                    <template v-else>
                        Every department paid {{ game.runCost }}.</template
                    >
                </div>
            </div>

            <div
                v-else-if="dusk.outcome === 'pulled'"
                class="flex flex-col gap-3 rounded-2xl border border-[#5a2228] bg-[#1a0f13] p-[14px]"
            >
                <div class="text-[15px] leading-[1.45] text-[#f6e3e4]">
                    Someone stopped the train. No run tonight, and tomorrow's
                    weather hits again.
                    {{ game.cordLeft }}
                    {{ game.cordLeft === 1 ? 'pull' : 'pulls' }} left.
                </div>
                <template v-if="dusk.clue.length">
                    <div class="label text-[#ff8e92]">
                        The clue · one of these pulled it
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="id in dusk.clue"
                            :key="id"
                            class="flex items-center gap-2 rounded-lg border border-[#5a2228] bg-[#2a0f14] px-[10px] py-[6px]"
                        >
                            <Avatar :name="name(id)" :size="24" />
                            <b class="text-[15px]">{{ name(id) }}</b>
                        </span>
                    </div>
                </template>
                <div v-else class="text-[14px] text-[#93a2ba]">
                    Clues are off this trip.
                </div>
            </div>

            <div
                v-else
                class="notice-red px-[14px] py-3 text-[15px] leading-[1.45]"
            >
                Not every department reached {{ dusk.line }}, so the train stays
                at {{ game.stations[game.leg]?.name }}.
                <template v-if="lastDay"> That was the last day.</template>
            </div>

            <button
                type="button"
                class="btn"
                :class="me.ready ? 'btn-outline' : 'btn-primary'"
                @click="emit('act', 'ready', { ready: !me.ready })"
            >
                {{
                    me.ready
                        ? `Waiting · ${readyCount} of ${game.players.length}`
                        : readyLabel
                }}
            </button>
        </template>
    </div>
</template>
