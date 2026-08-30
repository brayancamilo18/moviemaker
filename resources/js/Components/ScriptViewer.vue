<script setup>
import { onMounted, ref } from 'vue';

const VERDICTS = {
    publish: { label: 'publicar', color: '#4FA265' },
    revise: { label: 'revisar', color: '#E2A044' },
    discard: { label: 'descartar', color: '#D24A3C' },
};

const props = defineProps({
    slug: { type: String, required: true },
});

const loading = ref(true);
const missing = ref('');
const error = ref('');
const script = ref(null);

onMounted(() => {
    load();
});

async function load() {
    loading.value = true;
    missing.value = '';
    error.value = '';
    script.value = null;

    if (!props.slug) {
        loading.value = false;
        error.value = 'Esta historia no tiene slug.';

        return;
    }

    try {
        const response = await fetch(`/stories/${props.slug}/inspection/script`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        const body = await response.json().catch(() => null);

        if (response.status === 404) {
            missing.value = body?.reason || 'El guion todavía no se ha generado.';
            return;
        }

        if (!response.ok || !body?.available) {
            error.value = 'No se pudo cargar el guion.';
            return;
        }

        script.value = body;
    } catch {
        error.value = 'No se pudo cargar el guion.';
    } finally {
        loading.value = false;
    }
}

function formatDuration(seconds) {
    const total = Math.max(0, Math.round(Number(seconds) || 0));
    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    return `${minutes}:${String(rest).padStart(2, '0')}`;
}

function verdictMeta(review) {
    const key = review?.verdict;

    return key && VERDICTS[key] ? VERDICTS[key] : null;
}
</script>

<template>
    <p v-if="loading" class="py-16 text-center text-[13px] text-text-muted">Cargando el guion…</p>

    <p v-else-if="missing" class="py-16 text-center text-[13px] text-text-muted">{{ missing }}</p>

    <div v-else-if="error" class="border border-[#3A2622] bg-[#160F0E] px-4 py-3.5">
        <p class="text-[12.5px] font-extrabold text-bad">{{ error }}</p>
        <button
            type="button"
            class="mt-3 border border-[#3A2622] px-3 py-1.5 text-[11px] text-[#E58C7F] hover:bg-[#221513]"
            @click="load"
        >
            Reintentar
        </button>
    </div>

    <div v-else-if="script">
        <div class="flex items-start justify-between gap-6">
            <div class="min-w-0 flex-1">
                <h2 class="text-[22px] font-extrabold tracking-[-0.02em] leading-[1.2]">{{ script.title }}</h2>
                <p v-if="script.hook" class="mt-2 text-[13.5px] italic text-text-muted">{{ script.hook }}</p>
            </div>
            <div class="shrink-0 text-right text-[12px] text-text-muted">
                <div class="font-extrabold text-text">{{ Number(script.wordCount).toLocaleString('es-ES') }} palabras</div>
                <div class="mt-1">{{ formatDuration(script.estimatedSeconds) }}</div>
            </div>
        </div>

        <section
            v-if="script.review"
            class="mt-6 border border-border bg-surface-2 px-4 py-3.5"
        >
            <div class="flex flex-wrap items-baseline gap-3">
                <span
                    class="text-[13px] font-extrabold"
                    :style="{ color: verdictMeta(script.review)?.color || '#8E8D8A' }"
                >
                    {{ verdictMeta(script.review)?.label || script.review.verdict || 'sin veredicto' }}
                </span>
                <span v-if="script.review.score != null" class="text-[12px] text-text-muted">
                    {{ script.review.score }} / 10
                </span>
            </div>

            <div class="mt-3 flex flex-col gap-1">
                <details>
                    <summary class="cursor-pointer px-1 py-2 text-[12px] font-extrabold">
                        Frases no nativas
                        <span class="ml-2 font-normal text-text-dim">{{ script.review.nonNativePhrases?.length || 0 }}</span>
                    </summary>
                    <ul class="space-y-2 border-t border-border px-1 py-2 text-[12px] text-text-muted">
                        <li v-for="(item, index) in script.review.nonNativePhrases" :key="`nn-${index}`">
                            <span class="text-text">{{ item.text }}</span>
                            <span v-if="item.issue"> — {{ item.issue }}</span>
                            <span v-if="item.suggestion" class="block text-text-dim">{{ item.suggestion }}</span>
                        </li>
                        <li v-if="!script.review.nonNativePhrases?.length">Ninguna.</li>
                    </ul>
                </details>
                <details>
                    <summary class="cursor-pointer px-1 py-2 text-[12px] font-extrabold">
                        Clichés
                        <span class="ml-2 font-normal text-text-dim">{{ script.review.clichedElements?.length || 0 }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-border px-1 py-2 text-[12px] text-text-muted">
                        <li v-for="(item, index) in script.review.clichedElements" :key="`cl-${index}`">{{ item }}</li>
                        <li v-if="!script.review.clichedElements?.length">Ninguno.</li>
                    </ul>
                </details>
                <details>
                    <summary class="cursor-pointer px-1 py-2 text-[12px] font-extrabold">
                        Caídas de tensión
                        <span class="ml-2 font-normal text-text-dim">{{ script.review.tensionDips?.length || 0 }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-border px-1 py-2 text-[12px] text-text-muted">
                        <li v-for="(item, index) in script.review.tensionDips" :key="`td-${index}`">
                            <span v-if="item.sceneOrder">Escena {{ item.sceneOrder }} — </span>{{ item.reason }}
                        </li>
                        <li v-if="!script.review.tensionDips?.length">Ninguna.</li>
                    </ul>
                </details>
                <details>
                    <summary class="cursor-pointer px-1 py-2 text-[12px] font-extrabold">
                        Riesgos de TTS
                        <span class="ml-2 font-normal text-text-dim">{{ script.review.ttsRisks?.length || 0 }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-border px-1 py-2 text-[12px] text-text-muted">
                        <li v-for="(item, index) in script.review.ttsRisks" :key="`tts-${index}`">{{ item }}</li>
                        <li v-if="!script.review.ttsRisks?.length">Ninguno.</li>
                    </ul>
                </details>
            </div>
        </section>

        <div class="mt-6 flex flex-col gap-2">
            <article
                v-for="scene in script.scenes"
                :key="scene.order"
                class="flex gap-3.5 border border-border bg-surface-2 px-4 py-3.5"
            >
                <span class="w-7 shrink-0 text-[13px] font-extrabold text-amber">
                    {{ String(scene.order).padStart(2, '0') }}
                </span>
                <div class="min-w-0 flex-1">
                    <p v-if="scene.visualSummary" class="text-[11.5px] text-text-muted">{{ scene.visualSummary }}</p>
                    <p class="mt-1.5 text-[15px] leading-[1.75]">{{ scene.narration }}</p>
                </div>
            </article>
        </div>

        <details v-if="script.pronunciations?.length" class="mt-6 border border-border bg-surface-2">
            <summary class="cursor-pointer px-4 py-3 text-[12px] font-extrabold">
                Pronunciaciones
                <span class="ml-2 font-normal text-text-dim">{{ script.pronunciations.length }}</span>
            </summary>
            <table class="w-full border-t border-border text-left text-[12.5px]">
                <tbody>
                    <tr
                        v-for="item in script.pronunciations"
                        :key="item.term"
                        class="border-t border-border-soft"
                    >
                        <th class="px-4 py-2 font-extrabold">{{ item.term }}</th>
                        <td class="px-4 py-2 text-text-muted">{{ item.phonetic }}</td>
                    </tr>
                </tbody>
            </table>
        </details>

        <section v-if="script.description || script.tags?.length" class="mt-8">
            <p v-if="script.description" class="text-[13px] leading-[1.6] text-text-muted">{{ script.description }}</p>
            <div v-if="script.tags?.length" class="mt-3 flex flex-wrap gap-1.5">
                <span
                    v-for="tag in script.tags"
                    :key="tag"
                    class="border border-border bg-surface-2 px-2 py-1 text-[11px] text-amber"
                >
                    {{ tag }}
                </span>
            </div>
        </section>
    </div>
</template>
