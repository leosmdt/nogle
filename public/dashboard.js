// Indicadores no topo da tela e gráficos de distribuição geográfica (em janela), a partir de /api/dashboard.
const chartsModal = $('#charts-modal');
const chartTooltip = $('#chart-tooltip');
const numberFormat = new Intl.NumberFormat('pt-BR');
const percentFormat = new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 1});
const MAX_STATES = 10;
const showTable = {estado: false, cidade: false};
let dashboardData = null;
let dashboardRequest = 0;

const count = (value, one, many) => `${numberFormat.format(value)} ${value === 1 ? one : many}`;

function stateRows(data) {
  const rows = data.por_estado.map((row) => ({label: row.estado, value: row.total}));
  if (rows.length <= MAX_STATES) return rows;
  // Muitos estados viram "Outros" em vez de uma lista longa e ilegível.
  const others = rows.slice(MAX_STATES - 1).reduce((sum, row) => sum + row.value, 0);
  return [...rows.slice(0, MAX_STATES - 1), {label: 'Outros', value: others}];
}

function cityRows(data) {
  return data.top_cidades.map((row) => ({label: `${row.cidade}/${row.estado}`, value: row.total}));
}

function placeTooltip(event, row) {
  const share = dashboardData.ativos ? row.value * 100 / dashboardData.ativos : 0;
  chartTooltip.replaceChildren();
  const title = document.createElement('strong');
  title.textContent = row.label;
  chartTooltip.append(title, `${count(row.value, 'cliente ativo', 'clientes ativos')} · ${percentFormat.format(share)}% do total`);
  chartTooltip.hidden = false;
  const box = event.type === 'focus' ? event.currentTarget.getBoundingClientRect() : {left: event.clientX, top: event.clientY, width: 0};
  const left = Math.min(box.left + box.width / 2 + 12, window.innerWidth - chartTooltip.offsetWidth - 12);
  chartTooltip.style.left = `${Math.max(12, left)}px`;
  chartTooltip.style.top = `${Math.max(12, box.top - chartTooltip.offsetHeight - 10)}px`;
}

function renderBarList(rows) {
  const list = document.createElement('ul');
  list.className = 'bar-list';
  const max = Math.max(...rows.map((row) => row.value));
  for (const row of rows) {
    const item = document.createElement('li');
    item.className = 'bar-row';
    item.tabIndex = 0;
    item.setAttribute('aria-label', `${row.label}: ${count(row.value, 'cliente ativo', 'clientes ativos')}`);
    const label = document.createElement('span');
    label.className = 'bar-label';
    label.textContent = row.label;
    const track = document.createElement('span');
    track.className = 'bar-track';
    const fill = document.createElement('span');
    fill.className = 'bar-fill';
    fill.style.setProperty('--ratio', max ? row.value / max : 0);
    const value = document.createElement('span');
    value.className = 'bar-value';
    value.textContent = numberFormat.format(row.value);
    track.append(fill, value);
    item.append(label, track);
    item.addEventListener('mousemove', (event) => placeTooltip(event, row));
    item.addEventListener('focus', (event) => placeTooltip(event, row));
    item.addEventListener('mouseleave', () => { chartTooltip.hidden = true; });
    item.addEventListener('blur', () => { chartTooltip.hidden = true; });
    list.append(item);
  }
  return list;
}

function renderDataTable(rows, heading) {
  const table = document.createElement('table');
  table.className = 'data-table';
  const head = table.createTHead().insertRow();
  for (const text of [heading, 'Clientes ativos', '% do total']) {
    const th = document.createElement('th');
    th.scope = 'col';
    th.textContent = text;
    head.append(th);
  }
  const body = table.createTBody();
  for (const row of rows) {
    const tr = body.insertRow();
    tr.insertCell().textContent = row.label;
    tr.insertCell().textContent = numberFormat.format(row.value);
    tr.insertCell().textContent = `${percentFormat.format(dashboardData.ativos ? row.value * 100 / dashboardData.ativos : 0)}%`;
  }
  return table;
}

function renderChart(name) {
  const rows = name === 'estado' ? stateRows(dashboardData) : cityRows(dashboardData);
  const container = $(`#chart-${name}`);
  const toggle = document.querySelector(`.view-toggle[data-chart="${name}"]`);
  toggle.textContent = showTable[name] ? 'Ver gráfico' : 'Ver tabela';
  toggle.setAttribute('aria-pressed', String(showTable[name]));
  if (!rows.length) {
    container.textContent = 'Nenhum cliente ativo.';
    return;
  }
  container.replaceChildren(showTable[name] ? renderDataTable(rows, name === 'estado' ? 'Estado' : 'Cidade') : renderBarList(rows));
}

function setDelta(element, current, previous) {
  const difference = current - previous;
  element.replaceChildren();
  if (!current && !previous) {
    element.textContent = 'nenhum no mês anterior';
    return;
  }
  const delta = document.createElement('span');
  delta.className = `delta ${difference > 0 ? 'up' : difference < 0 ? 'down' : ''}`;
  delta.textContent = `${difference > 0 ? '+' : ''}${numberFormat.format(difference)}`;
  element.append(delta, ` vs. mês anterior (${numberFormat.format(previous)})`);
}

function renderDashboard() {
  const data = dashboardData;
  $('#dashboard-updated').textContent = `Clientes ativos por estado e cidade · atualizado às ${new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})}`;
  $('#kpi-ativos').textContent = numberFormat.format(data.ativos);
  $('#kpi-ativos-note').textContent = data.total ? `${percentFormat.format(data.ativos * 100 / data.total)}% da carteira` : 'nenhum cliente ainda';
  $('#kpi-total').textContent = numberFormat.format(data.total);
  $('#kpi-inativos').textContent = numberFormat.format(data.inativos);
  $('#kpi-taxa').textContent = `${percentFormat.format(data.taxa_inativacao)}% de inativação`;
  $('#kpi-novos').textContent = numberFormat.format(data.novos_mes);
  setDelta($('#kpi-novos-delta'), data.novos_mes, data.novos_mes_anterior);
  const states = data.por_estado.length;
  $('#estado-subtitle').textContent = `${count(states, 'estado', 'estados')} com clientes ativos`;
  renderChart('estado');
  renderChart('cidade');
}

async function loadDashboard() {
  const requestId = ++dashboardRequest;
  try {
    const data = await request('/api/dashboard');
    if (requestId !== dashboardRequest) return;
    dashboardData = data;
    renderDashboard();
  } catch (error) {
    flash(error.message, true);
  }
}

document.querySelectorAll('.view-toggle').forEach((button) => {
  button.addEventListener('click', () => {
    showTable[button.dataset.chart] = !showTable[button.dataset.chart];
    chartTooltip.hidden = true;
    if (dashboardData) renderChart(button.dataset.chart);
  });
});
// A lista recarrega após cada cadastro, edição, inativação ou importação; os indicadores acompanham.
document.addEventListener('clientschange', loadDashboard);

function openCharts() {
  chartsModal.hidden = false;
  $('#close-charts').focus();
}

function closeCharts() {
  chartsModal.hidden = true;
  chartTooltip.hidden = true;
  $('#open-charts').focus();
}

$('#open-charts').addEventListener('click', openCharts);
$('#close-charts').addEventListener('click', closeCharts);
chartsModal.addEventListener('click', (event) => { if (event.target === chartsModal) closeCharts(); });
document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !chartsModal.hidden) closeCharts(); });
