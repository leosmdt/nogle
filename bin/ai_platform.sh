# Versões, caminhos e plataforma da IA local. Carregado por bin/setup_local_ai.sh e bin/start_local.sh (a partir da raiz do projeto).

llama_release="b11763"
whisper_release="b5454"
llama_server=".runtime/llama/llama-$llama_release/llama-server"
whisper_dir=".runtime/whisper/$whisper_release"

text_model="data/models/Qwen3-1.7B-Q4_K_M.gguf"
text_model_bytes=1282439264
text_model_url="https://huggingface.co/ggml-org/Qwen3-1.7B-GGUF/resolve/daeb8e2d528a760970442092f6bf1e55c3b659eb/Qwen3-1.7B-Q4_K_M.gguf"
whisper_model="data/models/ggml-small-q5_1.bin"
whisper_model_bytes=190085487
whisper_model_url="https://huggingface.co/ggerganov/whisper.cpp/resolve/5359861c739e955e79d9a303bcbc70fb988958b1/ggml-small-q5_1.bin"
vision_base="https://huggingface.co/ggml-org/Qwen3-VL-2B-Instruct-GGUF/resolve/ea6a11058182570be6436b9a2e4ee7f7b49f908d"
vision_model="data/models/Qwen3-VL-2B-Instruct-Q8_0.gguf"
vision_model_bytes=1834427296
vision_mmproj="data/models/mmproj-Qwen3-VL-2B-Instruct-Q8_0.gguf"
vision_mmproj_bytes=445053056

# O whisper.cpp só publica binários prontos para Linux; no macOS a voz usa o reconhecimento do navegador.
case "$(uname -s)-$(uname -m)" in
  Linux-x86_64) llama_asset="ubuntu-x64"; whisper_asset="ubuntu-x64" ;;
  Linux-aarch64 | Linux-arm64) llama_asset="ubuntu-arm64"; whisper_asset="ubuntu-arm64" ;;
  Darwin-arm64) llama_asset="macos-arm64"; whisper_asset="" ;;
  Darwin-x86_64) llama_asset="macos-x64"; whisper_asset="" ;;
  *) llama_asset=""; whisper_asset="" ;;
esac

# Tamanho em bytes, 0 se não existir; wc funciona igual no Linux e no macOS (stat não).
file_bytes() {
  if [ -f "$1" ]; then wc -c < "$1" | tr -d ' '; else echo 0; fi
}
