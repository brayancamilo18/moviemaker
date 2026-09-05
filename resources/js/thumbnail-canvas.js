// Compone la portada en el tamaño que espera YouTube.
//
// Se pinta en el navegador y no en el servidor porque la composición está definida en CSS: el
// filtro de contraste y saturación, el degradado radial de la viñeta, y tres líneas con su
// interletraje, su sombra y su versalita. Reproducir eso en PHP sería reimplementar las
// matrices de filtro de CSS y el dibujado de texto a mano, y bastaría con que la fuente del
// servidor no fuera la del navegador para que lo descargado no se pareciera a lo aprobado.
//
// Cada valor de aquí es el mismo que usa la previsualización del DOM. Si uno se cambia allí,
// se cambia aquí, o la descarga deja de ser lo que se vio.

export const YT_WIDTH = 1280;
export const YT_HEIGHT = 720;

// Tope de peso de YouTube para una portada.
export const YT_MAX_BYTES = 2 * 1024 * 1024;

const FONT_STACK = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

// Solo existen los pesos 400, 500 y 600 de Instrument Sans: el 800 que usa toda la interfaz
// es negrita sintética. Se pide igual, para que el lienzo sintetice lo mismo que el DOM.
const FONT_WEIGHT = 800;

const MARGIN = 0.06;
const LINE_HEIGHT = 1.02;
const LETTER_SPACING = -0.02;

const SHADOW = { offsetY: 2, blur: 10, color: 'rgba(0,0,0,.85)' };

// La viñeta del DOM es radial-gradient(120% 90% at 50% 40%, transparent 30%, negro 100%).
const VIGNETTE = { rx: 1.2, ry: 0.9, cx: 0.5, cy: 0.4, clear: 0.3 };

/**
 * Dibuja la imagen recortando como object-fit: cover.
 */
function drawCover(ctx, image, width, height) {
    const scale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
    const drawWidth = image.naturalWidth * scale;
    const drawHeight = image.naturalHeight * scale;

    ctx.drawImage(
        image,
        (width - drawWidth) / 2,
        (height - drawHeight) / 2,
        drawWidth,
        drawHeight,
    );
}

function drawVignette(ctx, width, height, amount) {
    if (amount <= 0) {
        return;
    }

    const rx = VIGNETTE.rx * width;
    const ry = VIGNETTE.ry * height;
    const squash = ry / rx;

    ctx.save();
    ctx.translate(VIGNETTE.cx * width, VIGNETTE.cy * height);
    ctx.scale(1, squash);

    const gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, rx);
    gradient.addColorStop(VIGNETTE.clear, 'rgba(0,0,0,0)');
    gradient.addColorStop(1, `rgba(0,0,0,${amount / 100})`);

    ctx.fillStyle = gradient;
    // En el espacio ya deformado, un rectángulo holgado cubre el lienzo entero.
    ctx.fillRect(-width, -height / squash, width * 2, (height * 2) / squash);
    ctx.restore();
}

function drawLines(ctx, lines, width, height, options) {
    if (lines.length === 0) {
        return;
    }

    const fontSize = options.fontSize;
    const lineHeight = fontSize * LINE_HEIGHT;
    const centre = (options.posY / 100) * height;
    const top = centre - (lines.length * lineHeight) / 2;

    ctx.save();
    ctx.font = `${FONT_WEIGHT} ${fontSize}px ${FONT_STACK}`;
    ctx.letterSpacing = `${LETTER_SPACING * fontSize}px`;
    ctx.textBaseline = 'middle';
    ctx.textAlign = options.align;
    ctx.shadowColor = SHADOW.color;
    ctx.shadowOffsetY = SHADOW.offsetY * (width / YT_WIDTH);
    ctx.shadowBlur = SHADOW.blur * (width / YT_WIDTH);

    const left = MARGIN * width;
    const right = width - MARGIN * width;
    const x = options.align === 'center' ? width / 2 : options.align === 'right' ? right : left;

    lines.forEach((line, index) => {
        ctx.fillStyle = line.accent ? options.accentColor : options.textColor;
        ctx.fillText(
            line.text.toUpperCase(),
            x,
            top + index * lineHeight + lineHeight / 2,
            right - left,
        );
    });

    ctx.restore();
}

/**
 * Qué de lo que hace falta no soporta este navegador.
 *
 * Las dos cosas fallan calladas: un ctx.filter que no se entiende se queda en 'none' y la
 * portada sale sin contraste ni saturación; un letterSpacing que no existe se ignora y el
 * texto sale más ancho. En los dos casos el fichero descargado no sería el aprobado, y sin
 * avisar nadie lo notaría hasta tenerlo subido.
 *
 * @returns {string[]}
 */
export function unsupportedFeatures() {
    const ctx = document.createElement('canvas').getContext('2d');
    const missing = [];

    ctx.filter = 'contrast(150%)';

    if (ctx.filter !== 'contrast(150%)') {
        missing.push('los filtros de contraste y saturación');
    }

    if (!('letterSpacing' in ctx)) {
        missing.push('el interletraje del texto');
    }

    return missing;
}

/**
 * @returns {Promise<HTMLCanvasElement>}
 */
export async function composeThumbnail({
    imageUrl,
    lines,
    fontSize,
    posY,
    align,
    vignette,
    contrast,
    saturation,
    textColor,
    accentColor,
    width = YT_WIDTH,
    height = YT_HEIGHT,
}) {
    const image = await loadImage(imageUrl);

    // Sin esperar a la fuente, el lienzo mide con la de reserva y el texto sale con otro
    // ancho que el de la previsualización.
    if (document.fonts?.load) {
        await document.fonts.load(`${FONT_WEIGHT} ${fontSize}px 'Instrument Sans'`);
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);

    ctx.filter = `contrast(${contrast}%) saturate(${saturation}%)`;
    drawCover(ctx, image, width, height);
    ctx.filter = 'none';

    drawVignette(ctx, width, height, vignette);
    drawLines(ctx, lines, width, height, {
        fontSize: fontSize * (width / YT_WIDTH),
        posY,
        align,
        textColor,
        accentColor,
    });

    return canvas;
}

/**
 * JPEG por debajo del tope de YouTube. Baja la calidad solo si hace falta, porque cada
 * escalón se nota en las zonas oscuras, que es casi todo el cuadro en este material.
 *
 * @returns {Promise<Blob>}
 */
export async function toJpeg(canvas, maxBytes = YT_MAX_BYTES) {
    for (const quality of [0.92, 0.85, 0.78, 0.7, 0.6]) {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

        if (blob && blob.size <= maxBytes) {
            return blob;
        }
    }

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.5));
}

function loadImage(url) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('No se pudo cargar la imagen del plano.'));
        image.src = url;
    });
}
