<script setup lang="ts">
import { ref } from 'vue';
import type { LobbyView } from '@/game/types';
import Sheet from './Sheet.vue';

const props = defineProps<{ open: boolean; lobby: LobbyView }>();
const emit = defineEmits<{ close: []; reset: [] }>();
const confirming = ref(false);

function reset(): void {
    confirming.value = false;
    emit('reset');
}

void props;
</script>

<template>
    <Sheet :open="open" title="Wins table" @close="emit('close')">
        <div class="label">
            {{ lobby.code }} · {{ lobby.trips }}
            {{ lobby.trips === 1 ? 'trip' : 'trips' }} played
        </div>
        <div class="panel px-[14px] pt-1 pb-2">
            <table class="w-full border-collapse">
                <thead>
                    <tr
                        class="mono text-[10px] tracking-[.14em] text-[#93a2ba]"
                    >
                        <th
                            scope="col"
                            class="border-b border-[#2c3d5c] px-2 py-[10px] text-left"
                        >
                            PASSENGER
                        </th>
                        <th
                            scope="col"
                            class="border-b border-[#2c3d5c] px-2 py-[10px] text-right"
                        >
                            CREW
                        </th>
                        <th
                            scope="col"
                            class="border-b border-[#2c3d5c] px-2 py-[10px] text-right text-[#ff8e92]"
                        >
                            TRAITOR
                        </th>
                        <th
                            scope="col"
                            class="border-b border-[#2c3d5c] px-2 py-[10px] text-right text-[#f4b740]"
                        >
                            TOTAL
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in lobby.wins" :key="row.id">
                        <td
                            class="border-b border-[#18233a] px-2 py-3 text-[16px] font-bold"
                            :class="{ 'text-[#f4b740]': row.isMe }"
                        >
                            {{ row.isMe ? `You (${row.name})` : row.name }}
                        </td>
                        <td
                            class="mono border-b border-[#18233a] px-2 py-3 text-right text-[18px] font-semibold"
                        >
                            {{ row.crew }}
                        </td>
                        <td
                            class="mono border-b border-[#18233a] px-2 py-3 text-right text-[18px] font-semibold"
                            :class="
                                row.traitor
                                    ? 'text-[#ff8e92]'
                                    : 'text-[#5e6e88]'
                            "
                        >
                            {{ row.traitor }}
                        </td>
                        <td
                            class="mono border-b border-[#18233a] px-2 py-3 text-right text-[18px] font-semibold text-[#f4b740]"
                        >
                            {{ row.total }}
                        </td>
                    </tr>
                    <tr v-if="lobby.wins.length === 0">
                        <td
                            colspan="4"
                            class="px-2 py-4 text-[14px] text-[#93a2ba]"
                        >
                            No trips finished yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            class="flex items-start gap-[10px] text-[13px] leading-[1.45] text-[#93a2ba]"
        >
            <span
                >Wins only, no points. They live in this lobby and disappear
                when it closes, 30 minutes after the last person leaves.</span
            >
        </div>
        <template v-if="lobby.isHost">
            <button
                v-if="!confirming"
                type="button"
                class="btn btn-ghost"
                @click="confirming = true"
            >
                Reset the table
            </button>
            <div v-else class="notice-red flex flex-col gap-[10px] p-[14px]">
                <div class="text-[15px] leading-[1.4]">
                    Clear every win for everyone in this lobby? This can't be
                    undone.
                </div>
                <div class="flex gap-[10px]">
                    <button
                        type="button"
                        class="btn btn-ghost flex-1"
                        @click="confirming = false"
                    >
                        Keep
                    </button>
                    <button
                        type="button"
                        class="btn btn-danger flex-1"
                        @click="reset"
                    >
                        Reset
                    </button>
                </div>
            </div>
        </template>
        <div v-else class="text-center text-[13px] text-[#93a2ba]">
            Only the host can reset the table.
        </div>
    </Sheet>
</template>
