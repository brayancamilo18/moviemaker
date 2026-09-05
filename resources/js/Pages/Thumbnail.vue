<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { composeThumbnail, toJpeg, unsupportedFeatures, YT_WIDTH, YT_HEIGHT, YT_MAX_BYTES } from '../thumbnail-canvas';

const AMBER = '#E2A044';
const GREEN = '#4FA265';
const RED = '#D24A3C';

// Ancho real de la portada en la lista de YouTube. Todo lo que no se lea a este tamaño no
// se lee, por mucho que el lienzo grande engañe.
const PHONE_WIDTH = 168;
const CANVAS_WIDTH = YT_WIDTH;
const TEXT_COLOR = '#F2F0ED';

// Por debajo de esto una línea deja de leerse en el móvil. Sale de medir el alto de texto
// que sobrevive a 168 px de ancho: menos de 11 píxeles reales es una mancha.
const LEGIBLE_PX = 11;

const ALIGNS = [
    { key: 'left', label: 'Izquierda' },
    { key: 'center', label: 'Centro' },
    { key: 'right', label: 'Derecha' },
];

const props = defineProps({
    story: { type: Object, default: null },
    candidates: { type: Array, default: () => [] },
    variants: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
});

const picked = ref(props.candidates[0]?.order ?? null);
const saving = ref(false);
const downloading = ref(false);
const renderError = ref('');
const missingFeatures = ref([]);

const form = reactive({
    line1: '',
    line2: '',
    line3: '',
    ...props.defaults,
});

watch(
    () => props.candidates,
    (list) => {
        if (picked.value === null && list.length > 0) {
            picked.value = list[0].order;
        }
    },
);

const current = computed(
    () => props.candidates.find((item) => item.order === picked.value) ?? null,
);

const imageUrl = computed(() =>
    current.value ? `/stories/${props.story.slug}/thumbnail/${current.value.order}/image` : null,
);

const filter = computed(
    () => `contrast(${form.contrast}%) saturate(${form.saturation}%)`,
);

const vignette = computed(() => ({
    position: 'absolute',
    inset: 0,
    background: `radial-gradient(120% 90% at 50% 40%, transparent 30%, rgba(0,0,0,${form.vignette / 100}) 100%)`,
}));

function textBlock(width) {
    const scale = width / CANVAS_WIDTH;

    return {
        position: 'absolute',
        left: '6%',
        right: '6%',
        top: `${form.pos_y}%`,
        transform: 'translateY(-50%)',
        textAlign: form.align,
        lineHeight: 1.02,
        fontWeight: 800,
        letterSpacing: '-0.02em',
        textShadow: `0 ${2 * scale}px ${10 * scale}px rgba(0,0,0,.85)`,
    };
}

function lineStyle(width, accent) {
    return {
        fontSize: `${form.font_size * (width / CANVAS_WIDTH)}px`,
        color: accent ? AMBER : TEXT_COLOR,
        textTransform: 'uppercase',
    };
}

// El lienzo grande es flex:1, así que su ancho depende de la ventana. El cuerpo del texto se
// guarda en píxeles de 1280 y hay que escalarlo al ancho que de verdad tiene en pantalla: con
// un ancho supuesto, la línea que ves grande no es la que se guarda.
const canvas = ref(null);
const canvasWidth = ref(CANVAS_WIDTH);
let observer = null;

onMounted(() => {
    missingFeatures.value = unsupportedFeatures();

    if (!canvas.value) {
        return;
    }

    observer = new ResizeObserver((entries) => {
        canvasWidth.value = entries[0].contentRect.width || CANVAS_WIDTH;
    });

    observer.observe(canvas.value);
});

onUnmounted(() => {
    observer?.disconnect();
    observer = null;
});

const phoneLines = computed(() => {
    const scale = PHONE_WIDTH / CANVAS_WIDTH;

    return [form.line1, form.line2, form.line3]
        .filter((line) => String(line).trim() !== '')
        .map((line) => ({ line, px: form.font_size * scale }));
});

const legibility = computed(() => {
    const px = form.font_size * (PHONE_WIDTH / CANVAS_WIDTH);
    const longest = [form.line1, form.line2, form.line3]
        .map((line) => String(line).trim().length)
        .reduce((max, len) => Math.max(max, len), 0);

    if (longest === 0) {
        return { ok: false, label: 'Sin texto todavía', color: '#605F5D' };
    }

    if (px < LEGIBLE_PX) {
        return {
            ok: false,
            label: `A ${px.toFixed(1)} px no se lee en el móvil — sube el cuerpo`,
            color: RED,
        };
    }

    // Una línea larga se encoge sola para caber y acaba igual de ilegible que un cuerpo
    // pequeño. El ancho útil son 1280 menos los márgenes; a 0,55 em por carácter, más de
    // esto no cabe en una línea.
    const fits = Math.floor((CANVAS_WIDTH * 0.88) / (form.font_size * 0.55));

    if (longest > fits) {
        return {
            ok: false,
            label: `La línea más larga tiene ${longest} caracteres y caben ${fits}`,
            color: RED,
        };
    }

    return { ok: true, label: `Se lee: ${px.toFixed(1)} px en el móvil`, color: GREEN };
});

const sliders = computed(() => [
    { key: 'font_size', label: 'Cuerpo del texto', min: 40, max: 260, step: 2, display: form.font_size + ' px' },
    { key: 'pos_y', label: 'Altura del texto', min: 0, max: 100, step: 1, display: form.pos_y + ' %' },
    { key: 'vignette', label: 'Viñeta', min: 0, max: 100, step: 1, display: form.vignette + ' %' },
    { key: 'contrast', label: 'Contraste', min: 50, max: 200, step: 1, display: form.contrast + ' %' },
    { key: 'saturation', label: 'Saturación', min: 0, max: 200, step: 1, display: form.saturation + ' %' },
]);

const composedLines = computed(() =>
    [
        { text: form.line1, accent: false },
        { text: form.line2, accent: true },
        { text: form.line3, accent: false },
    ].filter((line) => String(line.text).trim() !== ''),
);

function composition() {
    return {
        imageUrl: imageUrl.value,
        lines: composedLines.value,
        fontSize: form.font_size,
        posY: form.pos_y,
        align: form.align,
        vignette: form.vignette,
        contrast: form.contrast,
        saturation: form.saturation,
        textColor: TEXT_COLOR,
        accentColor: AMBER,
    };
}

async function renderJpeg() {
    const canvas = await composeThumbnail(composition());

    return toJpeg(canvas);
}

async function download() {
    if (!canSave.value) {
        return;
    }

    downloading.value = true;
    renderError.value = '';

    try {
        const blob = await renderJpeg();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = props.story.download_name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        renderError.value = error instanceof Error ? error.message : 'No se pudo componer la portada.';
    } finally {
        downloading.value = false;
    }
}

const canSave = computed(
    () => current.value !== null && !saving.value && phoneLines.value.length > 0,
);

function frameStyle(order) {
    const isPicked = order === picked.value;

    return {
        position: 'relative',
        aspectRatio: '16/9',
        overflow: 'hidden',
        cursor: 'pointer',
        background: '#0E0E10',
        border: '1px solid ' + (isPicked ? AMBER : '#1F2024'),
    };
}

async function save() {
    if (!canSave.value) {
        return;
    }

    saving.value = true;
    renderError.value = '';

    let blob;

    try {
        blob = await renderJpeg();
    } catch (error) {
        renderError.value = error instanceof Error ? error.message : 'No se pudo componer la portada.';
        saving.value = false;

        return;
    }

    router.post(
        `/stories/${props.story.slug}/thumbnail`,
        {
            name: 'Plano ' + current.value.order,
            shot_order: current.value.order,
            frame_second: current.value.seconds,
            line1: form.line1,
            line2: form.line2,
            line3: form.line3,
            font_size: form.font_size,
            pos_y: form.pos_y,
            align: form.align,
            vignette: form.vignette,
            contrast: form.contrast,
            saturation: form.saturation,
            // El fichero que se guarda es exactamente el que se acaba de componer, no una
            // reconstrucción a partir de los ajustes: lo que se descargue será lo aprobado.
            image: new File([blob], 'miniatura.jpg', { type: 'image/jpeg' }),
        },
        { preserveScroll: true, forceFormData: true, onFinish: () => (saving.value = false) },
    );
}

function load(variant) {
    picked.value = variant.shot_order;
    form.line1 = variant.line1 ?? '';
    form.line2 = variant.line2 ?? '';
    form.line3 = variant.line3 ?? '';
    form.font_size = variant.font_size;
    form.pos_y = variant.pos_y;
    form.align = variant.align;
    form.vignette = variant.vignette;
    form.contrast = variant.contrast;
    form.saturation = variant.saturation;
}

function choose(variant) {
    router.post(`/stories/${props.story.slug}/thumbnail/${variant.id}/select`, {}, { preserveScroll: true });
}

function remove(variant) {
    if (!window.confirm('¿Borrar la variante «' + variant.name + '»?')) {
        return;
    }

    router.delete(`/stories/${props.story.slug}/thumbnail/${variant.id}`, { preserveScroll: true });
}

function variantUrl(variant) {
    return `/stories/${props.story.slug}/thumbnail/${variant.shot_order}/image`;
}
</script>

<template>
    <Head title="Miniatura" />

    <div v-if="!story" style="padding:26px 30px 60px">
        <div style="border:1px dashed #2A2B2F;padding:70px 30px;text-align:center;background:#0F0F11">
            <div style="font-size:17px;font-weight:800;margin-bottom:6px">Todavía no hay planos de los que sacar portada</div>
            <div style="font-size:12.5px;color:#8E8D8A;max-width:430px;margin:0 auto 20px;line-height:1.6">Las candidatas se proponen cuando una historia tiene imágenes generadas. Es el paso 5.</div>
            <Link href="/queue" style="background:#E2A044;color:#151006;padding:9px 18px;font-weight:800;text-decoration:none">Ir a la cola</Link>
        </div>
    </div>

    <div v-else style="padding:22px 24px 60px">
        <div style="margin-bottom:18px">
            <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:5px">Miniatura · {{ story.title }}</div>
            <h1 style="font-size:24px;font-weight:800;letter-spacing:-.02em">Composición</h1>
            <div style="font-size:11.5px;color:#605F5D;margin-top:4px">{{ candidates.length }} planos propuestos, ordenados por lo que aguanta una portada.</div>
        </div>

        <div style="display:flex;gap:22px;align-items:flex-start;min-width:1240px">

            <div style="width:168px;flex:none">
                <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:9px">Candidatas</div>
                <div style="display:flex;flex-direction:column;gap:8px;max-height:660px;overflow-y:auto;padding-right:4px">
                    <div v-for="(c, i) in candidates" :key="c.order">
                        <div :style="frameStyle(c.order)" @click="picked = c.order">
                            <img
                                :src="`/stories/${story.slug}/thumbnail/${c.order}/image`"
                                loading="lazy"
                                alt=""
                                style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block"
                            />
                            <div style="position:absolute;left:0;right:0;bottom:0;display:flex;justify-content:space-between;padding:3px 5px;font-size:9.5px;color:rgba(255,255,255,.75);text-shadow:0 1px 2px #000">
                                <span>{{ i + 1 }}º · plano {{ c.order }}</span>
                                <span>{{ (c.progress * 100).toFixed(0) }} %</span>
                            </div>
                        </div>
                        <div style="font-size:9.5px;color:#605F5D;line-height:1.45;margin-top:4px">{{ c.reasons.join(' · ') }}</div>
                    </div>
                </div>
            </div>

            <div style="flex:1;min-width:0">
                <div ref="canvas" style="position:relative;aspect-ratio:16/9;overflow:hidden;background:#000;border:1px solid #212226">
                    <img
                        v-if="imageUrl"
                        :src="imageUrl"
                        alt=""
                        :style="{ position:'absolute', inset:0, width:'100%', height:'100%', objectFit:'cover', filter }"
                    />
                    <div :style="vignette"></div>
                    <div :style="textBlock(canvasWidth)">
                        <div v-if="form.line1" :style="lineStyle(canvasWidth, false)">{{ form.line1 }}</div>
                        <div v-if="form.line2" :style="lineStyle(canvasWidth, true)">{{ form.line2 }}</div>
                        <div v-if="form.line3" :style="lineStyle(canvasWidth, false)">{{ form.line3 }}</div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:14px;margin-top:9px;font-size:11px;color:#605F5D">
                    <span>1280 × 720</span><span>·</span>
                    <span>contraste {{ form.contrast }} %</span><span>·</span>
                    <span>saturación {{ form.saturation }} %</span>
                    <span v-if="current" style="margin-left:auto">plano {{ current.order }} · {{ current.seconds.toFixed(1) }}s</span>
                </div>

                <div style="display:flex;align-items:center;gap:12px;margin-top:12px">
                    <button
                        type="button"
                        :disabled="!canSave || downloading"
                        class="thumb-download"
                        style="background:#E2A044;color:#151006;border:0;padding:9px 16px;font-weight:800;font-size:12.5px;cursor:pointer"
                        :style="canSave && !downloading ? '' : 'opacity:.4;cursor:not-allowed'"
                        @click="download"
                    >{{ downloading ? 'Componiendo…' : 'Descargar para YouTube' }}</button>
                    <span style="font-size:11px;color:#605F5D;line-height:1.5">
                        JPEG de {{ YT_WIDTH }} × {{ YT_HEIGHT }}, por debajo de {{ (YT_MAX_BYTES / 1024 / 1024).toFixed(0) }} MB — lo que acepta YouTube.
                    </span>
                    <span v-if="renderError" style="font-size:11px;color:#E58C7F">{{ renderError }}</span>
                </div>

                <div
                    v-if="missingFeatures.length > 0"
                    style="border:1px solid #5A2E28;background:#160F0E;padding:10px 13px;margin-top:10px;font-size:11.5px;color:#E58C7F;line-height:1.55"
                >
                    Este navegador no soporta {{ missingFeatures.join(' ni ') }}. La portada descargada no será igual a la que ves aquí.
                </div>

                <div style="margin-top:20px;border-top:2px solid #2A2B2F;padding-top:14px">
                    <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:12px">
                        <div style="font-size:11px;letter-spacing:.09em;text-transform:uppercase;font-weight:800">Variantes guardadas</div>
                        <button
                            type="button"
                            :disabled="!canSave"
                            style="background:transparent;border:1px solid #2A2B2F;color:#E2A044;padding:5px 11px;cursor:pointer;font-size:11.5px"
                            :style="canSave ? '' : 'opacity:.4;cursor:not-allowed'"
                            @click="save"
                        >{{ saving ? 'Guardando…' : 'Guardar la actual' }}</button>
                        <span v-if="!canSave && phoneLines.length === 0" style="font-size:11px;color:#605F5D">Escribe al menos una línea.</span>
                    </div>
                    <div style="display:flex;gap:14px;flex-wrap:wrap">
                        <div v-for="v in variants" :key="v.id">
                            <div :style="{ width: PHONE_WIDTH + 'px' }">
                                <div style="position:relative;aspect-ratio:16/9;overflow:hidden;background:#000;border:1px solid" :style="{ borderColor: v.is_selected ? AMBER : '#212226' }">
                                    <img
                                        :src="variantUrl(v)"
                                        alt=""
                                        :style="{ position:'absolute', inset:0, width:'100%', height:'100%', objectFit:'cover', filter:`contrast(${v.contrast}%) saturate(${v.saturation}%)` }"
                                    />
                                    <div :style="{ position:'absolute', inset:0, background:`radial-gradient(120% 90% at 50% 40%, transparent 30%, rgba(0,0,0,${v.vignette/100}) 100%)` }"></div>
                                    <div :style="{ position:'absolute', left:'6%', right:'6%', top: v.pos_y + '%', transform:'translateY(-50%)', textAlign: v.align, lineHeight:1.02, fontWeight:800, letterSpacing:'-0.02em' }">
                                        <div v-if="v.line1" :style="{ fontSize: (v.font_size * PHONE_WIDTH / CANVAS_WIDTH) + 'px', color:'#F2F0ED', textTransform:'uppercase' }">{{ v.line1 }}</div>
                                        <div v-if="v.line2" :style="{ fontSize: (v.font_size * PHONE_WIDTH / CANVAS_WIDTH) + 'px', color:AMBER, textTransform:'uppercase' }">{{ v.line2 }}</div>
                                        <div v-if="v.line3" :style="{ fontSize: (v.font_size * PHONE_WIDTH / CANVAS_WIDTH) + 'px', color:'#F2F0ED', textTransform:'uppercase' }">{{ v.line3 }}</div>
                                    </div>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:5px;font-size:10.5px;color:#605F5D">
                                    <span :style="{ color: v.is_selected ? AMBER : '#605F5D', fontWeight: v.is_selected ? 800 : 400 }">{{ v.is_selected ? '★ elegida' : v.name }}</span>
                                    <span style="display:flex;gap:8px">
                                        <button type="button" style="background:none;border:0;color:#E2A044;cursor:pointer;padding:0;font-size:10.5px" @click="load(v)">cargar</button>
                                        <button v-if="!v.is_selected" type="button" style="background:none;border:0;color:#8E8D8A;cursor:pointer;padding:0;font-size:10.5px" @click="choose(v)">elegir</button>
                                        <a
                                            v-if="v.has_file"
                                            :href="`/stories/${story.slug}/thumbnail/${v.id}/download`"
                                            style="color:#8E8D8A;font-size:10.5px;text-decoration:none"
                                        >descargar</a>
                                        <button type="button" style="background:none;border:0;color:#605F5D;cursor:pointer;padding:0;font-size:10.5px" @click="remove(v)">borrar</button>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div v-if="variants.length === 0" style="font-size:11.5px;color:#605F5D;max-width:340px;line-height:1.6">Guarda una composición para compararla aquí al tamaño real.</div>
                    </div>
                </div>
            </div>

            <div style="width:300px;flex:none;display:flex;flex-direction:column;gap:18px">
                <div style="border:1px solid #2A2B2F;background:#0E0E10;padding:14px">
                    <div style="font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#8E8D8A;margin-bottom:10px">Como se ve en el móvil</div>
                    <div :style="{ width: PHONE_WIDTH + 'px', position:'relative', aspectRatio:'16/9', overflow:'hidden', background:'#000', border:'1px solid #212226' }">
                        <img
                            v-if="imageUrl"
                            :src="imageUrl"
                            alt=""
                            :style="{ position:'absolute', inset:0, width:'100%', height:'100%', objectFit:'cover', filter }"
                        />
                        <div :style="vignette"></div>
                        <div :style="textBlock(PHONE_WIDTH)">
                            <div v-if="form.line1" :style="lineStyle(PHONE_WIDTH, false)">{{ form.line1 }}</div>
                            <div v-if="form.line2" :style="lineStyle(PHONE_WIDTH, true)">{{ form.line2 }}</div>
                            <div v-if="form.line3" :style="lineStyle(PHONE_WIDTH, false)">{{ form.line3 }}</div>
                        </div>
                    </div>
                    <div style="font-size:10.5px;color:#605F5D;margin-top:8px;line-height:1.5">168 píxeles de ancho — el tamaño real en la lista de YouTube.</div>
                    <div :style="{ fontSize:'11px', marginTop:'8px', fontWeight:800, color: legibility.color }">{{ legibility.label }}</div>
                </div>

                <div style="border-top:2px solid #2A2B2F;padding-top:14px;display:flex;flex-direction:column;gap:14px">
                    <div>
                        <div style="font-size:11px;color:#8E8D8A;margin-bottom:6px">Línea 1</div>
                        <input v-model="form.line1" maxlength="255" style="width:100%;background:#131316;border:1px solid #2A2B2F;color:#E8E6E3;padding:8px 10px;font-size:12px" />
                    </div>
                    <div>
                        <div style="font-size:11px;color:#8E8D8A;margin-bottom:6px">Línea 2 <span style="color:#E2A044">· acento ámbar</span></div>
                        <input v-model="form.line2" maxlength="255" style="width:100%;background:#131316;border:1px solid #2A2B2F;color:#E8E6E3;padding:8px 10px;font-size:12px" />
                    </div>
                    <div>
                        <div style="font-size:11px;color:#8E8D8A;margin-bottom:6px">Línea 3 <span style="color:#605F5D">· opcional</span></div>
                        <input v-model="form.line3" maxlength="255" style="width:100%;background:#131316;border:1px solid #2A2B2F;color:#E8E6E3;padding:8px 10px;font-size:12px" />
                    </div>

                    <div v-for="s in sliders" :key="s.key">
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:#8E8D8A;margin-bottom:6px">
                            <span>{{ s.label }}</span><span style="color:#B9B7B3">{{ s.display }}</span>
                        </div>
                        <input
                            type="range"
                            :min="s.min"
                            :max="s.max"
                            :step="s.step"
                            :value="form[s.key]"
                            style="width:100%;accent-color:#E2A044;background:transparent"
                            @input="form[s.key] = Number($event.target.value)"
                        />
                    </div>

                    <div>
                        <div style="font-size:11px;color:#8E8D8A;margin-bottom:6px">Posición del texto</div>
                        <div style="display:flex;gap:1px;background:#212226;border:1px solid #212226">
                            <button
                                v-for="a in ALIGNS"
                                :key="a.key"
                                type="button"
                                :style="{ flex:1, background: form.align === a.key ? '#1E1F22' : '#131316', border:0, padding:'7px 0', fontSize:'11.5px', cursor:'pointer', color: form.align === a.key ? AMBER : '#605F5D', fontWeight: form.align === a.key ? 800 : 400 }"
                                @click="form.align = a.key"
                            >{{ a.label }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
