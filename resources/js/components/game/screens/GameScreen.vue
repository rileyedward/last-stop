<script setup lang="ts">
import { computed, ref, toRef, watch } from 'vue';
import { boardActions } from '@/game/actions';
import { dept } from '@/game/departments';
import type { BoardState, Phase } from '@/game/types';
import { useClock } from '@/game/useClock';
import Avatar from '../Avatar.vue';
import DeptRow from '../DeptRow.vue';
import Flaps from '../Flaps.vue';
import Icon from '../Icon.vue';
import { ICONS } from '../icons';
import LogSheet from '../LogSheet.vue';
import Sheet from '../Sheet.vue';
import StationStrip from '../StationStrip.vue';
import Ticket from '../Ticket.vue';
import ArrivalPanel from './ArrivalPanel.vue';
import CrewPanel from './CrewPanel.vue';
import DuskPanel from './DuskPanel.vue';
import HandPanel from './HandPanel.vue';
import NightPanel from './NightPanel.vue';
import RevealPanel from './RevealPanel.vue';
import SignalBoard from './SignalBoard.vue';
import VotePanel from './VotePanel.vue';

const props = defineProps<{ state: BoardState; refresh: () => void }>();

const game = computed(() => props.state.game!);
const lobby = computed(() => props.state.lobby);
const me = computed(() => game.value.me!);
const actions = computed(() => boardActions(lobby.value.code));
const myName = computed(
    () =>
        game.value.players.find((p) => p.isMe)?.name ??
        props.state.me.name ??
        '',
);
const seatNo = computed(() => game.value.players.findIndex((p) => p.isMe) + 1);

const clock = useClock(
    toRef(() => game.value.deadline),
    toRef(() => props.state.serverNow),
    toRef(() => (game.value.paused ? game.value.remaining : null)),
    () => props.refresh(),
);

type Tab = 'train' | 'hand' | 'crew';
const tab = ref<Tab>(game.value.phase === 'day' ? 'hand' : 'train');
watch(
    () => game.value.phase,
    (phase: Phase) => {
        tab.value = phase === 'day' ? 'hand' : 'train';
    },
);

const sheet = ref<'log' | 'menu' | 'ticket' | null>(null);
const confirmEnd = ref(false);
const removing = ref<string | null>(null);
const preview = ref<Record<string, number>>({});

function act(
    action: string,
    data: Record<string, string | boolean> = {},
): void {
    actions.value.act(action, data);
}

const steps = ['dawn', 'day', 'dusk', 'night'] as const;
const stepIndex = computed(() => {
    const phase =
        (
            {
                reveal: 'day',
                departure: 'dusk',
                vote: 'night',
            } as Record<string, string>
        )[game.value.phase] ?? game.value.phase;

    return steps.indexOf(phase as (typeof steps)[number]);
});

const timerColor = computed(
    () =>
        ({
            dawn: '#8FD3F0',
            day: '#F4B740',
            dusk: '#8EE6B8',
            departure: '#8EE6B8',
            night: '#E8EEF5',
            vote: '#FF8E92',
            arrival: '#F4B740',
        })[game.value.phase as string] ?? '#F4B740',
);

const deltas = computed(() => {
    if (game.value.phase !== 'dawn') {
        return {};
    }

    return Object.fromEntries(
        game.value.departments.map((d) => [d.key, d.score - d.atDawn]),
    );
});

const latest = computed(
    () => game.value.log[game.value.log.length - 1] ?? null,
);
const latestLine = computed(() => {
    const e = latest.value;
    if (!e) {
        return '';
    }
    if (game.value.phase === 'day') {
        const locked = game.value.players.filter(
            (p) => p.status === 'picked',
        ).length;

        return `Day ${game.value.day} · ${locked} of ${game.value.players.length} locked in`;
    }

    return e.kind === 'weather' ? `${e.text} at dawn` : e.text;
});
const latestRed = computed(() =>
    ['cord', 'nominate', 'vote', 'veto'].includes(latest.value?.kind ?? ''),
);
const latestDot = computed(() =>
    latestRed.value
        ? '#E5484D'
        : latest.value?.kind === 'weather'
          ? '#8FD3F0'
          : latest.value?.kind === 'night'
            ? '#E8EEF5'
            : '#F4B740',
);

const readyCount = computed(
    () => game.value.players.filter((p) => p.ready).length,
);
const handNeedsMe = computed(
    () =>
        game.value.phase === 'day' &&
        (me.value.pickState === 'none' ||
            me.value.pickState === 'repick' ||
            (me.value.escorting?.pickState === 'pending' &&
                me.value.escorting.decision === null)),
);

function endTrip(): void {
    confirmEnd.value = false;
    sheet.value = null;
    actions.value.lobby('end');
}

function remove(id: string): void {
    removing.value = null;
    sheet.value = null;
    actions.value.lobby('kick', { player: id });
}
</script>

<template>
    <div class="ls-phone">
        <header
            class="sticky top-0 z-20 flex flex-none flex-col gap-3 border-b border-[#24324a] bg-[#0e1626] px-4 pt-[14px] pb-3"
            :style="
                ['night', 'vote', 'dusk', 'departure'].includes(game.phase)
                    ? 'background:#0A101C'
                    : ''
            "
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex items-center gap-[2px]"
                    :aria-label="`Day ${game.day} of ${game.days}`"
                >
                    <Flaps :text="game.day" :pad="2" />
                </div>
                <div v-if="stepIndex >= 0" class="steps">
                    <div
                        v-for="(s, i) in steps"
                        :key="s"
                        class="step"
                        :class="{
                            done: i < stepIndex,
                            on: i === stepIndex,
                            night: s === 'night',
                        }"
                    >
                        <i />{{ s.toUpperCase() }}
                    </div>
                </div>
                <div
                    v-else
                    class="mono flex-1 text-[11px] tracking-[.14em] text-[#93a2ba]"
                >
                    {{
                        game.phase === 'boarding' ? 'BOARDING' : 'ARRIVAL VOTE'
                    }}
                </div>
                <div
                    class="mono w-[44px] text-right text-[15px] font-semibold"
                    :style="{ color: game.paused ? '#93A2BA' : timerColor }"
                >
                    {{ game.paused ? 'PAUSED' : clock.label.value }}
                </div>
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#16213a] text-[#b7c3d6]"
                    aria-label="Menu"
                    @click="sheet = 'menu'"
                >
                    <Icon :paths="ICONS.menu" :size="18" />
                </button>
            </div>
            <div class="flex items-center gap-[10px]">
                <div class="flex-1">
                    <StationStrip :stations="game.stations" :leg="game.leg" />
                </div>
                <div class="mono text-[12px] text-[#8fd3f0]">
                    LINE {{ game.line }}
                </div>
            </div>
            <DeptRow
                :departments="game.departments"
                :crisis-at="game.crisisAt"
                :max="game.scoreMax"
                :line="game.line"
                :deltas="deltas"
                :previews="
                    tab === 'hand' && game.phase === 'day' ? preview : {}
                "
            />
        </header>

        <div
            v-if="game.paused"
            class="notice-amber m-3 mb-0 flex items-center gap-2 px-3 py-[10px] text-[14px]"
        >
            <Icon :paths="ICONS.pause" :size="16" /> {{ lobby.hostName }} paused
            the game. The clock is stopped.
        </div>

        <main class="flex min-h-0 flex-1 flex-col gap-3 p-4">
            <template v-if="game.phase === 'boarding'">
                <div class="flex flex-col gap-[6px]">
                    <div class="label">Before departure</div>
                    <h1 class="disp m-0 text-[40px] leading-[.9] font-black">
                        Your ticket
                    </h1>
                    <div class="text-[15px] leading-[1.4] text-[#b7c3d6]">
                        Check nobody can see your screen, then open it.
                    </div>
                </div>
                <Ticket :me="me" :game="game" :name="myName" :seat="seatNo" />
                <button
                    type="button"
                    class="btn"
                    :class="me.ready ? 'btn-outline' : 'btn-primary'"
                    @click="act('ready', { ready: !me.ready })"
                >
                    {{
                        me.ready
                            ? `Ready · ${readyCount} of ${game.players.length}`
                            : "I've seen my ticket"
                    }}
                </button>
                <div class="text-center text-[13px] text-[#93a2ba]">
                    You can reopen your ticket any time from the menu.
                </div>
            </template>

            <VotePanel
                v-else-if="game.phase === 'vote'"
                :game="game"
                :clock="clock.label.value"
                @act="act"
            />

            <ArrivalPanel
                v-else-if="game.phase === 'arrival'"
                :game="game"
                :clock="clock.label.value"
                @act="act"
            />

            <template v-else-if="tab === 'train'">
                <RevealPanel
                    v-if="game.phase === 'reveal' && game.today"
                    :game="game"
                    @act="act"
                />
                <NightPanel
                    v-else-if="game.phase === 'night'"
                    :game="game"
                    @act="act"
                    @log="sheet = 'log'"
                />
                <DuskPanel
                    v-else-if="
                        (game.phase === 'dusk' || game.phase === 'departure') &&
                        game.dusk
                    "
                    :game="game"
                    :clock="clock.label.value"
                    @act="act"
                />
                <template v-else>
                    <SignalBoard :game="game" />
                    <button
                        v-if="game.phase === 'dawn'"
                        type="button"
                        class="btn"
                        :class="me.ready ? 'btn-outline' : 'btn-primary'"
                        @click="act('ready', { ready: !me.ready })"
                    >
                        {{
                            me.ready
                                ? `Waiting · ${readyCount} of ${game.players.length}`
                                : 'Draw your hand'
                        }}
                    </button>
                    <button
                        v-else-if="game.phase === 'day'"
                        type="button"
                        class="btn btn-primary"
                        @click="tab = 'hand'"
                    >
                        {{
                            handNeedsMe ? 'Back to your hand' : 'See your hand'
                        }}
                    </button>
                </template>
            </template>

            <template v-else-if="tab === 'hand'">
                <HandPanel
                    v-if="game.phase === 'day'"
                    :game="game"
                    @act="act"
                    @preview="(fx) => (preview = fx)"
                />
                <div v-else class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1">
                        <div
                            class="label"
                            :style="{ color: dept(me.department).color }"
                        >
                            {{ dept(me.department).name }}
                        </div>
                        <h1
                            class="disp m-0 text-[34px] leading-[.9] font-black"
                        >
                            Your hand
                        </h1>
                    </div>
                    <div
                        class="panel-dash p-4 text-[15px] leading-[1.45] text-[#b7c3d6]"
                    >
                        You draw {{ game.handSize }} cards when the day starts
                        ({{ game.reducedHand }} if you're escorting or your
                        department is in crisis).
                    </div>
                    <div
                        v-if="me.escorting"
                        class="notice-amber px-[14px] py-3 text-[14px]"
                    >
                        You're escorting {{ me.escorting.name }}. You'll see
                        their hand here during the day.
                    </div>
                    <div
                        v-if="me.detained"
                        class="notice-red px-[14px] py-3 text-[14px]"
                    >
                        You're detained for {{ me.detained }} more
                        {{ me.detained === 1 ? 'day' : 'days' }}.
                        {{ me.escortedBy }} escorts you.
                    </div>
                    <button
                        type="button"
                        class="btn btn-ghost"
                        @click="sheet = 'ticket'"
                    >
                        Open your ticket
                    </button>
                </div>
            </template>

            <CrewPanel v-else :game="game" />
        </main>

        <div
            class="sticky bottom-0 z-20 flex-none bg-[#0b1220]/95 pt-2 backdrop-blur"
        >
            <div class="px-3 pb-[10px]">
                <button
                    type="button"
                    class="logpill"
                    :class="{ red: latestRed }"
                    @click="sheet = 'log'"
                >
                    <span
                        class="live"
                        :style="{
                            background: latestDot,
                            boxShadow: `0 0 0 4px ${latestDot}2e`,
                        }"
                    />
                    <span class="flex-1 truncate">{{ latestLine }}</span>
                    <span
                        class="mono text-[11px] tracking-[.12em] text-[#93a2ba]"
                        >LOG</span
                    >
                    <Icon :paths="ICONS.chevronUp" :size="14" :stroke="2.4" />
                </button>
            </div>
            <nav class="tabs" aria-label="Game sections">
                <button
                    type="button"
                    class="tab"
                    :class="{ on: tab === 'train' }"
                    @click="tab = 'train'"
                >
                    <Icon :paths="ICONS.train" />TRAIN
                </button>
                <button
                    type="button"
                    class="tab"
                    :class="{ on: tab === 'hand' }"
                    @click="tab = 'hand'"
                >
                    <Icon :paths="ICONS.hand" />HAND<span
                        v-if="handNeedsMe && tab !== 'hand'"
                        class="dot-badge"
                    />
                </button>
                <button
                    type="button"
                    class="tab"
                    :class="{ on: tab === 'crew' }"
                    @click="tab = 'crew'"
                >
                    <Icon :paths="ICONS.crew" />CREW
                </button>
            </nav>
        </div>

        <LogSheet
            :open="sheet === 'log'"
            :log="game.log"
            @close="sheet = null"
        />

        <Sheet
            :open="sheet === 'ticket'"
            title="Your ticket"
            @close="sheet = null"
        >
            <Ticket :me="me" :game="game" :name="myName" :seat="seatNo" />
        </Sheet>

        <Sheet
            :open="sheet === 'menu'"
            title="Menu"
            @close="((sheet = null), (confirmEnd = false), (removing = null))"
        >
            <button
                type="button"
                class="btn btn-ghost btn-small"
                @click="sheet = 'ticket'"
            >
                <Icon :paths="ICONS.ticket" :size="16" />Your ticket
            </button>
            <a
                :href="`/board/${lobby.code}/station`"
                target="_blank"
                class="btn btn-ghost btn-small"
                ><Icon :paths="ICONS.screen" :size="16" />Station board</a
            >
            <div class="mono text-center text-[12px] text-[#93a2ba]">
                BOARDING CODE {{ lobby.code }}
            </div>
            <template v-if="lobby.isHost">
                <div class="label pt-2 text-[#f4b740]">
                    Host controls · you never see secrets
                </div>
                <button
                    v-if="!game.paused"
                    type="button"
                    class="btn btn-ghost btn-small"
                    @click="(actions.lobby('pause'), (sheet = null))"
                >
                    <Icon :paths="ICONS.pause" :size="16" />Pause the clock
                </button>
                <button
                    v-else
                    type="button"
                    class="btn btn-primary btn-small"
                    @click="(actions.lobby('resume'), (sheet = null))"
                >
                    <Icon :paths="ICONS.play" :size="16" />Resume
                </button>
                <div class="label pt-1">
                    Remove a player · their seat plays itself
                </div>
                <div class="flex flex-col gap-2">
                    <template
                        v-for="p in game.players.filter(
                            (x) => !x.isMe && !x.absent,
                        )"
                        :key="p.id"
                    >
                        <button
                            type="button"
                            class="seat"
                            @click="removing = removing === p.id ? null : p.id"
                        >
                            <Avatar :name="p.name" :size="26" /><span
                                class="flex-1"
                                >{{ p.name }}</span
                            >
                            <span class="text-[12px] text-[#ff8e92]"
                                >Remove</span
                            >
                        </button>
                        <button
                            v-if="removing === p.id"
                            type="button"
                            class="btn btn-danger btn-small"
                            @click="remove(p.id)"
                        >
                            Remove {{ p.name }} from the lobby
                        </button>
                    </template>
                </div>
                <button
                    v-if="!confirmEnd"
                    type="button"
                    class="btn btn-small"
                    style="
                        background: #1a0f13;
                        border: 1px solid #5a2228;
                        color: #ff8e92;
                    "
                    @click="confirmEnd = true"
                >
                    End the trip early
                </button>
                <div
                    v-else
                    class="notice-red flex flex-col gap-[10px] p-[14px]"
                >
                    <div class="text-[15px]">
                        End this trip now for everyone? Nobody wins and it can't
                        be undone.
                    </div>
                    <div class="flex gap-[10px]">
                        <button
                            type="button"
                            class="btn btn-ghost flex-1"
                            @click="confirmEnd = false"
                        >
                            Keep going
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger flex-1"
                            @click="endTrip"
                        >
                            End trip
                        </button>
                    </div>
                </div>
            </template>
        </Sheet>
    </div>
</template>
