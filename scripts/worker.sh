#!/usr/bin/env bash
# Atiende la cola del pipeline. Sin esto, el botón "Generar vídeo completo" deja
# el trabajo en la tabla `jobs` y no ocurre nada nunca.
#
# Uso: bash scripts/worker.sh

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

# 1 GB porque Inworld devuelve el audio como base64 dentro del JSON y la memoria
# crece con cada frase: con los 128 MB por defecto la narración muere pasadas unas
# cien peticiones vivas. Es el mismo motivo que en run-story.sh.
#
# --timeout debe cubrir RunPipelineStep::$timeout (3600 s): un paso de imágenes de
# ciento y pico planos a 45 s cada uno pasa de la media hora larga.
#
# --tries=1 porque los pasos no son idempotentes en su coste: reintentar imágenes
# vuelve a pagar el proveedor entero. Un fallo se reanuda a mano desde la pantalla.
exec php -d memory_limit=1G artisan queue:work \
    --tries=1 \
    --timeout=3600 \
    --sleep=2 \
    --rest=0 \
    -v
