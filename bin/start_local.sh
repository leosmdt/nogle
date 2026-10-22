#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source bin/ai_platform.sh

if [ ! -x "$llama_server" ] || [ "$(file_bytes "$text_model")" -ne "$text_model_bytes" ]; then
  echo "IA local ainda não instalada. Execute bin/setup_local_ai.sh primeiro (ou php bin/serve.php para usar só o cadastro)." >&2
  exit 1
fi

mkdir -p data
"$llama_server" --model "$text_model" --alias qwen3-local --host 127.0.0.1 --port 8081 \
  --ctx-size 2048 --threads 6 --parallel 1 --no-webui > data/llama.log 2>&1 &
model_pid=$!
whisper_pid=""
if [ -x "$whisper_dir/whisper-server" ] && [ "$(file_bytes "$whisper_model")" -eq "$whisper_model_bytes" ]; then
  LD_LIBRARY_PATH="$whisper_dir" "$whisper_dir/whisper-server" --model "$whisper_model" \
    --host 127.0.0.1 --port 8082 --language pt --threads 6 --no-timestamps --audio-ctx 768 \
    > data/whisper.log 2>&1 &
  whisper_pid=$!
else
  echo "Voz local não instalada; o microfone usará o reconhecimento do navegador."
fi

# O modelo de visão é iniciado pelo PHP na primeira foto (PID em data/vision.pid).
# Este vigia o desliga depois de 10 minutos sem uso, já que o PHP não roda nada entre requisições.
stop_vision() {
  if [ -f data/vision.pid ]; then
    kill "$(cat data/vision.pid)" 2>/dev/null || true
    rm -f data/vision.pid
  fi
}
(
  while sleep 30; do
    if [ -f data/vision.pid ] && [ -n "$(find data/vision.last -mmin +10 2>/dev/null)" ]; then
      stop_vision
    fi
  done
) &
watcher_pid=$!
cleanup() { kill "$model_pid" $whisper_pid "$watcher_pid" 2>/dev/null || true; stop_vision; }
trap cleanup EXIT INT TERM

export NOGLE_LLAMA_SERVER="$PWD/$llama_server"
echo "IA local iniciando em 127.0.0.1:8081; aguarde alguns segundos para o modelo carregar."
php bin/serve.php "$@"
