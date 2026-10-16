<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { dept, effectList, signed } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';
import Icon from '../Icon.vue';
import { ICONS } from '../icons';

const props = defineProps<{ game: GameView }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
}>();

const today = computed(() => props.game.today!);
const picks = computed(() => today.value.picks.filter((p) => p.card !== null));
const inPlay = computed(() =>
    props.game.departments.map((d) => d.key as string),
);
const name = (id: string) =>
    props.game.players.find((p) => p.id === id)?.name ?? '';
const isMe = (id: string) =>
    props.game.players.find((p) => p.id === id)?.isMe ?? false;

// Picks flip one at a time on every screen, then the summary.
const flipped = ref(0);
let timer: number | undefined;
function flipNext(): void {
    flipped.value = Math.min(picks.value.length, flipped.value + 1);
}
onMounted(() => {
    timer = window.setInterval(() => {
        if (flipped.value < picks.value.length) {
            flipNext();
        }
    }, 1800);
});
onBeforeUnmount(() => window.clearInterval(timer));
watch(
    () => today.value.day,
    () => (flipped.value = 0),
);

const done = computed(() => flipped.value >= picks.value.length);
const current = computed(() =>
    flipped.value > 0 ? picks.value[flipped.value - 1] : null,
);

const tally = computed(() => {
    if (done.value) {
        return { scores: today.value.scoresAfter };
    }
    // Picks add up first and clamp once, as the engine does.
    const totals: Record<string, number> = {};
    for (const pick of picks.value.slice(0, flipped.value)) {
        for (const [k, v] of Object.entries(pick.card!.effects)) {
            totals[k] = (totals[k] ?? 0) + (v ?? 0);
        }
    }
    const scores: Record<string, number> = {};
    for (const [k, v] of Object.entries(today.value.scoresAfterWeather)) {
        scores[k] = Math.max(
            0,
            Math.min(props.game.scoreMax, v + (totals[k] ?? 0)),
        );
    }

    return { scores };
});

const rows = computed(() =>
    props.game.departments.map((d) => {
        const weather =
            (today.value.scoresAfterWeather[d.key] ?? 0) -
            (today.value.scoresBefore[d.key] ?? 0);
        const crew =
            (today.value.scoresAfter[d.key] ?? 0) -
            (today.value.scoresAfterWeather[d.key] ?? 0);

        return {
            d,
            weather,
            crew,
            from: today.value.scoresBefore[d.key],
            to: today.value.scoresAfter[d.key],
        };
    }),
);

const warnings = computed(() =>
    props.game.departments
        .filter((d) => d.score - props.game.crisisAt === 1 || d.crisis)
        .map((d) =>
            d.crisis
                ? `${d.name} is in crisis. It draws only ${props.game.reducedHand} cards.`
                : `${d.name} is one point from crisis.`,
        ),
);

const me = computed(() => props.game.me);
const readyCount = computed(
    () => props.game.players.filter((p) => p.ready).length,
);
const short = computed(() =>
    props.game.departments.filter((d) => !d.atLine).map((d) => d.name),
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="!done" class="flex justify-between gap-1">
            <div
                v-for="d in game.departments"
                :key="d.key"
                class="mono flex flex-col items-center gap-[2px] text-[11px] font-semibold"
            >
                <span :style="{ color: dept(d.key).color }">{{ d.code }}</span>
                <span class="text-[16px] text-[#e8eef5]">{{
                    tally.scores[d.key]
                }}</span>
            </div>
            <div
                class="mono flex flex-col items-center gap-[2px] text-[11px] font-semibold"
            >
                <span class="text-[#93a2ba]">LINE</span
                ><span class="text-[16px] text-[#e8eef5]">{{ game.line }}</span>
            </div>
        </div>

        <template v-if="!done">
            <div class="label">
                Picks flip one at a time · {{ flipped }}/{{ picks.length }}
            </div>
            <div v-if="current" :key="flipped" class="paper flip-in flex-none">
                <div
                    class="flex h-10 items-center gap-[10px] px-3 text-[#10151f]"
                    :style="{
                        background: dept(current.card!.department).color,
                    }"
                >
                    <span
                        class="flex h-[26px] w-[26px] items-center justify-center rounded-full bg-[#10151f] text-[12px] font-bold text-[#f3ede0]"
                        >{{ name(current.player)[0] }}</span
                    >
                    <span class="text-[15px] font-bold"
                        >{{
                            isMe(current.player) ? 'You' : name(current.player)
                        }}
                        played</span
                    >
                    <span
                        class="mono ml-auto text-[10px] tracking-[.12em] uppercase"
                        >{{ dept(current.card!.department).code }} ·
                        {{ current.card!.kind }}</span
                    >
                </div>
                <div class="flex flex-col gap-2 px-[14px] pt-3 pb-[14px]">
                    <span class="disp text-[34px] leading-[.9] font-black">{{
                        current.card!.name
                    }}</span>
                    <span class="flex flex-wrap gap-[6px]">
                        <span
                            v-for="f in effectList(
                                current.card!.effects,
                                inPlay,
                            )"
                            :key="f.key"
                            class="pfx"
                            :class="{ neg: f.value < 0 }"
                            >{{ f.label }}</span
                        >
                    </span>
                    <span
                        v-if="current.override"
                        class="mono text-[12px] text-[#a86f00]"
                        >OVERRIDE · played over the escort's veto</span
                    >
                    <span
                        v-else-if="current.vetoed"
                        class="mono text-[12px] text-[#b4232a]"
                        >Second pick after a veto</span
                    >
                    <span
                        v-if="current.auto"
                        class="mono text-[12px] text-[#5e6878]"
                        >Timer ran out · played at random</span
                    >
                </div>
            </div>
            <div class="flex flex-col gap-[5px]">
                <div
                    v-for="(p, i) in picks"
                    :key="p.player"
                    class="flex items-center gap-[10px] rounded-[10px] px-3 py-2 text-[14px]"
                    :style="{
                        background:
                            i === flipped - 1
                                ? '#1B2638'
                                : i < flipped
                                  ? '#111A2B'
                                  : 'transparent',
                        border: `1px ${i < flipped ? 'solid' : 'dashed'} ${i === flipped - 1 ? '#F4B740' : i < flipped ? '#24324A' : '#3A4A66'}`,
                        opacity: i < flipped ? 1 : 0.6,
                    }"
                >
                    <span
                        class="mono w-[30px] text-[11px] font-semibold"
                        :style="{ color: dept(p.card!.department).color }"
                        >{{ dept(p.card!.department).code }}</span
                    >
                    <b class="w-[70px] truncate">{{
                        isMe(p.player) ? 'You' : name(p.player)
                    }}</b>
                    <span class="flex-1 truncate text-[#c5d0e0]">{{
                        i < flipped ? p.card!.name : 'face down'
                    }}</span>
                    <span
                        v-if="i < flipped && p.override"
                        class="mono text-[11px] text-[#f4b740]"
                        >OVERRIDE</span
                    >
                </div>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="btn btn-primary flex-1"
                    @click="flipNext"
                >
                    {{
                        flipped === 0
                            ? 'Flip the first card'
                            : `Flip ${isMe(picks[flipped]?.player ?? '') ? 'your' : `${name(picks[flipped]?.player ?? '')}'s`} card`
                    }}
                </button>
                <button
                    type="button"
                    class="btn btn-ghost w-24 flex-none text-[15px]"
                    @click="flipped = picks.length"
                >
                    All
                </button>
            </div>
        </template>

        <template v-else>
            <div class="flex flex-col gap-1">
                <div class="label">Day {{ today.day }} in numbers</div>
                <h1 class="disp m-0 text-[34px] leading-[.9] font-black">
                    Weather vs crew
                </h1>
            </div>
            <div class="panel flex flex-col gap-3 p-[14px]">
                <div
                    class="mono grid grid-cols-[44px_1fr_1fr_1.4fr] text-[10px] tracking-[.12em] text-[#93a2ba]"
                >
                    <span /><span>WEATHER</span><span>CREW</span
                    ><span class="text-right">RESULT</span>
                </div>
                <div
                    v-for="r in rows"
                    :key="r.d.key"
                    class="mono grid grid-cols-[44px_1fr_1fr_1.4fr] items-baseline text-[14px]"
                >
                    <span :style="{ color: dept(r.d.key).color }">{{
                        r.d.code
                    }}</span>
                    <span :class="r.weather < 0 ? 'dn' : 'text-[#5e6e88]'">{{
                        signed(r.weather)
                    }}</span>
                    <span
                        :class="
                            r.crew < 0
                                ? 'dn'
                                : r.crew > 0
                                  ? 'text-[#f4b740]'
                                  : 'text-[#5e6e88]'
                        "
                        >{{ signed(r.crew) }}</span
                    >
                    <span class="text-right"
                        >{{ r.from }} →
                        <b class="text-[16px]">{{ r.to }}</b></span
                    >
                </div>
                <div class="h-px bg-[#24324a]" />
                <div class="text-[13px] text-[#c5d0e0]">
                    <template v-if="short.length === 0"
                        >Every department is at the line ({{ game.line }}). The
                        signal will be green at dusk.</template
                    >
                    <template v-else
                        >{{ short.join(', ') }}
                        {{ short.length === 1 ? 'is' : 'are' }} below the line
                        ({{ game.line }}). The signal will be red at
                        dusk.</template
                    >
                </div>
            </div>

            <div class="flex flex-col gap-[6px]">
                <div class="label">Who played what</div>
                <div
                    v-for="p in picks"
                    :key="p.player"
                    class="flex items-center gap-[10px] rounded-[10px] border border-[#24324a] bg-[#111a2b] px-3 py-2"
                >
                    <Avatar
                        :name="name(p.player)"
                        :me="isMe(p.player)"
                        :size="26"
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="text-[14px]">
                            <b>{{ isMe(p.player) ? 'You' : name(p.player) }}</b>
                            <span
                                class="mono ml-1 text-[11px]"
                                :style="{
                                    color: dept(p.card!.department).color,
                                }"
                                >{{ dept(p.card!.department).code }}</span
                            >
                            · {{ p.card!.name }}
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="f in effectList(p.card!.effects, inPlay)"
                                :key="f.key"
                                class="chip"
                                :class="{ dn: f.value < 0 }"
                                >{{ f.label }}</span
                            >
                            <span
                                v-if="p.card!.kind === 'routine'"
                                class="chip text-[#93a2ba]"
                                style="
                                    border: 1px dashed #3a4a66;
                                    background: transparent;
                                "
                                >ROUTINE</span
                            >
                            <span v-if="p.override" class="chip text-[#f4b740]"
                                >OVERRIDE</span
                            >
                            <span v-if="p.auto" class="chip text-[#93a2ba]"
                                >TIMER</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-for="w in warnings"
                :key="w"
                class="notice-ember flex items-center gap-[10px] px-3 py-[10px] text-[14px] leading-[1.35]"
            >
                <span class="flex-none text-[#ef7b45]"
                    ><Icon :paths="ICONS.warn" :size="18" /></span
                >{{ w }}
            </div>
        </template>

        <button
            v-if="me"
            type="button"
            class="btn"
            :class="
                me.ready ? 'btn-outline' : done ? 'btn-primary' : 'btn-ghost'
            "
            @click="emit('act', 'ready', { ready: !me.ready })"
        >
            {{
                me.ready
                    ? `Waiting · ${readyCount} of ${game.players.length}`
                    : 'To the departure check'
            }}
        </button>
    </div>
</template>
