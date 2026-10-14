<script setup lang="ts">
import { computed } from 'vue';
import type { GameView } from '@/game/types';
import Avatar from '../Avatar.vue';

const props = defineProps<{ game: GameView; clock: string }>();
const emit = defineEmits<{
    act: [action: string, data: Record<string, string | boolean>];
}>();

const vote = computed(() => props.game.vote!);
const me = computed(() => props.game.me);
const name = (id: string) =>
    props.game.players.find((p) => p.id === id)?.name ?? '';
const voters = computed(() =>
    props.game.players.filter((p) => p.id !== vote.value.nominee),
);
const voted = computed(() => voters.value.filter((p) => p.voted).length);
const mine = computed(() => me.value?.id ?? null);
const isNominee = computed(() => mine.value === vote.value.nominee);
const isPuller = computed(() => mine.value === vote.value.puller);
const nominee = computed(() =>
    props.game.players.find((p) => p.id === vote.value.nominee)!,
);
</script>

<template>
    <div class="-mx-4 -mt-4 flex flex-col">
        <div class="hazard h-[14px] flex-none" />
        <div
            class="flex flex-col gap-2 border-b border-[#3a1d22] px-[18px] pt-5 pb-4"
        >
            <div class="label">
                Night {{ game.day }} · Emergency brake pulled · {{ clock }}
            </div>
            <h1 class="disp m-0 text-[44px] leading-[.88] font-black">
                <span class="text-[#ff6b70]">{{
                    isPuller ? 'You' : name(vote.puller)
                }}</span>
                {{ isPuller ? 'name' : 'names' }}
                <span class="text-[#ff6b70]">{{
                    isNominee ? 'you' : name(vote.nominee)
                }}</span>
            </h1>
            <div class="text-[15px] leading-[1.4] text-[#e3c6c8]">
                Everyone except
                {{ isNominee ? 'you' : name(vote.nominee) }} votes, all at once.
                A strict majority of yes restricts them for
                {{ game.restrictDays }} days. Ties fail.
            </div>
        </div>
        <div class="flex flex-col gap-[10px] px-[18px] pt-4">
            <div class="flex items-baseline justify-between">
                <div class="label">Votes</div>
                <div class="mono text-[12px] text-[#c9a3a6]">
                    {{ voted }} OF {{ voters.length }} IN
                </div>
            </div>
            <div
                v-for="p in voters"
                :key="p.id"
                class="flex items-center gap-3 rounded-[10px] border bg-[#140b0e] px-3 py-2"
                :style="{
                    borderColor: p.isMe && !p.voted ? '#F4B740' : '#3A1D22',
                }"
            >
                <Avatar :name="p.name" :me="p.isMe" />
                <div class="flex-1 text-[15px] font-bold">
                    {{ p.isMe ? 'You' : p.name
                    }}{{ p.id === vote.puller ? ' (pulled)' : '' }}
                </div>
                <div
                    class="mono rounded-md px-[9px] py-[6px] text-[13px] font-semibold tracking-[.1em]"
                    :style="
                        p.voted
                            ? 'background:#3A1D22;color:#E3C6C8'
                            : 'background:transparent;color:#F4B740'
                    "
                >
                    {{ p.voted ? 'SEALED' : 'DECIDING' }}
                </div>
            </div>
            <div
                class="flex items-center gap-3 rounded-[10px] border border-dashed border-[#3a1d22] px-3 py-2 opacity-60"
            >
                <Avatar :name="nominee.name" muted />
                <div class="flex-1 text-[15px]">
                    {{ isNominee ? 'You' : nominee.name }}
                    <span class="text-[#c9a3a6]">· nominated, can't vote</span>
                </div>
            </div>

            <div class="mt-3 flex flex-col gap-[10px]">
                <template v-if="me && !isNominee && !isPuller">
                    <div class="text-center text-[14px] text-[#e3c6c8]">
                        {{
                            me.vote === null
                                ? 'Your vote stays hidden until everyone has voted.'
                                : `You voted ${me.vote ? 'yes' : 'no'}. You can change it until the vote closes.`
                        }}
                    </div>
                    <div class="flex gap-[10px]">
                        <button
                            type="button"
                            class="flex min-h-[86px] flex-1 flex-col items-center justify-center gap-1 rounded-xl"
                            :style="
                                me.vote === false
                                    ? 'background:#2C3D5C;border:2px solid #E8EEF5;color:#E8EEF5'
                                    : 'background:#16213A;border:2px solid #3A4A66;color:#E8EEF5'
                            "
                            :aria-pressed="me.vote === false"
                            @click="emit('act', 'vote', { yes: false })"
                        >
                            <span
                                class="disp text-[34px] leading-none font-black"
                                >NO</span
                            ><span class="text-[13px]">Let the train run</span>
                        </button>
                        <button
                            type="button"
                            class="flex min-h-[86px] flex-1 flex-col items-center justify-center gap-1 rounded-xl text-[#1a0b0e]"
                            :style="
                                me.vote === true
                                    ? 'background:#FF6B70;border:3px solid #FFFFFF'
                                    : 'background:#E5484D;border:2px solid #FF8E92'
                            "
                            :aria-pressed="me.vote === true"
                            @click="emit('act', 'vote', { yes: true })"
                        >
                            <span
                                class="disp text-[34px] leading-none font-black"
                                >YES</span
                            ><span class="text-[13px] font-bold"
                                >Restrict {{ nominee.name }}</span
                            >
                        </button>
                    </div>
                </template>
                <div
                    v-else-if="isPuller"
                    class="rounded-xl border border-[#5a2228] bg-[#1a0f13] p-[14px] text-[14px] text-[#f6e3e4]"
                >
                    You pulled the brake, so your vote counts as yes. Make your
                    case on voice.
                </div>
                <div
                    v-else-if="isNominee"
                    class="rounded-xl border border-[#5a2228] bg-[#1a0f13] p-[14px] text-[14px] text-[#f6e3e4]"
                >
                    You've been named. Everyone else is voting now. This is your
                    moment to talk.
                </div>
            </div>
        </div>
    </div>
</template>
