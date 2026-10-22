// Assistente da tela de clientes. Usa as funções e o estado definidos em app.js, carregado antes.
// Assistente: o modelo local interpreta pedidos; a aplicação valida e executa somente ações conhecidas.
const assistantPanel = $('#assistant-panel');
const assistantInput = $('#assistant-input');
const assistantMessages = $('#assistant-messages');
let assistantBusy = false;
let assistantAiAvailable = false;
let lastClient = null;
let lastAssistantRequest = '';
let assistantSpeechLocal = false;
let assistantVisionLocal = false;
let assistantVisionLoaded = false;

function normalizePhrase(value) {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

function normalizeTargetQuery(value) {
  return value.trim()
    .replace(/^(?:o|a)\s+/i, '')
    .replace(/^clientes?\s+/i, '')
    .trim();
}

function requestsAllClients(value) {
  return /\b(?:todos?\s+(?:os?\s+)?clientes?|todas?\s+(?:as?\s+)?clientes?)\b/.test(normalizePhrase(value));
}

function assistantMessage(message, kind = 'bot', action = null) {
  const bubble = document.createElement('div');
  bubble.className = `assistant-message ${kind}`;
  bubble.textContent = message;
  if (action) {
    const button = document.createElement('button');
    button.className = 'assistant-action';
    button.type = 'button';
    button.textContent = action.label;
    button.addEventListener('click', async () => {
      button.disabled = true;
      try { await action.run(); }
      catch (error) { assistantMessage(error.message); }
      finally { button.remove(); }
    });
    bubble.append(button);
  }
  assistantMessages.append(bubble);
  assistantMessages.scrollTop = assistantMessages.scrollHeight;
  return bubble;
}

function closeAssistant() {
  assistantPanel.hidden = true;
  $('#assistant-launcher').setAttribute('aria-expanded', 'false');
  $('#assistant-launcher').hidden = false;
}

async function refreshAssistantStatus() {
  try {
    const result = await request('/api/assistant/status');
    assistantAiAvailable = result.available;
    assistantSpeechLocal = Boolean(result.speech && navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
    assistantVisionLocal = Boolean(result.vision);
    assistantVisionLoaded = Boolean(result.vision_loaded);
    $('#assistant-mode').textContent = result.available ? `${result.model}${result.speech ? ' + voz' : ''}${result.vision ? ' + fotos' : ''} · nesta máquina` : 'Comandos rápidos · IA local indisponível';
  } catch {
    assistantAiAvailable = false;
    assistantSpeechLocal = false;
    assistantVisionLocal = false;
    $('#assistant-mode').textContent = 'Comandos rápidos disponíveis';
  }
  updateMicMode();
  $('#assistant-photo').hidden = !assistantVisionLocal;
}

function basicPlan(raw) {
  const normalized = normalizePhrase(raw);
  const plan = {intent: 'unknown', query: '', status: '', fields: {}, all: false};
  if (/\b(inativos?|desativados?)\b/.test(normalized)) plan.status = 'inativos';
  else if (/\bativos?\b/.test(normalized)) plan.status = 'ativos';
  if (/^(limpar|apagar)\s+(busca|filtros?|pesquisa)|^(mostrar|listar)\s+todos/.test(normalized)) plan.intent = 'clear';
  else if (/\b(exportar|baixar)\b/.test(normalized)) plan.intent = 'export';
  else if (/\b(importar)\b/.test(normalized)) plan.intent = 'import';
  else if (/\b(quantos|quantas|contar|total)\b/.test(normalized)) plan.intent = 'count';
  else if (/^(mostrar|listar|ver)\s+(clientes?\s+)?(ativos?|inativos?|desativados?)\b/.test(normalized)) plan.intent = 'list';
  else if (/^(inativar|inative|desativar|desative)\b/.test(normalized)) plan.intent = 'inactivate';
  else if (/^(reativar|reative|ativar|ative)\b/.test(normalized)) plan.intent = 'reactivate';
  else if (/^(editar|edite|alterar|altere|mudar|mude)\b/.test(normalized)) plan.intent = 'edit';
  else if (/^(novo cliente|cadastrar|cadastre|criar|crie)\b/.test(normalized)) plan.intent = 'new';
  else if (/^(buscar|busque|procurar|procure|encontrar|encontre|pesquisar|pesquise)\b/.test(normalized)) plan.intent = 'search';
  else if (/^(ajuda|o que voce faz|comandos)\b/.test(normalized)) plan.intent = 'help';
  else plan.intent = 'search';
  if (['search', 'edit', 'inactivate', 'reactivate'].includes(plan.intent)) {
    plan.all = ['inactivate', 'reactivate'].includes(plan.intent) && requestsAllClients(raw);
    plan.query = plan.all ? '' : normalizeTargetQuery(raw.replace(/^(buscar|busque|procurar|procure|encontrar|encontre|pesquisar|pesquise|editar|edite|alterar|altere|mudar|mude|inativar|inative|desativar|desative|reativar|reative|ativar|ative)\s*/i, ''));
  }
  if (plan.intent === 'new') {
    const rest = raw.replace(/^(novo cliente|cadastrar|cadastre|criar|crie)\s*(?:cliente\s+)?/i, '').trim();
    const name = rest.split(/\b(?:cpf|telefone|celular|cep)\b/i)[0].replace(/^[,;:\s]+|[,;:\s]+$/g, '');
    if (name) plan.fields.nome = name;
    const cpf = raw.match(/\bcpf\s*[:=]?\s*([\d.\-\s]{11,18})/i);
    const phone = raw.match(/\b(?:telefone|celular)\s*[:=]?\s*([\d()\s-]{10,20})/i);
    const cep = raw.match(/\bcep\s*[:=]?\s*([\d-]{8,9})/i);
    if (cpf) plan.fields.cpf = digits(cpf[1]).slice(0, 11);
    if (phone) plan.fields.telefone = digits(phone[1]).slice(0, 11);
    if (cep) plan.fields.cep = digits(cep[1]).slice(0, 8);
  }
  return plan;
}

// A voz transcrita varia a grafia dos nomes (Natalia/Nathalia, Sousa/Souza); comparamos pelo som aproximado.
function soundKey(value) {
  return normalizePhrase(value).replace(/ph/g, 'f').replace(/h/g, '').replace(/y/g, 'i').replace(/z/g, 's').replace(/w/g, 'v').replace(/(.)\1+/g, '$1');
}

async function similarClients(value, statuses) {
  const keys = soundKey(value).split(/\s+/).filter(Boolean);
  if (!keys.length || digits(value)) return [];
  const lists = await Promise.all(statuses.map((targetStatus) => request(`/api/clientes?status=${targetStatus}`)));
  return lists.flatMap((list) => list.clientes).filter((client) => {
    const parts = soundKey(client.nome).split(/\s+/);
    return keys.every((key) => parts.some((part) => part.startsWith(key)));
  });
}

async function findClient(query, targetStatus) {
  const value = query.trim();
  if (!value) { assistantMessage('Diga o nome ou CPF do cliente.'); return null; }
  const params = new URLSearchParams({status: targetStatus, nome: digits(value).length >= 3 ? '' : value, cpf: digits(value).length >= 3 ? digits(value) : ''});
  const matches = (await request(`/api/clientes?${params}`)).clientes;
  const exact = matches.filter((client) => normalizePhrase(client.nome) === normalizePhrase(value) || client.cpf === digits(value));
  let candidates = exact.length ? exact : matches;
  if (!candidates.length) candidates = await similarClients(value, [targetStatus]);
  if (candidates.length === 1) return candidates[0];
  if (candidates.length === 0) assistantMessage(`Não encontrei “${value}” entre os clientes ${targetStatus}.`);
  else {
    $('#filter-name').value = digits(value).length >= 3 ? '' : value;
    $('#filter-cpf').value = digits(value).length >= 3 ? cpfMask(value) : '';
    await setStatus(targetStatus);
    assistantMessage(`Encontrei ${candidates.length} clientes. Refine o nome ou CPF para eu identificar um só; deixei os resultados na lista.`);
  }
  return null;
}

const answerFormats = {
  telefone: (client) => `Telefone: ${phoneMask(client.telefone)}`,
  cpf: (client) => `CPF: ${cpfMask(client.cpf)}`,
  endereco: (client) => `Endereço: ${client.endereco}, ${client.numero}${client.complemento ? ` · ${client.complemento}` : ''} · ${client.bairro} · ${client.cidade}/${client.estado}`,
  cep: (client) => `CEP: ${cepMask(client.cep)}`,
  cidade: (client) => `Cidade: ${client.cidade}/${client.estado}`,
};

function clientSummary(list, ask = []) {
  if (!list?.length) return '';
  const lines = list.slice(0, 10).map((client) => `• ${client.nome} — ${(ask.length ? ask : ['telefone']).map((item) => answerFormats[item](client).replace(/^[^:]+: /, '')).join(' · ')}`);
  if (list.length > 10) lines.push(`… e mais ${list.length - 10}. Veja a lista completa na tabela.`);
  return `\n${lines.join('\n')}`;
}

function clientDetails(list, ask = []) {
  const items = ask.length ? ask : ['cpf', 'telefone', 'endereco', 'cep'];
  return list.map((client) => [`\n${client.nome}${client.ativo ? '' : ' (inativo)'}`, ...items.map((item) => answerFormats[item](client))].join('\n')).join('\n');
}

// Recarrega o cliente lembrado para não responder com dados de antes de uma edição.
async function rememberedClient() {
  if (!lastClient) { assistantMessage('De qual cliente você está falando? Diga o nome ou CPF.'); return null; }
  for (const targetStatus of ['ativos', 'inativos']) {
    const match = (await request(`/api/clientes?status=${targetStatus}&cpf=${lastClient.cpf}`)).clientes.find((client) => client.id === lastClient.id);
    if (match) return (lastClient = match);
  }
  lastClient = null;
  assistantMessage('Não encontrei mais o cliente da conversa. Diga o nome ou CPF.');
  return null;
}

async function targetClient(plan, targetStatus) {
  const client = plan.ref ? await rememberedClient() : await findClient(plan.query, targetStatus);
  if (!client) return null;
  if (Boolean(client.ativo) !== (targetStatus === 'ativos')) {
    assistantMessage(`${client.nome} já está entre os clientes ${client.ativo ? 'ativos' : 'inativos'}.`);
    return null;
  }
  lastClient = client;
  return client;
}

async function executeAssistantPlan(plan) {
  const {intent} = plan;
  const ask = plan.ask || [];
  const cidade = plan.cidade || '';
  const target = plan.status || 'ativos';
  if (intent === 'help' || intent === 'unknown') {
    assistantMessage('Tente: “telefone da Maria”, “quem mora em Curitiba?”, “mostrar inativos”, “quantos clientes”, “cadastrar Ana CPF ... CEP ...”, “editar João”, “inativar João” ou “exportar CSV”. Depois de achar um cliente, pode continuar com “e o endereço dele?”.');
    return;
  }
  if (intent === 'clear') {
    $('#filter-form').reset();
    const result = await setStatus('ativos');
    assistantMessage(`Filtros limpos. Mostrando ${result?.length ?? 0} cliente(s) ativo(s).${clientSummary(result)}`);
    return;
  }
  if (intent === 'list' && cidade) {
    const result = (await request(`/api/clientes?${new URLSearchParams({status: target, cidade})}`)).clientes;
    assistantMessage(result.length ? `Há ${result.length} cliente(s) ${target} em ${cidade}:${clientSummary(result, ask)}` : `Não há clientes ${target} em ${cidade}.`);
    return;
  }
  if (intent === 'list') {
    $('#filter-form').reset();
    const result = await setStatus(target);
    assistantMessage(`Mostrando ${result?.length ?? 0} cliente(s) ${target}.${clientSummary(result, ask)}`);
    return;
  }
  if (intent === 'count') {
    const [active, inactive] = await Promise.all(['ativos', 'inativos'].map((value) => request(`/api/clientes?${new URLSearchParams({status: value, cidade})}`)));
    const total = (result) => (cidade ? result.clientes.length : result.total);
    const place = cidade ? ` em ${cidade}` : '';
    if (plan.status) assistantMessage(`Há ${total(plan.status === 'ativos' ? active : inactive)} cliente(s) ${plan.status}${place}.`);
    else assistantMessage(`Há ${total(active)} cliente(s) ativo(s) e ${total(inactive)} inativo(s)${place}.`);
    return;
  }
  if (intent === 'export') {
    if (plan.status) await setStatus(plan.status);
    $('#export-clients').click();
    assistantMessage('Exportei os clientes da lista atual para CSV.');
    return;
  }
  if (intent === 'import') {
    $('#import-clients').click();
    assistantMessage('Escolha um arquivo CSV. Vou validar as linhas antes de importar.');
    return;
  }
  if (intent === 'new') {
    assistantMessage(plan.fromImage
      ? `Li a foto e preenchi ${Object.keys(plan.fields).length} campo(s) no cadastro. Confira com atenção, principalmente CPF e telefone, e salve.`
      : 'Abri o cadastro. Confira os campos preenchidos e salve quando estiver correto.');
    openModal();
    for (const [field, value] of Object.entries(plan.fields || {})) {
      if (!fields.includes(field) || typeof value !== 'string') continue;
      form.elements[field].value = field === 'cpf' ? cpfMask(value) : field === 'telefone' ? phoneMask(value) : field === 'cep' ? cepMask(value) : value;
    }
    if (plan.fields?.cep) form.elements.cep.dispatchEvent(new Event('input', {bubbles: true}));
    flash('Confira os dados sugeridos pelo assistente antes de salvar.');
    return;
  }
  if (intent === 'search' && plan.ref) {
    const client = await rememberedClient();
    if (client) assistantMessage(clientDetails([client], ask).trim());
    return;
  }
  if (intent === 'search') {
    const value = plan.query.trim();
    if (!value) { assistantMessage('Diga o nome ou CPF para buscar.'); return; }
    const cpf = digits(value).length >= 3 ? digits(value) : '';
    $('#filter-name').value = cpf ? '' : value;
    $('#filter-cpf').value = cpf ? cpfMask(cpf) : '';
    let result = await setStatus(target);
    let foundStatus = target;
    if (!result?.length && !plan.status) {
      foundStatus = 'inativos';
      result = await setStatus(foundStatus);
    }
    if (!result?.length) {
      const similar = await similarClients(value, plan.status ? [plan.status] : ['ativos', 'inativos']);
      if (similar.length === 1) {
        $('#filter-name').value = similar[0].nome;
        result = await setStatus(similar[0].ativo ? 'ativos' : 'inativos');
      } else if (similar.length) {
        assistantMessage(`Não achei “${value}” exatamente, mas encontrei nomes parecidos:${clientSummary(similar, ask)}`);
        return;
      }
    }
    if (result?.length === 1) lastClient = result[0];
    if (!result?.length) assistantMessage(`Não encontrei clientes para “${value}”.`);
    else if (result.length === 1) assistantMessage(clientDetails(result, ask).trim());
    else assistantMessage(`Encontrei ${result.length} cliente(s) ${foundStatus}. A lista está filtrada.${result.length <= 3 ? `\n${clientDetails(result, ask)}` : clientSummary(result, ask)}`);
    return;
  }
  if (intent === 'edit') {
    const client = await targetClient(plan, 'ativos');
    if (!client) return;
    assistantMessage(`Abri a edição de ${client.nome}. Confira os dados e salve.`);
    openModal(client);
    for (const [field, value] of Object.entries(plan.fields || {})) {
      if (!fields.includes(field) || typeof value !== 'string') continue;
      form.elements[field].value = field === 'cpf' ? cpfMask(value) : field === 'telefone' ? phoneMask(value) : field === 'cep' ? cepMask(value) : value;
    }
    flash('Confira as alterações sugeridas pelo assistente antes de salvar.');
    return;
  }
  if (intent === 'inactivate' || intent === 'reactivate') {
    if (plan.all) {
      const sourceStatus = intent === 'inactivate' ? 'ativos' : 'inativos';
      const clients = (await request(`/api/clientes?status=${sourceStatus}`)).clientes;
      if (!clients.length) {
        assistantMessage(`Não há clientes ${sourceStatus} para ${intent === 'inactivate' ? 'inativar' : 'reativar'}.`);
        return;
      }
      const action = intent === 'inactivate' ? 'inativar' : 'reativar';
      assistantMessage(`Quer ${action} todos os ${clients.length} cliente(s) ${sourceStatus}?`, 'bot', {
        label: `Confirmar ${action} todos`,
        run: async () => {
          const quantity = (await request(`/api/clientes/${action}`, {
            method: 'PATCH',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({confirmar: true}),
          })).quantidade;
          await loadClients();
          flash(`${quantity} cliente(s) ${intent === 'inactivate' ? 'inativado(s)' : 'reativado(s)'}.`);
          assistantMessage(`${quantity} cliente(s) foram ${intent === 'inactivate' ? 'inativados' : 'reativados'}.`);
        },
      });
      return;
    }
    const client = await targetClient(plan, intent === 'inactivate' ? 'ativos' : 'inativos');
    if (!client) return;
    if (intent === 'inactivate') {
      assistantMessage(`Quer inativar ${client.nome} (${cpfMask(client.cpf)})?`, 'bot', {
        label: 'Confirmar inativação',
        run: async () => {
          await request(`/api/clientes/${client.id}/inativar`, {method: 'PATCH'});
          await loadClients();
          flash('Cliente inativado.', false, client.id);
          assistantMessage(`${client.nome} foi inativado. Você pode desfazer pela mensagem no rodapé.`);
        },
      });
    } else {
      await request(`/api/clientes/${client.id}/reativar`, {method: 'PATCH'});
      await loadClients();
      flash('Cliente reativado.');
      assistantMessage(`${client.nome} foi reativado.`);
    }
  }
}

// Uma rodada do assistente: mostra o pedido, obtém o plano (por texto ou áudio) e executa.
async function assistantTurn(userText, pendingText, interpret) {
  assistantBusy = true;
  assistantInput.value = '';
  assistantInput.disabled = true;
  $('#assistant-suggestions').hidden = true;
  const userBubble = assistantMessage(userText, 'user');
  const pending = assistantMessage(pendingText, 'bot pending');
  try {
    const {plan, said} = await interpret(userBubble, pending);
    pending.remove();
    lastAssistantRequest = said;
    await executeAssistantPlan(plan);
  } catch (error) {
    pending.remove();
    assistantMessage(error.message || 'Não consegui concluir o pedido.');
  } finally {
    assistantBusy = false;
    assistantInput.disabled = false;
    if (!assistantPanel.hidden) assistantInput.focus();
  }
}

async function sendAssistantCommand(raw) {
  const message = raw.trim();
  if (!message || assistantBusy) return;
  if (!assistantAiAvailable) await refreshAssistantStatus();
  await assistantTurn(message, assistantAiAvailable ? 'A IA local está interpretando...' : 'Interpretando comando...', async () => {
    if (assistantAiAvailable) {
      try {
        const result = await request('/api/assistant', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({message, context: lastAssistantRequest})});
        return {plan: result.plan, said: message};
      } catch {
        assistantAiAvailable = false;
        $('#assistant-mode').textContent = 'Comandos rápidos disponíveis';
      }
    }
    return {plan: basicPlan(message), said: message};
  });
}

async function sendAssistantAudio(wav) {
  await assistantTurn('🎙 Áudio gravado', 'Transcrevendo o áudio nesta máquina...', async (bubble) => {
    const result = await request(`/api/assistant/audio?${new URLSearchParams({context: lastAssistantRequest})}`, {
      method: 'POST',
      headers: {'Content-Type': 'audio/wav'},
      body: wav,
    });
    bubble.textContent = `🎙 ${result.transcript}`;
    return {plan: result.plan, said: result.transcript};
  });
}

$('#assistant-launcher').addEventListener('click', () => {
  assistantPanel.hidden = false;
  $('#assistant-launcher').hidden = true;
  $('#assistant-launcher').setAttribute('aria-expanded', 'true');
  refreshAssistantStatus();
  assistantInput.focus();
});
$('#assistant-close').addEventListener('click', closeAssistant);
$('#assistant-form').addEventListener('submit', (event) => { event.preventDefault(); sendAssistantCommand(assistantInput.value); });
$('#assistant-suggestions').addEventListener('click', (event) => {
  const command = event.target.closest('[data-command]')?.dataset.command;
  if (command) sendAssistantCommand(command);
});
// Foto: o modelo de visão local lê a imagem e o cadastro abre preenchido para revisão.
const MAX_PHOTO_SIDE = 1280;

async function shrinkPhoto(file) {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_PHOTO_SIDE / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
}

async function sendAssistantPhoto(file) {
  if (assistantBusy || !file) return;
  if (!file.type.startsWith('image/')) { assistantMessage('Envie uma imagem (JPEG, PNG ou WebP).'); return; }
  let photo;
  try {
    photo = await shrinkPhoto(file);
  } catch {
    assistantMessage('Não consegui abrir essa imagem. Tente outra foto.');
    return;
  }
  const firstLoad = !assistantVisionLoaded;
  await assistantTurn('Foto para cadastro', firstLoad ? 'Carregando o leitor de fotos (só na primeira foto)...' : 'Lendo a foto nesta máquina...', async (bubble, pending) => {
    const preview = document.createElement('img');
    preview.src = URL.createObjectURL(photo);
    preview.alt = 'Foto enviada';
    bubble.prepend(preview);
    // A leitura leva de 15 a 45 s em CPU; as mensagens mostram que o trabalho continua.
    const steps = [
      [firstLoad ? 10000 : 0, 'Lendo a foto nesta máquina...'],
      [firstLoad ? 25000 : 15000, 'Ainda lendo. Em computadores sem placa de vídeo isso leva até um minuto.'],
      [firstLoad ? 45000 : 35000, 'Quase lá: organizando os campos do cadastro...'],
    ];
    const timers = steps.map(([delay, text]) => setTimeout(() => { pending.textContent = text; }, delay));
    try {
      const result = await request('/api/assistant/image', {method: 'POST', headers: {'Content-Type': 'image/jpeg'}, body: photo});
      assistantVisionLoaded = true;
      return {plan: {...result.plan, fromImage: true}, said: 'cadastrar cliente a partir de uma foto'};
    } finally {
      timers.forEach(clearTimeout);
    }
  });
}

$('#assistant-photo').addEventListener('click', () => $('#assistant-photo-input').click());
$('#assistant-photo-input').addEventListener('change', (event) => {
  sendAssistantPhoto(event.target.files[0]);
  event.target.value = '';
});
assistantInput.addEventListener('paste', (event) => {
  const image = [...event.clipboardData.files].find((file) => file.type.startsWith('image/'));
  if (!image || !assistantVisionLocal) return;
  event.preventDefault();
  sendAssistantPhoto(image);
});

// Voz: grava no navegador e transcreve com o Whisper local; sem ele, usa o reconhecimento do navegador.
const mic = $('#assistant-mic');
const BrowserSpeech = window.SpeechRecognition || window.webkitSpeechRecognition;
const MAX_RECORDING_MS = 15000;
const defaultPlaceholder = assistantInput.placeholder;
let recorder = null;
let recordingTimer = null;
let recognition = null;

function updateMicMode() {
  mic.hidden = !(assistantSpeechLocal || BrowserSpeech);
  $('.assistant-note').textContent = assistantSpeechLocal
    ? 'A IA e a transcrição de voz rodam nesta máquina. Clique no microfone para gravar e de novo para enviar.'
    : 'A IA roda nesta máquina. A transcrição de voz depende do navegador e pode usar um serviço externo.';
}

function setMicListening(active) {
  mic.classList.toggle('listening', active);
  mic.setAttribute('aria-label', active ? 'Parar e enviar' : 'Falar comando');
  mic.title = active ? 'Parar e enviar' : 'Falar comando';
  assistantInput.placeholder = active ? 'Gravando... clique no microfone para enviar' : defaultPlaceholder;
}

// O Whisper espera WAV mono de 16 kHz; o navegador grava em WebM/Opus, então convertemos aqui.
async function toWav16k(blob) {
  const context = new AudioContext();
  const decoded = await context.decodeAudioData(await blob.arrayBuffer()).finally(() => context.close());
  if (decoded.duration < 0.4) throw new Error('A gravação ficou curta demais. Segure um pouco mais antes de enviar.');
  const offline = new OfflineAudioContext(1, Math.ceil(decoded.duration * 16000), 16000);
  const source = offline.createBufferSource();
  source.buffer = decoded;
  source.connect(offline.destination);
  source.start();
  const samples = (await offline.startRendering()).getChannelData(0);
  const view = new DataView(new ArrayBuffer(44 + samples.length * 2));
  const ascii = (offset, value) => [...value].forEach((char, index) => view.setUint8(offset + index, char.charCodeAt(0)));
  ascii(0, 'RIFF');
  view.setUint32(4, 36 + samples.length * 2, true);
  ascii(8, 'WAVE');
  ascii(12, 'fmt ');
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);
  view.setUint16(22, 1, true);
  view.setUint32(24, 16000, true);
  view.setUint32(28, 32000, true);
  view.setUint16(32, 2, true);
  view.setUint16(34, 16, true);
  ascii(36, 'data');
  view.setUint32(40, samples.length * 2, true);
  samples.forEach((sample, index) => view.setInt16(44 + index * 2, Math.max(-1, Math.min(1, sample)) * 0x7fff, true));
  return new Blob([view], {type: 'audio/wav'});
}

async function startRecording() {
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({audio: true});
  } catch {
    assistantMessage('Não consegui acessar o microfone. Verifique a permissão do navegador ou digite o pedido.');
    return;
  }
  const chunks = [];
  recorder = new MediaRecorder(stream);
  recorder.ondataavailable = (event) => { if (event.data.size) chunks.push(event.data); };
  recorder.onstop = async () => {
    stream.getTracks().forEach((track) => track.stop());
    clearTimeout(recordingTimer);
    recorder = null;
    setMicListening(false);
    let wav;
    try {
      wav = await toWav16k(new Blob(chunks));
    } catch (error) {
      assistantMessage(error.message?.startsWith('A gravação') ? error.message : 'Não consegui processar o áudio gravado. Tente de novo ou digite o pedido.');
      return;
    }
    await sendAssistantAudio(wav);
  };
  recorder.start();
  setMicListening(true);
  recordingTimer = setTimeout(() => recorder?.stop(), MAX_RECORDING_MS);
}

function toggleBrowserSpeech() {
  if (!recognition) {
    recognition = new BrowserSpeech();
    recognition.lang = 'pt-BR';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    recognition.onstart = () => setMicListening(true);
    recognition.onend = () => setMicListening(false);
    recognition.onerror = () => assistantMessage('Não consegui ouvir o comando. Verifique a permissão do microfone ou digite o pedido.');
    recognition.onresult = (event) => sendAssistantCommand(event.results[0][0].transcript);
  }
  if (mic.classList.contains('listening')) recognition.stop();
  else {
    try { recognition.start(); }
    catch { assistantMessage('Não foi possível iniciar o microfone. Digite seu pedido.'); }
  }
}

mic.addEventListener('click', () => {
  if (!assistantSpeechLocal) toggleBrowserSpeech();
  else if (recorder) recorder.stop();
  else if (!assistantBusy) startRecording();
});
updateMicMode();
refreshAssistantStatus();

// Tour guiado: aparece uma vez por navegador e pode ser reaberto pelo menu.
const ONBOARDING_KEY = 'nogle:onboarding:v1';
const onboardingSteps = [
  {
    eyebrow: 'Bem-vindo',
    title: 'Conheça a Nogle',
    text: 'Em poucos passos, você vai conhecer a gestão de clientes, os indicadores e todas as formas de usar o assistente.',
    tip: 'O tour não altera nem salva nenhum cliente.',
    assistant: 'closed',
  },
  {
    eyebrow: 'Indicadores',
    title: 'A carteira em números',
    text: 'Clientes ativos, total cadastrado, inativos com a taxa de inativação e novos cadastros do mês. Os números se atualizam a cada alteração na lista.',
    selector: '.kpi-strip', assistant: 'closed',
  },
  {
    eyebrow: 'Resumo',
    title: 'Resumo da sua carteira',
    text: 'Aqui você acompanha a quantidade de clientes do status atual e encontra os atalhos de teclado para cadastrar ou buscar.',
    selector: '#view-clientes .page-heading > div',
    tip: 'Pressione N para um novo cadastro ou / para ir direto à busca.',
  },
  {
    eyebrow: 'Organização',
    title: 'Status e busca imediata',
    text: 'Alterne entre ativos e inativos e filtre por nome ou CPF. Os resultados são atualizados enquanto você digita.',
    selector: '.panel-top',
  },
  {
    eyebrow: 'Lista de clientes',
    title: 'Dados e ações no mesmo lugar',
    text: 'A tabela reúne CPF, telefone e endereço. Em cada linha você pode editar ou inativar um cliente; na aba Inativos, reativar ou excluir definitivamente.',
    selector: '.table-scroll',
    tip: 'Uma inativação pode ser desfeita pela mensagem exibida no rodapé.',
  },
  {
    eyebrow: 'Novo cliente',
    title: 'Cadastre em poucos passos',
    text: 'Este botão abre o formulário. O CPF, telefone e CEP são validados, e o endereço pode ser preenchido automaticamente pelo CEP.',
    selector: '#new-client', modal: 'closed',
  },
  {
    eyebrow: 'Formulário',
    title: 'Revise antes de salvar',
    text: 'Preencha os dados pessoais e o endereço. Use Salvar e novo para cadastros em sequência; este formulário demonstrativo será fechado sem salvar.',
    selector: '#client-modal .modal', modal: 'open',
    tip: 'Ctrl + Enter também salva o formulário.',
  },
  {
    eyebrow: 'Planilhas',
    title: 'Importe e exporte CSV',
    text: 'Importe até 500 clientes de uma vez ou exporte exatamente os clientes exibidos pelos filtros atuais.',
    selector: '.panel-tools', modal: 'closed',
  },
  {
    eyebrow: 'Dashboard',
    title: 'Onde estão seus clientes',
    text: 'O botão Dashboard abre a distribuição dos clientes ativos por estado e pelas cidades com mais clientes. Os gráficos também podem ser vistos como tabela.',
    selector: '#open-charts', modal: 'closed', assistant: 'closed',
  },
  {
    eyebrow: 'Destaque',
    title: 'Seu assistente está sempre por perto',
    text: 'O assistente entende pedidos em linguagem natural e executa somente as ações permitidas pelo cadastro.',
    selector: '#assistant-launcher', assistant: 'closed',
    tip: 'Peça: “buscar Maria”, “quem mora em Curitiba?” ou “quantos clientes inativos?”.',
  },
  {
    eyebrow: 'Assistente',
    title: 'Converse como preferir',
    text: 'Use as sugestões prontas ou escreva seu pedido. Ele pode buscar, contar, cadastrar, editar, inativar, reativar e exportar.',
    selector: '#assistant-panel', assistant: 'open',
  },
  {
    eyebrow: 'Texto, foto e voz',
    title: 'Três formas de pedir',
    text: 'Digite no campo, anexe uma foto com os dados de um cliente ou use o microfone. Os botões de foto e voz aparecem quando o recurso está disponível.',
    selector: '#assistant-form', assistant: 'open',
    tip: 'Você também pode colar uma imagem diretamente no campo de texto.',
  },
  {
    eyebrow: 'Segurança',
    title: 'Você continua no controle',
    text: 'Cadastros e edições abrem para revisão. Inativações pedem confirmação, inclusive quando o pedido envolve todos os clientes.',
    selector: '.assistant-note', assistant: 'open',
    tip: 'A IA principal roda nesta máquina; a observação abaixo informa quando a voz pode depender do navegador.',
  },
  {
    eyebrow: 'Tudo pronto',
    title: 'Experimente o assistente',
    text: 'O tour terminou e o assistente ficou aberto para seu primeiro pedido. Você pode rever este guia a qualquer momento pelo botão Tour, no canto inferior direito.',
    selector: '#assistant-panel', assistant: 'open',
    tip: 'Comece com: “mostre os clientes ativos”.',
  },
];

let onboardingIndex = 0;
let onboardingTarget = null;
let onboardingLayer = null;
let onboardingRunning = false;
let onboardingLastFocus = null;

const onboardingBackdrop = document.createElement('div');
onboardingBackdrop.className = 'onboarding-backdrop';
onboardingBackdrop.hidden = true;
const onboardingPopover = document.createElement('section');
onboardingPopover.className = 'onboarding-popover';
onboardingPopover.hidden = true;
onboardingPopover.setAttribute('role', 'dialog');
onboardingPopover.setAttribute('aria-modal', 'true');
onboardingPopover.setAttribute('aria-labelledby', 'onboarding-title');
onboardingPopover.innerHTML = `
  <div class="onboarding-topline"><div class="onboarding-label"><span class="onboarding-symbol" aria-hidden="true">✦</span><span class="onboarding-eyebrow"></span></div><button class="onboarding-skip" type="button">Pular tour</button></div>
  <h2 id="onboarding-title"></h2>
  <p class="onboarding-text"></p>
  <div class="onboarding-tip" hidden></div>
  <div class="onboarding-progress" aria-hidden="true"><span></span></div>
  <div class="onboarding-footer"><span class="onboarding-count"></span><div class="onboarding-actions"><button class="button button-ghost onboarding-back" type="button">Anterior</button><button class="button button-primary onboarding-next" type="button">Próximo</button></div></div>`;
document.body.append(onboardingBackdrop, onboardingPopover);

function onboardingSeen() {
  try { return localStorage.getItem(ONBOARDING_KEY) === 'done'; }
  catch { return false; }
}

function rememberOnboarding() {
  try { localStorage.setItem(ONBOARDING_KEY, 'done'); }
  catch { /* Armazenamento privado indisponível: o tour continua funcional nesta visita. */ }
}

function clearOnboardingHighlight() {
  onboardingTarget?.classList.remove('onboarding-highlight', 'onboarding-relative');
  onboardingLayer?.classList.remove('onboarding-layer');
  onboardingTarget = null;
  onboardingLayer = null;
}

function setAssistantForTour(mode) {
  if (!mode) return;
  if (mode === 'open') {
    assistantPanel.hidden = false;
    $('#assistant-launcher').hidden = true;
    $('#assistant-launcher').setAttribute('aria-expanded', 'true');
    refreshAssistantStatus();
  } else {
    closeAssistant();
  }
}

function setModalForTour(mode) {
  if (!mode) return;
  if (mode === 'open' && modal.hidden) openModal();
  if (mode === 'closed' && !modal.hidden) closeModal();
}

function positionOnboarding() {
  if (!onboardingRunning || !onboardingTarget) return;
  const rect = onboardingTarget.getBoundingClientRect();
  const pop = onboardingPopover.getBoundingClientRect();
  const gap = 16;
  const margin = 16;
  let top;
  let left;
  if (rect.left >= pop.width + gap + margin) {
    left = rect.left - pop.width - gap;
    top = rect.top + (rect.height - pop.height) / 2;
  } else if (innerWidth - rect.right >= pop.width + gap + margin) {
    left = rect.right + gap;
    top = rect.top + (rect.height - pop.height) / 2;
  } else if (innerHeight - rect.bottom >= pop.height + gap + margin) {
    left = rect.left + (rect.width - pop.width) / 2;
    top = rect.bottom + gap;
  } else {
    left = rect.left + (rect.width - pop.width) / 2;
    top = rect.top - pop.height - gap;
  }
  onboardingPopover.style.left = `${Math.max(margin, Math.min(left, innerWidth - pop.width - margin))}px`;
  onboardingPopover.style.top = `${Math.max(margin, Math.min(top, innerHeight - pop.height - margin))}px`;
}

async function renderOnboardingStep() {
  clearOnboardingHighlight();
  const step = onboardingSteps[onboardingIndex];
  setAssistantForTour(step.assistant);
  setModalForTour(step.modal);
  await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

  onboardingPopover.querySelector('.onboarding-eyebrow').textContent = step.eyebrow;
  onboardingPopover.querySelector('h2').textContent = step.title;
  onboardingPopover.querySelector('.onboarding-text').textContent = step.text;
  const tip = onboardingPopover.querySelector('.onboarding-tip');
  tip.hidden = !step.tip;
  tip.textContent = step.tip || '';
  onboardingPopover.querySelector('.onboarding-count').textContent = `ETAPA ${onboardingIndex + 1} / ${onboardingSteps.length}`;
  onboardingPopover.querySelector('.onboarding-progress span').style.width = `${(onboardingIndex + 1) * 100 / onboardingSteps.length}%`;
  onboardingPopover.querySelector('.onboarding-back').hidden = onboardingIndex === 0;
  onboardingPopover.querySelector('.onboarding-next').textContent = onboardingIndex === onboardingSteps.length - 1 ? 'Começar a usar' : 'Próximo';
  onboardingPopover.querySelector('.onboarding-skip').hidden = onboardingIndex === onboardingSteps.length - 1;
  onboardingPopover.classList.remove('is-entering');
  void onboardingPopover.offsetWidth;
  onboardingPopover.classList.add('is-entering');

  onboardingTarget = step.selector ? document.querySelector(step.selector) : null;
  onboardingPopover.classList.toggle('centered', !onboardingTarget);
  onboardingPopover.style.left = '';
  onboardingPopover.style.top = '';
  if (onboardingTarget) {
    onboardingTarget.scrollIntoView({block: 'center', inline: 'nearest'});
    onboardingLayer = onboardingTarget.closest('.sidebar, .modal-backdrop, .assistant-panel, .floating-actions') || onboardingTarget;
    onboardingLayer.classList.add('onboarding-layer');
    if (getComputedStyle(onboardingTarget).position === 'static') onboardingTarget.classList.add('onboarding-relative');
    onboardingTarget.classList.add('onboarding-highlight');
    await new Promise((resolve) => requestAnimationFrame(resolve));
    positionOnboarding();
  }
  onboardingPopover.querySelector('.onboarding-next').focus();
}

function finishOnboarding(skipped = false) {
  if (!onboardingRunning) return;
  rememberOnboarding();
  clearOnboardingHighlight();
  onboardingRunning = false;
  onboardingBackdrop.hidden = true;
  onboardingPopover.hidden = true;
  onboardingPopover.classList.remove('centered');
  document.body.classList.remove('onboarding-active');
  if (skipped) {
    if (!modal.hidden) closeModal();
    closeAssistant();
    onboardingLastFocus?.focus();
  } else {
    assistantInput.focus();
  }
}

function startOnboarding() {
  if (onboardingRunning) return;
  onboardingRunning = true;
  onboardingIndex = 0;
  onboardingLastFocus = document.activeElement;
  onboardingBackdrop.hidden = false;
  onboardingPopover.hidden = false;
  document.body.classList.add('onboarding-active');
  renderOnboardingStep();
}

onboardingPopover.querySelector('.onboarding-next').addEventListener('click', () => {
  if (onboardingIndex === onboardingSteps.length - 1) finishOnboarding();
  else { onboardingIndex += 1; renderOnboardingStep(); }
});
onboardingPopover.querySelector('.onboarding-back').addEventListener('click', () => {
  if (onboardingIndex > 0) { onboardingIndex -= 1; renderOnboardingStep(); }
});
onboardingPopover.querySelector('.onboarding-skip').addEventListener('click', () => finishOnboarding(true));
$('#restart-onboarding').addEventListener('click', startOnboarding);
window.addEventListener('resize', positionOnboarding);
document.addEventListener('keydown', (event) => {
  if (!onboardingRunning) return;
  if (event.key === 'Escape') { event.preventDefault(); finishOnboarding(true); }
  if (event.key === 'ArrowRight') { event.preventDefault(); onboardingPopover.querySelector('.onboarding-next').click(); }
  if (event.key === 'ArrowLeft' && onboardingIndex > 0) { event.preventDefault(); onboardingPopover.querySelector('.onboarding-back').click(); }
});
document.addEventListener('DOMContentLoaded', () => {
  if (!onboardingSeen()) setTimeout(startOnboarding, 500);
});
