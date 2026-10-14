<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '@/components/game/Icon.vue';
import { ICONS } from '@/components/game/icons';

const form = useForm({ code: '' });

const slots = computed(() => [0, 1, 2, 3].map((i) => form.code[i] ?? ''));
const full = computed(() => form.code.length === 4);

function clean(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.code = input.value
        .toUpperCase()
        .replace(/[^A-HJ-NP-Z2-9]/g, '')
        .slice(0, 4);
    input.value = form.code;
    form.clearErrors();
}

function submit(): void {
    if (full.value) {
        form.post('/join');
    }
}
</script>

<template>
    <Head title="Join a lobby" />
    <div class="ls-root">
        <form
            class="ls-phone gap-[22px] px-5 pt-5 pb-7"
            @submit.prevent="submit"
        >
            <Link
                href="/"
                class="flex min-h-11 items-center gap-[6px] self-start text-[15px] text-[#b7c3d6]"
            >
                <Icon :paths="ICONS.chevronLeft" :size="18" :stroke="2.4" />Home
            </Link>

            <div class="flex flex-col gap-[6px]">
                <div class="label">Join a lobby</div>
                <h1 class="disp m-0 text-[46px] leading-[.88] font-black">
                    Boarding code
                </h1>
                <div class="text-[15px] leading-[1.4] text-[#b7c3d6]">
                    Four letters from whoever started the lobby.
                </div>
            </div>

            <div class="relative flex justify-center gap-[10px] py-2">
                <div
                    v-for="(ch, i) in slots"
                    :key="i"
                    class="flap flap-lg"
                    :style="{
                        outline:
                            i === Math.min(form.code.length, 3) && !full
                                ? '2px solid #F4B740'
                                : '0 solid transparent',
                        outlineOffset: '3px',
                    }"
                >
                    {{ ch }}
                </div>
                <label for="code" class="sr-only">Boarding code</label>
                <input
                    id="code"
                    :value="form.code"
                    class="absolute inset-0 h-full w-full cursor-text opacity-0"
                    type="text"
                    maxlength="4"
                    autocomplete="off"
                    autocapitalize="characters"
                    autofocus
                    @input="clean"
                />
            </div>

            <div
                v-if="form.errors.code"
                class="notice-red flex flex-col gap-2 px-[14px] py-3 text-[14px] leading-[1.4]"
            >
                <span>{{ form.errors.code }}</span>
                <Link
                    v-if="
                        (form.errors as Record<string, string>).reason ===
                        'name_taken'
                    "
                    href="/name"
                    class="py-[6px] text-[#f4b740]"
                    >Change my name</Link
                >
            </div>

            <div class="text-[13px] leading-[1.45] text-[#93a2ba]">
                Codes never use O, 0, I or 1, so there's nothing to mix up.
            </div>

            <div class="flex-1" />
            <button
                type="submit"
                class="btn"
                :class="full ? 'btn-primary' : 'btn-waiting'"
                :disabled="!full || form.processing"
            >
                Board
            </button>
        </form>
    </div>
</template>
