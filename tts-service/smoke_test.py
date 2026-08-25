"""Prueba de humo contra el sidecar Kokoro ya en marcha."""

from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.request
import wave
from pathlib import Path

BASE_URL = os.environ.get("TTS_BASE_URL", "http://127.0.0.1:8020")
VOICE = os.environ.get("KOKORO_VOICE", "af_heart")
SPEED = float(os.environ.get("KOKORO_SPEED", "1.0"))

SAMPLES_DIR = Path(__file__).resolve().parent / "samples"

PHRASES = [
    (
        "short",
        "The house had stopped breathing.",
    ),
    (
        "medium",
        "I heard the whistle far away, which meant it was already standing beside the truck.",
    ),
    (
        "long",
        (
            "The bag of dry cloth tapped against my hip with a brittle click of bone. "
            "I first heard that whistle on the road outside San Fernando, at midnight, "
            "when the humidity of the plains filmed the windshield and the engine died "
            "without warning. The gauge still showed half a tank. There was only tall grass "
            "and dark on both sides, and somewhere in the wind a long thin note, almost too "
            "soft to name, coming from the back of the savanna."
        ),
    ),
]


def request_json(path: str) -> dict:
    with urllib.request.urlopen(f"{BASE_URL}{path}", timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))


def synthesize(text: str, destination: Path) -> float:
    payload = json.dumps({"text": text, "voice": VOICE, "speed": SPEED}).encode("utf-8")
    request = urllib.request.Request(
        f"{BASE_URL}/synthesize",
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    started = time.perf_counter()
    with urllib.request.urlopen(request, timeout=300) as response:
        destination.write_bytes(response.read())
    return time.perf_counter() - started


def wav_duration_seconds(path: Path) -> float:
    with wave.open(str(path), "rb") as handle:
        return handle.getnframes() / float(handle.getframerate())


def main() -> int:
    try:
        health = request_json("/health")
    except urllib.error.URLError as error:
        print(f"No se pudo conectar a {BASE_URL}: {error}", file=sys.stderr)
        print("Arranca antes: uvicorn main:app --host 127.0.0.1 --port 8020 --workers 1", file=sys.stderr)
        return 1

    if health.get("status") != "ok" or not health.get("model_loaded"):
        print(f"El servicio no está listo: {health}", file=sys.stderr)
        return 1

    SAMPLES_DIR.mkdir(parents=True, exist_ok=True)
    print(f"Servicio en {BASE_URL}  voz={VOICE}  speed={SPEED}")
    print()
    print(f"{'frase':<8} {'chars':>5} {'audio_s':>8} {'compute_s':>10} {'audio/s':>8}")
    print("-" * 44)

    for name, text in PHRASES:
        path = SAMPLES_DIR / f"{name}.wav"
        compute_s = synthesize(text, path)
        audio_s = wav_duration_seconds(path)
        realtime = audio_s / compute_s if compute_s > 0 else 0.0
        print(
            f"{name:<8} {len(text):>5} {audio_s:>8.2f} {compute_s:>10.2f} {realtime:>8.2f}"
        )
        print(f"  → {path}")

    print()
    print("audio/s = segundos de audio generados por segundo de cómputo.")
    print("Mayor que 1.00 significa más rápido que tiempo real.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
