let MODE = 'departures';

const DEFAULT_COLUMNS = { from:true, to:true, alt_ft:true, dist_km:true, gs_kt:true };
const COLUMNS = Object.assign({}, DEFAULT_COLUMNS, (window.SOLARI_COLUMNS || {}));
function isColOn(k){ return !!COLUMNS[k]; }
function visibleColCount(){
  let n = 4;
  if (isColOn('from')) n++;
  if (isColOn('to')) n++;
  if (isColOn('alt_ft')) n++;
  if (isColOn('dist_km')) n++;
  if (isColOn('gs_kt')) n++;
  return n;
}

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
  airline: 2,
  flight: 8,
  from: 3,
  to: 3,
  alt: 5,
  dist: 5,
  gs: 4,
  remark: 12
};

const tbody = document.getElementById('rows');

function makeAirlineLogoTd(colKey, initial='—', padTo=null, extraLineClass=null){
  const td = document.createElement('td');
  td.className = 'airline';
  if (colKey) td.dataset.col = colKey;

  const img = document.createElement('img');
  img.className = 'airline-logo';
  img.alt = '';
  img.loading = 'lazy';

  img.onerror = () => {
    img.src = "assets/empty.png";
  };

  const span = document.createElement('span');
  initFlapLine(span, String(initial));

  td.appendChild(img);
  if (extraLineClass) span.classList.add(extraLineClass);
  td.appendChild(span);

  td.dataset.padTo = padTo != null ? String(padTo) : '';
  return td;
}

function makeFlapTd(colKey, className, initial='—', padTo=null, extraLineClass=null){
  const td = document.createElement('td');
  if (className) td.className = className;
  if (colKey) td.dataset.col = colKey;

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

  tr.append(
    makeFlapTd('time',  'time',  '—', PAD.time),
    makeAirlineLogoTd('airline', '—', PAD.airline, 'iata'),
    makeFlapTd('flight','flight','—', PAD.flight)
  );

  if (isColOn('from'))    tr.append(makeFlapTd('from', 'from', '—', PAD.from));
  if (isColOn('to'))      tr.append(makeFlapTd('to', 'to', '—', PAD.to));
  if (isColOn('alt_ft'))  tr.append(makeFlapTd('alt_ft', 'col-right', '—', PAD.alt));
  if (isColOn('dist_km')) tr.append(makeFlapTd('dist_km','col-right', '—', PAD.dist));
  if (isColOn('gs_kt'))   tr.append(makeFlapTd('gs_kt', 'col-right', '—', PAD.gs));

  tr.append(makeFlapTd('remark','col-right','—', PAD.remark));

  updateRow(tr, r);
  return tr;
}

function updateRow(tr, r){
  function td(col){ return tr.querySelector(`td[data-col="${col}"]`); }

  const timeTxt = r.last_seen_epoch ? hhmm(r.last_seen_epoch) : '—';
  const airline = guessIata(r);
  const flight  = safe(r.flight).toUpperCase();
  const from    = safe(r.from).toUpperCase();
  const to      = safe(r.to).toUpperCase();

  setTd(td('time'), timeTxt, { delay: 0 * COL_STAGGER });

  const tdAir = td('airline');
  if (tdAir){
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
    setTd(tdAir, airline, { delay: 1 * COL_STAGGER });
  }

  setTd(td('flight'), flight, { delay: 2 * COL_STAGGER });

  if (isColOn('from'))    setTd(td('from'), from, { delay: 2 * COL_STAGGER });
  if (isColOn('to'))      setTd(td('to'), to, { delay: 2 * COL_STAGGER });
  if (isColOn('alt_ft'))  setTd(td('alt_ft'), fmt(r.alt_ft), { delay: 3 * COL_STAGGER });
  if (isColOn('dist_km')) setTd(td('dist_km'), fmt(r.dist_km), { delay: 4 * COL_STAGGER });
  if (isColOn('gs_kt'))   setTd(td('gs_kt'), fmt(r.gs_kt), { delay: 5 * COL_STAGGER });

  const rem = remarkFor(r);
  const cls = (rem.cls === 'good' || rem.cls === 'warn' || rem.cls === 'bad') ? rem.cls : null;
  setTd(td('remark'), rem.txt, { cls, delay: 6 * COL_STAGGER });
}

function showEmpty(){
  tbody.innerHTML = '';
  const tr = document.createElement('tr');
  const td = document.createElement('td');
  td.colSpan = visibleColCount();
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
	document.title = data.airport || 'Airport';

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
