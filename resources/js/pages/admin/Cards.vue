<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminFrame from '@/components/admin/AdminFrame.vue';
import TaskCard from '@/components/game/TaskCard.vue';
import WeatherCard from '@/components/game/WeatherCard.vue';
import { DEPARTMENTS } from '@/game/departments';
import type { CardData, DeptKey } from '@/game/types';

const props = defineProps<{
    tasks: CardData[];
    weather: CardData[];
    columns: Record<'tasks' | 'weather', string[]>;
    edited: string[];
}>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const deck = ref<'tasks' | 'weather'>('tasks');
const file = ref<File | null>(null);
const uploading = ref(false);
const confirmReset = ref(false);

const byDept = computed(() => {
    const out: { key: DeptKey; cards: CardData[] }[] = [];
    for (const key of Object.keys(DEPARTMENTS) as DeptKey[]) {
        const cards = props.tasks.filter((c) => c.department === key);
        if (cards.length) {
            out.push({ key, cards });
        }
    }

    return out;
});

function pick(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload(): void {
    if (!file.value) {
        return;
    }
    uploading.value = true;
    router.post(
        `/admin/cards/${deck.value}`,
        { csv: file.value },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => (uploading.value = false),
            onSuccess: () => (file.value = null),
        },
    );
}

function reset(): void {
    confirmReset.value = false;
    router.post(
        `/admin/cards/${deck.value}/reset`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <AdminFrame title="Cards" active="cards">
        <div
            class="flex overflow-hidden rounded-[10px] border border-[#2c3d5c]"
            role="group"
            aria-label="Deck"
        >
            <button
                v-for="d in ['tasks', 'weather'] as const"
                :key="d"
                type="button"
                class="mono h-11 flex-1 text-[12px] tracking-[.08em] uppercase"
                :aria-pressed="deck === d"
                :style="{
                    background: deck === d ? '#F4B740' : '#0B1220',
                    color: deck === d ? '#1A1200' : '#B7C3D6',
                }"
                @click="((deck = d), (confirmReset = false))"
            >
                {{
                    d === 'tasks'
                        ? `Task cards · ${tasks.length}`
                        : `Weather · ${weather.length}`
                }}
            </button>
        </div>

        <section class="panel flex flex-col gap-3 p-[14px]">
            <div class="label text-[#8fd3f0]">
                Import or export the {{ deck === 'tasks' ? 'task' : 'weather' }}
                deck
                <span v-if="edited.includes(deck)" class="text-[#f4b740]">
                    · changed from shipped</span
                >
            </div>
            <div class="text-[14px] leading-[1.45] text-[#b7c3d6]">
                A CSV in the simulator's format with the columns
                <span class="mono text-[#e8eef5]">{{
                    columns[deck].join(', ')
                }}</span
                >. An import replaces the whole deck for new games; it's checked
                first and rejected if anything is wrong.
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="text-[14px] text-[#b7c3d6]"
                    aria-label="CSV file"
                    @change="pick"
                />
                <button
                    type="button"
                    class="btn btn-primary btn-small"
                    :disabled="!file || uploading"
                    @click="upload"
                >
                    Import
                </button>
                <a
                    :href="`/admin/cards/${deck}/export`"
                    class="btn btn-ghost btn-small"
                    >Export CSV</a
                >
                <button
                    v-if="edited.includes(deck) && !confirmReset"
                    type="button"
                    class="btn btn-ghost btn-small"
                    @click="confirmReset = true"
                >
                    Reset to shipped
                </button>
                <button
                    v-else-if="confirmReset"
                    type="button"
                    class="btn btn-danger btn-small"
                    @click="reset"
                >
                    Yes, reset this deck
                </button>
            </div>
            <div
                v-if="errors.csv"
                class="notice-red px-[14px] py-3 text-[14px]"
                role="alert"
            >
                {{ errors.csv }}
            </div>
        </section>

        <template v-if="deck === 'tasks'">
            <section
                v-for="group in byDept"
                :key="group.key"
                class="flex flex-col gap-2"
            >
                <div
                    class="label"
                    :style="{ color: DEPARTMENTS[group.key].color }"
                >
                    {{ DEPARTMENTS[group.key].name }} · {{ group.cards.length }}
                </div>
                <div class="grid gap-2 md:grid-cols-2">
                    <TaskCard v-for="c in group.cards" :key="c.id" :card="c" />
                </div>
            </section>
        </template>
        <div v-else class="grid gap-2 md:grid-cols-2">
            <WeatherCard v-for="c in weather" :key="c.id" :card="c" row />
        </div>
    </AdminFrame>
</template>
