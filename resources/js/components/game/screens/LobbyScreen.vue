<script setup lang="ts">
import { computed, ref } from 'vue';
import { boardActions } from '@/game/actions';
import type { BoardState } from '@/game/types';
import Avatar from '../Avatar.vue';
import Icon from '../Icon.vue';
import { ICONS } from '../icons';
import SettingsSheet from '../SettingsSheet.vue';
import WinsSheet from '../WinsSheet.vue';

const props = defineProps<{
    state: BoardState;
    online: Set<string>;
    connected: boolean;
}>();

const lobby = computed(() => props.state.lobby);
const actions = computed(() => boardActions(lobby.value.code));
const mySeat = computed(() => lobby.value.seats.find((s) => s.isMe));
const open = ref<string | null>(null);
const sheet = ref<'settings' | 'wins' | null>(null);
const copied = ref(false);
const busy = ref(false);

const presetLabel = computed(() => lobby.value.preset.toUpperCase());

const notReady = computed(() => lobby.value.seats.filter((s) => !s.ready));
const status = computed(() => {
    const count = lobby.value.seats.length;
    if (count < lobby.value.minPlayers) {
        const need = lobby.value.minPlayers - count;

        return `Waiting for ${need} more ${need === 1 ? 'passenger' : 'passengers'}. Departs with ${lobby.value.minPlayers} to ${lobby.value.maxPlayers}.`;
    }
    if (notReady.value.length === 0) {
        return lobby.value.isHost
            ? 'Everyone is ready. Depart when you like.'
            : `Everyone is ready. Waiting for ${lobby.value.hostName} to depart.`;
    }

    return `Waiting on ${notReady.value.length} ${notReady.value.length === 1 ? 'passenger' : 'passengers'} to ready up.`;
});

const winsLine = computed(() =>
    lobby.value.wins
        .filter((w) => w.total > 0)
        .slice(0, 3)
        .map((w) => `${w.isMe ? 'You' : w.name} ${w.total}`)
        .join(', '),
);

function inviteLink(): string {
    return `${window.location.origin}/board/${lobby.value.code}`;
}

async function copyInvite(): Promise<void> {
    try {
        await navigator.clipboard.writeText(inviteLink());
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1800);
    } catch {
        window.prompt('Copy the invite link', inviteLink());
    }
}

function toggleReady(): void {
    busy.value = true;
    actions.value.lobby(
        'ready',
        { ready: !mySeat.value?.ready },
        () => (busy.value = false),
    );
}

function depart(): void {
    busy.value = true;
    actions.value.lobby('start', {}, () => (busy.value = false));
}

function seatTap(id: string, isMe: boolean): void {
    if (lobby.value.isHost && !isMe) {
        open.value = open.value === id ? null : id;
    }
}

function leave(): void {
    if (
        window.confirm('Leave this lobby? Your seat opens up for someone else.')
    ) {
        actions.value.lobby('leave');
    }
}
</script>

<template>
    <div class="ls-phone gap-[14px] px-[18px] pt-5 pb-[22px]">
        <div class="flex items-center justify-between">
            <div v-if="lobby.isHost" class="flex flex-col gap-1">
                <div class="label text-[#f4b740]">You're the host</div>
                <div class="disp text-[32px] leading-[.9] font-black">
                    Your lobby
                </div>
            </div>
            <div v-else class="disp text-[22px] font-black">
                Last <span class="text-[#f4b740]">Stop</span>
            </div>
            <button
                type="button"
                class="mono flex h-11 items-center gap-2 rounded-[10px] border border-[#2c3d5c] bg-[#16213a] px-3 text-[18px] font-semibold tracking-[.18em] text-[#f4b740]"
                :aria-label="`Copy invite link for ${lobby.code}`"
                @click="copyInvite"
            >
                {{ lobby.code
                }}<span class="text-[#b7c3d6]"
                    ><Icon :paths="ICONS.copy" :size="16"
                /></span>
            </button>
        </div>

        <div class="flex gap-2">
            <button
                type="button"
                class="btn btn-ghost btn-small flex-1"
                @click="copyInvite"
            >
                <Icon :paths="ICONS.link" :size="16" />{{
                    copied ? 'Link copied' : 'Copy invite link'
                }}
            </button>
            <a
                :href="`/board/${lobby.code}/station`"
                target="_blank"
                class="btn btn-ghost btn-small flex-1"
            >
                <Icon :paths="ICONS.screen" :size="16" />Station board
            </a>
        </div>

        <div
            v-if="lobby.notice"
            class="notice-amber flex items-center gap-[10px] px-3 py-[10px] text-[14px] leading-[1.35]"
        >
            <span class="flex-none text-[#f4b740]"
                ><Icon :paths="ICONS.warn" :size="18" /></span
            ><span>{{ lobby.notice }}</span>
        </div>

        <button
            type="button"
            class="panel flex flex-col gap-[10px] p-[14px] text-left"
            :style="lobby.isHost ? 'border-color: #F4B740' : ''"
            @click="sheet = 'settings'"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="label">This trip</span>
                    <span
                        class="mono rounded-[5px] bg-[#16213a] px-[7px] py-1 text-[11px] text-[#b7c3d6]"
                        >{{ presetLabel }}</span
                    >
                </div>
                <span
                    class="py-[6px] text-[13px]"
                    :class="lobby.isHost ? 'text-[#f4b740]' : 'text-[#b7c3d6]'"
                    >{{ lobby.isHost ? 'Edit' : 'All settings' }}</span
                >
            </div>
            <div class="flex gap-[6px]">
                <div
                    class="flex-1 rounded-lg bg-[#0b1220] px-[6px] py-2 text-center"
                >
                    <div class="mono text-[20px] font-semibold">
                        {{ lobby.setup.rules.legs }}
                    </div>
                    <div class="label mt-1 text-[9px]">Legs</div>
                </div>
                <div
                    class="flex-1 rounded-lg bg-[#0b1220] px-[6px] py-2 text-center"
                >
                    <div class="mono text-[20px] font-semibold">
                        {{ lobby.setup.rules.days }}
                    </div>
                    <div class="label mt-1 text-[9px]">Days</div>
                </div>
                <div
                    class="flex-1 rounded-lg bg-[#0b1220] px-[6px] py-2 text-center"
                >
                    <div class="mono text-[20px] font-semibold text-[#8fd3f0]">
                        {{ lobby.setup.rules.weather_per_day }}
                    </div>
                    <div class="label mt-1 text-[9px]">Weather</div>
                </div>
                <div
                    class="flex-1 rounded-lg bg-[#0b1220] px-[6px] py-2 text-center"
                >
                    <div class="mono text-[20px] font-semibold text-[#ff8e92]">
                        {{ lobby.setup.rules.traitors }}
                    </div>
                    <div class="label mt-1 text-[9px]">
                        {{
                            lobby.setup.rules.traitors === 1
                                ? 'Traitor'
                                : 'Traitors'
                        }}
                    </div>
                </div>
            </div>
            <div
                v-if="lobby.seats.length < lobby.minPlayers"
                class="text-[12px] text-[#93a2ba]"
            >
                Shown for {{ lobby.minPlayers }} players. It adjusts as people
                board.
            </div>
        </button>

        <div class="flex flex-col gap-[6px]">
            <div class="flex items-baseline justify-between">
                <div class="label">
                    Passengers · {{ lobby.seats.length }} of
                    {{ lobby.maxPlayers
                    }}{{ lobby.isHost ? ' · tap for options' : '' }}
                </div>
                <div class="mono text-[12px] text-[#f4b740]">
                    {{ lobby.readyCount }} OF {{ lobby.seats.length }} READY
                </div>
            </div>
            <template v-for="seat in lobby.seats" :key="seat.id">
                <button
                    type="button"
                    class="seat"
                    :class="{ me: seat.isMe }"
                    :aria-expanded="open === seat.id"
                    @click="seatTap(seat.id, seat.isMe)"
                >
                    <Avatar :name="seat.name" :me="seat.isMe" />
                    <span class="flex-1 text-[15px]">
                        <b>{{ seat.isMe ? 'You' : seat.name }}</b>
                        <span class="text-[#93a2ba]"
                            >{{ seat.isMe ? ` · ${seat.name}` : ''
                            }}{{ seat.isHost ? ' · host' : '' }}</span
                        >
                    </span>
                    <span
                        v-if="connected && online.has(seat.id)"
                        class="online"
                        title="Connected"
                    />
                    <span
                        class="mono text-[11px] tracking-[.1em]"
                        :class="
                            seat.ready ? 'text-[#f4b740]' : 'text-[#5e6e88]'
                        "
                        >{{ seat.ready ? 'READY' : 'NOT YET' }}</span
                    >
                </button>
                <div v-if="open === seat.id" class="flex gap-2 pb-1">
                    <button
                        type="button"
                        class="btn btn-ghost btn-small flex-1"
                        @click="
                            (actions.lobby('host', { player: seat.id }),
                            (open = null))
                        "
                    >
                        Make {{ seat.name }} host
                    </button>
                    <button
                        type="button"
                        class="btn btn-small flex-1"
                        style="
                            background: #1a0f13;
                            border: 1px solid #5a2228;
                            color: #ff8e92;
                        "
                        @click="
                            (actions.lobby('kick', { player: seat.id }),
                            (open = null))
                        "
                    >
                        Remove from lobby
                    </button>
                </div>
            </template>
            <div
                v-if="lobby.seats.length < lobby.maxPlayers"
                class="seat"
                style="border-style: dashed; background: transparent"
            >
                <span
                    class="av"
                    style="
                        background: #16213a;
                        color: #93a2ba;
                        border: 1px dashed #3a4a66;
                    "
                    >+</span
                >
                <span class="flex-1 text-[#93a2ba]"
                    >Seat {{ lobby.seats.length + 1 }} is open</span
                >
            </div>
        </div>

        <button
            type="button"
            class="flex min-h-11 items-center gap-[10px] rounded-[10px] border border-dashed border-[#3a4a66] px-3 py-[10px] text-left text-[14px]"
            @click="sheet = 'wins'"
        >
            <span class="text-[#f4b740]"
                ><Icon :paths="ICONS.trophy" :size="18"
            /></span>
            <span class="flex-1">{{
                lobby.wins.length
                    ? `Wins so far: ${winsLine}`
                    : 'Wins table: no trips yet'
            }}</span>
            <span class="text-[#93a2ba]">Table</span>
        </button>

        <div class="flex-1" />
        <div class="flex flex-col gap-2">
            <button
                v-if="mySeat"
                type="button"
                class="btn"
                :class="mySeat.ready ? 'btn-outline' : 'btn-primary'"
                :aria-pressed="mySeat.ready"
                :disabled="busy"
                @click="toggleReady"
            >
                {{ mySeat.ready ? 'Ready · tap to undo' : "I'm ready" }}
            </button>
            <template v-if="lobby.isHost">
                <button
                    v-if="lobby.canStart"
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy"
                    @click="depart"
                >
                    Depart
                </button>
                <button v-else type="button" class="btn" disabled>
                    {{
                        notReady.length &&
                        lobby.seats.length >= lobby.minPlayers
                            ? `Depart · waiting on ${notReady.map((s) => (s.isMe ? 'you' : s.name)).join(', ')}`
                            : 'Depart'
                    }}
                </button>
            </template>
            <div class="text-center text-[14px] text-[#b7c3d6]">
                {{ status }}
            </div>
            <button
                type="button"
                class="self-center py-2 text-[13px] text-[#93a2ba] underline"
                @click="leave"
            >
                Leave lobby
            </button>
        </div>

        <SettingsSheet
            :open="sheet === 'settings'"
            :lobby="lobby"
            @close="sheet = null"
            @preset="(p) => actions.lobby('preset', { preset: p })"
            @custom="(values) => actions.settings(values)"
        />
        <WinsSheet
            :open="sheet === 'wins'"
            :lobby="lobby"
            @close="sheet = null"
            @reset="actions.lobby('wins/reset')"
        />
    </div>
</template>
