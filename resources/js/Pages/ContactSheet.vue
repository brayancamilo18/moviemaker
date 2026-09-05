<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const AMBER = '#E2A044';
const GREEN = '#4FA265';
const RED = '#D24A3C';
const MUT = '#8E8D8A';
const DIM = '#605F5D';

const SUBJECT_LABELS = {
    threat: 'figura',
    detail: 'detalle',
    environment: 'paisaje',
};

const STAGE_LABELS = {
    hint: 'indicio',
    presence: 'presencia',
    reveal: 'revelación',
};

const FILTERS = [
    { key: 'all', label: 'Todos' },
    { key: 'threat', label: 'Figura' },
    { key: 'environment', label: 'Paisaje' },
    { key: 'detail', label: 'Detalle' },
    { key: 'missing', label: 'Sin imagen' },
];

const props = defineProps({
    story: { type: Object, default: null },
    shots: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
});

const filter = ref('all');
const hovered = ref(null);
const selected = ref([]);

const missingCount = computed(() => props.shots.filter((shot) => !shot.hasImage).length);

const heading = computed(() => {
    const total = props.shots.length;

    if (total === 0) {
        return 'Sin planos';
    }

    return total + (total === 1 ? ' plano' : ' planos');
});

const subheading = computed(() => {
    if (!props.stats) {
        return '';
    }

    const done = props.stats.withImage;
    const total = props.stats.total;

    if (done === total) {
        return 'Todas las imágenes generadas';
    }

    return done + ' de ' + total + ' con imagen · faltan ' + (total - done);
});

const filters = computed(() =>
    FILTERS.map((item) => {
        const active = filter.value === item.key;
        const count = item.key === 'all'
            ? props.shots.length
            : item.key === 'missing'
                ? missingCount.value
                : props.shots.filter((shot) => shot.subject === item.key).length;

        return {
            ...item,
            count,
            style:
                'background:' + (active ? '#1E1F22' : '#131316')
                + ';border:0;padding:7px 13px;font-size:11.5px;cursor:pointer;color:'
                + (active ? AMBER : DIM)
                + ';font-weight:' + (active ? 800 : 400),
        };
    }),
);

const visible = computed(() => {
    if (filter.value === 'all') {
        return props.shots;
    }

    if (filter.value === 'missing') {
        return props.shots.filter((shot) => !shot.hasImage);
    }

    return props.shots.filter((shot) => shot.subject === filter.value);
});

const cells = computed(() =>
    visible.value.map((shot) => {
        const isSelected = selected.value.includes(shot.order);
        const isHovered = hovered.value === shot.order;

        return {
            order: shot.order,
            num: String(shot.order).padStart(3, '0'),
            dur: shot.seconds.toFixed(1) + 's',
            kind: SUBJECT_LABELS[shot.subject] ?? '—',
            stage: shot.threatStage ? STAGE_LABELS[shot.threatStage] : '',
            line: shot.line ?? shot.description ?? '',
            prompt: shot.prompt ?? '',
            hasImage: shot.hasImage,
            src: `/stories/${props.story.slug}/shots/${shot.order}/image`,
            badge: shot.isIntro ? 'careta' : shot.isOutro ? 'cierre' : '',
            wrap:
                'position:relative;aspect-ratio:16/9;overflow:hidden;cursor:pointer;background:#0E0E10;border:1px solid '
                + (isSelected ? AMBER : '#1F2024'),
            kindStyle:
                'font-size:9.5px;letter-spacing:.04em;text-transform:uppercase;font-weight:800;padding:1px 4px;background:rgba(0,0,0,.55);color:'
                + (shot.subject === 'threat' ? '#E2A044' : shot.subject === 'detail' ? '#8FA8C4' : '#9C9A97'),
            stageStyle:
                'font-size:9.5px;padding:1px 4px;background:rgba(0,0,0,.55);color:#C88F84',
            hoverStyle: isHovered
                ? 'position:absolute;inset:0;background:rgba(8,8,9,.93);padding:8px;overflow:hidden;display:flex;flex-direction:column;gap:6px'
                : 'display:none',
            selMark: isSelected
                ? 'position:absolute;top:5px;right:5px;width:12px;height:12px;background:' + AMBER
                : 'display:none',
        };
    }),
);

const selCount = computed(() => {
    const count = selected.value.length;

    return count + (count === 1 ? ' plano seleccionado' : ' planos seleccionados');
});

const figure = computed(() => bandFor(props.stats?.figure, 'min'));
const detail = computed(() => bandFor(props.stats?.detail, null));

function bandFor(band, minKey) {
    if (!band) {
        return null;
    }

    const pct = band.ratio * 100;
    const min = minKey ? band.min * 100 : null;
    const max = band.max * 100;
    const inRange = (min === null || pct >= min) && pct <= max;

    return {
        pct,
        label: pct.toFixed(0) + ' %',
        note: min === null ? 'máximo ' + max.toFixed(0) + ' %' : 'entre ' + min.toFixed(0) + ' % y ' + max.toFixed(0) + ' %',
        valueStyle: 'font-size:28px;font-weight:800;line-height:1;color:' + (inRange ? AMBER : RED),
        barStyle: 'position:absolute;left:0;top:0;bottom:0;width:' + Math.min(100, pct) + '%;background:' + (inRange ? GREEN : RED),
        minMark: min === null ? 'display:none' : 'position:absolute;left:' + min + '%;top:-3px;bottom:-3px;width:1px;background:#8E8D8A',
        maxMark: 'position:absolute;left:' + Math.min(100, max) + '%;top:-3px;bottom:-3px;width:1px;background:#8E8D8A',
    };
}

const threatRows = computed(() =>
    (props.stats?.threat ?? []).map((row) => ({
        stage: STAGE_LABELS[row.stage] ?? row.stage,
        gate: (row.gate * 100).toFixed(0) + ' %',
        where: row.firstOrder === null
            ? 'no aparece'
            : 'plano ' + row.firstOrder + ' · ' + (row.firstProgress * 100).toFixed(0) + ' %',
        early: row.early,
        style: 'font-size:11.5px;color:' + (row.early ? '#E58C7F' : row.firstOrder === null ? DIM : MUT),
        markStyle:
            'position:absolute;top:0;bottom:0;width:2px;background:'
            + (row.early ? RED : AMBER)
            + ';left:' + (row.firstProgress === null ? 0 : row.firstProgress * 100) + '%'
            + (row.firstOrder === null ? ';display:none' : ''),
        gateStyle: 'position:absolute;top:-3px;bottom:-3px;width:1px;background:#4E4D4B;left:' + row.gate * 100 + '%',
    })),
);

const earlyStages = computed(() => threatRows.value.filter((row) => row.early));

function toggle(order) {
    selected.value = selected.value.includes(order)
        ? selected.value.filter((item) => item !== order)
        : [...selected.value, order];
}
</script>

<template>
    <Head title="Hoja de contactos" />

    <div v-if="!story" style="padding:26px 30px 60px">
        <div style="border:1px dashed #2A2B2F;padding:70px 30px;text-align:center;background:#0F0F11">
            <div style="font-size:17px;font-weight:800;margin-bottom:6px">Ninguna historia tiene planos todavía</div>
            <div style="font-size:12.5px;color:#8E8D8A;max-width:420px;margin:0 auto 20px;line-height:1.6">La hoja de contactos aparece cuando el planificador ha repartido la narración en planos. Es el paso 4.</div>
            <Link href="/queue" style="background:#E2A044;color:#151006;padding:9px 18px;font-weight:800;text-decoration:none">Ir a la cola</Link>
        </div>
    </div>

    <div v-else style="padding:22px 24px 60px;min-width:1020px">
        <div style="display:flex;align-items:flex-end;gap:16px;margin-bottom:18px">
            <div style="flex:1;min-width:0">
                <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:5px">Hoja de contactos · {{ story.title }}</div>
                <h1 style="font-size:24px;font-weight:800;letter-spacing:-.02em">{{ heading }}</h1>
                <div style="font-size:11.5px;color:#605F5D;margin-top:4px">{{ subheading }}</div>
            </div>
            <Link
                :href="`/stories/${story.slug}/thumbnail`"
                class="sheet-thumb-link"
                style="flex:none;background:transparent;border:1px solid #2A2B2F;color:#E2A044;padding:8px 14px;font-size:12px;text-decoration:none"
            >Proponer miniatura</Link>
            <div style="display:flex;gap:1px;background:#212226;border:1px solid #212226">
                <button
                    v-for="f in filters"
                    :key="f.key"
                    type="button"
                    :style="f.style"
                    @click="filter = f.key"
                >{{ f.label }} <span style="opacity:.6">{{ f.count }}</span></button>
            </div>
        </div>

        <div
            v-if="earlyStages.length > 0"
            style="border:1px solid #5A2E28;background:#160F0E;padding:12px 15px;display:flex;align-items:center;gap:13px;margin-bottom:16px"
        >
            <span style="width:3px;height:30px;background:#D24A3C;flex:none"></span>
            <div style="flex:1">
                <div style="font-size:12.5px;font-weight:800;color:#E58C7F">La amenaza se adelanta a su puerta</div>
                <div style="font-size:11.5px;color:#B08C86;margin-top:2px">
                    <span v-for="(row, i) in earlyStages" :key="row.stage">
                        <span v-if="i > 0"> · </span>{{ row.stage }} en el {{ row.where }}, permitida a partir del {{ row.gate }}
                    </span>
                </div>
            </div>
        </div>

        <div
            v-if="selected.length > 0"
            style="border:1px solid #6B4C1C;background:#1C150A;padding:11px 14px;display:flex;align-items:center;gap:13px;margin-bottom:14px"
        >
            <span style="font-size:12.5px;font-weight:800;color:#E2A044">{{ selCount }}</span>
            <span style="font-size:11.5px;color:#B49A72;flex:1">Regenerar planos sueltos todavía no está montado.</span>
            <button
                type="button"
                style="background:transparent;border:1px solid #3A3B40;color:#8E8D8A;padding:6px 12px;cursor:pointer;font-size:11.5px"
                @click="selected = []"
            >Deseleccionar</button>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(146px,1fr));gap:6px">
            <div
                v-for="cell in cells"
                :key="cell.order"
                :style="cell.wrap"
                @click="toggle(cell.order)"
                @mouseenter="hovered = cell.order"
                @mouseleave="hovered = null"
            >
                <img
                    v-if="cell.hasImage"
                    :src="cell.src"
                    loading="lazy"
                    decoding="async"
                    alt=""
                    style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block"
                />
                <div
                    v-else
                    style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:10.5px;color:#4E4D4B;background:repeating-linear-gradient(45deg,#0E0E10,#0E0E10 6px,#131316 6px,#131316 12px)"
                >sin imagen</div>

                <div style="position:absolute;left:0;top:0;right:0;display:flex;justify-content:space-between;padding:4px 5px;font-size:9.5px;color:rgba(255,255,255,.72);text-shadow:0 1px 2px #000">
                    <span>{{ cell.num }}</span><span>{{ cell.dur }}</span>
                </div>
                <div style="position:absolute;left:0;right:0;bottom:0;display:flex;justify-content:space-between;align-items:flex-end;padding:4px 5px;gap:4px">
                    <span :style="cell.kindStyle">{{ cell.kind }}</span>
                    <span v-if="cell.stage" :style="cell.stageStyle">{{ cell.stage }}</span>
                </div>
                <div v-if="cell.badge" style="position:absolute;top:20px;left:5px;font-size:9.5px;padding:1px 4px;background:rgba(0,0,0,.6);color:#9B99C4">{{ cell.badge }}</div>

                <div :style="cell.hoverStyle">
                    <div style="font-size:10.5px;color:#E8E6E3;line-height:1.4">{{ cell.line }}</div>
                    <div style="font-size:9.5px;color:#8E8D8A;line-height:1.4;font-family:ui-monospace,Menlo,monospace;overflow:hidden">{{ cell.prompt }}</div>
                </div>
                <div :style="cell.selMark"></div>
            </div>
        </div>

        <div
            v-if="stats"
            style="margin-top:26px;border-top:2px solid #2A2B2F;padding-top:16px;display:grid;grid-template-columns:220px 220px 1fr;gap:28px;align-items:start"
        >
            <div v-if="figure">
                <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:8px">Planos con figura</div>
                <div :style="figure.valueStyle">{{ figure.label }}</div>
                <div style="font-size:11px;color:#605F5D;margin-top:5px">{{ figure.note }}</div>
                <div style="height:6px;background:#17181A;margin-top:9px;position:relative">
                    <div :style="figure.barStyle"></div>
                    <div :style="figure.minMark"></div>
                    <div :style="figure.maxMark"></div>
                </div>
            </div>
            <div v-if="detail">
                <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:8px">Planos de detalle</div>
                <div :style="detail.valueStyle">{{ detail.label }}</div>
                <div style="font-size:11px;color:#605F5D;margin-top:5px">{{ detail.note }}</div>
                <div style="height:6px;background:#17181A;margin-top:9px;position:relative">
                    <div :style="detail.barStyle"></div>
                    <div :style="detail.maxMark"></div>
                </div>
            </div>
            <div>
                <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:8px">Progresión de la amenaza</div>
                <div style="position:relative;height:26px;background:#131316;border:1px solid #1F2024">
                    <template v-for="row in threatRows" :key="row.stage">
                        <div :style="row.gateStyle"></div>
                        <div :style="row.markStyle"></div>
                    </template>
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;margin-top:9px">
                    <div v-for="row in threatRows" :key="row.stage" :style="row.style">
                        <span style="display:inline-block;width:88px;font-weight:800">{{ row.stage }}</span>
                        <span>{{ row.where }}</span>
                        <span style="color:#4E4D4B"> · puerta {{ row.gate }}</span>
                    </div>
                </div>
                <div style="font-size:11px;color:#605F5D;margin-top:7px">La barra gris es la puerta de cada etapa; la marca, dónde aparece por primera vez.</div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sheet-thumb-link:hover {
    border-color: #E2A044;
}
</style>
