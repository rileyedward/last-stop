<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Flaps from '@/components/game/Flaps.vue';
import FinalScreen from '@/components/game/screens/FinalScreen.vue';
import GameScreen from '@/components/game/screens/GameScreen.vue';
import LobbyScreen from '@/components/game/screens/LobbyScreen.vue';
import PlatformScreen from '@/components/game/screens/PlatformScreen.vue';
import type { BoardState } from '@/game/types';
import { useLive } from '@/game/useLive';

const props = defineProps<{ state: BoardState; heartbeat: number }>();

const page = usePage();
const code = props.state.lobby.code;
const seated =
    props.state.membership === 'seated' ||
    props.state.membership === 'platform';
const { refresh, connected, online } = useLive(code, {
    presence: seated,
    playerId: seated ? props.state.me.id : null,
    heartbeatSeconds: props.heartbeat,
});

// Errors from the last action, shown briefly.
const error = ref<string | null>(null);
let errorTimer: number | undefined;
watch(
    () => (page.props.errors as Record<string, string>)?.game,
    (message) => {
        if (message) {
            error.value = message;
            window.clearTimeout(errorTimer);
            errorTimer = window.setTimeout(() => (error.value = null), 4000);
        }
    },
    { immediate: true },
);

const title = computed(() => {
    const game = props.state.game;
    if (props.state.screen === 'game' && game) {
        return `Day ${game.day} · ${game.phase}`;
    }

    return `Lobby ${code}`;
});

const boarding = ref(false);
function board(): void {
    boarding.value = true;
    router.post(
        `/board/${code}/join`,
        {},
        { onFinish: () => (boarding.value = false) },
    );
}
</script>

<template>
    <Head :title="title" />
    <div class="ls-root">
        <Transition name="ls-fade">
            <div
                v-if="error"
                class="notice-red fixed top-3 left-1/2 z-[60] w-[calc(100%-24px)] max-w-[456px] -translate-x-1/2 px-[14px] py-3 text-[14px]"
                role="alert"
            >
                {{ error }}
            </div>
        </Transition>

        <GameScreen
            v-if="state.screen === 'game' && state.game"
            :state="state"
            :refresh="refresh"
        />
        <FinalScreen
            v-else-if="state.screen === 'final' && state.game"
            :state="state"
        />
        <PlatformScreen
            v-else-if="state.screen === 'platform'"
            :state="state"
            :refresh="refresh"
        />
        <LobbyScreen
            v-else-if="state.screen === 'lobby'"
            :state="state"
            :online="online"
            :connected="connected"
        />

        <div
            v-else-if="state.screen === 'join'"
            class="ls-phone gap-[26px] px-[22px] pt-14 pb-7"
        >
            <div class="flex flex-col gap-[10px]">
                <div class="label">Winter route · No. 9</div>
                <div class="disp text-[92px] leading-[.82] font-black">
                    Last<br /><span class="text-[#f4b740]">Stop</span>
                </div>
            </div>
            <div class="panel flex items-center gap-3 px-[14px] py-3">
                <Flaps :text="code" />
                <div class="text-[14px] leading-[1.35] text-[#c5d0e0]">
                    You're boarding {{ state.lobby.hostName }}'s train.
                    {{ state.lobby.seats.length }} aboard{{
                        state.lobby.inProgress ? ', mid-trip' : ''
                    }}.
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <div class="label">Boarding as</div>
                <div class="disp text-[40px] leading-none font-black">
                    {{ state.me.name }}
                </div>
                <Link
                    :href="`/name?code=${code}`"
                    class="text-[14px] text-[#f4b740]"
                    >Change my name</Link
                >
            </div>
            <div class="flex-1" />
            <button
                type="button"
                class="btn btn-primary"
                :disabled="boarding"
                @click="board"
            >
                Board the train
            </button>
        </div>

        <div
            v-else-if="state.screen === 'full'"
            class="ls-phone items-center gap-[22px] px-5 pt-16 pb-7 text-center"
        >
            <div
                class="panel flex flex-col items-center gap-[10px] p-[18px]"
                role="img"
                aria-label="All seats taken"
            >
                <div class="grid grid-cols-5 gap-2">
                    <span
                        v-for="i in state.lobby.maxPlayers"
                        :key="i"
                        class="h-[30px] w-6 rounded-[5px_5px_3px_3px] bg-[#5b6c88]"
                    />
                </div>
                <div class="mono text-[12px] tracking-[.14em] text-[#93a2ba]">
                    {{ state.lobby.maxPlayers }} OF
                    {{ state.lobby.maxPlayers }} SEATS
                </div>
            </div>
            <div class="label text-[#ff8e92]">{{ code }} · lobby is full</div>
            <h1 class="disp m-0 text-[50px] leading-[.88] font-black">
                Standing<br />room only
            </h1>
            <div
                class="max-w-[300px] text-[16px] leading-[1.45] text-[#b7c3d6]"
            >
                {{ state.lobby.hostName }}'s train already has all
                {{ state.lobby.maxPlayers }} passengers. A seat opens if someone
                leaves between games.
            </div>
            <div class="flex-1" />
            <a :href="`/board/${code}/station`" class="btn btn-primary"
                >Watch the station board</a
            >
            <div class="flex w-full gap-[10px]">
                <Link href="/join" class="btn btn-ghost flex-1 text-[17px]"
                    >Another code</Link
                >
                <Link href="/" class="btn btn-ghost flex-1 text-[17px]"
                    >Start your own</Link
                >
            </div>
        </div>

        <div
            v-else
            class="ls-phone items-center gap-[22px] px-5 pt-20 pb-7 text-center"
        >
            <Flaps :text="code" size="md" />
            <div class="label text-[#ff8e92]">Removed by the host</div>
            <h1 class="disp m-0 text-[50px] leading-[.88] font-black">
                Off at this<br />station
            </h1>
            <div
                class="max-w-[300px] text-[16px] leading-[1.45] text-[#b7c3d6]"
            >
                {{ state.lobby.hostName }} took you off this train. You can
                board again with the code.
            </div>
            <div class="flex-1" />
            <button type="button" class="btn btn-primary" @click="board">
                Board again
            </button>
            <Link href="/" class="btn btn-ghost">Home</Link>
        </div>
    </div>
</template>
