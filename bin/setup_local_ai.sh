#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source bin/ai_platform.sh

if [ -z "$llama_asset" ]; then
  echo "A IA local não tem binários prontos para $(uname -s) $(uname -m). O cadastro funciona normalmente com php bin/serve.php." >&2
  exit 1
fi
mkdir -p .runtime/llama "$whisper_dir" data/models
download_dir=$(mktemp -d)
trap 'rm -rf "$download_dir"' EXIT

download_checked() {
  local label="$1" url="$2" path="$3" expected="$4"
  if [ "$(file_bytes "$path")" -ne "$expected" ]; then
    echo "Baixando $label..."
    curl -fL --retry 3 --continue-at - --silent --show-error "$url" -o "$path"
  fi
  if [ "$(file_bytes "$path")" -ne "$expected" ]; then
    echo "Download incompleto de $label: $(file_bytes "$path") de $expected bytes." >&2
    exit 1
  fi
}

if [ ! -x "$llama_server" ]; then
  echo "Baixando o executor local (llama.cpp, $llama_asset)..."
  curl -fL --retry 3 --silent --show-error -o "$download_dir/llama.tar.gz" \
    "https://github.com/ggml-org/llama.cpp/releases/download/$llama_release/llama-$llama_release-bin-$llama_asset.tar.gz"
  tar -xzf "$download_dir/llama.tar.gz" -C .runtime/llama
fi
download_checked "o modelo de texto Qwen3 1.7B (cerca de 1,3 GB)" "$text_model_url" "$text_model" "$text_model_bytes"

if [ -n "$whisper_asset" ]; then
  if [ ! -x "$whisper_dir/whisper-server" ]; then
    echo "Baixando o transcritor de voz local (whisper.cpp)..."
    curl -fL --retry 3 --silent --show-error -o "$download_dir/whisper.tar.gz" \
      "https://github.com/ggml-org/whisper.cpp/releases/download/$whisper_release/whisper-bin-$whisper_asset.tar.gz"
    tar -xzf "$download_dir/whisper.tar.gz" -C "$whisper_dir" --strip-components=1
  fi
  download_checked "o modelo de voz Whisper small (cerca de 190 MB)" "$whisper_model_url" "$whisper_model" "$whisper_model_bytes"
else
  echo "Voz local indisponível nesta plataforma; o microfone usará o reconhecimento do navegador."
fi

download_checked "o modelo de visão Qwen3-VL 2B (cerca de 1,8 GB)" "$vision_base/Qwen3-VL-2B-Instruct-Q8_0.gguf" "$vision_model" "$vision_model_bytes"
download_checked "o projetor de imagens do Qwen3-VL (cerca de 450 MB)" "$vision_base/mmproj-Qwen3-VL-2B-Instruct-Q8_0.gguf" "$vision_mmproj" "$vision_mmproj_bytes"

echo "IA local pronta. Execute bin/start_local.sh"
