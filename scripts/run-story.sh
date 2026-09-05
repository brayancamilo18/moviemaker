#!/usr/bin/env bash
# Encadena los pasos de una historia y los vuelca a una sola terminal.
# Uso: bash scripts/run-story.sh <ruta-del-json>

set -uo pipefail

FILE="${1:?Falta la ruta del JSON de la historia}"
SLUG="$(basename "$FILE" .json)"

# Inworld devuelve el audio como base64 dentro del JSON, y la memoria crece con
# cada frase sintetizada: con los 128 MB por defecto la narración muere pasadas
# unas cien peticiones vivas. El síntoma es un "Allowed memory size exhausted"
# en Http\Client\Response::json(), a mitad de narración y sin dejar nada en disco.
artisan() {
    php -d memory_limit=1G artisan "$@"
}

banner() {
    printf '\n\033[1;33m═══ %s ═══\033[0m  \033[2m%s\033[0m\n' "$1" "$(date '+%H:%M:%S')"
}

step() {
    local nombre="$1"
    shift
    banner "$nombre"
    local inicio
    inicio=$(date +%s)

    if ! "$@"; then
        printf '\n\033[1;31m✗ FALLÓ: %s\033[0m (tras %ss)\n' "$nombre" "$(( $(date +%s) - inicio ))"
        exit 1
    fi

    printf '\033[1;32m✓ %s\033[0m en %ss\n' "$nombre" "$(( $(date +%s) - inicio ))"
}

printf '\033[1mHistoria:\033[0m %s\n' "$SLUG"
printf '\033[1mArranque:\033[0m %s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
INICIO_TOTAL=$(date +%s)

step "NARRACIÓN (Inworld · voz Blake)" artisan story:narrate "$FILE"
step "IMÁGENES"                       artisan story:images   "$FILE"
step "SONIDO"                         artisan story:sounds   "$FILE"
# La validación va después de la mezcla: comprueba narration_mix.wav, que es
# justo lo que produce story:mix. Antes de la mezcla siempre falla.
step "MEZCLA"                         artisan story:mix      "$FILE"
step "VALIDACIÓN"                     artisan story:validate "$FILE"
step "RENDER"                         artisan story:render   "$FILE"

TOTAL=$(( $(date +%s) - INICIO_TOTAL ))
printf '\n\033[1;32m════ HISTORIA COMPLETA ════\033[0m  %sh %sm\n' "$(( TOTAL / 3600 ))" "$(( (TOTAL % 3600) / 60 ))"
