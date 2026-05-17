const STATIONS = {
  opet: 'Opet',
  shell: 'Shell',
  po: 'Petrol Ofisi',
  aytemiz: 'Aytemiz'
};

const state = {
  payload: null,
  search: '',
  station: 'all'
};

const elements = {
  statusText: document.querySelector('#statusText'),
  citySearch: document.querySelector('#citySearch'),
  stationFilter: document.querySelector('#stationFilter'),
  clearFilters: document.querySelector('#clearFilters'),
  cityCount: document.querySelector('#cityCount'),
  stationCount: document.querySelector('#stationCount'),
  errorCount: document.querySelector('#errorCount'),
  errorBox: document.querySelector('#errorBox'),
  emptyBox: document.querySelector('#emptyBox'),
  cityGrid: document.querySelector('#cityGrid')
};

elements.citySearch.addEventListener('input', () => {
  state.search = elements.citySearch.value.trim().toLocaleLowerCase('tr-TR');
  render();
});

elements.stationFilter.addEventListener('change', () => {
  state.station = elements.stationFilter.value;
  render();
});

elements.clearFilters.addEventListener('click', () => {
  state.search = '';
  state.station = 'all';
  elements.citySearch.value = '';
  elements.stationFilter.value = 'all';
  render();
});

loadData();

async function loadData() {
  try {
    const response = await fetch('data/latest.json', { cache: 'no-store' });

    if (!response.ok) {
      throw new Error(`JSON okunamadi: HTTP ${response.status}`);
    }

    state.payload = await response.json();
    render();
  } catch (error) {
    elements.statusText.textContent = 'Veri yuklenemedi.';
    elements.errorBox.hidden = false;
    elements.errorBox.textContent = error instanceof Error ? error.message : String(error);
  }
}

function render() {
  const payload = state.payload;

  if (!payload) {
    return;
  }

  const cities = Array.isArray(payload.cities) ? payload.cities : [];
  const filtered = cities.filter((city) => {
    const cityName = String(city.il || '');
    return cityName.toLocaleLowerCase('tr-TR').includes(state.search);
  });

  const stationKeys = state.station === 'all' ? Object.keys(STATIONS) : [state.station];
  const stationCount = filtered.reduce((total, city) => {
    return total + stationKeys.filter((key) => hasAnyPrice(city.stations?.[key])).length;
  }, 0);

  elements.statusText.textContent = statusLine(payload);
  elements.cityCount.textContent = String(filtered.length);
  elements.stationCount.textContent = String(stationCount);
  elements.errorCount.textContent = String(payload.summary?.errors?.length || 0);
  elements.emptyBox.hidden = filtered.length > 0;

  renderErrors(payload.summary?.errors || []);
  elements.cityGrid.replaceChildren(...filtered.map((city) => cityCard(city, stationKeys)));
}

function statusLine(payload) {
  if (!payload.fetched_at) {
    return 'Henuz veri cekilmedi.';
  }

  const date = new Date(payload.fetched_at);
  const formatted = Number.isNaN(date.getTime())
    ? payload.fetched_at
    : new Intl.DateTimeFormat('tr-TR', {
      dateStyle: 'medium',
      timeStyle: 'short'
    }).format(date);

  return `Son guncelleme: ${formatted}`;
}

function renderErrors(errors) {
  if (errors.length === 0) {
    elements.errorBox.hidden = true;
    elements.errorBox.textContent = '';
    return;
  }

  elements.errorBox.hidden = false;
  elements.errorBox.textContent = `${errors.length} sehir cekilemedi. JSON dosyasinda detay var.`;
}

function cityCard(city, stationKeys) {
  const section = document.createElement('section');
  section.className = 'cityCard';

  const header = document.createElement('div');
  header.className = 'cityHead';

  const title = document.createElement('h2');
  title.textContent = titleCase(String(city.il || '-'));

  header.append(title);
  section.append(header);

  for (const key of stationKeys) {
    section.append(stationRow(key, city.stations?.[key]));
  }

  return section;
}

function stationRow(key, station) {
  const row = document.createElement('div');
  row.className = 'stationRow';

  const name = document.createElement('strong');
  name.className = 'stationName';
  name.textContent = STATIONS[key] || key;

  row.append(
    name,
    priceCell('Benzin', station?.benzin),
    priceCell('Dizel', station?.dizel),
    priceCell('LPG', station?.lpg)
  );

  return row;
}

function priceCell(label, value) {
  const cell = document.createElement('div');
  cell.className = 'priceCell';

  const small = document.createElement('span');
  small.textContent = label;

  const strong = document.createElement('strong');
  strong.textContent = formatPrice(value);

  cell.append(small, strong);
  return cell;
}

function formatPrice(value) {
  if (value === null || value === undefined || value === '') {
    return '-';
  }

  const number = Number(value);
  if (!Number.isFinite(number)) {
    return '-';
  }

  return new Intl.NumberFormat('tr-TR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(number);
}

function hasAnyPrice(station) {
  return station && Object.values(station).some((value) => value !== null && value !== undefined);
}

function titleCase(value) {
  return value
    .split('-')
    .filter(Boolean)
    .map((part) => part.charAt(0).toLocaleUpperCase('tr-TR') + part.slice(1))
    .join(' ');
}
