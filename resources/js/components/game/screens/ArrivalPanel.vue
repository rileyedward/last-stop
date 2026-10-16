<script setup lang="ts">
import { computed, ref } from 'vue';
import { dept } from '@/game/departments';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';
import StationStrip from '../StationStrip.vue';

const props = defineProps<{ game: GameView; clock: string }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
}>();

const me = computed(() => props.game.me);
const chosen = ref<string | null>(me.value?.accuse ?? null);
const name = (id: string | null) =>
    props.game.players.find((p) => p.id === id)?.name ?? '';
const accused = computed(
    () => props.game.players.filter((p) => p.accused).length,
);
const present = computed(
    () => props.game.players.filter((p) => !p.absent).length,
);
const pulled = computed(() => props.game.pulls.length > 0);
</script>

<template>
    <div class="flex flex-col gap-[14px]">
        <div class="flex flex-col gap-2">
            <div class="label text-[#f4b740]">
                Day {{ game.day }} · arrival vote · {{ clock }}
            </div>
            <h1 class="disp m-0 text-[54px] leading-[.85] font-black">
                Last<br /><span class="text-[#f4b740]">Stop</span>
            </h1>
            <StationStrip :stations="game.stations" :leg="game.leg" />
            <div class="text-[15px] leading-[1.45] text-[#c5d0e0]">
                The train made it. Now everyone names one player. If the most
                votes land on
                {{ game.traitorCount > 1 ? 'a traitor' : 'the traitor' }}, the
                crew wins. A wrong name or a tie and the traitor wins<template
                    v-if="game.mustPull && !pulled"
                    >, unless the cord was never pulled (it wasn't)</template
                >.
            </div>
        </div>

        <div
            v-if="game.pulls.length"
            class="flex flex-col gap-2 rounded-xl border border-[#5a2228] bg-[#1a0f13] p-[14px]"
        >
            <div class="label text-[#ff8e92]">Cord clues</div>
            <div
                v-for="(pull, i) in game.pulls"
                :key="i"
                class="text-[14px] text-[#f6e3e4]"
            >
                Day {{ pull.day }}:
                {{
                    pull.clue.length
                        ? pull.clue.map(name).join(', ')
                        : 'no names'
                }}
            </div>
        </div>

        <template v-if="me">
            <div class="label">
                {{ me.accuse ? 'Your vote' : 'Name the traitor' }} ·
                {{ accused }} of {{ present }} in
            </div>
            <div class="flex flex-col gap-2">
                <button
                    v-for="p in game.players.filter((x) => !x.isMe)"
                    :key="p.id"
                    type="button"
                    class="seat"
                    :style="
                        chosen === p.id
                            ? 'border-color:#F4B740;background:#2A2110'
                            : ''
                    "
                    :aria-pressed="chosen === p.id"
                    @click="chosen = p.id"
                >
                    <Avatar :name="p.name" />
                    <span class="flex-1 text-[15px] font-bold">{{
                        p.name
                    }}</span>
                    <span
                        v-if="p.accused"
                        class="mono text-[10px] text-[#93a2ba]"
                        >VOTED</span
                    >
                    <span
                        class="mono text-[11px]"
                        :style="{ color: dept(p.department).color }"
                        >{{ dept(p.department).code }}</span
                    >
                </button>
            </div>
            <div class="text-[13px] text-[#93a2ba]">
                Votes stay secret until everyone has voted. You can change yours
                until then; a missed vote doesn't count.
            </div>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="!chosen || chosen === me.accuse"
                @click="chosen && emit('act', 'accuse', { suspect: chosen })"
            >
                {{
                    chosen && chosen === me.accuse
                        ? `You named ${name(chosen)}`
                        : chosen
                          ? `Name ${name(chosen)}`
                          : 'Pick a player'
                }}
            </button>
        </template>
    </div>
</template>
