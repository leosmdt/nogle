const $ = (selector) => document.querySelector(selector);
const form = $('#client-form');
const modal = $('#client-modal');
const rows = $('#client-rows');
const toast = $('#toast');
const fields = ['nome', 'cpf', 'telefone', 'cep', 'cidade', 'estado', 'endereco', 'bairro', 'numero', 'complemento'];
let status = 'ativos';
let editingId = null;
let clients = [];
let lastFocus = null;
let cepController = null;
let toastTimer = null;
let searchTimer = null;
let listRequestId = 0;
let totalCount = 0;
let saveAndNew = false;
let saving = false;
let lastLookupCep = null;
let addressCep = null;
let undoClientId = null;
const cepCache = new Map();

const digits = (value) => String(value || '').replace(/\D/g, '');
const cpfMask = (value) => digits(value).slice(0, 11).replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2');
const cepMask = (value) => digits(value).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');
function phoneMask(value) {
  const number = digits(value).slice(0, 11);
  if (number.length <= 2) return number ? `(${number}` : '';
  const local = number.slice(2);
  const split = number.length > 10 ? 5 : 4;
  return `(${number.slice(0, 2)}) ${local.slice(0, split)}${local.length > split ? `-${local.slice(split)}` : ''}`;
}

function flash(message, error = false, undoId = null) {
  $('#toast-message').textContent = message;
  undoClientId = undoId;
  $('#toast-undo').hidden = undoId === null;
  toast.classList.toggle('error', error);
  toast.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toast.hidden = true; undoClientId = null; }, undoId === null ? 4200 : 6500);
}

async function request(path, options = {}) {
  let response;
  try {
    response = await fetch(path, options);
  } catch {
    throw new Error('Não foi possível conectar ao servidor. Tente novamente.');
  }
  // Um proxy ou uma falha inesperada pode devolver algo que não é JSON.
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.erro || 'Não foi possível concluir a operação.');
  return payload;
}

function initials(name) {
  return name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('');
}

function address(client) {
  const street = `${client.endereco}, ${client.numero}${client.complemento ? ` · ${client.complemento}` : ''}`;
  return `${street} · ${client.bairro} · ${client.cidade}/${client.estado} · CEP ${cepMask(client.cep)}`;
}

function cell(content, className = '', label = '') {
  const element = document.createElement('td');
  if (className) element.className = className;
  if (label) element.dataset.label = label;
  element.textContent = content;
  return element;
}

function render() {
  rows.replaceChildren();
  for (const client of clients) {
    const tr = document.createElement('tr');
    const nameCell = document.createElement('td');
    const nameWrap = document.createElement('div');
    nameWrap.className = 'client-name';
    const avatar = document.createElement('span');
    avatar.className = 'avatar';
    avatar.textContent = initials(client.nome);
    const name = document.createElement('span');
    name.textContent = client.nome;
    nameWrap.append(avatar, name);
    nameCell.append(nameWrap);
    tr.append(nameCell, cell(cpfMask(client.cpf), 'nowrap', 'CPF'), cell(phoneMask(client.telefone), 'nowrap', 'Telefone'), cell(address(client), 'address', 'Endereço'));

    const actionCell = document.createElement('td');
    actionCell.dataset.label = 'Ações';
    const actions = document.createElement('div');
    actions.className = 'row-actions';
    if (status === 'ativos') {
      const edit = document.createElement('button');
      edit.className = 'row-button';
      edit.type = 'button';
      edit.textContent = 'Editar';
      edit.addEventListener('click', () => openModal(client));
      actions.append(edit);
    }
    const changeStatus = document.createElement('button');
    changeStatus.className = `row-button${status === 'ativos' ? ' danger' : ''}`;
    changeStatus.type = 'button';
    changeStatus.textContent = status === 'ativos' ? 'Inativar' : 'Reativar';
    changeStatus.addEventListener('click', () => updateStatus(client, changeStatus));
    actions.append(changeStatus);
    if (status === 'inativos') {
      const remove = document.createElement('button');
      remove.className = 'row-button danger icon-button';
      remove.type = 'button';
      remove.title = 'Excluir definitivamente';
      remove.setAttribute('aria-label', 'Excluir definitivamente');
      remove.innerHTML = TRASH_ICON;
      remove.addEventListener('click', () => deleteClient(client, remove));
      actions.append(remove);
    }
    actionCell.append(actions);
    tr.append(actionCell);
    rows.append(tr);
  }
  const count = clients.length;
  $('#client-count').textContent = String(totalCount).padStart(2, '0');
  $('#stat-label').textContent = status === 'ativos' ? 'Clientes ativos' : 'Clientes inativos';
  $('#result-count').textContent = `${count} ${count === 1 ? 'cliente encontrado' : 'clientes encontrados'}`;
  $('#empty-state').hidden = count !== 0;
  const hasSearch = $('#filter-name').value.trim() || $('#filter-cpf').value.trim();
  $('#clear-filters').hidden = !hasSearch;
  $('#empty-title').textContent = hasSearch ? 'Nenhum resultado para a busca' : status === 'ativos' ? 'Nenhum cliente cadastrado' : 'Nenhum cliente inativo';
  $('#empty-description').textContent = hasSearch ? 'Tente ajustar os filtros para encontrar clientes.' : status === 'ativos' ? 'Cadastre seu primeiro cliente para começar.' : 'Os clientes inativados aparecerão aqui.';
  $('#empty-new').hidden = status !== 'ativos' || Boolean(hasSearch);
}

async function loadClients() {
  const requestId = ++listRequestId;
  const params = new URLSearchParams({
    status,
    nome: $('#filter-name').value.trim(),
    cpf: digits($('#filter-cpf').value),
  });
  try {
    const result = await request(`/api/clientes?${params}`);
    if (requestId !== listRequestId) return;
    clients = result.clientes;
    totalCount = result.total;
    render();
    document.dispatchEvent(new Event('clientschange'));
    return clients;
  } catch (error) {
    if (requestId === listRequestId) flash(error.message, true);
  }
}

function setStatus(value) {
  status = value;
  document.querySelectorAll('.tab').forEach((tab) => {
    const active = tab.dataset.status === status;
    tab.classList.toggle('active', active);
    tab.setAttribute('aria-pressed', String(active));
  });
  return loadClients();
}

function scheduleSearch() {
  $('#clear-filters').hidden = !($('#filter-name').value.trim() || $('#filter-cpf').value.trim());
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadClients, 220);
}

function openModal(client = null) {
  closeAssistant();
  cepController?.abort();
  lastLookupCep = client?.cep ?? null;
  addressCep = client?.cep ?? null;
  lastFocus = document.activeElement;
  editingId = client?.id ?? null;
  form.reset();
  $('#form-error').hidden = true;
  $('#cep-help').textContent = 'Digite o CEP para buscar o endereço.';
  $('#cep-help').classList.remove('error');
  $('#modal-title').textContent = client ? 'Editar cliente' : 'Novo cliente';
  $('#modal-description').textContent = client ? 'Atualize os dados do cliente abaixo.' : 'Preencha os dados para cadastrar um cliente.';
  $('#save-client').textContent = client ? 'Salvar alterações' : 'Salvar cliente';
  $('#save-and-new').hidden = Boolean(client);
  if (client) {
    for (const field of fields) form.elements[field].value = client[field] || '';
    form.elements.cpf.value = cpfMask(client.cpf);
    form.elements.telefone.value = phoneMask(client.telefone);
    form.elements.cep.value = cepMask(client.cep);
  }
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
  form.elements.nome.focus();
}

function closeModal() {
  cepController?.abort();
  modal.hidden = true;
  document.body.style.overflow = '';
  lastFocus?.focus();
}

async function lookupCep() {
  const cep = digits(form.elements.cep.value);
  if (cep === lastLookupCep) return;
  cepController?.abort();
  const help = $('#cep-help');
  help.classList.remove('error');
  if (cep.length !== 8) {
    help.textContent = cep.length ? 'Informe os 8 dígitos do CEP.' : 'Digite o CEP para buscar o endereço.';
    return;
  }
  const controller = new AbortController();
  cepController = controller;
  help.textContent = 'Buscando endereço...';
  try {
    let data = cepCache.get(cep);
    if (!data) {
      const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`, { signal: controller.signal });
      if (!response.ok) throw new Error('Consulta indisponível');
      data = await response.json();
      if (!data.erro) cepCache.set(cep, data);
    }
    if (controller.signal.aborted || digits(form.elements.cep.value) !== cep) return;
    if (data.erro) {
      help.textContent = 'CEP não encontrado. Preencha o endereço manualmente.';
      help.classList.add('error');
      return;
    }
    form.elements.cidade.value = data.localidade || '';
    form.elements.estado.value = data.uf || '';
    if (data.logradouro) form.elements.endereco.value = data.logradouro;
    if (data.bairro) form.elements.bairro.value = data.bairro;
    lastLookupCep = cep;
    addressCep = cep;
    help.textContent = 'Endereço encontrado. Confira os dados antes de salvar.';
    if (document.activeElement === form.elements.cep) {
      const nextField = ['cidade', 'estado', 'bairro', 'endereco'].find((field) => !form.elements[field].value) || 'numero';
      form.elements[nextField].focus();
    }
  } catch (error) {
    if (error.name === 'AbortError') return;
    help.textContent = 'Não foi possível consultar o CEP. Preencha manualmente.';
    help.classList.add('error');
  }
}

async function updateStatus(client, button) {
  const action = status === 'ativos' ? 'inativar' : 'reativar';
  button.disabled = true;
  try {
    const result = await request(`/api/clientes/${client.id}/${action}`, { method: 'PATCH' });
    flash(result.mensagem, false, action === 'inativar' ? client.id : null);
    await loadClients();
  } catch (error) {
    flash(error.message, true);
  } finally {
    button.disabled = false;
  }
}

const TRASH_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v5.5M14 11v5.5"/></svg>';

// Exclusão definitiva: o primeiro clique pede confirmação no próprio botão por alguns segundos.
async function deleteClient(client, button) {
  if (!button.classList.contains('confirming')) {
    button.classList.add('confirming');
    button.textContent = 'Confirmar exclusão';
    setTimeout(() => {
      button.classList.remove('confirming');
      button.innerHTML = TRASH_ICON;
    }, 4000);
    return;
  }
  button.disabled = true;
  try {
    const result = await request(`/api/clientes/${client.id}`, { method: 'DELETE' });
    flash(result.mensagem);
    await loadClients();
  } catch (error) {
    flash(error.message, true);
    button.disabled = false;
  }
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (saving) return;
  const errorBox = $('#form-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  saving = true;
  const data = Object.fromEntries(fields.map((field) => [field, form.elements[field].value.trim()]));
  data.cpf = digits(data.cpf);
  data.telefone = digits(data.telefone);
  data.cep = digits(data.cep);
  data.estado = data.estado.toUpperCase();
  const save = $('#save-client');
  save.disabled = true;
  $('#save-and-new').disabled = true;
  try {
    await request(editingId ? `/api/clientes/${editingId}` : '/api/clientes', {
      method: editingId ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    flash(editingId ? 'Cliente atualizado com sucesso.' : 'Cliente cadastrado com sucesso.');
    if (saveAndNew && editingId === null) {
      form.reset();
      lastLookupCep = null;
      addressCep = null;
      $('#cep-help').textContent = 'Digite o CEP para buscar o endereço.';
      form.elements.nome.focus();
      setStatus('ativos');
    } else {
      closeModal();
      setStatus('ativos');
    }
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
    // A mensagem fica no fim do formulário; em telas baixas ela estaria fora da área visível do modal.
    errorBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  } finally {
    saving = false;
    save.disabled = false;
    $('#save-and-new').disabled = false;
    saveAndNew = false;
  }
});

$('#save-and-new').addEventListener('click', () => {
  saveAndNew = true;
  $('#save-and-new').disabled = true;
  form.requestSubmit();
  if (!form.checkValidity()) {
    saveAndNew = false;
    $('#save-and-new').disabled = false;
  }
});

$('#toast-undo').addEventListener('click', async () => {
  const clientId = undoClientId;
  if (clientId === null) return;
  undoClientId = null;
  $('#toast-undo').hidden = true;
  try {
    await request(`/api/clientes/${clientId}/reativar`, { method: 'PATCH' });
    flash('Inativação desfeita.');
    await loadClients();
  } catch (error) {
    flash(error.message, true);
  }
});

$('#new-client').addEventListener('click', () => openModal());
$('#empty-new').addEventListener('click', () => openModal());
$('#close-modal').addEventListener('click', closeModal);
$('#cancel-modal').addEventListener('click', closeModal);
modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
document.addEventListener('keydown', (event) => {
  if (!$('#charts-modal').hidden) return;
  if (modal.hidden) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if (!typing && !event.ctrlKey && !event.metaKey && !event.altKey && event.key.toLowerCase() === 'n') {
      event.preventDefault();
      openModal();
    } else if (!typing && event.key === '/') {
      event.preventDefault();
      $('#filter-name').focus();
    }
    return;
  }
  if (event.key === 'Escape') { closeModal(); return; }
  if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
    event.preventDefault();
    form.requestSubmit();
  }
  if (event.key === 'Tab') {
    const focusable = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled])')];
    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }
});

$('#filter-form').addEventListener('submit', (event) => { event.preventDefault(); clearTimeout(searchTimer); loadClients(); });
$('#clear-filters').addEventListener('click', () => { $('#filter-form').reset(); scheduleSearch(); $('#filter-name').focus(); });
$('#filter-name').addEventListener('input', scheduleSearch);
$('#filter-cpf').addEventListener('input', (event) => { event.target.value = cpfMask(event.target.value); scheduleSearch(); });
document.querySelectorAll('.tab').forEach((tab) => tab.addEventListener('click', () => {
  setStatus(tab.dataset.status);
}));
$('#export-clients').addEventListener('click', () => {
  const params = new URLSearchParams({status, nome: $('#filter-name').value.trim(), cpf: digits($('#filter-cpf').value)});
  const link = document.createElement('a');
  link.href = `/api/clientes/export?${params}`;
  link.download = 'clientes.csv';
  document.body.append(link);
  link.click();
  link.remove();
});
$('#import-clients').addEventListener('click', () => $('#import-file').click());
$('#import-file').addEventListener('change', async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  event.target.value = '';
  if (file.size > 512000) return flash('O CSV deve ter até 500 KB.', true);
  const button = $('#import-clients');
  button.disabled = true;
  try {
    const result = await request('/api/clientes/import', {
      method: 'POST',
      headers: {'Content-Type': 'text/csv'},
      body: await file.text(),
    });
    flash(`${result.adicionados} cadastrado(s); ${result.ignorados} CPF(s) já existente(s).`);
    setStatus('ativos');
  } catch (error) {
    flash(error.message, true);
  } finally {
    button.disabled = false;
  }
});
form.elements.cpf.addEventListener('input', (event) => { event.target.value = cpfMask(event.target.value); });
form.elements.telefone.addEventListener('input', (event) => { event.target.value = phoneMask(event.target.value); });
form.elements.cep.addEventListener('input', (event) => {
  event.target.value = cepMask(event.target.value);
  if (addressCep && digits(event.target.value) !== addressCep) {
    for (const field of ['cidade', 'estado', 'endereco', 'bairro']) form.elements[field].value = '';
    addressCep = null;
    lastLookupCep = null;
  }
  if (digits(event.target.value).length === 8) lookupCep();
});
form.elements.cep.addEventListener('blur', lookupCep);
loadClients();

// Fundo interativo do menu: a luz e a grade de pontos seguem o cursor.
const sidebar = $('.sidebar');
const sidebarGlow = $('.sidebar-glow');
sidebar.addEventListener('pointermove', (event) => {
  const rect = sidebar.getBoundingClientRect();
  sidebarGlow.style.setProperty('--mx', `${event.clientX - rect.left}px`);
  sidebarGlow.style.setProperty('--my', `${event.clientY - rect.top}px`);
});
