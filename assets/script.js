let MODE = 'departures';

const FLIP_DURATION = 360;
const FLIP_STAGGER  = 45;
const COL_STAGGER   = 110;

function buildFlap(char = " "){
  const flap = document.createElement("span");
  flap.className = "flap";
  flap.innerHTML = `
    <span class="top" data-char=""></span>
    <span class="bot" data-char=""></span>
    <span class="flipTop" data-old=""></span>
    <span class="flipBot" data-new=""></span>
  `;
  flap.querySelector(".top").dataset.char = char;
  flap.querySelector(".bot").dataset.char = char;
  return flap;
}

function initFlapLine(el, text = ""){
  el.classList.add("flapline");
  el.innerHTML = "";
  for (const ch of text) el.appendChild(buildFlap(ch));
  el.dataset.value = text;
}

function setFlapLine(el, nextText, { padTo = null, cls = null, startDelay = 0, force = false } = {}){
  if (cls){
    el.classList.remove("good","warn","bad");
    el.classList.add(cls);
  }

  if (el._flipTimers && el._flipTimers.length){
    for (const t of el._flipTimers) clearTimeout(t);
  }
  el._flipTimers = [];

  const prev = el.dataset.value ?? "";
  let a = prev;
  let b = String(nextText ?? "");

  if (padTo != null){
    a = a.padEnd(padTo, " ").slice(0, padTo);
    b = b.padEnd(padTo, " ").slice(0, padTo);
  } else {
    const n = Math.max(a.length, b.length);
    a = a.padEnd(n, " ");
    b = b.padEnd(n, " ");
  }

  while (el.children.length < b.length) el.appendChild(buildFlap(" "));

  for (let i = 0; i < b.length; i++){
    const flap = el.children[i];
    const top = flap.querySelector(".top");
    const bot = flap.querySelector(".bot");
    const flipTop = flap.querySelector(".flipTop");
    const flipBot = flap.querySelector(".flipBot");

    const oldCh = top.dataset.char ?? " ";
    const newCh = b[i];

    if (!force && oldCh === newCh) continue;

    flipTop.dataset.old = oldCh;
    flipBot.dataset.new = newCh;

    const delay = startDelay + (i * FLIP_STAGGER);

    const t1 = setTimeout(() => {
      flap.classList.add("is-flipping");

      const t2 = setTimeout(() => {
        top.dataset.char = newCh;
        bot.dataset.char = newCh;
        flap.classList.remove("is-flipping");
      }, FLIP_DURATION);

      el._flipTimers.push(t2);
    }, delay);

    el._flipTimers.push(t1);
  }

  el.dataset.value = b;
}

function setMode(m){
  MODE = m;
  document.getElementById('modeTitle').textContent = (m === 'arrivals') ? 'Arrivals' : 'Departures';
  document.getElementById('btnDep').classList.toggle('active', m === 'departures');
  document.getElementById('btnArr').classList.toggle('active', m === 'arrivals');
  refresh(true);
}

function safe(s){ return (s && String(s).trim().length) ? String(s).trim() : '—'; }
function fmt(n){ return (n === null || n === undefined || n === '') ? '—' : String(n); }
function pad2(n){ return String(n).padStart(2,'0'); }

function hhmm(epoch){
  if (!epoch) return '—';
  const d = new Date(epoch * 1000);
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function guessIata(row){
  const i = (row.airline_iata_guess || '').trim();
  if (i) return i.toUpperCase();
  const f = (row.flight || '').trim().toUpperCase();
  const m = f.match(/^([A-Z]{2})/);
  return m ? m[1] : '—';
}

function remarkFor(row){
  if (row && typeof row.status === 'string' && row.status.trim().length){
    const txt = row.status.trim().toUpperCase();
    const cls = (row.status_cls === 'good' || row.status_cls === 'warn' || row.status_cls === 'bad') ? row.status_cls : null;
    return { txt, cls };
  }

  const fpm = row?.trend_fpm;
  if (MODE === 'arrivals') {
    if (typeof fpm === 'number' && fpm <= -700) return { txt:'ON APPROACH', cls:'warn' };
    return { txt:'INBOUND', cls:'good' };
  } else {
    if (typeof fpm === 'number' && fpm >= 700) return { txt:'CLIMBING', cls:'good' };
    return { txt:'OUTBOUND', cls:'good' };
  }
}

const PAD = {
  time: 5,
  airline: 3,
  flight: 8,
  iata: 3,
  alt: 5,
  dist: 5,
  gs: 4,
  remark: 12
};

const tbody = document.getElementById('rows');

function makeAirlineLogoTd(){
  const td = document.createElement('td');
  td.className = 'airline';

  const img = document.createElement('img');
  img.className = 'airline-logo';
  img.alt = '';
  img.loading = 'lazy';

  img.onerror = () => {
    if (!img.dataset.fallback) {
      img.dataset.fallback = "1";
      img.src = "assets/empty.png";
    } else {
      img.src = "assets/empty.png";
    }
  };

  td.appendChild(img);
  return td;
}

function makeFlapTd(className, initial='—', padTo=null, extraLineClass=null){
  const td = document.createElement('td');
  if (className) td.className = className;

  const span = document.createElement('span');
  initFlapLine(span, String(initial));

  if (extraLineClass) span.classList.add(extraLineClass);
  td.appendChild(span);

  td.dataset.padTo = padTo != null ? String(padTo) : '';
  return td;
}

function setTd(td, text, { cls=null, delay=0, force=false } = {}){
  const span = td.querySelector('.flapline');
  const padTo = td.dataset.padTo ? parseInt(td.dataset.padTo, 10) : null;
  setFlapLine(span, String(text ?? '—'), { padTo, cls, startDelay: delay, force });
}

function buildRow(r){
  const tr = document.createElement('tr');
  tr.dataset.key = safe(r.icao);

  const tdTime   = makeFlapTd('time',  '—', PAD.time);
  const tdAir    = makeAirlineLogoTd();
  const tdFlight = makeFlapTd('flight','—', PAD.flight);
  const tdIata   = makeFlapTd('iata',  '—', PAD.iata, 'iata');
  const tdAlt    = makeFlapTd('col-right','—', PAD.alt);
  const tdDist   = makeFlapTd('col-right','—', PAD.dist);
  const tdGs     = makeFlapTd('col-right','—', PAD.gs);
  const tdRemark = makeFlapTd('col-right','—', PAD.remark);

  tr.append(tdTime, tdAir, tdFlight, tdIata, tdAlt, tdDist, tdGs, tdRemark);
  updateRow(tr, r);
  return tr;
}

function updateRow(tr, r){
  const tds = tr.children;

  const timeTxt = r.last_seen_epoch ? hhmm(r.last_seen_epoch) : '—';
  const airline = guessIata(r);
  const flight  = safe(r.flight).toUpperCase();
  const iata    = airline;

  setTd(tds[0], timeTxt, { delay: 0 * COL_STAGGER });

  const tdAir = tds[1];
  const img = tdAir.querySelector('img.airline-logo');
  if (airline && airline !== '—') {
    img.style.display = '';
    img.alt = airline;
    img.src = `assets/icon/${airline}.png`;
  } else {
    img.style.display = '';
    img.alt = airline;
    img.src = `assets/empty.png`;
  }

  setTd(tds[2], flight,        { delay: 1 * COL_STAGGER });
  setTd(tds[3], iata,          { delay: 2 * COL_STAGGER });
  setTd(tds[4], fmt(r.alt_ft), { delay: 3 * COL_STAGGER });
  setTd(tds[5], fmt(r.dist_km),{ delay: 4 * COL_STAGGER });
  setTd(tds[6], fmt(r.gs_kt),  { delay: 5 * COL_STAGGER });

  const rem = remarkFor(r);
  const cls = (rem.cls === 'good' || rem.cls === 'warn' || rem.cls === 'bad') ? rem.cls : null;
  setTd(tds[7], rem.txt, { cls, delay: 6 * COL_STAGGER });
}

function showEmpty(){
  tbody.innerHTML = '';
  const tr = document.createElement('tr');
  const td = document.createElement('td');
  td.colSpan = 8;
  td.style.padding = '16px 10px';
  td.style.color = 'rgba(207,207,207,.55)';
  td.style.fontWeight = '600';
  td.textContent = 'No aircraft matching filters.';
  tr.appendChild(td);
  tbody.appendChild(tr);
}

async function refresh(forceRebuild=false){
  document.getElementById('err').textContent = '';
  try{
    const res = await fetch(`api.php?mode=${encodeURIComponent(MODE)}`, {cache:'no-store'});
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'API error');

    document.getElementById('airportName').textContent = data.airport || 'Airport';

    const d = new Date(data.updated_epoch * 1000);
    document.getElementById('meta').textContent =
      `${(data.mode||MODE).toUpperCase()} • shown ${data.counts.shown} • ${d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}`;

    document.getElementById('footerLeft').textContent =
      `Updated ${d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} • ${d.toLocaleDateString()}`;

    const rows = Array.isArray(data.rows) ? data.rows : [];
    if (forceRebuild) tbody.innerHTML = '';

    if (!rows.length){
      showEmpty();
      return;
    } else {
      if (tbody.children.length === 1 && tbody.children[0].children.length === 1 && tbody.children[0].children[0].colSpan === 8){
        tbody.innerHTML = '';
      }
    }

    const existing = new Map();
    [...tbody.querySelectorAll('tr[data-key]')].forEach(tr => existing.set(tr.dataset.key, tr));

    rows.forEach((r, idx) => {
      const key = safe(r.icao);
      let tr = existing.get(key);
      if (!tr){
        tr = buildRow(r);
        const ref = tbody.children[idx] || null;
        tbody.insertBefore(tr, ref);
      } else {
        updateRow(tr, r);
        const ref = tbody.children[idx];
        if (ref !== tr) tbody.insertBefore(tr, ref);
        existing.delete(key);
      }
    });

    for (const tr of existing.values()) tr.remove();

  }catch(e){
    document.getElementById('err').textContent = 'Error: ' + e.message;
  }
}

function updateClock(){
  const now = new Date();
  document.getElementById('clock').textContent = now.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
}

updateClock();
setInterval(updateClock, 1000);

refresh(true);
setInterval(() => refresh(false), 5000);
