<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const AMBER = '#E2A044';
const GREEN = '#4FA265';
const YEL = '#D6C044';
const RED = '#D24A3C';
const S1 = '#131316';
const DIM = '#605F5D';

const FILTER_LABELS = ['Todas', 'En curso', 'Listas', 'Cerradas'];

const props = defineProps({
    stats: { type: Array, default: () => [] },
    attention: { type: Array, default: () => [] },
    rest: { type: Array, default: () => [] },
    queue: {
        type: Object,
        default: () => ({
            pending: 0,
            waiting: 0,
            running: 0,
            oldestWaitingSeconds: null,
            failed: 0,
            likelyNoWorker: false,
            workerBusy: false,
        }),
    },
});

const filter = ref('Todas');

const publishedNote = '«El Familiar» y «La luz mala» están aprobadas y aún sin descargar. «La mujer del río Cauca» se marcó publicada el 25 de agosto.';

const queueEmpty = computed(() => props.attention.length === 0 && props.rest.length === 0);
const queueHasRows = computed(() => !queueEmpty.value);

const queueSubtitle = computed(() => {
    if (queueEmpty.value) {
        return 'Sin historias en el sistema';
    }

    const total = props.attention.length + props.rest.length;

    return props.attention.length + ' esperan tu revisión · ' + total + ' historias en total';
});

const pendingCount = computed(() => props.attention.length + ' historias');

const filteredRest = computed(() => {
    if (filter.value === 'Todas') {
        return props.rest;
    }

    return props.rest.filter((s) => {
        if (filter.value === 'Listas') {
            return s.st === 'lista para publicar' || s.st === 'descargada';
        }
        if (filter.value === 'En curso') {
            return ['borrador', 'guion listo', 'narrada', 'imágenes listas', 'mezclada', 'renderizada'].includes(s.st);
        }

        return ['descartada', 'fallida', 'publicada'].includes(s.st);
    });
});

const restCount = computed(() => filteredRest.value.length + ' de ' + props.rest.length);

const pendingRows = computed(() => props.attention.map((s) => mkRow(s, true)));
const restRows = computed(() => filteredRest.value.map((s) => mkRow(s, false)));

const filters = computed(() =>
    FILTER_LABELS.map((label) => ({
        label,
        style:
            'background:transparent;border:0;padding:3px 8px;font-size:11px;cursor:pointer;color:' +
            (filter.value === label ? AMBER : DIM) +
            ';font-weight:' +
            (filter.value === label ? 800 : 400),
    })),
);

function tone(h, l1, l2) {
    return (
        'position:absolute;inset:0;background:linear-gradient(160deg,hsl(' +
        h +
        ' 14% ' +
        l1 +
        '%),hsl(' +
        ((h + 28) % 360) +
        ' 10% ' +
        l2 +
        '%))'
    );
}

function chip(c) {
    return (
        'display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;letter-spacing:.02em;color:' +
        c +
        ';border:1px solid ' +
        c +
        '55;background:' +
        c +
        '14;padding:3px 8px'
    );
}

function mkRow(s, hi) {
    const rowPad = '9px 14px';

    return {
        id: s.id,
        href: s.href,
        title: s.t,
        meta:
            s.mode +
            (s.cr !== '—' ? ' · ' + s.cr : '') +
            ' · ' +
            (s.mode === 'Folclore' ? 'usada ' + s.usedCount + '×' : 'premisa libre'),
        dur: s.dur,
        date: s.d,
        thumb:
            'width:86px;height:48px;flex:none;' +
            tone(s.tone, 11, 6).replace('position:absolute;inset:0;', '') +
            ';border:1px solid #212226',
        rowStyle:
            'display:flex;align-items:center;gap:14px;padding:' +
            (hi ? '12px 14px' : rowPad) +
            ';cursor:pointer;background:' +
            (hi ? '#17140E' : S1) +
            ';border-left:2px solid ' +
            (hi ? AMBER : 'transparent'),
        status: s.st,
        statusStyle: chip(s.stColor),
        verdict: s.v === '—' ? '' : s.v,
        verdictStyle: s.v === '—' ? 'display:none' : chip(s.v === 'publish' ? GREEN : s.v === 'revise' ? YEL : RED),
        score: s.sc ? Number(s.sc).toFixed(1) : '',
        scoreStyle: s.sc
            ? 'font-size:12px;font-weight:800;color:' + (s.sc >= 7.5 ? GREEN : s.sc >= 5 ? YEL : RED)
            : 'display:none',
    };
}
</script>

<template>
    <Head title="Cola de trabajo" />

    <div style="padding:26px 30px 60px;max-width:1420px;min-width:1180px">
        <div style="display:flex;align-items:flex-end;gap:16px;margin-bottom:22px">
            <div style="flex:1">
                <h1 style="font-size:26px;font-weight:800;letter-spacing:-.02em">Cola de trabajo</h1>
                <div style="font-size:12px;color:#8E8D8A;margin-top:4px">{{ queueSubtitle }}</div>
            </div>
            <Link
                href="/stories/create"
                class="queue-btn-link queue-new"
                style="background:#E2A044;color:#151006;border:0;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer"
            >Nueva historia</Link>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#212226;border:1px solid #212226;margin-bottom:26px">
            <div v-for="(s, i) in stats" :key="s.label" style="background:#131316;padding:15px 16px">
                <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:8px">{{ s.label }}</div>
                <div
                    :title="s.title || undefined"
                    style="font-size:30px;font-weight:800;line-height:1;color:#E2A044;letter-spacing:-.02em"
                >{{ s.value }}</div>
                <div style="font-size:11px;color:#605F5D;margin-top:6px">{{ s.note }}</div>
                <div
                    v-if="i === 3 && queue.likelyNoWorker"
                    title="php artisan queue:work --tries=1"
                    style="display:flex;align-items:center;gap:6px;font-size:11px;color:#605F5D;margin-top:6px"
                >
                    <span style="width:8px;height:8px;flex:none;border-radius:50%;background:#E2A044"></span>
                    worker parado
                </div>
                <div
                    v-else-if="i === 3 && queue.workerBusy"
                    style="display:flex;align-items:center;gap:6px;font-size:11px;color:#605F5D;margin-top:6px"
                >
                    <span style="width:8px;height:8px;flex:none;border-radius:50%;background:#605F5D"></span>
                    worker ocupado · {{ queue.waiting }} en cola
                </div>
            </div>
        </div>

        <div v-if="queueEmpty">
            <div style="border:1px dashed #2A2B2F;padding:70px 30px;text-align:center;background:#0F0F11">
                <div style="font-size:17px;font-weight:800;margin-bottom:6px">Aún no hay historias</div>
                <div style="font-size:12.5px;color:#8E8D8A;max-width:400px;margin:0 auto 20px;line-height:1.6">Cuando lances una tanda, aparecerá aquí con su estado. Las que terminen el render se agruparán arriba esperando tu revisión.</div>
                <Link
                    href="/stories/create"
                    class="queue-btn-link queue-first"
                    style="background:#E2A044;color:#151006;border:0;padding:9px 18px;font-weight:800;cursor:pointer"
                >Crear la primera historia</Link>
            </div>
        </div>

        <div v-if="queueHasRows">
            <div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <span style="width:3px;height:14px;background:#E2A044"></span>
                    <span style="font-size:11px;letter-spacing:.09em;text-transform:uppercase;font-weight:800">Reclaman tu atención</span>
                    <span style="font-size:11px;color:#605F5D">{{ pendingCount }}</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px;margin-bottom:30px">
                    <Link
                        v-for="r in pendingRows"
                        :key="r.id"
                        :href="r.href"
                        class="queue-row queue-attention"
                        :style="r.rowStyle"
                    >
                        <div :style="r.thumb"></div>
                        <div style="flex:1;min-width:0">
                            <div style="font-size:13.5px;font-weight:800;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ r.title }}</div>
                            <div style="font-size:11px;color:#8E8D8A;margin-top:3px">{{ r.meta }}</div>
                        </div>
                        <div style="width:56px;flex:none;text-align:right;font-size:12px;color:#B9B7B3">{{ r.dur }}</div>
                        <div style="width:150px;flex:none"><span :style="r.statusStyle">{{ r.status }}</span></div>
                        <div style="width:132px;flex:none;display:flex;align-items:center;gap:7px"><span :style="r.verdictStyle">{{ r.verdict }}</span><span :style="r.scoreStyle">{{ r.score }}</span></div>
                        <div style="width:66px;flex:none;text-align:right;font-size:11px;color:#605F5D">{{ r.date }}</div>
                        <div style="width:14px;flex:none;text-align:right;color:#E2A044;font-weight:800">›</div>
                    </Link>
                </div>

                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <span style="font-size:11px;letter-spacing:.09em;text-transform:uppercase;font-weight:800;color:#8E8D8A">Resto de la cola</span>
                    <span style="font-size:11px;color:#605F5D">{{ restCount }}</span>
                    <div style="flex:1;height:1px;background:#1F2024"></div>
                    <button
                        v-for="f in filters"
                        :key="f.label"
                        type="button"
                        :style="f.style"
                        @click="filter = f.label"
                    >{{ f.label }}</button>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px">
                    <Link
                        v-for="r in restRows"
                        :key="r.id"
                        :href="r.href"
                        class="queue-row queue-rest"
                        :style="r.rowStyle"
                    >
                        <div :style="r.thumb"></div>
                        <div style="flex:1;min-width:0">
                            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#D6D4D0">{{ r.title }}</div>
                            <div style="font-size:11px;color:#605F5D;margin-top:3px">{{ r.meta }}</div>
                        </div>
                        <div style="width:56px;flex:none;text-align:right;font-size:12px;color:#8E8D8A">{{ r.dur }}</div>
                        <div style="width:150px;flex:none"><span :style="r.statusStyle">{{ r.status }}</span></div>
                        <div style="width:132px;flex:none;display:flex;align-items:center;gap:7px"><span :style="r.verdictStyle">{{ r.verdict }}</span><span :style="r.scoreStyle">{{ r.score }}</span></div>
                        <div style="width:66px;flex:none;text-align:right;font-size:11px;color:#4E4D4B">{{ r.date }}</div>
                        <div style="width:14px;flex:none"></div>
                    </Link>
                </div>
                <div style="margin-top:14px;font-size:11px;color:#4E4D4B">{{ publishedNote }}</div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.queue-btn-link {
    display: inline-block;
    text-decoration: none;
}
.queue-new:hover,
.queue-first:hover {
    background: #F0B45E !important;
}
.queue-row {
    text-decoration: none;
    color: inherit;
}
.queue-attention:hover {
    background: #1D1A15 !important;
}
.queue-rest:hover {
    background: #17181A !important;
}
</style>
