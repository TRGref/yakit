import { mkdir, writeFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

const PHP_BIN = process.env.PHP_BIN || 'php';
const LOCAL_API_FILE = process.env.FUEL_PHP_FILE || 'yakit.php';
const OUT_FILE = process.env.FUEL_OUT_FILE || 'data/latest.json';
const REQUEST_DELAY_MS = Number(process.env.FUEL_REQUEST_DELAY_MS || 200);
const REQUEST_TIMEOUT_MS = Number(process.env.FUEL_REQUEST_TIMEOUT_MS || 600000);

const DEFAULT_CITIES = [
  'adana', 'adiyaman', 'afyonkarahisar', 'agri', 'aksaray', 'amasya', 'ankara', 'antalya',
  'ardahan', 'artvin', 'aydin', 'balikesir', 'bartin', 'batman', 'bayburt',
  'bilecik', 'bingol', 'bitlis', 'bolu', 'burdur', 'bursa', 'canakkale',
  'cankiri', 'corum', 'denizli', 'diyarbakir', 'duzce', 'edirne', 'elazig',
  'erzincan', 'erzurum', 'eskisehir', 'gaziantep', 'giresun', 'gumushane',
  'hakkari', 'hatay', 'igdir', 'isparta', 'istanbul-anadolu', 'istanbul-avrupa',
  'izmir', 'kahramanmaras', 'karabuk', 'karaman', 'kars', 'kastamonu',
  'kayseri', 'kilis', 'kirikkale', 'kirklareli', 'kirsehir', 'kocaeli',
  'konya', 'kutahya', 'malatya', 'manisa', 'mardin', 'mersin', 'mugla',
  'mus', 'nevsehir', 'nigde', 'ordu', 'osmaniye', 'rize', 'sakarya',
  'samsun', 'sanliurfa', 'siirt', 'sinop', 'sirnak', 'sivas', 'tekirdag',
  'tokat', 'trabzon', 'tunceli', 'usak', 'van', 'yalova', 'yozgat', 'zonguldak'
];

const STATIONS = ['opet', 'shell', 'po', 'aytemiz'];

const cities = (process.env.FUEL_CITIES || '')
  .split(',')
  .map((city) => city.trim())
  .filter(Boolean);

const requestedCities = cities.length > 0 ? cities : DEFAULT_CITIES;
const fetchedAt = new Date().toISOString();
const priceDate = fetchedAt.slice(0, 10);
const errors = [];
const cityMap = new Map();

if (cities.length > 0) {
  for (const city of requestedCities) {
    await fetchAndSaveCity(city);
    await delay(REQUEST_DELAY_MS);
  }
} else {
  try {
    console.log('Fetching all cities in one PHP run...');
    const rows = await fetchPhp(['--all']);
    saveRows(rows);
    console.log(`Saved all cities: ${cityMap.size} city result(s)`);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.warn(`Failed all cities: ${message}`);
    errors.push({
      city: 'all',
      message
    });
  }
}

const payload = {
  schema_version: 1,
  source: LOCAL_API_FILE,
  fetched_at: fetchedAt,
  price_date: priceDate,
  summary: {
    cities_requested: requestedCities.length,
    cities_saved: cityMap.size,
    station_rows_saved: Array.from(cityMap.values()).reduce((total, city) => {
      return total + STATIONS.filter((station) => hasAnyPrice(city.stations[station])).length;
    }, 0),
    errors
  },
  cities: Array.from(cityMap.values()).sort((a, b) => a.il.localeCompare(b.il, 'tr'))
};

const target = resolve(OUT_FILE);
await mkdir(dirname(target), { recursive: true });
await writeFile(target, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');

console.log(`Saved ${payload.cities.length} city rows to ${OUT_FILE}`);
if (errors.length > 0) {
  console.warn(`${errors.length} city request(s) failed.`);
}

if (payload.cities.length === 0) {
  console.error('No city data was saved. Failing the run to avoid publishing an empty dataset.');
  process.exitCode = 1;
}

async function fetchAndSaveCity(city) {
  try {
    console.log(`Fetching ${city}...`);
    const rows = await fetchPhp([city]);
    const before = cityMap.size;
    saveRows(rows);
    console.log(`Saved ${city}: ${cityMap.size - before} city result(s)`);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.warn(`Failed ${city}: ${message}`);
    errors.push({
      city,
      message
    });
  }
}

async function fetchPhp(args) {
  try {
    const { stdout, stderr } = await execFileAsync(PHP_BIN, [LOCAL_API_FILE, ...args], {
      timeout: REQUEST_TIMEOUT_MS,
      maxBuffer: 1024 * 1024 * 10,
      windowsHide: true
    });

    writePhpDebug(stderr);

    const data = JSON.parse(stdout);

    if (data && typeof data === 'object' && !Array.isArray(data) && (data.error || data.message)) {
      throw new Error(String(data.error || data.message));
    }

    if (!Array.isArray(data)) {
      throw new Error('API response is not an array');
    }

    return data;
  } catch (error) {
    writePhpDebug(error?.stderr);

    if (error instanceof SyntaxError) {
      throw new Error('Local PHP output is not valid JSON');
    }

    throw error;
  }
}

function saveRows(rows) {
  for (const row of rows) {
    if (!row || typeof row !== 'object' || !row.il) {
      continue;
    }

    const cityKey = String(row.il);
    const stations = {};

    for (const station of STATIONS) {
      stations[station] = normalizeStation(row[station]);
    }

    cityMap.set(cityKey, {
      il: cityKey,
      stations
    });

    console.log(`  ${cityKey}: ${formatStationsForLog(stations)}`);
  }
}

function normalizeStation(value) {
  if (!value || typeof value !== 'object') {
    return {
      benzin: null,
      dizel: null,
      lpg: null
    };
  }

  return {
    benzin: numberOrNull(value.benzin),
    dizel: numberOrNull(value.dizel),
    lpg: numberOrNull(value.lpg)
  };
}

function numberOrNull(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const normalized = typeof value === 'string' ? value.replace(',', '.') : value;
  const number = Number(normalized);

  return Number.isFinite(number) ? number : null;
}

function hasAnyPrice(station) {
  return station && Object.values(station).some((value) => value !== null);
}

function formatStationsForLog(stations) {
  return STATIONS.map((station) => {
    const prices = stations[station] || {};
    return `${station} ${formatPriceForLog(prices.benzin)}/${formatPriceForLog(prices.dizel)}/${formatPriceForLog(prices.lpg)}`;
  }).join(' | ');
}

function formatPriceForLog(value) {
  return value === null || value === undefined ? '-' : String(value);
}

function writePhpDebug(stderr) {
  const output = typeof stderr === 'string' ? stderr.trim() : '';

  if (output !== '') {
    console.warn(output);
  }
}

function delay(ms) {
  return new Promise((resolveDelay) => {
    setTimeout(resolveDelay, ms);
  });
}
