#!/usr/bin/env bash
# Vista limpia del progreso de una historia. Uso: bash scripts/story-status.sh [--watch]

cd /Users/brayancamilosilvagomez/Desktop/horror-studio || exit 1

LOG=storage/logs/pipeline-run.log
SLUG=$(grep -a '^.*Historia:' "$LOG" 2>/dev/null | tail -1 | sed 's/.*Historia:[^ ]* //' | tr -d '\r')

limpio() { sed 's/\x1b\[[0-9;]*m//g'; }

pinta() {
    clear 2>/dev/null || printf '\033[2J\033[H'
    printf '\033[1m  %s\033[0m\n' "${SLUG:-sin historia}"
    printf '  \033[2m%s\033[0m\n\n' "$(date '+%H:%M:%S')"

    # Pasos ya terminados
    grep -a '✓\|✗' "$LOG" 2>/dev/null | limpio | while read -r linea; do
        if [[ $linea == *✗* ]]; then
            printf '  \033[31m%s\033[0m\n' "$linea"
        else
            printf '  \033[32m%s\033[0m\n' "$linea"
        fi
    done

    # Paso en curso: el último ═══ sin ✓/✗ detrás
    local ultimo_paso
    ultimo_paso=$(grep -a '═══' "$LOG" 2>/dev/null | limpio | tail -1 | sed 's/═//g' | xargs)
    local terminados en_curso
    terminados=$(grep -ac '✓\|✗' "$LOG" 2>/dev/null)
    en_curso=$(grep -ac '═══' "$LOG" 2>/dev/null)

    if [[ $en_curso -gt $terminados ]]; then
        printf '\n  \033[1;33m▶ %s\033[0m\n' "$ultimo_paso"

        # En imágenes, la barra del log es la de la fase de dirección y engaña.
        # El progreso de verdad son los imagePath ya escritos en shots.json.
        if [[ $ultimo_paso == *IMÁGENES* && -n $SLUG ]]; then
            php -r '
                $f = "storage/app/stories/'"$SLUG"'/shots.json";
                if (! is_file($f)) { echo "    planificando planos...\n"; exit; }
                $d = json_decode(file_get_contents($f), true);
                $shots = $d["shots"] ?? [];
                $con = 0;
                foreach ($shots as $s) {
                    if (! empty($s["imagePath"]) && file_exists($s["imagePath"])) { $con++; }
                }
                $total = count($shots);
                $faltan = $total - $con;
                printf("    planos: %d/%d (%d%%)\n", $con, $total, $total ? $con * 100 / $total : 0);
                printf("    quedan ~%d min a 45s/imagen\n", (int) round($faltan * 45 / 60));
            ' 2>/dev/null
        else
            local prog
            prog=$(tr '\r' '\n' < "$LOG" | limpio | grep -aoE '[0-9]+/[0-9]+ \[' | tail -1 | tr -d ' [')
            [[ -n $prog ]] && printf '    progreso: %s\n' "$prog"
        fi
    fi

    # Errores del log de la aplicación, solo recientes: uno viejo ya resuelto
    # asusta más de lo que informa.
    local corte err
    corte=$(date -v-10M '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -d '10 minutes ago' '+%Y-%m-%d %H:%M:%S')
    err=$(grep -a 'ERROR\|CRITICAL' storage/logs/laravel.log 2>/dev/null \
        | awk -v c="[$corte]" '$0 > c' | tail -1 | cut -c1-110)
    [[ -n $err ]] && printf '\n  \033[31múltimo error (10 min):\033[0m %s\n' "$err"
}

if [[ ${1:-} == --watch ]]; then
    while true; do pinta; sleep 5; done
else
    pinta
fi
