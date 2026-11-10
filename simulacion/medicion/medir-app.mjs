// Mide una instancia de la app: API (tiempo, tamaño, usuarios simultáneos) y navegador real.
// Solo hace lecturas: nunca envía el formulario de registro.
// Uso: node medir-app.mjs <url> <codigoCompra> <repeticiones> [--sin-concurrencia]
// Imprime un JSON en la salida estándar.
import http from 'node:http';
import { performance } from 'node:perf_hooks';
import puppeteer from 'puppeteer-core';

const [base, compra, repsTexto, bandera] = process.argv.slice(2);
const repeticiones = Number(repsTexto);
const LIMITE_MS = 180_000;
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

const mediana = (v) => {
  const n = v.filter((x) => typeof x === 'number').sort((a, b) => a - b);
  return n.length ? n[Math.floor((n.length - 1) / 2)] : null;
};

function pedir(ruta, gzip = false) {
  return new Promise((resolve) => {
    const t0 = performance.now();
    const req = http.get(base + ruta, { headers: gzip ? { 'Accept-Encoding': 'gzip' } : {} }, (res) => {
      let bytes = 0;
      let inicio = '';
      let final = '';
      res.on('data', (c) => {
        bytes += c.length;
        if (inicio.length < 40) inicio += c.toString('latin1', 0, 40);
        final = c.toString('latin1', Math.max(0, c.length - 5));
      });
      res.on('end', () => resolve({
        http: res.statusCode,
        ms: Math.round(performance.now() - t0),
        bytes,
        valido: res.statusCode === 200 && inicio.startsWith('{"status":"success"') && final.endsWith('}'),
      }));
      res.on('error', (e) => resolve({ http: 0, ms: Math.round(performance.now() - t0), bytes, valido: false, error: e.message }));
    });
    req.setTimeout(LIMITE_MS, () => req.destroy(new Error('timeout')));
    req.on('error', (e) => resolve({ http: 0, ms: Math.round(performance.now() - t0), bytes: 0, valido: false, error: e.message }));
  });
}

async function medirApi() {
  const secuencial = [];
  for (let i = 0; i < 3; i++) secuencial.push(await pedir('/api/leer.php'));
  const conGzip = await pedir('/api/leer.php', true);

  const busqueda = [];
  for (let i = 0; i < 5; i++) busqueda.push(await pedir(`/api/buscar.php?compra=${encodeURIComponent(compra)}`));

  const r = {
    leer_ms: mediana(secuencial.map((x) => x.ms)),
    leer_http: secuencial[0].http,
    leer_valido: secuencial.every((x) => x.valido),
    leer_mb: +(secuencial[0].bytes / 1048576).toFixed(2),
    leer_gzip_mb: +(conGzip.bytes / 1048576).toFixed(2),
    leer_error: secuencial.find((x) => x.error)?.error,
    buscar_ms: mediana(busqueda.map((x) => x.ms)),
    buscar_valido: busqueda.every((x) => x.valido),
  };

  if (bandera !== '--sin-concurrencia') {
    const t0 = performance.now();
    const todos = await Promise.all(Array.from({ length: 5 }, () => pedir('/api/leer.php')));
    r.cinco_usuarios_mediana_ms = mediana(todos.map((x) => x.ms));
    r.cinco_usuarios_total_ms = Math.round(performance.now() - t0);
    r.cinco_usuarios_validas = todos.filter((x) => x.valido).length;
  }
  return r;
}

async function esperar(pagina, condicion) {
  const t0 = performance.now();
  await pagina.waitForFunction(condicion, { timeout: LIMITE_MS, polling: 200 });
  return Math.round(performance.now() - t0);
}

async function medirNavegador(navegador) {
  const pagina = await navegador.newPage();
  const r = {};
  let llamadasLeer = 0;
  pagina.on('request', (q) => { if (q.url().includes('api/leer.php')) llamadasLeer++; });

  try {
    const t0 = performance.now();
    await pagina.goto(base + '/', { waitUntil: 'domcontentloaded', timeout: LIMITE_MS });
    await esperar(pagina, () => document.querySelectorAll('#especie option').length > 0);
    r.dashboard_ms = Math.round(performance.now() - t0);
    const inicioTraz = performance.now();

    // Con los datos ya descargados en el Dashboard, hasta que la pantalla se dibuja
    await pagina.evaluate(() => { loadScreen('history'); });
    await esperar(pagina, () => typeof traceabilityChart !== 'undefined' && traceabilityChart !== null);
    r.trazabilidad_cache_ms = Math.round(performance.now() - inicioTraz);
    r.llamadas_leer = llamadasLeer;

    // Caso más lento: la copia de 60 s venció y hay que volver a descargar todo
    r.trazabilidad_ms = await pagina.evaluate(async () => {
      const t = performance.now();
      appDataTime = 0;
      await fetchFullHistory();
      await new Promise((listo) => requestAnimationFrame(() => requestAnimationFrame(listo)));
      return Math.round(performance.now() - t);
    });

    r.filas_tabla = await pagina.evaluate(() => document.querySelectorAll('#tableBody tr').length);
    r.filtro_ms = await pagina.evaluate(() => {
      const t = performance.now();
      filterMatrix('TI');
      const ms = performance.now() - t;
      filterMatrix('');
      return Math.round(ms);
    });
    r.proyeccion_ms = await pagina.evaluate(() => {
      const t = performance.now();
      runForecastWithFilters();
      return Math.round(performance.now() - t);
    });

    const m = await pagina.metrics();
    r.heap_mb = Math.round(m.JSHeapUsedSize / 1048576);
    r.nodos_dom = m.Nodes;
  } catch (e) {
    r.error = String(e.message).slice(0, 160);
    r.llamadas_leer = llamadasLeer;
  } finally {
    await pagina.close().catch(() => {});
  }
  return r;
}

const salida = { base, api: await medirApi() };

if (salida.api.leer_valido) {
  const navegador = await puppeteer.launch({ executablePath: CHROME, headless: true, protocolTimeout: LIMITE_MS * 2 });
  const corridas = [];
  try {
    for (let i = 0; i < repeticiones; i++) {
      const c = await medirNavegador(navegador);
      corridas.push(c);
      if (c.error) break;
    }
  } finally {
    await navegador.close().catch(() => {});
  }
  const campos = ['dashboard_ms', 'trazabilidad_cache_ms', 'trazabilidad_ms', 'filtro_ms', 'proyeccion_ms', 'heap_mb', 'nodos_dom', 'filas_tabla', 'llamadas_leer'];
  salida.navegador = Object.fromEntries(campos.map((c) => [c, mediana(corridas.map((x) => x[c]))]));
  salida.navegador.corridas = corridas.length;
  const fallo = corridas.find((c) => c.error);
  if (fallo) salida.navegador.error = fallo.error;
} else {
  salida.navegador = { omitido: 'leer.php no devolvió una respuesta válida' };
}

console.log(JSON.stringify(salida));
