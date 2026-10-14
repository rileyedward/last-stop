<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, toRef } from 'vue';
import Flaps from '@/components/game/Flaps.vue';
import Icon from '@/components/game/Icon.vue';
import { ICONS } from '@/components/game/icons';
import Rail from '@/components/game/Rail.vue';
import { dept, effectList, signed } from '@/game/departments';
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
const inPlay = computed(() => [
    ...(game.value?.departments.map((d) => d.key as string) ?? []),
    'distance',
]);
const phaseName = computed(
    () =>
        ({
            boarding: 'Boarding',
            dawn: 'Dawn',
            day: 'Day',
            reveal: 'Reveal',
            night: 'Night',
            vote: 'Vote',
            inquiry: 'Inquiry',
            finished: 'Arrived',
        })[game.value?.phase ?? 'boarding'],
);
const picked = computed(
    () => game.value?.players.filter((p) => p.status === 'picked').length ?? 0,
);
const showToday = computed(
    () =>
        game.value &&
        game.value.today &&
        ['reveal', 'night', 'vote', 'inquiry', 'finished'].includes(
            game.value.phase,
        ),
);
const tonight = computed(() =>
    game.value?.lastBrake && game.value.lastBrake.day === game.value.day
        ? game.value.lastBrake
        : null,
);
const inviteUrl = computed(
    () => `${window.location.origin}/board/${lobby.value.code}`,
);
</script>

<template>
    <Head :title="`Station board · ${lobby.code}`" />
    <div class="ls-root flex min-h-dvh flex-col gap-7 px-6 py-8 lg:px-12">
        <div class="flex flex-wrap items-center gap-7">
            <div class="flex flex-col gap-[6px]">
                <div class="label">Northern Lantern No. 9 · Winter route</div>
                <div class="disp text-[52px] leading-[.9] font-black">
                    Last <span class="text-[#f4b740]">Stop</span>
                </div>
            </div>
            <div class="flex-1" />
            <div v-if="game" class="flex items-center gap-[6px]">
                <div class="label mr-2">Day</div>
                <Flaps :text="game.day" :pad="2" size="md" />
                <div class="mono ml-2 text-[18px] text-[#93a2ba]">
                    / {{ game.days }}
                </div>
            </div>
            <div
                v-if="live"
                class="panel flex items-center gap-3 px-[18px] py-3"
            >
                <span class="text-[#e8eef5]"
                    ><Icon
                        :paths="
                            game!.phase === 'night' || game!.phase === 'vote'
                                ? ICONS.moon
                                : ICONS.sun
                        "
                        :size="28"
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
            <div class="relative flex flex-col gap-3 px-5">
                <div class="label flex justify-between">
                    <span class="text-[#8fd3f0]"
                        >{{ game.distance }} of {{ game.route }} travelled</span
                    >
                    <span v-if="game.weather.length"
                        >Weather today:
                        {{ game.weather.map((w) => w.name).join(' · ') }}</span
                    >
                </div>
                <Rail :distance="game.distance" :route="game.route" big />
                <div class="label flex justify-between text-[#e8eef5]">
                    <span>Hollow Pines</span><span>Last Stop</span>
                </div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-9 lg:flex-row">
                <div
                    class="flex flex-col gap-[14px] rounded-2xl border border-[#24324a] bg-[#0e1626] p-[22px] lg:w-[480px]"
                >
                    <div class="label">Departments</div>
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
                                        height: `${(game.crisisAt / 10) * 100}%`,
                                        background:
                                            'repeating-linear-gradient(-45deg, rgba(229,72,77,.5) 0 4px, transparent 4px 8px)',
                                    }"
                                />
                                <div
                                    class="absolute right-0 bottom-0 left-0 transition-all duration-700"
                                    :style="{
                                        height: `${d.score * 10}%`,
                                        background: dept(d.key).color,
                                        opacity: 0.9,
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
                        Striped zone = crisis. Two departments in it and the
                        traitor wins.
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
                            The traitor was
                            <b class="text-[#ff6b70]">{{
                                game.reveal.traitors
                                    .map((id) => name(id))
                                    .join(' and ')
                            }}</b
                            >.
                            {{
                                game.reveal.guesses.filter((g) => g.correct)
                                    .length
                            }}
                            of {{ game.reveal.guesses.length }} crew named them.
                        </div>
                    </template>
                    <template v-else-if="showToday && game.today">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <div class="label">
                                Day {{ game.today.day }} departures
                            </div>
                            <div class="mono text-[13px] text-[#93a2ba]">
                                DISTANCE TODAY
                                {{ signed(game.today.dayDistance) }} · BASE
                                {{ signed(game.today.base) }} · WEATHER
                                {{ signed(game.today.weatherDistance) }} · CARDS
                                {{ signed(game.today.cardDistance) }}
                            </div>
                        </div>
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
                                                p.card!.distance,
                                                inPlay,
                                            )"
                                            :key="f.key"
                                            class="ml-3"
                                            :class="{ dn: f.value < 0 }"
                                            >{{
                                                f.key === 'distance'
                                                    ? `DIST ${signed(f.value)}`
                                                    : f.label
                                            }}</span
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
                                >{{ p.name }}</span
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
                                            w.distance,
                                            inPlay,
                                        )"
                                        :key="f.key"
                                        class="chip"
                                        :class="{ dn: f.value < 0 }"
                                        >{{
                                            f.key === 'distance'
                                                ? `DIST ${signed(f.value)}`
                                                : f.label
                                        }}</span
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
                                name(game.vote.puller)
                            }}</b>
                            pulled the brake and named
                            <b class="text-[#ff6b70]">{{
                                name(game.vote.nominee)
                            }}</b
                            >.
                        </div>
                        <div class="text-[16px] text-[#e3c6c8]">
                            {{ game.players.filter((p) => p.voted).length }} of
                            {{ game.players.length - 1 }} votes sealed. Votes
                            are revealed together.
                        </div>
                    </div>
                    <div
                        v-else-if="game.phase !== 'finished'"
                        class="flex items-center gap-[14px] rounded-[10px] border border-[#5a2228] bg-[#120a0d] px-4 py-[14px]"
                    >
                        <div
                            class="hazard h-[18px] w-[70px] flex-none rounded"
                        />
                        <div class="text-[17px] text-[#f6e3e4]">
                            <template v-if="tonight">
                                Tonight {{ name(tonight.puller) }} pulled the
                                brake on {{ name(tonight.nominee) }}:
                                {{ tonight.passed ? 'carried' : 'failed' }}
                                {{ tonight.yes }} to {{ tonight.no }}.
                            </template>
                            <template
                                v-else-if="game.day < game.brake.fromNight"
                                >The emergency brake unlocks on night
                                {{ game.brake.fromNight }}.</template
                            >
                            <template v-else-if="game.brake.trainLeft === 0"
                                >The train has used all its brake
                                pulls.</template
                            >
                            <template v-else>
                                Emergency brake is
                                <b>{{
                                    game.brake.open && game.phase === 'night'
                                        ? 'unlocked'
                                        : 'available at night'
                                }}</b
                                >.
                                {{
                                    game.brake.trainLeft > 0
                                        ? `${game.brake.trainLeft} pulls left this trip.`
                                        : ''
                                }}
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
