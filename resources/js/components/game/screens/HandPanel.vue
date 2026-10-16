<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import TaskCard from '../TaskCard.vue';

const props = defineProps<{ game: GameView }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
    preview: [effects: Record<string, number>];
}>();

const me = computed(() => props.game.me!);
const inPlay = computed(() =>
    props.game.departments.map((d) => d.key as string),
);
const selected = ref<string | null>(me.value.pick);
const sending = ref(false);

watch(
    () => [props.game.day, me.value.pickState, me.value.pick],
    () => {
        selected.value = me.value.pickState === 'repick' ? null : me.value.pick;
        sending.value = false;
    },
);

const card = computed(
    () => me.value.hand.find((c) => c.id === selected.value) ?? null,
);
watch(
    card,
    (c) => {
        emit('preview', { ...c?.effects } as Record<string, number>);
    },
    { immediate: true },
);

const myName = computed(
    () => props.game.players.find((p) => p.isMe)?.name ?? 'you',
);
const lockedCount = computed(
    () => props.game.players.filter((p) => p.status === 'picked').length,
);

const canChoose = computed(() =>
    ['none', 'locked', 'repick'].includes(me.value.pickState),
);
const changed = computed(
    () =>
        selected.value !== null &&
        (me.value.pickState !== 'locked' || selected.value !== me.value.pick),
);

function choose(id: string): void {
    if (!canChoose.value || me.value.vetoed.includes(id)) {
        return;
    }
    selected.value = id;
}

function lock(): void {
    if (!selected.value) {
        return;
    }
    sending.value = true;
    emit('act', 'pick', { card: selected.value });
}

// Escort duty
const duty = computed(() => me.value.escorting);
const dutyPick = computed(
    () => duty.value?.hand.find((c) => c.id === duty.value?.pick) ?? null,
);
function decide(decision: 'allow' | 'veto'): void {
    emit('act', 'escort', { decision });
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <!-- Escort duty -->
        <section
            v-if="duty"
            class="flex flex-col gap-3 rounded-2xl border border-[#7a5a1a] bg-[#14110a] p-[14px]"
        >
            <div class="flex flex-col gap-1">
                <div class="label text-[#f4b740]">
                    Escort duty · day {{ duty.dayOfDetention }} of
                    {{ game.detainDays }}
                </div>
                <h2 class="disp m-0 text-[32px] leading-[.9] font-black">
                    {{ duty.name }}'s hand
                </h2>
                <div class="text-[14px] leading-[1.4] text-[#b7c3d6]">
                    Only you can see this. Tell the table whatever you like,
                    true or not.
                    <template v-if="duty.hand.length < game.handSize"
                        >{{ dept(duty.department).name }} is in crisis, so they
                        drew {{ duty.hand.length }}.</template
                    >
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <TaskCard
                    v-for="c in duty.hand"
                    :key="c.id"
                    :card="c"
                    :flavor="false"
                    :only="inPlay"
                    :selected="
                        c.id === duty.pick && duty.pickState !== 'repick'
                    "
                    :stamp="duty.vetoed.includes(c.id) ? 'vetoed' : null"
                    :tag="
                        c.id === duty.pick && duty.pickState !== 'none'
                            ? duty.pickState === 'final'
                                ? 'PLAYING'
                                : 'THEIR PICK'
                            : null
                    "
                />
            </div>
            <template
                v-if="duty.pickState === 'pending' && duty.decision === null"
            >
                <div class="panel flex flex-col gap-2 p-3">
                    <div class="label">If you veto, everyone sees</div>
                    <div class="text-[15px] leading-[1.4] italic">
                        "{{ duty.name }}'s pick was vetoed by their escort,
                        {{ myName }}."
                    </div>
                    <div class="text-[13px] text-[#93a2ba]">
                        Not which card. They pick again from what's left{{
                            duty.overridesLeft > 0
                                ? `, or use their one override for the game`
                                : ''
                        }}.
                    </div>
                </div>
                <div class="flex gap-[10px]">
                    <button
                        type="button"
                        class="btn btn-ghost flex-1"
                        @click="decide('allow')"
                    >
                        Let it through
                    </button>
                    <button
                        type="button"
                        class="btn btn-danger flex-1"
                        :disabled="
                            duty.vetoesLeft < 1 ||
                            duty.hand.length - duty.vetoed.length < 2
                        "
                        @click="decide('veto')"
                    >
                        Veto · {{ duty.vetoesLeft }} left today
                    </button>
                </div>
            </template>
            <div
                v-else-if="duty.pickState === 'none'"
                class="text-[14px] text-[#b7c3d6]"
            >
                Waiting for {{ duty.name }} to pick. You'll decide whether it
                goes through.
            </div>
            <div
                v-else-if="duty.pickState === 'repick'"
                class="text-[14px] text-[#ff8e92]"
            >
                You vetoed
                {{
                    duty.hand.find(
                        (c) => c.id === duty?.vetoed[duty.vetoed.length - 1],
                    )?.name ?? dutyPick?.name
                }}. Waiting for {{ duty.name }} to pick again.
            </div>
            <div v-else class="text-[14px] text-[#b7c3d6]">
                {{ duty.name }}'s pick is settled for today.
            </div>
        </section>

        <div
            class="flex items-center gap-[10px] rounded-[10px] border border-[#24324a] bg-[#111a2b] px-3 py-2"
        >
            <span class="flex-1 text-[13px] text-[#b7c3d6]"
                >{{ lockedCount }} of {{ game.players.length }} locked in</span
            >
            <div class="flex gap-[6px]" aria-hidden="true">
                <span
                    v-for="p in game.players"
                    :key="p.id"
                    class="h-[10px] w-[10px] rounded-full"
                    :style="{
                        background:
                            p.status === 'picked'
                                ? '#F4B740'
                                : p.status === 'escort' || p.status === 'vetoed'
                                  ? '#E5484D'
                                  : '#2C3D5C',
                    }"
                />
            </div>
        </div>

        <div
            v-if="me.detained && me.escortedBy"
            class="notice-red px-[14px] py-3 text-[14px] leading-[1.4]"
        >
            <b class="text-[#ff8e92]">You're detained.</b>
            {{ me.escortedBy }} sees your hand and can veto your pick. Your pick
            goes to them as soon as you send it.
        </div>

        <div class="flex items-end justify-between">
            <div class="flex flex-col gap-1">
                <div
                    class="label"
                    :style="{ color: dept(me.department).color }"
                >
                    {{ dept(me.department).name }} · drew {{ me.hand.length }}
                </div>
                <h1 class="disp m-0 text-[34px] leading-[.9] font-black">
                    {{
                        me.pickState === 'none' ? 'Pick one task' : 'Your hand'
                    }}
                </h1>
            </div>
            <div class="mono text-right text-[11px] text-[#93a2ba]">
                ONLY YOUR PICK<br />GOES PUBLIC
            </div>
        </div>

        <TaskCard
            v-for="c in me.hand"
            :key="c.id"
            :card="c"
            as="button"
            :only="inPlay"
            :selected="selected === c.id"
            :dimmed="
                selected !== null &&
                selected !== c.id &&
                !me.vetoed.includes(c.id)
            "
            :stamp="
                me.vetoed.includes(c.id)
                    ? me.pickState === 'final' && me.pick === c.id
                        ? 'override'
                        : 'vetoed'
                    : null
            "
            :tag="
                me.pick === c.id && me.pickState !== 'repick'
                    ? me.pickState === 'pending'
                        ? 'WITH ESCORT'
                        : 'YOUR PICK'
                    : selected === c.id
                      ? 'SELECTED'
                      : null
            "
            @click="choose(c.id)"
        />

        <div class="text-[13px] leading-[1.45] text-[#93a2ba]">
            Only the card you pick goes public, with your name on it. The others
            are discarded face down. If the timer runs out, a random card is
            played for you.
        </div>

        <div class="flex flex-col gap-2">
            <template v-if="me.pickState === 'repick'">
                <div
                    class="notice-red px-[14px] py-3 text-[14px] leading-[1.4]"
                >
                    <b class="text-[#ff8e92]">Your escort vetoed that pick.</b>
                    Pick again from the rest{{
                        me.overridesLeft > 0
                            ? ', or override and play it anyway. The log will mark it OVERRIDE.'
                            : '.'
                    }}
                </div>
                <button
                    v-if="selected"
                    type="button"
                    class="btn btn-primary"
                    :disabled="sending"
                    @click="lock"
                >
                    Lock in: {{ card?.name }}
                </button>
                <button
                    v-if="me.overridesLeft > 0"
                    type="button"
                    class="btn btn-outline"
                    @click="emit('act', 'override', {})"
                >
                    Override · {{ me.overridesLeft }} left this game
                </button>
            </template>
            <button
                v-else-if="me.pickState === 'pending'"
                type="button"
                class="btn"
                disabled
            >
                Waiting on your escort, {{ me.escortedBy }}
            </button>
            <button
                v-else-if="me.pickState === 'final'"
                type="button"
                class="btn"
                disabled
            >
                Locked in ·
                {{
                    me.pick && me.vetoed.includes(me.pick)
                        ? 'override'
                        : 'final'
                }}
            </button>
            <template v-else>
                <button
                    v-if="changed"
                    type="button"
                    class="btn btn-primary"
                    :disabled="sending"
                    @click="lock"
                >
                    Lock in: {{ card?.name }}
                </button>
                <button
                    v-else-if="me.pickState === 'locked'"
                    type="button"
                    class="btn btn-ghost"
                    disabled
                >
                    Locked in · tap another card to change
                </button>
                <button v-else type="button" class="btn" disabled>
                    Tap a card to choose
                </button>
            </template>
        </div>
    </div>
</template>
