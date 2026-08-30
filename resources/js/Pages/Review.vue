<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ScriptViewer from '../Components/ScriptViewer.vue';

const TABS = [
    { key: 'script', label: 'Guion' },
    { key: 'sounds', label: 'Sonidos' },
    { key: 'audio', label: 'Audio' },
    { key: 'video', label: 'Vídeo' },
];

const props = defineProps({
    story: { type: Object, default: null },
    empty: { type: Boolean, default: false },
    status_label: { type: String, default: '' },
    status_color: { type: String, default: '' },
});

const tab = ref('script');

const modeLabel = computed(() => (props.story?.mode === 'original' ? 'Original' : 'Folclore'));

const heading = computed(() => {
    const title = typeof props.story?.title === 'string' ? props.story.title.trim() : '';

    return title !== '' ? title : 'Sin título';
});
</script>

<template>
    <Head :title="empty ? 'Revisión' : heading" />

    <div v-if="empty" class="px-[30px] pt-[26px] pb-[60px]">
        <div class="border border-dashed border-border bg-[#0F0F11] px-[30px] py-[70px] text-center">
            <div class="text-[17px] font-extrabold">No hay historias pendientes de revisión.</div>
            <Link
                href="/queue"
                class="mt-5 inline-block bg-amber px-[18px] py-[9px] text-[13px] font-extrabold text-[#151006] hover:bg-amber-hover"
            >
                Ir a la cola
            </Link>
        </div>
    </div>

    <div v-else-if="story" class="max-w-[920px] px-[30px] pt-[26px] pb-[60px]">
        <div class="mb-4 text-[10.5px] uppercase tracking-[0.09em] text-text-muted">
            <span class="inline-flex items-center gap-2">
                <span class="inline-block h-2 w-2 rounded-full" :style="{ background: status_color }" />
                {{ status_label }}
            </span>
            <span> · {{ modeLabel }}</span>
            <span v-if="story.lore_name"> · {{ story.lore_name }}</span>
        </div>
        <h1 class="text-[26px] font-extrabold tracking-[-0.02em] leading-[1.15]">{{ heading }}</h1>

        <div class="mt-5 flex border border-border">
            <button
                v-for="item in TABS"
                :key="item.key"
                type="button"
                class="px-3.5 py-2 text-[12px] font-extrabold"
                :class="
                    tab === item.key
                        ? 'bg-surface-3 text-text'
                        : 'text-text-muted hover:bg-surface-2 hover:text-text'
                "
                @click="tab = item.key"
            >
                {{ item.label }}
            </button>
        </div>

        <div class="mt-6">
            <ScriptViewer v-if="tab === 'script'" :slug="story.slug" />
            <p v-else class="py-16 text-center text-[13px] text-text-muted">
                Esta pestaña llega después.
            </p>
        </div>
    </div>
</template>
