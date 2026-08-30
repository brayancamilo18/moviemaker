<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const STEPS = [
    { key: 'script', label: 'Guion' },
    { key: 'narration', label: 'Narración' },
    { key: 'images', label: 'Imágenes' },
    { key: 'sound', label: 'Sonido' },
    { key: 'render', label: 'Render' },
];

const STATUS_COMPLETED = {
    borrador: 0,
    'guion listo': 1,
    narrada: 2,
    'imagenes listas': 3,
    mezclada: 4,
    renderizada: 5,
    'pendiente de revision': 5,
    'lista para publicar': 5,
    descargada: 5,
    publicada: 5,
    fallida: 0,
    descartada: 0,
};

const VERDICTS = {
    publish: { label: 'publicar', color: '#4FA265' },
    revise: { label: 'revisar', color: '#E2A044' },
    discard: { label: 'descartar', color: '#D24A3C' },
};

const STEP_LABELS = {
    script: 'Guion',
    narration: 'Narración',
    images: 'Imágenes',
    sound: 'Sonido',
    render: 'Render',
};

const WORKER_COMMAND = 'php artisan queue:work --tries=1';

const EMPTY_QUEUE = {
    pending: 0,
    oldestPendingSeconds: null,
    failed: 0,
    likelyNoWorker: false,
};

const props = defineProps({
    story: { type: Object, default: null },
    progress: { type: Object, default: null },
    snapshot: { type: Object, default: null },
    queue: { type: Object, default: null },
});

const snapshot = ref(props.snapshot ?? emptySnapshot(props.story, props.progress, props.queue));
const copied = ref(false);
let timer = 0;

const heading = computed(() => {
    const title = snapshot.value?.title?.trim();

    if (title) {
        return title;
    }

    return props.story ? 'Generando el guion…' : 'Progreso';
});

const inProgress = computed(() => snapshot.value?.progress ?? null);

const settledFailure = computed(
    () => snapshot.value?.status === 'fallida' && !inProgress.value,
);

const scriptReadyIdle = computed(
    () => snapshot.value?.status === 'guion listo' && !inProgress.value,
);

const fallbackOn = computed(() => Boolean(snapshot.value?.used_fallback));

const queue = computed(() => snapshot.value?.queue ?? props.queue ?? EMPTY_QUEUE);

const staleDraft = computed(() => {
    if (!props.story || snapshot.value?.status !== 'borrador' || inProgress.value) {
        return false;
    }

    const created = snapshot.value?.created_at ?? props.story.created_at;
    const limit = snapshot.value?.stale_draft_seconds ?? 30;

    if (!created) {
        return false;
    }

    return (Date.now() - new Date(created).getTime()) / 1000 > limit;
});

const showWorkerWarning = computed(() => Boolean(queue.value.likelyNoWorker) || staleDraft.value);

const verdictMeta = computed(() => {
    const key = snapshot.value?.verdict;

    return key && VERDICTS[key] ? VERDICTS[key] : null;
});

const steps = computed(() => {
    const current = snapshot.value;
    const doneThrough = completedCount(current);
    const runningKey = current?.progress?.step ?? null;

    return STEPS.map((step, index) => {
        let state = 'pending';

        if (index < doneThrough) {
            state = 'done';
        } else if (runningKey === step.key) {
            state = 'running';
        }

        return { ...step, state };
    });
});

const barPercent = computed(() => {
    const progress = inProgress.value;

    if (!progress || progress.total < 1) {
        return 0;
    }

    return Math.max(0, Math.min(100, Math.round((100 * progress.done) / progress.total)));
});

function emptySnapshot(story, progress, queueStatus) {
    if (!story) {
        return null;
    }

    return {
        status: story.status,
        status_label: story.status,
        status_color: '#8E8D8A',
        progress,
        failed_step: story.failed_step,
        failed_message: story.failed_message,
        title: story.title ?? '',
        verdict: story.verdict,
        score: story.score,
        scene_count: story.scene_count,
        used_fallback: Boolean(story.used_fallback),
        created_at: story.created_at ?? null,
        stale_draft_seconds: 30,
        queue: queueStatus ?? EMPTY_QUEUE,
    };
}

async function copyWorkerCommand() {
    try {
        await navigator.clipboard.writeText(WORKER_COMMAND);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // El comando sigue visible para copiar a mano.
    }
}

function completedCount(current) {
    if (!current) {
        return 0;
    }

    if (current.status === 'fallida') {
        const index = STEPS.findIndex((step) => step.key === current.failed_step);

        return index > 0 ? index : 0;
    }

    return STATUS_COMPLETED[current.status] ?? 0;
}

function shouldStop(current) {
    if (!current) {
        return true;
    }

    if (current.status === 'pendiente de revision') {
        return true;
    }

    if (current.status === 'guion listo' && !current.progress) {
        return true;
    }

    if (current.status === 'fallida' && !current.progress) {
        return true;
    }

    return false;
}

function stop() {
    if (timer) {
        window.clearInterval(timer);
        timer = 0;
    }
}

async function tick() {
    if (!props.story?.id) {
        return;
    }

    try {
        const response = await fetch(`/stories/${props.story.id}/progress`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        snapshot.value = await response.json();

        if (shouldStop(snapshot.value)) {
            stop();
        }
    } catch {
        // El siguiente intervalo lo reintenta; un fallo de red no tumba la pantalla.
    }
}

function retry() {
    router.post(`/stories/${props.story.id}/retry`);
}

function continuePipeline() {
    router.post(`/stories/${props.story.id}/continue`);
}

function discard() {
    router.post(`/stories/${props.story.id}/discard`);
}

onMounted(async () => {
    if (!props.story?.id) {
        return;
    }

    if (shouldStop(snapshot.value)) {
        return;
    }

    await tick();

    if (!shouldStop(snapshot.value)) {
        timer = window.setInterval(tick, 2000);
    }
});

onUnmounted(() => {
    stop();
});
</script>

<template>
    <Head title="Progreso" />

    <div class="px-[30px] pt-[26px] pb-[60px] max-w-[1100px]">
        <template v-if="!story">
            <h1 class="text-[26px] font-extrabold tracking-[-0.02em]">Progreso</h1>
            <p class="mt-1 text-[12px] text-text-muted">
                No hay una historia abierta. Lanza una desde Nueva historia o ábrela desde la cola.
            </p>
        </template>

        <template v-else>
            <div class="mb-[22px]">
                <h1 class="text-[26px] font-extrabold tracking-[-0.02em]">{{ heading }}</h1>
                <div class="mt-1 flex items-center gap-2 text-[12px] text-text-muted">
                    <span
                        class="inline-block h-2 w-2 rounded-full"
                        :style="{ background: snapshot?.status_color || '#8E8D8A' }"
                    />
                    <span>{{ snapshot?.status_label }}</span>
                    <span v-if="snapshot?.scene_count">· {{ snapshot.scene_count }} escenas</span>
                </div>
            </div>

            <div
                v-if="showWorkerWarning"
                class="mb-5 flex items-start gap-3.5 border border-[#6B4C1C] bg-[#1C150A] px-4 py-3.5"
            >
                <span class="mt-0.5 h-[34px] w-[3px] shrink-0 bg-amber" />
                <div class="min-w-0 flex-1">
                    <p class="text-[12.5px] font-extrabold text-amber">
                        El pipeline está esperando. Hay {{ queue.pending }} trabajo(s) en cola y ninguno se está ejecutando. Arranca el worker en otra terminal:
                    </p>
                    <div class="mt-2.5 flex items-center gap-2">
                        <code class="flex-1 truncate border border-[#6B4C1C] bg-[#151006] px-2.5 py-1.5 font-mono text-[11.5px] text-text">{{ WORKER_COMMAND }}</code>
                        <button
                            type="button"
                            class="shrink-0 border border-[#6B4C1C] px-3 py-1.5 text-[11px] font-extrabold text-amber hover:bg-[#22180A]"
                            @click="copyWorkerCommand"
                        >
                            {{ copied ? 'Copiado' : 'Copiar' }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="fallbackOn"
                class="mb-5 flex items-start gap-3.5 border border-[#6B4C1C] bg-[#1C150A] px-4 py-3.5"
            >
                <span class="mt-0.5 h-[34px] w-[3px] shrink-0 bg-amber" />
                <p class="text-[12.5px] font-extrabold text-amber">
                    Se agotó la cuota de Gemini y se continuó con Claude Haiku.
                </p>
            </div>

            <div class="flex flex-col gap-px border border-[#1F2024] bg-[#1F2024]">
                <div
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="bg-surface-2 px-[15px] py-[13px]"
                >
                    <div class="flex items-center gap-3.5">
                        <span
                            v-if="step.state === 'done'"
                            class="flex h-4 w-4 shrink-0 items-center justify-center text-[12px] font-extrabold text-ok"
                        >
                            ✓
                        </span>
                        <span
                            v-else
                            class="h-2 w-2 shrink-0 rounded-full"
                            :class="step.state === 'running' ? 'animate-pulse bg-amber' : 'bg-text-dim'"
                        />
                        <span class="w-4 shrink-0 text-[10.5px] text-text-dim">{{ String(index + 1).padStart(2, '0') }}</span>
                        <span class="flex-1 text-[13.5px] font-extrabold tracking-[-0.01em]">{{ step.label }}</span>
                        <span
                            v-if="step.state === 'running' && inProgress?.label"
                            class="max-w-[320px] truncate text-[11.5px] text-text-muted"
                        >
                            {{ inProgress.label }}
                        </span>
                        <span
                            class="w-[76px] text-right text-[10.5px] font-extrabold tracking-[0.07em] uppercase"
                            :class="{
                                'text-ok': step.state === 'done',
                                'text-amber': step.state === 'running',
                                'text-text-dim': step.state === 'pending',
                            }"
                        >
                            {{ step.state === 'done' ? 'hecho' : step.state === 'running' ? 'en curso' : 'en espera' }}
                        </span>
                    </div>
                    <div v-if="step.state === 'running'" class="mt-2.5 ml-[38px] h-1 bg-[#1B1C1F]">
                        <div class="h-full bg-amber" :style="{ width: `${barPercent}%` }" />
                    </div>
                </div>
            </div>

            <section
                v-if="settledFailure"
                class="mt-5 border border-[#3A2622] bg-[#160F0E] px-4 py-3.5"
            >
                <div class="text-[11px] font-extrabold tracking-[0.09em] uppercase text-bad">Paso fallido</div>
                <div class="mt-2 text-[13px] font-extrabold">
                    {{ STEP_LABELS[snapshot.failed_step] || snapshot.failed_step }}
                </div>
                <p class="mt-2 font-mono text-[11.5px] leading-[1.6] text-[#E58C7F]">
                    {{ snapshot.failed_message || 'El paso del pipeline falló.' }}
                </p>
                <div class="mt-3.5 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="bg-amber px-3.5 py-2 text-[12px] font-extrabold text-[#151006] hover:bg-amber-hover"
                        @click="retry"
                    >
                        Reintentar este paso
                    </button>
                    <button
                        type="button"
                        class="border border-[#3A2622] px-3.5 py-2 text-[12px] text-[#E58C7F] hover:bg-[#221513]"
                        @click="discard"
                    >
                        Descartar
                    </button>
                </div>
            </section>

            <section
                v-if="scriptReadyIdle"
                class="mt-5 border border-border bg-surface-2 px-4 py-4"
            >
                <div class="text-[11px] font-extrabold tracking-[0.09em] uppercase text-text-muted">Guion listo</div>
                <h2 class="mt-2 text-[18px] font-extrabold tracking-[-0.02em]">
                    {{ snapshot.title || 'Sin título' }}
                </h2>
                <div class="mt-2 flex flex-wrap items-center gap-3 text-[13px]">
                    <span v-if="verdictMeta" class="font-extrabold" :style="{ color: verdictMeta.color }">
                        {{ verdictMeta.label }}
                    </span>
                    <span v-else class="text-text-muted">sin veredicto</span>
                    <span v-if="snapshot.score != null" class="text-text-muted">
                        {{ Number(snapshot.score).toFixed(1) }}
                    </span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        :href="`/review?story=${story.id}&tab=script`"
                        class="border border-border px-3.5 py-2 text-[12px] text-text-muted hover:border-[#3A3B40] hover:text-text"
                    >
                        Leer el guion
                    </Link>
                    <button
                        type="button"
                        class="bg-amber px-3.5 py-2 text-[12px] font-extrabold text-[#151006] hover:bg-amber-hover"
                        @click="continuePipeline"
                    >
                        Continuar el pipeline
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>
