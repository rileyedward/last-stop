<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Flaps from '@/components/game/Flaps.vue';

const props = defineProps<{
    name: string;
    invite: { code: string; host: string; aboard: number } | null;
    max: number;
}>();

const form = useForm({ name: props.name, code: props.invite?.code ?? null });

function submit(): void {
    form.post('/name');
}
</script>

<template>
    <Head title="Your name" />
    <div class="ls-root">
        <form
            class="ls-phone gap-[26px] px-[22px] pt-14 pb-7"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-[10px]">
                <div class="label">Winter route · No. 9</div>
                <div class="disp text-[92px] leading-[.82] font-black">
                    Last<br /><span class="text-[#f4b740]">Stop</span>
                </div>
            </div>

            <div
                v-if="invite"
                class="panel flex items-center gap-3 px-[14px] py-3"
            >
                <Flaps :text="invite.code" />
                <div class="text-[14px] leading-[1.35] text-[#c5d0e0]">
                    You're boarding {{ invite.host }}'s train.
                    {{ invite.aboard }}
                    {{ invite.aboard === 1 ? 'passenger' : 'passengers' }}
                    aboard.
                </div>
            </div>

            <div class="flex flex-col gap-[10px]">
                <label
                    for="name"
                    class="disp text-[30px] leading-none font-extrabold"
                    >What should the crew call you?</label
                >
                <input
                    id="name"
                    v-model="form.name"
                    class="h-[60px] w-full rounded-xl border-2 border-[#f4b740] bg-[#0b1220] px-4 text-[22px] font-bold text-[#e8eef5] outline-none"
                    type="text"
                    :maxlength="max"
                    autocomplete="nickname"
                    autofocus
                />
                <div class="flex justify-between text-[13px] text-[#93a2ba]">
                    <span>{{
                        form.errors.name ?? 'Shown to everyone at the table'
                    }}</span>
                    <span class="mono">{{ form.name.length }}/{{ max }}</span>
                </div>
            </div>

            <div class="flex-1" />
            <div class="flex flex-col gap-3">
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="form.processing || form.name.trim() === ''"
                >
                    {{ invite ? 'Board the train' : 'Continue' }}
                </button>
                <div
                    class="text-center text-[13px] leading-[1.45] text-[#93a2ba]"
                >
                    No account, no sign-up. We'll remember your name in this
                    browser.
                </div>
            </div>
        </form>
    </div>
</template>
