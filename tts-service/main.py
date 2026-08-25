"""Sidecar local de Kokoro: el modelo se carga una vez y se reutiliza."""

from __future__ import annotations

import asyncio
import io
import logging
import os
import sys
import time
from contextlib import asynccontextmanager
from typing import Any

import numpy as np
import soundfile as sf
from fastapi import FastAPI, HTTPException
from fastapi.responses import StreamingResponse
from kokoro import KPipeline
from pydantic import BaseModel, Field

SAMPLE_RATE = 24_000
DEFAULT_VOICE = os.environ.get("KOKORO_VOICE", "af_heart")
DEFAULT_LANG = os.environ.get("KOKORO_LANG", "a")
DEFAULT_DEVICE = os.environ.get("KOKORO_DEVICE", "cpu")

# Voces del repositorio hexgrad/Kokoro-82M, agrupadas por lang_code.
VOICES_BY_LANG: dict[str, list[str]] = {
    "a": [
        "af_heart", "af_alloy", "af_aoede", "af_bella", "af_jessica", "af_kore",
        "af_nicole", "af_nova", "af_river", "af_sarah", "af_sky", "am_adam",
        "am_echo", "am_eric", "am_fenrir", "am_liam", "am_michael", "am_onyx",
        "am_puck", "am_santa",
    ],
    "b": [
        "bf_alice", "bf_emma", "bf_isabella", "bf_lily",
        "bm_daniel", "bm_fable", "bm_george", "bm_lewis",
    ],
    "e": ["ef_dora", "em_alex", "em_santa"],
    "f": ["ff_siwis"],
    "h": ["hf_alpha", "hf_beta", "hm_omega", "hm_psi"],
    "i": ["if_sara", "im_nicola"],
    "j": ["jf_alpha", "jf_gongitsune", "jf_nezumi", "jf_tebukuro", "jm_kumo"],
    "p": ["pf_dora", "pm_alex", "pm_santa"],
    "z": [
        "zf_xiaobei", "zf_xiaoni", "zf_xiaoxiao", "zf_xiaoyi",
        "zm_yunjian", "zm_yunxi", "zm_yunxia", "zm_yunyang",
    ],
}

logger = logging.getLogger("kokoro-tts")
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    stream=sys.stdout,
)

pipeline: KPipeline | None = None
model_loaded = False
inference_lock = asyncio.Lock()


class SynthesizeRequest(BaseModel):
    text: str = Field(min_length=1)
    voice: str = Field(default_factory=lambda: DEFAULT_VOICE)
    speed: float = Field(default=1.0, ge=0.5, le=2.0)


def _load_model() -> None:
    global pipeline, model_loaded

    logger.info(
        "Cargando Kokoro lang=%s device=%s voice_default=%s",
        DEFAULT_LANG,
        DEFAULT_DEVICE,
        DEFAULT_VOICE,
    )
    pipeline = KPipeline(
        lang_code=DEFAULT_LANG,
        device=DEFAULT_DEVICE,
    )
    model_loaded = pipeline.model is not None
    logger.info("Modelo Kokoro cargado (model_loaded=%s)", model_loaded)


def _to_numpy(audio: Any) -> np.ndarray:
    if hasattr(audio, "detach"):
        audio = audio.detach().cpu().numpy()
    return np.asarray(audio, dtype=np.float32)


def _synthesize(text: str, voice: str, speed: float) -> bytes:
    if pipeline is None or not model_loaded:
        raise RuntimeError("El modelo Kokoro no está cargado.")

    chunks: list[np.ndarray] = []
    for _graphemes, _phonemes, audio in pipeline(text, voice=voice, speed=speed):
        if audio is None:
            continue
        chunks.append(_to_numpy(audio))

    if not chunks:
        raise RuntimeError("Kokoro no generó audio.")

    waveform = np.concatenate(chunks)
    buffer = io.BytesIO()
    sf.write(buffer, waveform, SAMPLE_RATE, format="WAV")
    return buffer.getvalue()


@asynccontextmanager
async def lifespan(_app: FastAPI):
    _load_model()
    yield


app = FastAPI(title="Kokoro TTS sidecar", lifespan=lifespan)


@app.get("/health")
async def health() -> dict[str, object]:
    return {"status": "ok", "model_loaded": model_loaded}


@app.get("/voices")
async def voices() -> dict[str, object]:
    available = VOICES_BY_LANG.get(DEFAULT_LANG, [])
    return {
        "lang": DEFAULT_LANG,
        "default": DEFAULT_VOICE,
        "voices": available,
    }


@app.post("/synthesize")
async def synthesize(payload: SynthesizeRequest) -> StreamingResponse:
    voice = payload.voice.strip() or DEFAULT_VOICE
    known = VOICES_BY_LANG.get(DEFAULT_LANG, [])
    if known and voice not in known:
        raise HTTPException(status_code=400, detail=f"Voz desconocida: {voice}")

    started = time.perf_counter()
    try:
        async with inference_lock:
            wav_bytes = await asyncio.to_thread(
                _synthesize,
                payload.text,
                voice,
                payload.speed,
            )
    except HTTPException:
        raise
    except Exception as error:
        logger.exception("Fallo al sintetizar")
        raise HTTPException(status_code=500, detail=str(error)) from error

    elapsed = time.perf_counter() - started
    logger.info(
        "síntesis voice=%s chars=%s seconds=%.3f",
        voice,
        len(payload.text),
        elapsed,
    )

    return StreamingResponse(
        io.BytesIO(wav_bytes),
        media_type="audio/wav",
        headers={"Content-Disposition": 'inline; filename="speech.wav"'},
    )
