<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, toRef } from 'vue';
import Flaps from '@/components/game/Flaps.vue';
import Icon from '@/components/game/Icon.vue';
import { ICONS } from '@/components/game/icons';
import StationStrip from '@/components/game/StationStrip.vue';
import { dept, effectList } from '@/game/departments';
import type { StationState } from '@/game/types';
import { useClock } from '@/game/useClock';
import { useLive } from '@/game/useLive';

/*
 * The station board, for screen-sharing. It only ever gets the public table,
 * so it can't show a secret even by accident.
 */
const props = defineProps<{ state: StationState }>();

const lobby = computed(() => props.state.lobby);
const game = computed(() => props.state.game);
const { refresh } = useLive(lobby.value.code);

const clock = useClock(
    toRef(() => game.value?.deadline ?? null),
    toRef(() => props.state.serverNow),
    toRef(() => (game.value?.paused ? game.value.remaining : null)),
    () => refresh(),
);

const live = computed(
    () => game.value !== null && game.value.phase !== 'finished',
);
const name = (id: string | null | undefined) =>
    game.value?.players.find((p) => p.id === id)?.name ?? '';
const inPlay = computed(
    () => game.value?.departments.map((d) => d.key as string) ?? [],
);
const phaseName = computed(
    () =>
        ({
            boarding: 'Boarding',
            dawn: 'Dawn',
            day: 'Day',
            reveal: 'Reveal',
            dusk: 'Dusk',
            departure: 'Departure',
            night: 'Night',
            vote: 'Detain vote',
            arrival: 'Arrival vote',
            finished: 'Last stop',
        })[game.value?.phase ?? 'boarding'],
);
const dark = computed(() =>
    ['dusk', 'departure', 'night', 'vote'].includes(game.value?.phase ?? ''),
);
const picked = computed(
    () => game.value?.players.filter((p) => p.status === 'picked').length ?? 0,
);
const green = computed(
    () => game.value?.departments.every((d) => d.atLine) ?? false,
);
const daysLeft = computed(() => {
    const g = game.value;
    if (!g) {
        return 0;
    }

    return Math.max(
        0,
        g.days -
            g.day +
            (['boarding', 'dawn', 'day', 'reveal', 'dusk'].includes(g.phase)
                ? 1
                : 0),
    );
});
const showToday = computed(
    () =>
        game.value &&
        game.value.today &&
        ['reveal', 'night', 'vote'].includes(game.value.phase),
);
const recentLog = computed(() =>
    (game.value?.log ?? [])
        .filter((e) =>
            [
                'signal',
                'run',
                'cord',
                'tailwind',
                'nominate',
                'vote',
                'veto',
                'arrival',
                'result',
            ].includes(e.kind),
        )
        .slice(-6)
        .reverse(),
);
const inviteUrl = computed(
    () => `${window.location.origin}/board/${lobby.value.code}`,
);
</script>

<template>
    <Head :title="`Station board · ${lobby.code}`" />
    <div
        class="ls-root flex min-h-dvh flex-col gap-7 px-6 py-8 lg:px-12"
        :style="dark ? 'background:#070b14' : ''"
    >
        <div class="flex flex-wrap items-center gap-7">
            <div class="flex flex-col gap-[6px]">
                <div class="label">Northern Lantern No. 9 · Departure</div>
                <div class="disp text-[52px] leading-[.9] font-black">
                    Last <span class="text-[#f4b740]">Stop</span>
                </div>
            </div>
            <div class="flex-1" />
            <div v-if="game" class="flex items-center gap-[6px]">
                <div class="label mr-2">Days left</div>
                <Flaps :text="daysLeft" :pad="2" size="md" />
            </div>
            <div
                v-if="live"
                class="panel flex items-center gap-3 px-[18px] py-3"
            >
                <span class="text-[#e8eef5]"
                    ><Icon :paths="dark ? ICONS.moon : ICONS.sun" :size="28"
                /></span>
                <div class="disp text-[30px] font-extrabold">
                    {{ phaseName }}
                </div>
                <div class="mono text-[30px] font-semibold text-[#f4b740]">
                    {{ game!.paused ? 'PAUSED' : clock.label.value }}
                </div>
            </div>
            <div class="flex flex-col items-end gap-1">
                <div class="label">Boarding code</div>
                <div class="mono text-[26px] font-semibold tracking-[.2em]">
                    {{ lobby.code }}
                </div>
            </div>
        </div>

        <!-- Lobby, before the first trip -->
        <template v-if="!game">
            <div
                class="flex flex-1 flex-col items-center justify-center gap-6 text-center"
            >
                <div class="label">Board with the code, or open</div>
                <div class="mono text-[20px] text-[#8fd3f0]">
                    {{ inviteUrl }}
                </div>
                <Flaps :text="lobby.code" size="lg" />
                <div class="flex flex-wrap justify-center gap-3">
                    <span
                        v-for="s in lobby.seats"
                        :key="s.id"
                        class="rounded-full border px-4 py-2 text-[18px]"
                        :style="
                            s.ready
                                ? 'border-color:#F4B740;color:#F4B740'
                                : 'border-color:#24324A'
                        "
                        >{{ s.name }}{{ s.ready ? ' · ready' : '' }}</span
                    >
                </div>
                <div class="text-[18px] text-[#b7c3d6]">
                    {{ lobby.seats.length }} aboard ·
                    {{ lobby.readyCount }} ready · waiting for
                    {{ lobby.hostName }} to depart
                </div>
            </div>
        </template>

        <template v-else>
            <div class="flex flex-col gap-3 px-5">
                <div class="label flex justify-between">
                    <span class="text-[#8fd3f0]"
                        >Station {{ game.leg }} of {{ game.legs }} · line
                        {{ game.line }}</span
                    >
                    <span v-if="game.weather.length"
                        >Weather today:
                        {{ game.weather.map((w) => w.name).join(' · ') }}</span
                    >
                </div>
                <StationStrip
                    :stations="game.stations"
                    :leg="game.leg"
                    big
                    names
                />
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-9 lg:flex-row">
                <div
                    class="flex flex-col gap-[14px] rounded-2xl border border-[#24324a] bg-[#0e1626] p-[22px] lg:w-[480px]"
                >
                    <div class="flex items-center justify-between">
                        <div class="label">Signal board</div>
                        <div class="flex items-center gap-2">
                            <span class="lamp" :class="green ? 'off' : 'red'" />
                            <span
                                class="lamp"
                                :class="green ? 'green' : 'off'"
                            />
                            <span
                                class="mono text-[14px] font-semibold tracking-[.12em]"
                                :style="{
                                    color: green ? '#8EE6B8' : '#FF8E92',
                                }"
                                >{{ green ? 'GREEN' : 'RED' }}</span
                            >
                        </div>
                    </div>
                    <div class="flex min-h-[260px] flex-1 gap-[14px]">
                        <div
                            v-for="d in game.departments"
                            :key="d.key"
                            class="flex flex-1 flex-col items-center gap-[10px]"
                        >
                            <div class="mono text-[34px] font-semibold">
                                {{ d.score }}
                            </div>
                            <div
                                class="relative w-full max-w-[64px] flex-1 overflow-hidden rounded-md bg-[#1b263a]"
                                :class="{ 'crisis-tile': d.crisis }"
                            >
                                <div
                                    class="absolute right-0 bottom-0 left-0"
                                    :style="{
                                        height: `${(game.crisisAt / game.scoreMax) * 100}%`,
                                        background:
                                            'repeating-linear-gradient(-45deg, rgba(229,72,77,.5) 0 4px, transparent 4px 8px)',
                                    }"
                                />
                                <div
                                    class="absolute right-0 bottom-0 left-0 transition-all duration-700"
                                    :style="{
                                        height: `${(d.score / game.scoreMax) * 100}%`,
                                        background: dept(d.key).color,
                                        opacity: 0.9,
                                    }"
                                />
                                <div
                                    class="absolute right-0 left-0 h-[3px]"
                                    :style="{
                                        bottom: `${(game.line / game.scoreMax) * 100}%`,
                                        background: d.atLine
                                            ? '#5CC8A0'
                                            : '#E8EEF5',
                                    }"
                                />
                            </div>
                            <div
                                class="mono text-[13px] font-semibold"
                                :style="{ color: dept(d.key).color }"
                            >
                                {{ d.code }}
                            </div>
                        </div>
                    </div>
                    <div class="text-[14px] text-[#93a2ba]">
                        The bar across each column is the line ({{
                            game.line
                        }}). Every department must reach it for a green signal.
                        Striped zone = crisis.
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div
                            class="rounded-[10px] border border-[#5a2228] bg-[#120a0d] px-4 py-3"
                        >
                            <div class="label text-[#ff8e92]">Cord pulls</div>
                            <div class="mono text-[28px] font-semibold">
                                {{ game.cordLeft }}
                                <span class="text-[16px] text-[#93a2ba]"
                                    >/ {{ game.cordPulls }} left</span
                                >
                            </div>
                        </div>
                        <div
                            class="rounded-[10px] border border-[#24324a] bg-[#0b1220] px-4 py-3"
                        >
                            <div class="label">Next run</div>
                            <div
                                class="mono text-[22px] font-semibold"
                                :class="game.freeRun ? 'text-[#f4b740]' : ''"
                            >
                                {{
                                    game.freeRun
                                        ? 'Free'
                                        : `−${game.runCost} each`
                                }}
                            </div>
                        </div>
                    </div>
                    <div v-if="game.pulls.length" class="flex flex-col gap-2">
                        <div class="label text-[#ff8e92]">Clue history</div>
                        <div
                            v-for="(pull, i) in game.pulls"
                            :key="i"
                            class="text-[16px] text-[#f6e3e4]"
                        >
                            Day {{ pull.day }}:
                            {{
                                pull.clue.length
                                    ? pull.clue.map((id) => name(id)).join(', ')
                                    : 'no clue'
                            }}
                        </div>
                    </div>
                </div>

                <div
                    class="flex min-w-0 flex-1 flex-col gap-[14px] rounded-2xl border border-[#24324a] bg-[#05080f] p-[22px]"
                >
                    <template v-if="game.phase === 'finished' && game.reveal">
                        <div class="label">Last stop</div>
                        <div
                            class="disp text-[64px] leading-[.9] font-black"
                            :class="
                                game.winner === 'crew'
                                    ? 'text-[#f4b740]'
                                    : 'text-[#ff6b70]'
                            "
                        >
                            {{
                                game.cause === 'ended'
                                    ? 'Trip ended'
                                    : game.winner === 'crew'
                                      ? 'Crew wins'
                                      : 'Traitor wins'
                            }}
                        </div>
                        <div class="text-[22px]">
                            The
                            {{
                                game.reveal.traitors.length > 1
                                    ? 'traitors were'
                                    : 'traitor was'
                            }}
                            <b class="text-[#ff6b70]">{{
                                game.reveal.traitors
                                    .map((id) => name(id))
                                    .join(' and ')
                            }}</b
                            >.
                        </div>
                        <div
                            v-for="(pull, i) in game.reveal.pulls"
                            :key="i"
                            class="text-[18px] text-[#e3c6c8]"
                        >
                            Day {{ pull.day }}: {{ name(pull.by) }} pulled the
                            cord.
                        </div>
                    </template>

                    <template v-else-if="game.phase === 'arrival'">
                        <div class="label">Last Stop · arrival vote</div>
                        <div class="disp text-[48px] leading-none font-black">
                            {{ game.players.filter((p) => p.accused).length }}
                            of {{ game.players.length }} have named someone
                        </div>
                        <div class="text-[18px] text-[#b7c3d6]">
                            Votes are revealed together. Most votes on a traitor
                            and the crew wins.
                        </div>
                    </template>

                    <template
                        v-else-if="
                            (game.phase === 'dusk' ||
                                game.phase === 'departure') &&
                            game.dusk
                        "
                    >
                        <div class="label">
                            Dusk · the departure check · line
                            {{ game.dusk.line }}
                        </div>
                        <div
                            class="disp text-[64px] leading-[.9] font-black"
                            :class="
                                game.phase === 'dusk' ||
                                game.dusk.outcome === 'ran'
                                    ? 'text-[#8ee6b8]'
                                    : 'text-[#ff6b70]'
                            "
                        >
                            {{
                                game.phase === 'dusk'
                                    ? 'Signal green'
                                    : game.dusk.outcome === 'ran'
                                      ? game.dusk.arrived
                                          ? 'Last Stop'
                                          : 'Train departs'
                                      : game.dusk.outcome === 'pulled'
                                        ? 'Cord pulled'
                                        : 'Signal red'
                            }}
                        </div>
                        <div class="text-[22px] text-[#c5d0e0]">
                            <template v-if="game.phase === 'dusk'"
                                >Waiting for the platform.</template
                            >
                            <template v-else-if="game.dusk.outcome === 'ran'"
                                >The train runs to
                                {{ game.stations[game.leg]?.name }}.
                                {{
                                    game.dusk.free
                                        ? 'Tailwind: the run was free.'
                                        : `Every department paid ${game.runCost}.`
                                }}</template
                            >
                            <template v-else-if="game.dusk.outcome === 'pulled'"
                                >One of
                                <b class="text-[#ff6b70]">{{
                                    game.dusk.clue
                                        .map((id) => name(id))
                                        .join(', ') || 'nobody named'
                                }}</b>
                                pulled it.</template
                            >
                            <template v-else
                                >Not every department reached the
                                line.</template
                            >
                        </div>
                    </template>

                    <template v-else-if="showToday && game.today">
                        <div class="label">Day {{ game.today.day }} picks</div>
                        <table class="mono w-full border-collapse text-[17px]">
                            <thead>
                                <tr
                                    class="text-[11px] tracking-[.14em] text-[#93a2ba]"
                                >
                                    <th
                                        class="border-b border-[#24324a] py-2 text-left"
                                    >
                                        PASSENGER
                                    </th>
                                    <th
                                        class="border-b border-[#24324a] py-2 text-left"
                                    >
                                        DEPT
                                    </th>
                                    <th
                                        class="border-b border-[#24324a] py-2 text-left"
                                    >
                                        TASK
                                    </th>
                                    <th
                                        class="border-b border-[#24324a] py-2 text-right"
                                    >
                                        EFFECT
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="p in game.today.picks.filter(
                                        (x) => x.card,
                                    )"
                                    :key="p.player"
                                    class="text-[#f4b740]"
                                >
                                    <td
                                        class="border-b border-[#111a2b] py-[10px] text-[#e8eef5] uppercase"
                                    >
                                        {{ name(p.player) }}
                                    </td>
                                    <td
                                        class="border-b border-[#111a2b] py-[10px]"
                                        :style="{
                                            color: dept(p.card!.department)
                                                .color,
                                        }"
                                    >
                                        {{ dept(p.card!.department).code }}
                                    </td>
                                    <td
                                        class="border-b border-[#111a2b] py-[10px] uppercase"
                                    >
                                        {{ p.card!.name
                                        }}<span
                                            v-if="p.override"
                                            class="ml-2 text-[#ff8e92]"
                                            >OVERRIDE</span
                                        >
                                    </td>
                                    <td
                                        class="border-b border-[#111a2b] py-[10px] text-right"
                                    >
                                        <span
                                            v-for="f in effectList(
                                                p.card!.effects,
                                                inPlay,
                                            )"
                                            :key="f.key"
                                            class="ml-3"
                                            :class="{ dn: f.value < 0 }"
                                            >{{ f.label }}</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <template v-else-if="game.phase === 'day'">
                        <div class="label">Day {{ game.day }} · picking</div>
                        <div class="disp text-[48px] leading-none font-black">
                            {{ picked }} of {{ game.players.length }} locked in
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <span
                                v-for="p in game.players"
                                :key="p.id"
                                class="rounded-full border px-4 py-2 text-[18px]"
                                :style="
                                    p.status === 'picked'
                                        ? 'border-color:#F4B740;color:#F4B740'
                                        : p.status === 'escort' ||
                                            p.status === 'vetoed'
                                          ? 'border-color:#E5484D;color:#FF8E92'
                                          : 'border-color:#24324A;color:#93A2BA'
                                "
                                >{{ p.name
                                }}{{ p.detained ? ' · detained' : '' }}</span
                            >
                        </div>
                    </template>

                    <template v-else>
                        <div class="label">
                            {{
                                game.phase === 'boarding'
                                    ? 'Boarding'
                                    : `Dawn of day ${game.day}`
                            }}
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <div
                                v-for="(w, i) in game.weather"
                                :key="i"
                                class="wcard flex min-w-[200px] flex-col gap-2 p-4"
                            >
                                <div
                                    class="disp text-[28px] leading-none font-black"
                                >
                                    {{ w.name }}
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="f in effectList(
                                            w.effects,
                                            inPlay,
                                        )"
                                        :key="f.key"
                                        class="chip"
                                        :class="{ dn: f.value < 0 }"
                                        >{{ f.label }}</span
                                    >
                                    <span
                                        v-if="w.special === 'free_run'"
                                        class="chip text-[#f4b740]"
                                        >NEXT RUN FREE</span
                                    >
                                </div>
                            </div>
                        </div>
                    </template>

                    <div class="flex-1" />
                    <div
                        v-if="game.phase === 'vote' && game.vote"
                        class="flex flex-col gap-3 rounded-[10px] border border-[#5a2228] bg-[#120a0d] px-4 py-[14px]"
                    >
                        <div class="text-[22px] text-[#f6e3e4]">
                            <b class="text-[#ff6b70]">{{
                                name(game.vote.nominator)
                            }}</b>
                            nominated
                            <b class="text-[#ff6b70]">{{
                                name(game.vote.nominee)
                            }}</b>
                            to be detained.
                        </div>
                        <div class="text-[16px] text-[#e3c6c8]">
                            {{ game.players.filter((p) => p.voted).length }} of
                            {{ game.players.length - 1 }} votes sealed. Votes
                            are revealed together.
                        </div>
                    </div>
                    <div
                        v-if="recentLog.length && game.phase !== 'finished'"
                        class="flex flex-col gap-2 rounded-[10px] border border-[#24324a] bg-[#0b1220] px-4 py-[14px]"
                    >
                        <div class="label">Log</div>
                        <div
                            v-for="(e, i) in recentLog"
                            :key="i"
                            class="text-[16px]"
                            :class="
                                ['cord', 'vote', 'nominate', 'veto'].includes(
                                    e.kind,
                                )
                                    ? 'text-[#ff8e92]'
                                    : 'text-[#c5d0e0]'
                            "
                        >
                            <span class="mono mr-2 text-[12px] text-[#5e6e88]"
                                >D{{ e.day }}</span
                            >{{ e.text }}
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
