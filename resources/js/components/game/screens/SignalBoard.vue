<script setup lang="ts">
import { computed, ref } from 'vue';
import { dept, effectList, signed } from '@/game/departments';
import type { GameView } from '@/game/types';
import Flaps from '../Flaps.vue';
import Gauge from '../Gauge.vue';
import Icon from '../Icon.vue';
import Sheet from '../Sheet.vue';
import StationStrip from '../StationStrip.vue';
import WeatherCard from '../WeatherCard.vue';

const props = defineProps<{ game: GameView }>();

const inPlay = computed(() =>
    props.game.departments.map((d) => d.key as string),
);
const detail = ref<number | null>(null);
const mine = computed(() => props.game.me?.department ?? null);
const green = computed(() => props.game.departments.every((d) => d.atLine));

const here = computed(() => props.game.stations[props.game.leg]?.name ?? '');
const next = computed(
    () => props.game.stations[props.game.leg + 1]?.name ?? '',
);
const nextRise = computed(() => {
    for (let leg = props.game.leg + 1; leg < props.game.legs; leg++) {
        const line = props.game.stations[leg]?.line ?? props.game.line;
        if (line > props.game.line) {
            return { at: props.game.stations[leg].name, line };
        }
    }

    return null;
});

const daysLeft = computed(() =>
    Math.max(
        0,
        props.game.days -
            props.game.day +
            (['boarding', 'dawn', 'day', 'reveal', 'dusk'].includes(
                props.game.phase,
            )
                ? 1
                : 0),
    ),
);

function status(d: GameView['departments'][number]): {
    text: string;
    color: string;
} {
    const short = props.game.line - d.score;
    if (d.crisis) {
        return {
            text: `CRISIS · ${short} short · draws ${props.game.reducedHand}`,
            color: '#FF8E92',
        };
    }
    if (short > 0) {
        return {
            text: `${short} short of the line`,
            color: short === 1 ? '#F4D9CC' : '#F4B740',
        };
    }

    return {
        text: short === 0 ? 'At the line' : `${-short} over the line`,
        color: '#5CC8A0',
    };
}

const worst = computed(() => {
    let best: { name: string; from: number; to: number } | null = null;
    for (const d of props.game.departments) {
        const drop = d.atDawn - d.score;
        if (drop > 0 && (!best || drop > best.from - best.to)) {
            best = { name: d.name, from: d.atDawn, to: d.score };
        }
    }

    return best;
});

const card = computed(() =>
    detail.value === null ? null : props.game.weather[detail.value],
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-end justify-between gap-2">
            <div class="flex flex-col gap-1">
                <div
                    class="label"
                    :style="{
                        color: game.phase === 'dawn' ? '#8FD3F0' : undefined,
                    }"
                >
                    {{
                        game.phase === 'dawn'
                            ? 'Dawn · the weather lands'
                            : `Day ${game.day} of ${game.days}`
                    }}
                </div>
                <h1 class="disp m-0 text-[34px] leading-[.9] font-black">
                    Signal board
                </h1>
            </div>
            <div
                class="flex items-center gap-2 rounded-lg px-[10px] py-[7px]"
                :style="{
                    background: green ? '#0F2A1E' : '#2A0F14',
                    border: `1px solid ${green ? '#2E7A55' : '#5A2228'}`,
                }"
                :aria-label="green ? 'Signal green' : 'Signal red'"
            >
                <span class="lamp" :class="green ? 'off' : 'red'" />
                <span class="lamp" :class="green ? 'green' : 'off'" />
                <span
                    class="mono text-[12px] font-semibold tracking-[.12em]"
                    :style="{ color: green ? '#8EE6B8' : '#FF8E92' }"
                    >{{ green ? 'GREEN' : 'RED' }}</span
                >
            </div>
        </div>

        <div class="panel flex flex-col gap-3 p-[14px]">
            <StationStrip :stations="game.stations" :leg="game.leg" names />
            <div class="text-[14px] leading-[1.4] text-[#c5d0e0]">
                At <b class="text-[#e8eef5]">{{ here }}</b
                ><template v-if="next">
                    · next stop <b class="text-[#e8eef5]">{{ next }}</b
                    >. Every department needs
                    <b class="text-[#f4b740]">{{ game.line }}</b> to leave.
                    <template v-if="nextRise"
                        >The line rises to {{ nextRise.line }} at
                        {{ nextRise.at }}.</template
                    ></template
                >
            </div>
        </div>

        <div class="grid grid-cols-2 gap-[10px]">
            <div
                v-for="d in game.departments"
                :key="d.key"
                class="panel flex flex-col gap-2 p-3"
                :class="{ 'crisis-tile': d.crisis }"
                :style="
                    !d.crisis && d.key === mine ? 'border-color:#6E5590' : ''
                "
            >
                <div
                    class="mono flex items-center gap-[6px] text-[11px] font-semibold tracking-[.1em]"
                    :style="{ color: dept(d.key).color }"
                >
                    <Icon
                        :paths="dept(d.key).icon"
                        :size="16"
                        :stroke="2.2"
                    />{{ d.code }}
                    <span v-if="d.key === mine" class="ml-auto text-[#c39beb]"
                        >YOURS</span
                    >
                </div>
                <div class="flex items-baseline gap-[6px]">
                    <span class="mono text-[30px] leading-none font-semibold">{{
                        d.score
                    }}</span>
                    <span class="mono text-[13px] text-[#93a2ba]"
                        >/ {{ game.line }}</span
                    >
                    <span
                        v-if="d.score !== d.atDawn"
                        class="mono ml-auto text-[13px]"
                        :class="d.score < d.atDawn ? 'dn' : 'text-[#f4b740]'"
                        >{{ signed(d.score - d.atDawn) }}</span
                    >
                </div>
                <Gauge
                    :score="d.score"
                    :color="dept(d.key).color"
                    :crisis-at="game.crisisAt"
                    :max="game.scoreMax"
                    :line="game.line"
                />
                <span
                    class="text-[12px]"
                    :style="{
                        color: status(d).color,
                        fontWeight: d.crisis ? 700 : 400,
                    }"
                    >{{ status(d).text }}</span
                >
            </div>
        </div>

        <div class="panel-dash grid grid-cols-3 gap-2 p-[14px]">
            <div class="flex flex-col gap-[6px]">
                <span class="label">Days left</span>
                <Flaps :text="daysLeft" :pad="2" />
            </div>
            <div class="flex flex-col gap-[6px]">
                <span class="label">Cord pulls</span>
                <span class="mono text-[20px] font-semibold text-[#ff8e92]"
                    >{{ game.cordLeft
                    }}<span class="text-[13px] text-[#93a2ba]">
                        / {{ game.cordPulls }} left</span
                    ></span
                >
            </div>
            <div class="flex flex-col gap-[6px]">
                <span class="label">Next run</span>
                <span
                    class="mono text-[15px] font-semibold"
                    :class="game.freeRun ? 'text-[#f4b740]' : ''"
                    >{{
                        game.freeRun
                            ? 'Free · Tailwind'
                            : `−${game.runCost} each`
                    }}</span
                >
            </div>
        </div>

        <div v-if="game.weather.length" class="flex flex-col gap-2">
            <div class="flex justify-between">
                <div class="label">
                    Today's weather · {{ game.weather.length }}
                    {{ game.weather.length === 1 ? 'card' : 'cards' }}
                </div>
                <div class="text-[12px] text-[#93a2ba]">Tap for detail</div>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="(w, i) in game.weather"
                    :key="i"
                    type="button"
                    class="flex text-left"
                    @click="detail = i"
                >
                    <WeatherCard :card="w" :only="inPlay" />
                </button>
            </div>
        </div>

        <div
            v-if="game.phase === 'dawn' && worst"
            class="panel px-[14px] py-3 text-[15px] leading-[1.45] text-[#c5d0e0]"
        >
            {{ worst.name }} took the worst of it,
            <b class="text-[#e8eef5]">{{ worst.from }} → {{ worst.to }}</b
            >.
        </div>

        <Sheet :open="card !== null" :title="card?.name" @close="detail = null">
            <template v-if="card">
                <div class="label text-[#8fd3f0]">
                    Weather · {{ (detail ?? 0) + 1 }} of
                    {{ game.weather.length }}
                </div>
                <div
                    v-if="card.flavor"
                    class="text-[15px] text-[#b7c3d6] italic"
                >
                    {{ card.flavor }}.
                </div>
                <div class="panel flex flex-col gap-3 p-[14px]">
                    <div class="label">What it does</div>
                    <div
                        v-for="f in effectList(card.effects, inPlay)"
                        :key="f.key"
                        class="mono flex justify-between text-[14px]"
                    >
                        <span>{{ dept(f.key).code }}</span
                        ><span :class="f.value < 0 ? 'dn' : ''">{{
                            signed(f.value)
                        }}</span>
                    </div>
                    <div
                        v-if="card.special === 'free_run'"
                        class="text-[14px] text-[#f4b740]"
                    >
                        The next run costs nothing.
                    </div>
                    <div
                        v-else-if="
                            effectList(card.effects, inPlay).length === 0
                        "
                        class="text-[14px] text-[#93a2ba]"
                    >
                        No effect.
                    </div>
                </div>
                <div class="flex gap-[10px]">
                    <button
                        type="button"
                        class="btn btn-ghost w-14 flex-none"
                        aria-label="Previous"
                        :disabled="detail === 0"
                        @click="detail = Math.max(0, (detail ?? 0) - 1)"
                    >
                        ‹
                    </button>
                    <button
                        type="button"
                        class="btn btn-ghost w-14 flex-none"
                        aria-label="Next"
                        :disabled="detail === game.weather.length - 1"
                        @click="
                            detail = Math.min(
                                game.weather.length - 1,
                                (detail ?? 0) + 1,
                            )
                        "
                    >
                        ›
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary flex-1"
                        @click="detail = null"
                    >
                        Close
                    </button>
                </div>
            </template>
        </Sheet>
    </div>
</template>
