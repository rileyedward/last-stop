<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{ configured: boolean }>();

const form = useForm({ username: '', password: '' });

function submit(): void {
    form.post('/admin/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Admin sign in" />
    <div class="ls-root">
        <form
            class="ls-phone gap-[22px] px-5 pt-14 pb-7"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-[6px]">
                <div class="label">Last Stop · Departure</div>
                <h1 class="disp m-0 text-[46px] leading-[.88] font-black">
                    Admin
                </h1>
                <div class="text-[15px] leading-[1.4] text-[#b7c3d6]">
                    Set the default rules and cards every new lobby starts with.
                </div>
            </div>

            <div
                v-if="!configured"
                class="notice-amber px-[14px] py-3 text-[14px] leading-[1.4]"
            >
                No admin login is set up. Add ADMIN_USERNAME and ADMIN_PASSWORD
                to the environment.
            </div>

            <label class="flex flex-col gap-2">
                <span class="label">Username</span>
                <input
                    v-model="form.username"
                    v-focus
                    name="username"
                    autocomplete="username"
                    class="h-12 rounded-[10px] border border-[#2c3d5c] bg-[#0b1220] px-3 text-[17px] text-[#e8eef5]"
                />
            </label>
            <label class="flex flex-col gap-2">
                <span class="label">Password</span>
                <input
                    v-model="form.password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    class="h-12 rounded-[10px] border border-[#2c3d5c] bg-[#0b1220] px-3 text-[17px] text-[#e8eef5]"
                />
            </label>
            <div
                v-if="form.errors.username || form.errors.password"
                class="notice-red px-[14px] py-3 text-[14px]"
                role="alert"
            >
                {{ form.errors.username || form.errors.password }}
            </div>

            <div class="flex-1" />
            <button
                type="submit"
                class="btn btn-primary"
                :disabled="form.processing || !form.username || !form.password"
            >
                Sign in
            </button>
        </form>
    </div>
</template>
