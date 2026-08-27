# Kokoro TTS sidecar

Servicio FastAPI local. Mantiene Kokoro en memoria para no recargar pesos en cada frase. Laravel hablará con él por HTTP en `127.0.0.1`.

## Requisitos

- Python 3.11 o 3.12. No es un mínimo abierto: `kokoro` y `misaki` declaran `>=3.10,<3.13`, y
  `numpy` exige `>=3.11`. Con 3.13 o superior la instalación falla.
- `espeak-ng` en el sistema (G2P de Kokoro)

macOS:

```bash
brew install espeak-ng
```

Debian/Ubuntu:

```bash
sudo apt-get install -y espeak-ng
```

## Instalación

Desde la raíz del repo:

```bash
cd tts-service
python3.11 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

La primera ejecución descarga el modelo desde Hugging Face.

## Arranque manual

Solo localhost. No uses `0.0.0.0`. Un solo worker: el modelo no es seguro en paralelo.

```bash
cd tts-service
source venv/bin/activate
uvicorn main:app --host 127.0.0.1 --port 8020 --workers 1
```

Comprobación:

```bash
curl http://127.0.0.1:8020/health
curl http://127.0.0.1:8020/voices
```

Síntesis de prueba:

```bash
curl -X POST http://127.0.0.1:8020/synthesize \
  -H 'Content-Type: application/json' \
  -d '{"text":"The house had stopped breathing.","voice":"af_heart","speed":1.0}' \
  --output /tmp/kokoro.wav
```

Variables de entorno opcionales:

| Variable | Por defecto | Qué hace |
| --- | --- | --- |
| `KOKORO_VOICE` | `af_heart` | Voz si el cliente no envía `voice` |
| `KOKORO_LANG` | `a` | Código de idioma de Kokoro (`a` = inglés americano) |
| `KOKORO_DEVICE` | `cpu` | Dispositivo de inferencia |

## Supervisor

Sustituye `USER` y la ruta del proyecto. El servicio debe escuchar solo en `127.0.0.1`.

```ini
[program:kokoro-tts]
user=USER
directory=/home/USER/moviemaker/tts-service
command=/home/USER/moviemaker/tts-service/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8020 --workers 1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/var/log/kokoro-tts.log
stderr_logfile=/var/log/kokoro-tts.err.log
environment=KOKORO_VOICE="af_heart",KOKORO_LANG="a",KOKORO_DEVICE="cpu"
```

Activar:

```bash
sudo cp kokoro-tts.conf /etc/supervisor/conf.d/kokoro-tts.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start kokoro-tts
```
