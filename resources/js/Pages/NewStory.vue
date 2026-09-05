<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const POLL_MS = 2000;
const POLL_MAX_MS = 30000;

const props = defineProps({
    creatures: { type: Array, required: true },
    providers: { type: Object, required: true },
    health: { type: Object, default: null },
    defaults: { type: Object, required: true },
    queue: { type: Object, default: () => ({ likelyNoWorker: false, failed: 0 }) },
});

const form = useForm({
    mode: props.defaults.mode === 'original' ? 'original' : 'folclore',
    lore_slug: null,
    premise: '',
    only_script: false,
});

form.transform((data) => {
    const payload = {
        mode: data.mode,
        premise: data.premise,
        only_script: data.only_script,
    };

    if (data.mode === 'folclore') {
        payload.lore_slug = data.lore_slug;
    }

    return payload;
});

const query = ref('');
const checking = ref(false);
const checkFailed = ref('');
const status = ref({
    gemini: { ...props.providers.gemini },
    anthropic: { ...props.providers.anthropic },
});
const healthMeasuredAt = ref(props.health?.measuredAt ?? null);
const healthAgeSeconds = ref(props.health?.ageSeconds ?? null);
const healthMeasuredBy = ref(props.health?.measuredBy ?? null);

let pollTimer = null;

const filteredCreatures = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.creatures;
    }

    return props.creatures.filter(
        (creature) =>
            creature.name.toLowerCase().includes(needle)
            || creature.region.toLowerCase().includes(needle),
    );
});

const canSubmit = computed(() => {
    if (form.processing) {
        return false;
    }

    return form.mode !== 'folclore' || Boolean(form.lore_slug);
});

const submitLabel = computed(() => (form.only_script ? 'Generar guion' : 'Generar vídeo completo'));

const noWorker = computed(() => Boolean(props.queue?.likelyNoWorker));

const providerRows = computed(() => [
    { key: 'gemini', label: 'Gemini', ...status.value.gemini },
    { key: 'anthropic', label: 'Anthropic', ...status.value.anthropic },
]);

function setMode(mode) {
    form.mode = mode;

    if (mode === 'original') {
        form.lore_slug = null;
        query.value = '';
    }
}

function selectCreature(slug) {
    form.lore_slug = slug;
}

function cardClass(creature) {
    const selected = form.lore_slug === creature.slug;
    const used = creature.usedCount > 0;

    if (selected) {
        return 'bg-[#1F1710] text-text shadow-[inset_0_0_0_1px_var(--color-amber)]';
    }

    if (used) {
        return 'bg-surface-2 text-[#787673] hover:bg-[#1D1E22]';
    }

    return 'bg-surface-2 text-[#C9C7C3] hover:bg-[#1D1E22]';
}

function dotClass(row) {
    const live = row.reachable !== null && row.reachable !== undefined;
    const ok = live ? row.reachable === true : Boolean(row.configured);

    return ok ? 'bg-ok' : 'bg-bad';
}

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        return '';
    }

    return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

function jsonHeaders() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
    };
}

function applyHealth(payload) {
    if (!payload?.report) {
        return;
    }

    status.value = {
        gemini: { ...status.value.gemini, ...payload.report.gemini },
        anthropic: { ...status.value.anthropic, ...payload.report.anthropic },
    };
    healthMeasuredAt.value = payload.measuredAt ?? null;
    healthAgeSeconds.value = payload.ageSeconds ?? null;
    healthMeasuredBy.value = payload.measuredBy ?? null;
}

function hasNewMeasurement(payload, before) {
    return Boolean(payload?.measuredAt) && payload.measuredAt !== before;
}

function stopPoll() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function measuredLabel(row) {
    const age = row.ageSeconds ?? healthAgeSeconds.value;
    const who = row.measuredBy || healthMeasuredBy.value;

    if (age === null || age === undefined || age > 3600) {
        return { stale: true, text: 'sin medir recientemente' };
    }

    const when = age < 60 ? `medido hace ${age} s` : `medido hace ${Math.floor(age / 60)} min`;
    const suffix = who ? ` (${who})` : '';

    return { stale: false, text: when + suffix };
}

async function checkNow() {
    checking.value = true;
    checkFailed.value = '';
    stopPoll();

    const before = healthMeasuredAt.value;

    try {
        const response = await fetch('/llm/health', {
            method: 'POST',
            headers: jsonHeaders(),
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const posted = await response.json();

        if (hasNewMeasurement(posted.last, before)) {
            applyHealth(posted.last);
            checking.value = false;

            return;
        }

        let elapsed = 0;

        pollTimer = setInterval(async () => {
            elapsed += POLL_MS;

            if (elapsed >= POLL_MAX_MS) {
                stopPoll();
                checkFailed.value = 'El worker no ha respondido. Comprueba que esté corriendo.';
                checking.value = false;

                return;
            }

            try {
                const get = await fetch('/llm/health', {
                    method: 'GET',
                    headers: jsonHeaders(),
                    credentials: 'same-origin',
                });

                if (!get.ok) {
                    throw new Error(`HTTP ${get.status}`);
                }

                const payload = await get.json();

                if (hasNewMeasurement(payload, before)) {
                    applyHealth(payload);
                    stopPoll();
                    checking.value = false;
                }
            } catch (error) {
                stopPoll();
                checkFailed.value = error instanceof Error ? error.message : 'La comprobación falló.';
                checking.value = false;
            }
        }, POLL_MS);
    } catch (error) {
        checkFailed.value = error instanceof Error ? error.message : 'La comprobación falló.';
        checking.value = false;
        stopPoll();
    }
}

onUnmounted(() => {
    stopPoll();
});

function submit() {
    if (!canSubmit.value) {
        return;
    }

    form.post('/stories');
}
</script>

<template>
    <Head title="Nueva historia" />

    <div class="px-[30px] pt-[26px] pb-[60px] max-w-[1040px]">
        <h1 class="text-[26px] font-extrabold tracking-[-0.02em]">Nueva historia</h1>
        <p class="mt-1 mb-[26px] text-[12px] text-text-muted">Elige el modo y lanza el guion cuando los modelos respondan.</p>

        <div
            v-if="noWorker"
            class="mb-[26px] flex items-center gap-3.5 border border-[#6B4C1C] bg-[#1C150A] px-4 py-3.5"
        >
            <span class="h-[34px] w-[3px] shrink-0 bg-amber" />
            <div class="flex-1">
                <div class="text-[12.5px] font-extrabold text-amber">Nadie está atendiendo la cola</div>
                <div class="mt-0.5 text-[11.5px] text-[#B49A72]">
                    La historia se encolará y ahí se quedará. Arranca el worker en otra terminal:
                    <code class="text-amber">bash scripts/worker.sh</code>
                </div>
            </div>
        </div>

        <section class="mb-[26px] border-t-2 border-border pt-4">
            <div class="mb-2.5 text-[11px] font-extrabold tracking-[0.09em] uppercase">Modelos</div>

            <div class="flex flex-col gap-2">
                <div
                    v-for="row in providerRows"
                    :key="row.key"
                    class="flex flex-col gap-1 bg-surface-2 px-3 py-2.5"
                >
                    <div class="flex items-center gap-3">
                        <span class="h-2 w-2 shrink-0 rounded-full" :class="dotClass(row)" />
                        <span class="w-24 shrink-0 font-extrabold">{{ row.label }}</span>
                        <span class="min-w-0 flex-1 truncate text-text-muted">{{ row.name }}</span>
                        <span
                            v-if="row.reachable === true && row.latencyMs != null"
                            class="shrink-0 text-[12px] text-ok"
                        >
                            {{ row.latencyMs }} ms
                        </span>
                        <span
                            v-else-if="row.reachable === false && row.error"
                            class="max-w-[420px] shrink-0 truncate text-[12px] text-bad"
                            :title="row.error"
                        >
                            {{ row.error }}
                        </span>
                    </div>
                    <p v-if="row.hint" class="pl-5 text-[11px] text-warn">{{ row.hint }}</p>
                    <p
                        class="pl-5 text-[11px]"
                        :class="measuredLabel(row).stale ? 'text-text-dim' : 'text-text-muted'"
                    >
                        {{ measuredLabel(row).text }}
                    </p>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="border border-border bg-transparent px-3 py-1.5 text-[12px] text-text-muted hover:border-[#3A3B40] hover:text-text disabled:cursor-wait disabled:opacity-50"
                    :disabled="checking"
                    @click="checkNow"
                >
                    {{ checking ? 'Comprobando…' : 'Comprobar ahora' }}
                </button>
                <span v-if="checkFailed" class="text-[12px] text-bad">{{ checkFailed }}</span>
            </div>

            <p v-if="!status.anthropic.configured" class="mt-2 text-[11px] text-text-muted">
                Sin clave de Anthropic no hay respaldo cuando se agote la cuota de Gemini.
            </p>
        </section>

        <form @submit.prevent="submit">
            <section class="mb-[26px] border-t-2 border-border pt-4">
                <div class="mb-2.5 text-[11px] font-extrabold tracking-[0.09em] uppercase">Modo</div>
                <div class="grid max-w-[520px] grid-cols-2 gap-px bg-border">
                    <button
                        type="button"
                        class="px-5 py-4 text-left text-[15px] leading-tight"
                        :class="
                            form.mode === 'folclore'
                                ? 'bg-amber font-extrabold text-[#151006]'
                                : 'bg-surface-2 font-semibold text-text-muted hover:bg-surface-3 hover:text-text'
                        "
                        @click="setMode('folclore')"
                    >
                        Folclore
                    </button>
                    <button
                        type="button"
                        class="px-5 py-4 text-left text-[15px] leading-tight"
                        :class="
                            form.mode === 'original'
                                ? 'bg-amber font-extrabold text-[#151006]'
                                : 'bg-surface-2 font-semibold text-text-muted hover:bg-surface-3 hover:text-text'
                        "
                        @click="setMode('original')"
                    >
                        Original
                    </button>
                </div>
                <p v-if="form.errors.mode" class="mt-2 text-[12px] text-bad">{{ form.errors.mode }}</p>
            </section>

            <section v-if="form.mode === 'folclore'" class="mb-[26px] border-t-2 border-border pt-4">
                <div class="mb-3 flex items-baseline gap-3">
                    <div class="text-[11px] font-extrabold tracking-[0.09em] uppercase">Criatura</div>
                    <div class="text-[11px] text-text-dim">
                        {{ filteredCreatures.length }} de {{ creatures.length }}
                    </div>
                </div>

                <input
                    v-model="query"
                    type="search"
                    placeholder="Buscar por nombre o región"
                    class="mb-3 w-full max-w-[420px] border border-border bg-surface-2 px-3 py-2 text-text placeholder:text-text-dim"
                >

                <div
                    v-if="filteredCreatures.length > 0"
                    class="grid grid-cols-[repeat(auto-fill,minmax(178px,1fr))] gap-px border border-[#1F2024] bg-[#1F2024]"
                >
                    <button
                        v-for="creature in filteredCreatures"
                        :key="creature.slug"
                        type="button"
                        class="relative flex min-h-[62px] flex-col items-start px-3 py-2.5 text-left"
                        :class="cardClass(creature)"
                        @click="selectCreature(creature.slug)"
                    >
                        <span class="pr-7 text-[12px] font-semibold leading-tight">{{ creature.name }}</span>
                        <span class="mt-1 text-[11px] text-text-muted">{{ creature.region }}</span>
                        <span
                            v-if="creature.usedCount > 0"
                            class="absolute top-2 right-2.5 text-[10.5px] text-text-dim"
                        >
                            {{ creature.usedCount }}×
                        </span>
                    </button>
                </div>
                <p v-else class="text-[12px] text-text-muted">Ninguna criatura coincide.</p>
                <p v-if="form.errors.lore_slug" class="mt-2 text-[12px] text-bad">{{ form.errors.lore_slug }}</p>
            </section>

            <section class="mb-[26px] border-t-2 border-border pt-4">
                <div class="mb-1 flex items-baseline justify-between gap-3">
                    <div class="text-[11px] font-extrabold tracking-[0.09em] uppercase">
                        Premisa <span class="font-normal tracking-normal text-text-dim normal-case">· opcional</span>
                    </div>
                    <div class="text-[11px] text-text-dim">{{ form.premise.length }}/500</div>
                </div>
                <textarea
                    v-model="form.premise"
                    rows="3"
                    maxlength="500"
                    placeholder="Opcional. Si lo dejas vacío, el modelo inventa la premisa."
                    class="w-full resize-y border border-border bg-surface-2 px-3 py-[11px] leading-[1.6] text-text placeholder:text-text-dim"
                />
                <p v-if="form.errors.premise" class="mt-2 text-[12px] text-bad">{{ form.errors.premise }}</p>
            </section>

            <section class="mb-8 border-t-2 border-border pt-4">
                <button
                    type="button"
                    class="flex max-w-[640px] items-start gap-3 text-left"
                    role="switch"
                    :aria-checked="form.only_script"
                    @click="form.only_script = !form.only_script"
                >
                    <span
                        class="relative mt-0.5 h-5 w-9 shrink-0 rounded-full"
                        :class="form.only_script ? 'bg-amber' : 'bg-border'"
                    >
                        <span
                            class="absolute top-0.5 h-4 w-4 rounded-full bg-[#151006] transition-transform"
                            :class="form.only_script ? 'left-4' : 'left-0.5'"
                        />
                    </span>
                    <span>
                        <span class="block font-extrabold">Parar después del guion</span>
                        <span class="mt-1 block text-[12px] text-text-muted">
                            Actívalo para leer el guion antes de gastar horas en narración, imágenes y render. Apagado, la historia va sola hasta el vídeo.
                        </span>
                    </span>
                </button>
                <p v-if="form.errors.only_script" class="mt-2 text-[12px] text-bad">{{ form.errors.only_script }}</p>
            </section>

            <button
                type="submit"
                class="bg-amber px-[22px] py-[11px] text-[13.5px] font-extrabold text-[#151006] hover:bg-amber-hover disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-amber"
                :disabled="!canSubmit"
            >
                {{ form.processing ? 'Generando…' : submitLabel }}
            </button>
        </form>
    </div>
</template>
