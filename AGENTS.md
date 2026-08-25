# Contexto del proyecto

App Laravel que genera vídeos de historias de terror en español para YouTube.
Pipeline: guion (LLM) → narración (TTS) → imágenes (IA) → render (FFmpeg) → revisión manual → subida.

Estamos en la FASE 1: solo el generador de guiones. Sin base de datos, sin colas, sin UI.
Todo se ejecuta con comandos artisan y escribe en `storage/app/stories/`.

## timings.json (entrada de la Fase 3)

El generador de imágenes de la Fase 3 **no transcribe ni estima duraciones**. Lee `storage/app/stories/{slug}/timings.json`, escrito por `App\Services\Audio\TranscriptTimer` tras alinear el máster (`narration.wav`) con las frases originales.

`TranscriptTimer::time($slug, $audioPath, $sentences)` es el método que produce este fichero. `start`/`end` van en **segundos** (3 decimales) y marcan solo el habla; el silencio posterior es `pauseAfter` y/o el `start` de la frase siguiente.

```json
{
  "version": 1,
  "sentences": [
    {
      "order": 1,
      "sceneOrder": 1,
      "text": "The door closed.",
      "start": 0.0,
      "end": 1.42,
      "pauseAfter": 0.45,
      "alignment": "text"
    }
  ],
  "scenes": [
    {
      "order": 1,
      "start": 0.0,
      "end": 14.2,
      "duration": 14.2,
      "sentenceCount": 8
    }
  ]
}
```

| Campo | Dónde | Qué es |
|---|---|---|
| `version` | raíz | Esquema. Hoy `1`. Si cambia, bump. |
| `sentences[].order` | frase | Orden global de narración, 1-based. |
| `sentences[].sceneOrder` | frase | Escena del guion (`StoryScene.order`). Agrupa el plano. |
| `sentences[].text` | frase | Texto original del guion, no la transcripción de Whisper. |
| `sentences[].start` | frase | Inicio del habla de esa frase en el máster. |
| `sentences[].end` | frase | Fin del habla. **No incluye** la pausa. |
| `sentences[].pauseAfter` | frase | Silencio pedido al ensamblar (s). Entre escenas es el valor largo. |
| `sentences[].alignment` | frase | `text` = emparejado por texto normalizado; `sequential` = respaldo por posición. Diagnóstico; la Fase 3 puede ignorarlo. |
| `scenes[].order` | escena | Igual que `sceneOrder`. Una entrada por escena, en orden. |
| `scenes[].start` | escena | `start` de la primera frase de la escena. |
| `scenes[].end` | escena | `start` de la siguiente escena; en la última, `end` de la última frase + su `pauseAfter`. |
| `scenes[].duration` | escena | `end - start`. Tiempo que el plano de esa escena permanece en pantalla. |
| `scenes[].sentenceCount` | escena | Frases cubiertas por ese plano. |

Cómo usar esto en la Fase 3:

1. Una imagen por escena, la de `StoryScene.imagePrompt` con el mismo `order` que `scenes[].order`.
2. Duración del plano = `scenes[].duration` (no recalcular a partir de recuento de palabras).
3. Corte entre planos = `scenes[].end` de una escena = `scenes[].start` de la siguiente. Las pausas entre escenas quedan dentro del plano que termina.
4. No uses `sentences[].end` como corte de plano: deja fuera el silencio y desincroniza imagen y voz.

## Reglas
- PHP 8.3+, tipado estricto (`declare(strict_types=1)`) en todos los archivos.
- Nombres de clases, métodos y variables en inglés. Comentarios y mensajes de consola en español.
- Nada de facades dentro de los services: inyección por constructor.
- Un archivo, una responsabilidad. No metas lógica de negocio en los comandos.
- No instales paquetes sin preguntar antes. Para HTTP usa el cliente `Http` de Laravel.
- No crees archivos que no te haya pedido explícitamente.
- No toques `.env` ni me pidas nunca que te enseñe su contenido.
