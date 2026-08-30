<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    queue: {
        type: Object,
        default: () => ({
            pending: 0,
            oldestPendingSeconds: null,
            failed: 0,
            likelyNoWorker: false,
        }),
    },
});
</script>

<template>
    <Head title="Cola de trabajo" />

    <div class="px-[30px] pt-[26px] pb-[60px]">
        <h1 class="text-[26px] font-extrabold tracking-[-0.02em]">Cola de trabajo</h1>
        <div class="mt-3 flex items-center gap-4 text-[12px] text-text-muted">
            <span>{{ queue.pending }} en cola</span>
            <span>{{ queue.failed }} fallidos</span>
            <span
                v-if="queue.likelyNoWorker"
                class="flex items-center gap-2 font-extrabold text-amber"
            >
                <span class="h-2 w-2 rounded-full bg-amber" />
                worker parado
            </span>
        </div>
    </div>
</template>
