<script setup lang="ts">
import { computed, ref } from 'vue';
import { boardActions } from '@/game/actions';
import { dept, effectList } from '@/game/departments';
import type { BoardState } from '@/game/types';
import Avatar from '../Avatar.vue';
import Sheet from '../Sheet.vue';
import WinsSheet from '../WinsSheet.vue';

const props = defineProps<{ state: BoardState }>();

const game = computed(() => props.state.game!);
const lobby = computed(() => props.state.lobby);
const reveal = computed(() => game.value.reveal!);
const actions = computed(() => boardActions(lobby.value.code));
const inPlay = computed(() =>
    game.value.departments.map((d) => d.key as string),
);

const sheet = ref<'hands' | 'wins' | null>(null);
const name = (id: string | null) =>
    game.value.players.find((p) => p.id === id)?.name ?? '';
const isMe = (id: string) =>
    game.value.players.find((p) => p.id === id)?.isMe ?? false;
const traitorNames = computed(() =>
    reveal.value.traitors.map((id) => name(id)).join(' and '),
);
const ended = computed(() => game.value.cause === 'ended');

const banner = computed(() => {
    if (ended.value) {
        return {
            title: 'Trip ended',
            line: `${lobby.value.hostName} ended the trip early. Nobody wins. The traitor was ${traitorNames.value}.`,
            stamp: 'ENDED',
        };
    }
    const was = `The ${reveal.value.traitors.length > 1 ? 'traitors were' : 'traitor was'} ${traitorNames.value}.`;
    if (game.value.winner === 'crew') {
        const why =
            {
                named: 'The train made Last Stop and the crew named a traitor',
                never_pulled:
                    'The crew named the wrong person, but the cord was never pulled',
                arrived: 'The train made Last Stop',
            }[game.value.cause as string] ?? 'The train made Last Stop';

        return { title: 'Crew wins', line: `${why}. ${was}`, stamp: 'ARRIVED' };
    }
    const why =
        {
            crisis: 'Two departments fell into crisis',
            clock: 'The train ran out of days before Last Stop',
            wrong_name:
                'The train arrived, but the crew named the wrong person',
            tie_vote: 'The train arrived, but the arrival vote tied',
        }[game.value.cause as string] ?? 'The traitor got away';

    return {
        title:
            reveal.value.traitors.length > 1 ? 'Traitors win' : 'Traitor wins',
        line: `${why}. ${was}`,
        stamp:
            game.value.cause === 'crisis'
                ? 'CRISIS'
                : game.value.cause === 'clock'
                  ? 'STRANDED'
                  : 'ESCAPED',
    };
});

const accusations = computed(() =>
    reveal.value.accusations.map((a) => ({
        ...a,
        correct:
            a.suspect !== null && reveal.value.traitors.includes(a.suspect),
    })),
);
const correct = computed(
    () => accusations.value.filter((a) => a.correct).length,
);
const detentions = computed(() =>
    reveal.value.days.reduce(
        (n, d) => n + d.nominations.filter((x) => x.passed).length,
        0,
    ),
);

function won(id: string): boolean {
    if (ended.value) {
        return false;
    }
    const traitor = reveal.value.traitors.includes(id);

    return game.value.winner === 'traitor' ? traitor : !traitor;
}

// Every hand, day by day
const dayIndex = ref(0);
const shownDay = computed(() => reveal.value.days[dayIndex.value]);
const focus = ref<string | null>(reveal.value.traitors[0] ?? null);

const traitorDept = (id: string) =>
    dept(game.value.players.find((p) => p.id === id)?.department);
</script>

<template>
    <div class="ls-phone gap-[14px] px-[18px] pt-6 pb-6">
        <div
            class="relative flex flex-col gap-[6px] overflow-hidden rounded-2xl p-[18px]"
            :style="
                game.winner === 'crew'
                    ? 'background:#F4B740;color:#1A1200'
                    : ended
                      ? 'background:#16213A;color:#E8EEF5'
                      : 'background:#1A0B0E;border:2px solid #E5484D;color:#F6E3E4'
            "
        >
            <div class="mono text-[11px] tracking-[.16em]">
                TRIP {{ lobby.trips }} · {{ lobby.code }} ·
                {{
                    ended
                        ? 'ENDED'
                        : game.winner === 'crew'
                          ? 'ARRIVED'
                          : 'STOPPED'
                }}
                DAY {{ game.day }}
            </div>
            <div
                class="disp text-[56px] leading-[.85] font-black"
                :style="game.winner === 'traitor' ? 'color:#FF6B70' : ''"
            >
                {{ banner.title }}
            </div>
            <div class="max-w-[280px] text-[15px]">{{ banner.line }}</div>
            <div
                class="absolute right-3 bottom-3 rotate-[-8deg] rounded border-[3px] px-[10px] py-[6px] font-[900] tracking-[.08em] opacity-80"
                style="
                    font-family: 'Big Shoulders Display', sans-serif;
                    font-size: 18px;
                    border-color: currentColor;
                "
            >
                {{ banner.stamp }}
            </div>
        </div>

        <div class="flex gap-[6px]">
            <div
                class="flex-1 rounded-[10px] bg-[#0b1220] px-[6px] py-[10px] text-center"
            >
                <div class="mono text-[18px] font-semibold text-[#8fd3f0]">
                    {{ reveal.leg }}/{{ game.legs }}
                </div>
                <div class="label mt-1 text-[9px]">Legs</div>
            </div>
            <div
                class="flex-1 rounded-[10px] bg-[#0b1220] px-[6px] py-[10px] text-center"
            >
                <div class="mono text-[18px] font-semibold">
                    {{ detentions }}
                </div>
                <div class="label mt-1 text-[9px]">Detained</div>
            </div>
            <div
                class="flex-1 rounded-[10px] bg-[#0b1220] px-[6px] py-[10px] text-center"
            >
                <div class="mono text-[18px] font-semibold text-[#ff8e92]">
                    {{ reveal.pulls.length }}
                </div>
                <div class="label mt-1 text-[9px]">Cord pulls</div>
            </div>
            <div
                class="flex-1 rounded-[10px] bg-[#0b1220] px-[6px] py-[10px] text-center"
            >
                <div class="mono text-[18px] font-semibold">
                    {{ correct }}/{{ accusations.length }}
                </div>
                <div class="label mt-1 text-[9px]">Named right</div>
            </div>
        </div>

        <div
            v-for="id in reveal.traitors"
            :key="id"
            class="flex items-center gap-[14px] rounded-[14px] border-2 border-[#e5484d] bg-[#1a0b0e] px-4 py-[14px]"
        >
            <div
                class="flex h-[54px] w-[54px] flex-none items-center justify-center rounded-full bg-[#f6e3e4] text-[22px] font-bold text-[#1a0b0e]"
            >
                {{ name(id)[0] }}
            </div>
            <div class="flex flex-1 flex-col gap-[2px]">
                <div class="mono text-[10px] tracking-[.14em] text-[#f09a9d]">
                    THE TRAITOR WAS
                </div>
                <div
                    class="disp text-[36px] leading-[.95] font-black text-[#ff6b70]"
                >
                    {{ isMe(id) ? 'You' : name(id) }}
                </div>
                <div class="text-[13px] text-[#f6e3e4]">
                    {{ traitorDept(id).name }} ·
                    {{
                        reveal.everDetained.includes(id)
                            ? 'detained at least once'
                            : 'never once detained'
                    }}
                    ·
                    {{
                        reveal.pulls.filter((p) => p.by === id).length === 0
                            ? 'never pulled the cord'
                            : `pulled the cord ${reveal.pulls.filter((p) => p.by === id).length}×`
                    }}
                </div>
            </div>
        </div>

        <div v-if="reveal.pulls.length" class="flex flex-col gap-[6px]">
            <div class="label">Every cord pull</div>
            <div
                v-for="(pull, i) in reveal.pulls"
                :key="i"
                class="rounded-[10px] border border-[#5a2228] bg-[#1a0f13] px-3 py-2 text-[14px] text-[#f6e3e4]"
            >
                Day {{ pull.day }}: <b>{{ name(pull.by) }}</b> pulled it.
                <span class="text-[#c9a3a6]">{{
                    pull.clue.length
                        ? `The clue named ${pull.clue.map((id) => name(id)).join(', ')}.`
                        : 'No clue.'
                }}</span>
            </div>
        </div>

        <div v-if="accusations.length" class="flex flex-col">
            <div class="label mb-1">Arrival vote: who everyone named</div>
            <div
                v-for="g in accusations"
                :key="g.id"
                class="flex items-center gap-[10px] border-b border-[#18233a] py-2 text-[15px]"
            >
                <b class="w-[70px] truncate">{{
                    isMe(g.id) ? 'You' : name(g.id)
                }}</b>
                <span class="flex-1 text-[#93a2ba]">{{
                    g.suspect ? `named ${name(g.suspect)}` : 'named nobody'
                }}</span>
                <span
                    class="mono rounded-[5px] px-[7px] py-1 text-[11px] tracking-[.1em]"
                    :style="
                        g.correct
                            ? 'background:#F4B740;color:#1A1200'
                            : 'background:#16213A;color:#93A2BA'
                    "
                >
                    {{ g.correct ? 'CORRECT' : 'MISS' }}
                </span>
            </div>
        </div>

        <div class="flex flex-col gap-[6px]">
            <div class="label">Everyone's ticket</div>
            <div
                v-for="p in game.players"
                :key="p.id"
                class="flex min-h-[46px] items-center gap-[10px] rounded-[10px] border px-3 py-2"
                :style="
                    p.traitor
                        ? 'border-color:#5A2228;background:#1A0B0E'
                        : p.isMe
                          ? 'border-color:#F4B740;background:#111A2B'
                          : 'border-color:#24324A;background:#111A2B'
                "
            >
                <Avatar :name="p.name" :me="p.isMe" />
                <div class="flex-1 text-[15px]">
                    <b>{{ p.isMe ? 'You' : p.name }}</b>
                    <span
                        class="mono ml-1 text-[11px]"
                        :style="{ color: dept(p.department).color }"
                        >{{ dept(p.department).code }}</span
                    >
                </div>
                <span
                    class="mono rounded-[5px] px-[7px] py-[5px] text-[11px] tracking-[.1em]"
                    :style="
                        p.traitor
                            ? 'background:#E5484D;color:#1A0B0E'
                            : 'background:#F3EDE0;color:#1A2230'
                    "
                >
                    {{ p.traitor ? 'TRAITOR' : 'CREW' }}
                </span>
                <span
                    class="mono w-10 text-right text-[12px]"
                    :class="won(p.id) ? 'text-[#f4b740]' : 'text-[#5e6e88]'"
                    >{{ won(p.id) ? '+1' : '—' }}</span
                >
            </div>
        </div>

        <div class="flex gap-2">
            <button
                type="button"
                class="btn btn-ghost btn-small flex-1"
                @click="sheet = 'hands'"
            >
                Every hand, day by day
            </button>
            <button
                type="button"
                class="btn btn-ghost btn-small flex-1"
                @click="sheet = 'wins'"
            >
                Wins table
            </button>
        </div>

        <div class="flex-1" />
        <div class="flex flex-col gap-2">
            <button
                type="button"
                class="btn btn-outline"
                @click="actions.lobby('back')"
            >
                Back to lobby
            </button>
            <div class="text-center text-[13px] text-[#93a2ba]">
                Everyone's Ready resets. Nothing starts until
                {{ lobby.hostName }} departs again.
            </div>
        </div>

        <Sheet
            :open="sheet === 'hands'"
            title="Every hand"
            @close="sheet = null"
        >
            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="btn btn-ghost w-12 flex-none"
                    :disabled="dayIndex === 0"
                    aria-label="Previous day"
                    @click="dayIndex--"
                >
                    ‹
                </button>
                <div class="flex flex-col items-center gap-1">
                    <div class="label">
                        Day {{ shownDay.day }} of {{ reveal.days.length }}
                    </div>
                    <div class="mono text-[12px] text-[#8fd3f0]">
                        {{ shownDay.weather.map((w) => w.name).join(' · ') }}
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-ghost w-12 flex-none"
                    :disabled="dayIndex >= reveal.days.length - 1"
                    aria-label="Next day"
                    @click="dayIndex++"
                >
                    ›
                </button>
            </div>
            <div class="flex flex-wrap gap-[6px]">
                <button
                    v-for="p in game.players"
                    :key="p.id"
                    type="button"
                    class="mono h-9 rounded-lg px-3 text-[12px]"
                    :style="
                        focus === p.id
                            ? 'background:#F4B740;color:#1A1200'
                            : p.traitor
                              ? 'background:#1A0B0E;color:#FF8E92;border:1px solid #5A2228'
                              : 'background:#111A2B;color:#B7C3D6;border:1px solid #2C3D5C'
                    "
                    @click="focus = focus === p.id ? null : p.id"
                >
                    {{ p.isMe ? 'You' : p.name }}
                </button>
            </div>
            <div
                v-for="pick in shownDay.picks.filter(
                    (x) => focus === null || x.player === focus,
                )"
                :key="pick.player"
                class="flex flex-col gap-2"
            >
                <div class="text-[14px]">
                    <b>{{ isMe(pick.player) ? 'You' : name(pick.player) }}</b>
                    <span
                        v-if="reveal.traitors.includes(pick.player)"
                        class="mono ml-2 text-[11px] text-[#ff8e92]"
                        >TRAITOR</span
                    >
                    <span
                        v-if="pick.override"
                        class="mono ml-2 text-[11px] text-[#f4b740]"
                        >OVERRIDE</span
                    >
                    <span
                        v-if="pick.auto"
                        class="mono ml-2 text-[11px] text-[#93a2ba]"
                        >TIMER</span
                    >
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div
                        v-for="c in pick.hand"
                        :key="c.id"
                        class="paper relative flex flex-col gap-1 p-2"
                        :style="
                            c.id === pick.pick
                                ? `outline: 3px solid ${reveal.traitors.includes(pick.player) ? '#E5484D' : '#F4B740'}; outline-offset: 2px`
                                : 'opacity:.92'
                        "
                    >
                        <div
                            class="mono text-[9px] tracking-[.1em]"
                            :style="{ color: dept(c.department).ink }"
                        >
                            {{
                                c.id === pick.pick
                                    ? 'PLAYED'
                                    : pick.vetoed.includes(c.id)
                                      ? 'VETOED'
                                      : 'PASSED'
                            }}
                        </div>
                        <div
                            class="disp text-[15px] leading-[.95] font-extrabold"
                        >
                            {{ c.name }}
                        </div>
                        <div class="mono text-[11px] leading-[1.4]">
                            <div
                                v-for="f in effectList(c.effects, inPlay)"
                                :key="f.key"
                                :class="{ neg: f.value < 0 }"
                            >
                                {{ f.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div
                v-if="shownDay.dusk"
                class="px-3 py-[10px] text-[14px]"
                :class="
                    shownDay.dusk.outcome === 'pulled' ? 'notice-red' : 'panel'
                "
            >
                Dusk {{ shownDay.day }}:
                <template v-if="shownDay.dusk.outcome === 'pulled'"
                    >{{ name(shownDay.dusk.puller) }} pulled the cord.</template
                >
                <template v-else-if="shownDay.dusk.outcome === 'ran'"
                    >green, the train ran to
                    {{ game.stations[shownDay.dusk.legAfter]?.name
                    }}{{ shownDay.dusk.free ? ' for free' : '' }}.</template
                >
                <template v-else>red, the train stayed put.</template>
            </div>
            <div
                v-for="(n, i) in shownDay.nominations"
                :key="i"
                class="notice-red px-3 py-[10px] text-[14px]"
            >
                Night {{ shownDay.day }}: {{ name(n.nominator) }} nominated
                {{ name(n.nominee) }}. {{ n.passed ? 'Detained' : 'Failed' }}
                {{ n.yes }} to {{ n.no }}.
            </div>
        </Sheet>

        <WinsSheet
            :open="sheet === 'wins'"
            :lobby="lobby"
            @close="sheet = null"
            @reset="actions.lobby('wins/reset')"
        />
    </div>
</template>
