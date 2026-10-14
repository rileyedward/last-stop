<script setup lang="ts">
import { computed, ref } from 'vue';
import { dept, effectList, signed } from '@/game/departments';
import type { GameView } from '@/game/types';
import Gauge from '../Gauge.vue';
import Icon from '../Icon.vue';
import Sheet from '../Sheet.vue';
import WeatherCard from '../WeatherCard.vue';

const props = defineProps<{ game: GameView }>();

const inPlay = computed(() =>
    props.game.departments.map((d) => d.key as string),
);
const detail = ref<number | null>(null);
const mine = computed(() => props.game.me?.department ?? null);

function status(d: GameView['departments'][number]): {
    text: string;
    color: string;
} {
    const gap = d.score - props.game.crisisAt;
    if (d.crisis) {
        return {
            text: `CRISIS · draws ${props.game.reducedHand}`,
            color: '#FF8E92',
        };
    }
    if (d.key === mine.value) {
        return {
            text: gap === 1 ? 'Yours · 1 from crisis' : 'Yours',
            color: dept(d.key).color,
        };
    }
    if (gap === 1) {
        return { text: '1 from crisis', color: '#F4D9CC' };
    }

    return { text: `${gap} above crisis`, color: '#93A2BA' };
}

const daysLeft = computed(() =>
    Math.max(
        0,
        props.game.days -
            props.game.day +
            (props.game.phase === 'dawn' || props.game.phase === 'day' ? 1 : 0),
    ),
);
const needed = computed(() => {
    const left = props.game.route - props.game.distance;

    return daysLeft.value > 0 ? (left / daysLeft.value).toFixed(1) : '—';
});

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
                    The train
                </h1>
            </div>
            <div
                v-if="mine"
                class="flex items-center gap-[6px] rounded-lg bg-[#16213a] px-2 py-[6px] text-[12px] text-[#c5d0e0]"
            >
                <span
                    class="h-[10px] w-[10px] rounded-[2px]"
                    :style="{ background: dept(mine).color }"
                />You: {{ dept(mine).short }}
            </div>
        </div>

        <div class="grid grid-cols-2 gap-[10px]">
            <div
                v-for="d in game.departments"
                :key="d.key"
                class="panel flex flex-col gap-2 p-3"
                :class="{ 'crisis-tile': d.crisis }"
                :style="
                    !d.crisis && d.key === mine
                        ? 'border-color:#6E5590'
                        : !d.crisis && d.score - game.crisisAt === 1
                          ? 'border-color:#7A3A22;background:#24120F'
                          : ''
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
                </div>
                <div class="flex items-baseline gap-[6px]">
                    <span class="mono text-[30px] leading-none font-semibold">{{
                        d.score
                    }}</span>
                    <span
                        v-if="d.score !== d.atDawn"
                        class="mono text-[13px]"
                        :class="d.score < d.atDawn ? 'dn' : 'text-[#f4b740]'"
                        >{{ signed(d.score - d.atDawn) }}</span
                    >
                </div>
                <Gauge
                    :score="d.score"
                    :color="dept(d.key).color"
                    :crisis-at="game.crisisAt"
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

        <div class="flex flex-col gap-2">
            <div class="flex justify-between">
                <div class="label">
                    Today's weather · {{ game.weather.length }}
                    {{ game.weather.length === 1 ? 'card' : 'cards' }}
                </div>
                <div class="text-[12px] text-[#93a2ba]">Tap for detail</div>
            </div>
            <div class="flex gap-2">
                <button
                    v-for="(w, i) in game.weather"
                    :key="i"
                    type="button"
                    class="flex flex-1 text-left"
                    @click="detail = i"
                >
                    <WeatherCard :card="w" :only="[...inPlay, 'distance']" />
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
            <template v-if="game.weatherDistance < 0">
                The weather will cost
                <b class="text-[#8fd3f0]"
                    >{{ -game.weatherDistance }} distance</b
                >
                when today's tasks resolve.</template
            >
        </div>

        <div class="panel-dash flex flex-col gap-2 p-[14px]">
            <div class="flex justify-between text-[15px]">
                <span class="text-[#b7c3d6]">Base speed today</span>
                <b
                    class="mono"
                    :class="game.baseToday === 0 ? 'text-[#ff8e92]' : ''"
                    >{{
                        game.baseToday === 0
                            ? '0 · brake'
                            : signed(game.baseToday)
                    }}</b
                >
            </div>
            <div class="flex justify-between text-[15px]">
                <span class="text-[#b7c3d6]">Weather distance today</span
                ><b class="mono text-[#8fd3f0]">{{
                    signed(game.weatherDistance)
                }}</b>
            </div>
            <div class="flex justify-between text-[15px]">
                <span class="text-[#b7c3d6]">Needed per day to arrive</span
                ><b class="mono text-[#f4b740]">{{ needed }}</b>
            </div>
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
                        v-for="f in effectList(card.effects, card.distance, [
                            ...inPlay,
                            'distance',
                        ])"
                        :key="f.key"
                        class="mono flex justify-between text-[14px]"
                    >
                        <span>{{
                            f.key === 'distance' ? 'DIST' : dept(f.key).code
                        }}</span
                        ><span :class="f.value < 0 ? 'dn' : ''">{{
                            signed(f.value)
                        }}</span>
                    </div>
                    <div
                        v-if="
                            effectList(card.effects, card.distance, [
                                ...inPlay,
                                'distance',
                            ]).length === 0
                        "
                        class="text-[14px] text-[#93a2ba]"
                    >
                        No effect.
                    </div>
                </div>
                <div class="text-[14px] leading-[1.45] text-[#b7c3d6]">
                    Weather is background pressure: nobody responds to it
                    directly. Distance effects count when the day's tasks
                    resolve.
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
