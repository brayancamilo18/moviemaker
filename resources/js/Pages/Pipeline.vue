<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';

const AMBER = '#E2A044';
const GREEN = '#4FA265';
const RED = '#D24A3C';
const S1 = '#131316';
const MUT = '#8E8D8A';
const DIM = '#605F5D';

const props = defineProps({
    active: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    queue: { type: Object, default: null },
});

const backupOn = computed(() => Boolean(props.selected?.story?.used_fallback));
const backupCost = computed(() => props.selected?.backupCost ?? '');
const backupTokens = computed(() => props.selected?.backupTokens ?? '');

const pipeTitle = computed(() => props.selected?.story?.title ?? '');

const pipeSub = computed(() => {
    if (!props.selected) {
        return '';
    }

    const current = currentStepNum(props.selected.rows ?? []);
    const failed = (props.selected.rows ?? []).some((row) => row.state === 'fallido');
    const elapsed = props.selected.elapsed ?? '00:00';

    if (failed) {
        return 'Detenido en el paso ' + current + ' · ' + elapsed + ' transcurridos';
    }

    return 'Paso ' + current + ' de 7 · ' + elapsed + ' transcurridos';
});

const rows = computed(() => (props.selected?.rows ?? []).map((row) => styleRow(row)));

function currentStepNum(list) {
    const failed = list.find((row) => row.state === 'fallido');

    if (failed) {
        return Number(failed.num);
    }

    const running = list.find((row) => row.state === 'en curso');

    if (running) {
        return Number(running.num);
    }

    return list.length || 7;
}

function styleRow(row) {
    const state = row.state;
    const running = state === 'en curso';
    const failed = state === 'fallido';
    const color = state === 'hecho' ? GREEN : failed ? RED : running ? AMBER : '#4E4D4B';
    const prog = Number(row.progress ?? 0);

    return {
        num: row.num,
        name: row.name,
        unit: row.unit,
        time: row.time,
        state,
        wrap: 'padding:13px 15px;background:' + (failed ? '#160F0E' : S1),
        dot: 'width:8px;height:8px;flex:none;background:' + color + (running ? ';animation:hs-pulse 1.4s infinite' : ''),
        unitStyle: 'width:150px;text-align:right;font-size:11.5px;color:' + (state === 'en espera' ? '#4E4D4B' : MUT),
        stateStyle: 'width:76px;text-align:right;font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;font-weight:800;color:' + color,
        resume: () => resume(failed),
        resumeLabel: failed ? 'Reanudar' : 'Reejecutar',
        resumeStyle: state === 'en espera'
            ? 'visibility:hidden;width:88px;border:0'
            : 'width:88px;background:transparent;border:1px solid ' + (failed ? '#5A2E28' : '#26272B') + ';color:' + (failed ? '#E58C7F' : DIM) + ';padding:5px 0;font-size:11px;cursor:pointer;margin-left:10px',
        barWrap: prog > 0 || running ? 'height:4px;background:#1B1C1F;margin-top:10px;margin-left:38px' : 'display:none',
        bar: 'height:100%;width:' + (prog * 100) + '%;background:' + color,
        errWrap: failed ? 'margin:12px 0 2px 38px;padding:12px 14px;background:#1C1211;border:1px solid #3A2622' : 'display:none',
        error: row.error ?? '',
    };
}

function resume(failed) {
    const id = props.selected?.story?.id;

    if (!failed || !id) {
        return;
    }

    router.post(`/stories/${id}/retry`);
}

function goQueue() {
    const id = props.selected?.story?.id;

    if (!id) {
        return;
    }

    router.post(`/stories/${id}/discard`);
}
</script>

<template>
    <Head :title="pipeTitle || 'Progreso'" />

    <div style="padding:26px 30px 60px;max-width:1100px;min-width:940px">
        <div style="display:flex;align-items:flex-end;gap:16px;margin-bottom:22px">
            <div style="flex:1">
                <h1 style="font-size:26px;font-weight:800;letter-spacing:-.02em">{{ pipeTitle }}</h1>
                <div style="font-size:12px;color:#8E8D8A;margin-top:4px">{{ pipeSub }}</div>
            </div>
        </div>

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
                        <button type="button" class="hs-trace" style="background:transparent;border:1px solid #3A2622;color:#E58C7F;padding:8px 14px;cursor:pointer;font-size:12px">Ver traza completa</button>
                        <button type="button" class="hs-discard" @click="goQueue" style="background:transparent;border:1px solid #2A2B2F;color:#8E8D8A;padding:8px 14px;cursor:pointer;font-size:12px">Descartar historia</button>
                    </div>
                </div>
            </div>
        </div>
        <div style="margin-top:14px;font-size:11px;color:#605F5D">Cualquier paso completado se puede reanudar: pasa el cursor por su fila y reejecuta desde ahí conservando lo anterior.</div>
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
