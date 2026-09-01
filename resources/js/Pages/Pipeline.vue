<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ActiveStrip from '../Components/ActiveStrip.vue';

const AMBER = '#E2A044';
const GREEN = '#4FA265';
const RED = '#D24A3C';
const S1 = '#131316';
const MUT = '#8E8D8A';
const DIM = '#605F5D';
const POLL_MS = 2000;

const props = defineProps({
    active: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    queue: { type: Object, default: null },
});

const active = ref(props.active);
const selected = ref(props.selected);
const queue = ref(props.queue);
const traceOpen = ref(false);

let timer = 0;

const isEmpty = computed(() => active.value.length === 0);
const showStrip = computed(() => active.value.length > 1);
const selectedId = computed(() => selected.value?.story?.id ?? null);

const backupOn = computed(() => Boolean(selected.value?.story?.used_fallback));
const backupCost = computed(() => selected.value?.backupCost ?? '');
const backupTokens = computed(() => selected.value?.backupTokens ?? '');

const pipeTitle = computed(() => selected.value?.story?.title ?? '');

const pipeSub = computed(() => {
    if (!selected.value) {
        return '';
    }

    const current = currentStepNum(selected.value.rows ?? []);
    const failed = (selected.value.rows ?? []).some((row) => row.state === 'fallido');
    const elapsed = selected.value.elapsed ?? '00:00';

    if (failed) {
        return 'Detenido en el paso ' + current + ' · ' + elapsed + ' transcurridos';
    }

    return 'Paso ' + current + ' de 7 · ' + elapsed + ' transcurridos';
});

const rows = computed(() => (selected.value?.rows ?? []).map((row) => styleRow(row)));

watch(
    () => [props.active, props.selected, props.queue],
    () => {
        active.value = props.active;
        selected.value = props.selected;
        queue.value = props.queue;
        syncPoll();
    },
);

function currentStepNum(list) {
    const failed = list.find((row) => row.state === 'fallido');

    if (failed) {
        return Number(failed.num);
    }

    const running = list.find((row) => row.state === 'en curso' || row.state === 'en cola');

    if (running) {
        return Number(running.num);
    }

    return list.length || 7;
}

function styleRow(row) {
    const state = row.state;
    const running = state === 'en curso';
    const failed = state === 'fallido';
    const idle = state === 'en espera' || state === 'en cola';
    const color = state === 'hecho' ? GREEN : failed ? RED : running ? AMBER : '#4E4D4B';
    const prog = Number(row.progress ?? 0);

    return {
        num: row.num,
        name: row.name,
        job: row.job,
        unit: row.unit,
        time: row.time,
        state,
        wrap: 'padding:13px 15px;background:' + (failed ? '#160F0E' : S1),
        dot: 'width:8px;height:8px;flex:none;background:' + color + (running ? ';animation:hs-pulse 1.4s infinite' : ''),
        unitStyle: 'width:150px;text-align:right;font-size:11.5px;color:' + (idle ? '#4E4D4B' : MUT),
        stateStyle: 'width:76px;text-align:right;font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;font-weight:800;color:' + color,
        resume: () => resumeRow(row),
        resumeLabel: failed ? 'Reanudar' : 'Reejecutar',
        resumeStyle: idle
            ? 'visibility:hidden;width:88px;border:0'
            : 'width:88px;background:transparent;border:1px solid ' + (failed ? '#5A2E28' : '#26272B') + ';color:' + (failed ? '#E58C7F' : DIM) + ';padding:5px 0;font-size:11px;cursor:pointer;margin-left:10px',
        barWrap: prog > 0 || running ? 'height:4px;background:#1B1C1F;margin-top:10px;margin-left:38px' : 'display:none',
        bar: 'height:100%;width:' + (prog * 100) + '%;background:' + color,
        errWrap: failed ? 'margin:12px 0 2px 38px;padding:12px 14px;background:#1C1211;border:1px solid #3A2622' : 'display:none',
        error: row.error ?? '',
    };
}

function jobForRow(row) {
    if (row.job) {
        return row.job;
    }

    const number = Number(row.num);

    if (number <= 2) {
        return 'script';
    }

    if (number === 3) {
        return 'narration';
    }

    if (number <= 5) {
        return 'images';
    }

    if (number === 6) {
        return 'sound';
    }

    return 'render';
}

function destroyMessage(row) {
    const later = (selected.value?.rows ?? [])
        .filter((item) => Number(item.num) > Number(row.num))
        .map((item) => item.name);
    const obsolete = later.length > 0 ? later.join(', ') : 'ninguno';

    return 'Se va a rehacer «' + row.name + '». Los pasos posteriores quedarán obsoletos: ' + obsolete + '.';
}

function resumeRow(row) {
    const id = selected.value?.story?.id;

    if (!id || row.state === 'en espera' || row.state === 'en cola' || row.state === 'en curso') {
        return;
    }

    if (row.state === 'hecho' && !window.confirm(destroyMessage(row))) {
        return;
    }

    router.post(`/stories/${id}/retry`, { step: jobForRow(row) });
}

function goQueue() {
    const id = selected.value?.story?.id;
    const title = selected.value?.story?.title || 'esta historia';

    if (!id) {
        return;
    }

    if (!window.confirm('¿Descartar «' + title + '»?')) {
        return;
    }

    router.post(`/stories/${id}/discard`);
}

function shouldPoll(stories) {
    return stories.some((story) => !story.failed);
}

function stateUrl() {
    const fromQuery = new URLSearchParams(window.location.search).get('story');
    const id = fromQuery || selected.value?.story?.id;

    return id ? '/pipeline/state?story=' + id : '/pipeline/state';
}

function applyState(data) {
    active.value = data.active ?? [];
    selected.value = data.selected ?? null;
    queue.value = data.queue ?? null;
}

async function tick() {
    try {
        const response = await fetch(stateUrl(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        applyState(await response.json());

        if (!shouldPoll(active.value)) {
            stopPoll();
        }
    } catch {
        // El siguiente intervalo lo reintenta; un fallo de red no tumba la pantalla.
    }
}

function startPoll() {
    if (timer) {
        return;
    }

    timer = window.setInterval(tick, POLL_MS);
}

function stopPoll() {
    if (!timer) {
        return;
    }

    window.clearInterval(timer);
    timer = 0;
}

function syncPoll() {
    if (shouldPoll(active.value)) {
        startPoll();

        return;
    }

    stopPoll();
}

onMounted(() => {
    syncPoll();
});

onUnmounted(() => {
    stopPoll();
});
</script>

<template>
    <Head :title="pipeTitle || 'Progreso'" />

    <div style="padding:26px 30px 60px;max-width:1100px;min-width:940px">
        <div v-if="isEmpty" style="padding:70px 30px;text-align:center">
            <div style="font-size:17px;font-weight:800;margin-bottom:10px">No hay historias en proceso.</div>
            <Link href="/stories/create" style="color:#E2A044">Nueva historia</Link>
        </div>

        <template v-else>
        <div style="display:flex;align-items:flex-end;gap:16px;margin-bottom:22px">
            <div style="flex:1">
                <h1 style="font-size:26px;font-weight:800;letter-spacing:-.02em">{{ pipeTitle }}</h1>
                <div style="font-size:12px;color:#8E8D8A;margin-top:4px">{{ pipeSub }}</div>
            </div>
        </div>

        <ActiveStrip
            v-if="showStrip"
            :stories="active"
            :selected-id="selectedId"
        />

        <div
            v-if="backupOn"
            style="border:1px solid #6B4C1C;background:#1C150A;padding:13px 16px;display:flex;align-items:center;gap:14px;margin-bottom:20px"
        >
            <span style="width:3px;height:34px;background:#E2A044;flex:none"></span>
            <div style="flex:1">
                <div style="font-size:12.5px;font-weight:800;color:#E2A044">Respaldo de modelo activo — Claude Haiku</div>
                <div style="font-size:11.5px;color:#B49A72;margin-top:2px">Cuota gratuita de Gemini agotada a las 03:12. Desde ese punto la generación cuesta dinero.</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:16px;font-weight:800;color:#E2A044">{{ backupCost }}</div>
                <div style="font-size:10.5px;color:#8E8D8A">{{ backupTokens }}</div>
            </div>
            <button
                type="button"
                disabled
                title="Todavía no implementado"
                class="hs-pause"
                style="background:transparent;border:1px solid #6B4C1C;color:#E2A044;padding:7px 12px;cursor:pointer;font-size:11.5px"
            >Pausar y esperar cuota</button>
        </div>

        <div style="display:flex;flex-direction:column;gap:1px;background:#1F2024;border:1px solid #1F2024">
            <div v-for="s in rows" :key="s.num" :style="s.wrap">
                <div style="display:flex;align-items:center;gap:14px">
                    <span :style="s.dot"></span>
                    <span style="width:16px;font-size:10.5px;color:#4E4D4B">{{ s.num }}</span>
                    <span style="flex:1;font-size:13.5px;font-weight:800;letter-spacing:-.01em">{{ s.name }}</span>
                    <span :style="s.unitStyle">{{ s.unit }}</span>
                    <span style="width:66px;text-align:right;font-size:11.5px;color:#8E8D8A">{{ s.time }}</span>
                    <span :style="s.stateStyle">{{ s.state }}</span>
                    <button type="button" @click="s.resume" :style="s.resumeStyle">{{ s.resumeLabel }}</button>
                </div>
                <div :style="s.barWrap"><div :style="s.bar"></div></div>
                <div :style="s.errWrap">
                    <div style="font-size:11.5px;color:#E58C7F;line-height:1.6;font-family:ui-monospace,Menlo,monospace">{{ s.error }}</div>
                    <div style="display:flex;gap:8px;margin-top:11px">
                        <button type="button" class="hs-resume-step" @click="s.resume" style="background:#E2A044;color:#151006;border:0;padding:8px 14px;font-weight:800;cursor:pointer;font-size:12px">Reanudar desde este paso</button>
                        <button type="button" class="hs-trace" @click="traceOpen = !traceOpen" style="background:transparent;border:1px solid #3A2622;color:#E58C7F;padding:8px 14px;cursor:pointer;font-size:12px">Ver traza completa</button>
                        <button type="button" class="hs-discard" @click="goQueue" style="background:transparent;border:1px solid #2A2B2F;color:#8E8D8A;padding:8px 14px;cursor:pointer;font-size:12px">Descartar historia</button>
                    </div>
                    <div
                        v-if="traceOpen"
                        style="margin-top:11px;white-space:pre-wrap;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#E58C7F;line-height:1.6"
                    >{{ selected?.story?.failed_message || s.error }}</div>
                </div>
            </div>
        </div>
        <div style="margin-top:14px;font-size:11px;color:#605F5D">Cualquier paso completado se puede reanudar: pasa el cursor por su fila y reejecuta desde ahí conservando lo anterior.</div>
        </template>
    </div>
</template>

<style scoped>
.hs-pause:disabled {
    opacity: 1;
    cursor: not-allowed;
}
.hs-resume-step:hover {
    background: #F0B45E;
}
.hs-trace:hover {
    background: #221513;
}
.hs-discard:hover {
    color: #E8E6E3;
}
</style>
