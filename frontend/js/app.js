/**
 * =========================================================
 * FIFA World Cup 2026 Tracker — Frontend SPA
 * =========================================================
 *
 * Arquitectura:
 *  State  → objeto centralizado con todos los datos del app
 *  render → reconstruye la UI a partir del estado
 *  API    → módulo de comunicación con el backend PHP
 *  i18n   → módulo de traducción ES/EN
 *  TZ     → módulo de conversión de zona horaria
 *
 * No depende de frameworks externos; usa fetch() y DOM APIs.
 */

/* ── Estado global de la aplicación ────────────────────── */
const State = {
  lang:     localStorage.getItem('wc_lang') || 'es',     // Idioma activo
  tz:       localStorage.getItem('wc_tz')   || Intl.DateTimeFormat().resolvedOptions().timeZone,
  tab:      'today',     // Pestaña activa: today | matches | groups
  group:    null,        // Filtro de grupo activo (A–L o null)
  data:     null,        // Último payload de la API
  updating: false,       // Bloqueo durante actualización
  i18n:     {},          // Diccionario de traducciones cargado
};

/* ── Módulo i18n ────────────────────────────────────────── */
const I18n = {
  /**
   * Carga el JSON de traducciones para el idioma indicado.
   * Los archivos están en /i18n/{lang}.json.
   */
  async load(lang) {
    const res  = await fetch(`i18n/${lang}.json?v=${Date.now()}`);
    State.i18n = await res.json();
    State.lang = lang;
    localStorage.setItem('wc_lang', lang);
  },

  /** Devuelve la cadena traducida por clave */
  t(key) {
    return State.i18n[key] || key;
  },
};

/* ── Módulo de Zona Horaria ─────────────────────────────── */
const TZ = {
  /**
   * Convierte una fecha UTC (ISO 8601) a la zona horaria
   * actualmente seleccionada por el usuario.
   *
   * @param {string} utcStr  Ej: "2026-06-11T19:00:00Z"
   * @param {object} opts    Opciones de Intl.DateTimeFormat
   * @returns {string}       Fecha/hora formateada
   */
  format(utcStr, opts = {}) {
    if (!utcStr) return '—';
    try {
      const date = new Date(utcStr);
      const defaultOpts = {
        timeZone: State.tz,
        hour:     '2-digit',
        minute:   '2-digit',
        hour12:   false,
      };
      return new Intl.DateTimeFormat(State.lang === 'es' ? 'es-419' : 'en-US', { ...defaultOpts, ...opts }).format(date);
    } catch { return utcStr.slice(11, 16) + ' UTC'; }
  },

  /** Devuelve solo la hora (HH:mm) en la zona del usuario */
  time(utcStr) {
    return this.format(utcStr, { hour: '2-digit', minute: '2-digit', hour12: false });
  },

  /**
   * Devuelve la fecha "larga" con día de la semana.
   * Se usa en los encabezados de cada bloque de fecha.
   */
  dateLabel(dateStr) {
    // dateStr viene como "YYYY-MM-DD" (extraída del UTC original)
    // La parseamos como fecha local para que el label no se desplace
    const [y, m, d] = dateStr.split('-').map(Number);
    const date      = new Date(y, m - 1, d);
    const locale    = State.lang === 'es' ? 'es-419' : 'en-US';
    return new Intl.DateTimeFormat(locale, {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    }).format(date);
  },

  /**
   * Convierte la fecha UTC del partido a la fecha local del usuario.
   * Necesario para agrupar partidos por "el día del usuario", no por UTC.
   */
  localDateKey(utcStr) {
    if (!utcStr) return '';
    try {
      const date   = new Date(utcStr);
      const locale = 'sv-SE';    // Sueco = formato ISO YYYY-MM-DD sin ajuste
      return new Intl.DateTimeFormat(locale, {
        timeZone: State.tz, year: 'numeric', month: '2-digit', day: '2-digit',
      }).format(date);
    } catch { return utcStr.slice(0, 10); }
  },

  /**
   * Lista de zonas horarias importantes agrupadas por región.
   * Se usa para poblar el <select> del header.
   */
  getTimezones() {
    return [
      { label: '── Américas ──', disabled: true },
      { tz: 'America/New_York',             label: 'Nueva York (ET)' },
      { tz: 'America/Chicago',              label: 'Chicago (CT)' },
      { tz: 'America/Denver',               label: 'Denver (MT)' },
      { tz: 'America/Los_Angeles',          label: 'Los Ángeles (PT)' },
      { tz: 'America/Mexico_City',          label: 'Ciudad de México (CST)' },
      { tz: 'America/Bogota',               label: 'Bogotá / Lima (COT)' },
      { tz: 'America/Caracas',              label: 'Caracas (VET)' },
      { tz: 'America/Toronto',              label: 'Toronto (ET)' },
      { tz: 'America/Vancouver',            label: 'Vancouver (PT)' },
      { tz: 'America/Argentina/Buenos_Aires', label: 'Buenos Aires (ART)' },
      { tz: 'America/Sao_Paulo',            label: 'São Paulo (BRT)' },
      { tz: 'America/Santiago',             label: 'Santiago (CLT)' },
      { label: '── Europa ──', disabled: true },
      { tz: 'UTC',                          label: 'UTC / GMT' },
      { tz: 'Europe/London',                label: 'Londres (BST)' },
      { tz: 'Europe/Madrid',                label: 'Madrid / París / Berlín (CEST)' },
      { tz: 'Europe/Rome',                  label: 'Roma / Ámsterdam (CEST)' },
      { tz: 'Europe/Lisbon',                label: 'Lisboa (WEST)' },
      { tz: 'Europe/Athens',                label: 'Atenas / Bucarest (EEST)' },
      { tz: 'Europe/Moscow',                label: 'Moscú (MSK)' },
      { label: '── África ──', disabled: true },
      { tz: 'Africa/Casablanca',            label: 'Casablanca (WET)' },
      { tz: 'Africa/Cairo',                 label: 'El Cairo (EET)' },
      { tz: 'Africa/Lagos',                 label: 'Lagos / Dakar (WAT)' },
      { tz: 'Africa/Johannesburg',          label: 'Johannesburgo (SAST)' },
      { label: '── Asia / Pacífico ──', disabled: true },
      { tz: 'Asia/Riyadh',                  label: 'Riad / Bagdad (AST)' },
      { tz: 'Asia/Dubai',                   label: 'Dubái (GST)' },
      { tz: 'Asia/Karachi',                 label: 'Karachi (PKT)' },
      { tz: 'Asia/Kolkata',                 label: 'Nueva Delhi (IST)' },
      { tz: 'Asia/Bangkok',                 label: 'Bangkok (ICT)' },
      { tz: 'Asia/Singapore',               label: 'Singapur (SGT)' },
      { tz: 'Asia/Tokyo',                   label: 'Tokio (JST)' },
      { tz: 'Asia/Seoul',                   label: 'Seúl (KST)' },
      { tz: 'Australia/Sydney',             label: 'Sídney (AEST)' },
      { tz: 'Pacific/Auckland',             label: 'Auckland (NZST)' },
    ];
  },
};

/* ── Módulo de API ──────────────────────────────────────── */
const API = {
  /**
   * Carga el payload completo de partidos, posiciones y estado.
   * Pasa el filtro de grupo si está activo.
   */
  async loadData() {
    const url  = `api/get-data.php` + (State.group ? `?group=${State.group}` : '');
    const res  = await fetch(url);
    const data = await res.json();
    if (data.error) throw new Error(data.message);
    return data;
  },

  /** Dispara la actualización contra football-data.org */
  async update() {
    const res  = await fetch('api/update-results.php', { method: 'POST' });
    return await res.json();
  },
};

/* ── Renderizado: banderas ──────────────────────────────── */

/**
 * Construye la URL de la bandera en flagcdn.com.
 * Para banderas de naciones del UK (Inglaterra, Escocia, etc.)
 * usa el código compuesto (gb-eng, gb-sct).
 *
 * @param {string} iso  Código ISO minúsculo (ej: "mx", "gb-eng")
 * @param {string} tla  Abreviatura de 3 letras (fallback)
 * @returns {string}    URL de la imagen
 */
function flagUrl(iso, tla) {
  if (!iso) return '';
  return `https://flagcdn.com/w40/${iso.toLowerCase()}.png`;
}

/** Devuelve el elemento <img> de la bandera o un placeholder */
function flagImg(iso, tla, alt) {
  if (!iso) return `<span class="team-flag-placeholder">${tla || '?'}</span>`;
  return `<img class="team-flag" src="${flagUrl(iso, tla)}" alt="${alt}" loading="lazy"
               onerror="this.outerHTML='<span class=\\'team-flag-placeholder\\'>${tla||'?'}</span>'">`;
}

/* ── Renderizado: estado del partido ────────────────────── */

/** Devuelve la clase y etiqueta del badge de estado del partido */
function statusBadge(status) {
  const map = {
    SCHEDULED:  ['scheduled', 'status_scheduled'],
    IN_PLAY:    ['live',      'status_in_play'],
    PAUSED:     ['paused',    'status_paused'],
    FINISHED:   ['finished',  'status_finished'],
    POSTPONED:  ['postponed', 'status_postponed'],
    CANCELLED:  ['postponed', 'status_cancelled'],
    SUSPENDED:  ['postponed', 'status_suspended'],
  };
  const [cls, key] = map[status] || ['scheduled', 'status_scheduled'];
  return `<span class="match-status-badge badge-${cls}">${I18n.t(key)}</span>`;
}

/** Traduce el nombre de etapa (GROUP_STAGE → "Fase de Grupos") */
function stageLabel(stage) {
  const map = {
    GROUP_STAGE:     'stage_group',
    ROUND_OF_32:     'stage_r32',
    LAST_16:         'stage_r16',
    ROUND_OF_16:     'stage_r16',
    QUARTER_FINALS:  'stage_qf',
    SEMI_FINALS:     'stage_sf',
    THIRD_PLACE:     'stage_3rd',
    FINAL:           'stage_final',
  };
  return I18n.t(map[stage] || 'stage_group');
}

/* ── Renderizado: tarjeta de partido ────────────────────── */

/**
 * Genera el HTML completo para una tarjeta de partido.
 * Incluye ambos equipos con banderas, marcador, hora en la
 * zona horaria del usuario y badges de estado.
 *
 * @param {object} match  Objeto partido del payload
 * @returns {string}      HTML de la tarjeta
 */
function renderMatchCard(match) {
  const isLive     = ['IN_PLAY', 'PAUSED'].includes(match.status);
  const isFinished = match.status === 'FINISHED';
  const hasScore   = match.home_score !== null && match.away_score !== null;

  // Nombres en el idioma activo
  const homeName = (State.lang === 'es' ? match.home_name_es : match.home_name_en) || match.home_name;
  const awayName = (State.lang === 'es' ? match.away_name_es : match.away_name_en) || match.away_name;

  // Marcador o línea de hora
  let scoreHtml;
  if (hasScore) {
    const htHtml = (match.home_score_ht !== null)
      ? `<span class="score-ht">${match.home_score_ht}–${match.away_score_ht} ${I18n.t('halftime')}</span>`
      : '';
    scoreHtml = `
      <div class="score-main">${match.home_score} <span class="score-dash">—</span> ${match.away_score}</div>
      ${htHtml}
      ${statusBadge(match.status)}
    `;
  } else {
    const localTime = TZ.time(match.match_date);
    scoreHtml = `
      <div class="match-time">${localTime}</div>
      ${statusBadge(match.status)}
    `;
  }

  // Grupo y jornada
  const groupInfo = match.group_name
    ? `${I18n.t('group')} ${match.group_name} · ${I18n.t('matchday')} ${match.matchday || 1}`
    : stageLabel(match.stage);

  // Info de estadio (sección expandible)
  const venueHtml = match.venue
    ? `<span>🏟 ${match.venue}${match.city ? ', ' + match.city : ''}</span>`
    : '';

  return `
    <div class="match-card ${isLive ? 'live' : ''} ${isFinished ? 'finished' : ''}"
         onclick="toggleMatchDetail(this)" style="--i:0">
      <div class="team-block home">
        ${flagImg(match.home_iso, match.home_tla, homeName)}
        <div>
          <div class="team-name full">${homeName}</div>
          <div class="team-tla">${match.home_tla}</div>
        </div>
      </div>

      <div class="match-score">
        <div class="match-meta">${groupInfo}</div>
        ${scoreHtml}
      </div>

      <div class="team-block away">
        ${flagImg(match.away_iso, match.away_tla, awayName)}
        <div>
          <div class="team-name full">${awayName}</div>
          <div class="team-tla">${match.away_tla}</div>
        </div>
      </div>

      <div class="match-extra">
        ${venueHtml}
        <span>🕐 ${TZ.format(match.match_date, { weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false })}</span>
      </div>
    </div>
  `;
}

/** Expande/colapsa el detalle de un partido al hacer clic */
function toggleMatchDetail(el) {
  el.classList.toggle('expanded');
}

/* ── Renderizado: pestaña "Hoy" ──────────────────────────── */

function renderToday() {
  const container = document.getElementById('tab-today');
  const matches   = State.data?.today || [];

  if (!matches.length) {
    container.innerHTML = `<div class="empty-state">
      <h3>📅</h3><p>${I18n.t('no_matches_today')}</p>
    </div>`;
    return;
  }

  container.innerHTML = matches.map(renderMatchCard).join('');
}

/* ── Renderizado: pestaña "Todos los Partidos" ───────────── */

/**
 * Agrupa los partidos por fecha LOCAL del usuario (no UTC),
 * para que un partido a las 23:00 ET aparezca el día correcto.
 */
function renderAllMatches() {
  const container = document.getElementById('tab-matches');
  const matchesByDate = State.data?.matches || {};

  // Re-agrupar por fecha LOCAL del usuario
  const localGrouped = {};
  Object.values(matchesByDate).flat().forEach(m => {
    const key = TZ.localDateKey(m.match_date);
    if (!localGrouped[key]) localGrouped[key] = [];
    localGrouped[key].push(m);
  });

  const keys = Object.keys(localGrouped).sort();
  if (!keys.length) {
    container.innerHTML = `<div class="empty-state"><h3>⚽</h3><p>${I18n.t('no_matches')}</p></div>`;
    return;
  }

  // Etiquetas especiales para hoy/mañana/ayer
  const todayKey    = TZ.localDateKey(new Date().toISOString());
  const tomorrowKey = TZ.localDateKey(new Date(Date.now() + 86400000).toISOString());
  const yesterdayKey= TZ.localDateKey(new Date(Date.now() - 86400000).toISOString());

  container.innerHTML = keys.map(dateKey => {
    let label = TZ.dateLabel(dateKey);
    let extra = '';
    if (dateKey === todayKey)     extra = ` — <strong style="color:var(--fifa-gold)">${I18n.t('today')}</strong>`;
    if (dateKey === tomorrowKey)  extra = ` — <strong style="color:#66aaff">${I18n.t('tomorrow')}</strong>`;
    if (dateKey === yesterdayKey) extra = ` — <span style="color:var(--text-3)">${I18n.t('yesterday')}</span>`;

    const cards = localGrouped[dateKey].map(renderMatchCard).join('');
    return `
      <div class="date-block">
        <div class="date-header">
          <span class="date-label">${label}${extra}</span>
          <span class="date-sub">${localGrouped[dateKey].length} partidos</span>
        </div>
        ${cards}
      </div>
    `;
  }).join('');
}

/* ── Renderizado: pestaña "Grupos" ──────────────────────── */

function renderGroups() {
  const container  = document.getElementById('tab-groups');
  const standings  = State.data?.standings || {};
  const groups     = Object.keys(standings).sort();

  if (!groups.length) {
    container.innerHTML = `<div class="empty-state"><h3>📊</h3><p>${I18n.t('no_standings')}</p></div>`;
    return;
  }

  container.innerHTML = `<div class="groups-grid">` + groups.map(g => renderGroupCard(g, standings[g])).join('') + `</div>`;
}

/**
 * Genera la tarjeta de posiciones de un grupo.
 * Las primeras 2 posiciones se destacan con dorado (clasifican directo).
 * La posición 3 se destaca en azul (posible clasificado como mejor tercero).
 */
function renderGroupCard(group, rows) {
  const cols = ['pos', 'team', 'played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_diff', 'points'];
  const headers = cols.map(c => `<th>${I18n.t(c)}</th>`).join('');

  const rowsHtml = rows.map(row => {
    const name    = (State.lang === 'es' ? row.name_es : row.name_en) || row.name;
    const posClass = row.position <= 2 ? `pos-${row.position}` : (row.position === 3 ? 'pos-3' : '');
    const gdCls    = row.goal_difference > 0 ? 'positive' : (row.goal_difference < 0 ? 'negative' : '');

    return `<tr>
      <td class="pos-cell ${posClass}">${row.position}</td>
      <td>
        <div class="st-team">
          ${row.iso_code ? `<img class="st-flag" src="${flagUrl(row.iso_code)}" alt="${name}" loading="lazy"
               onerror="this.style.display='none'">` : ''}
          <div>
            <div class="st-name">${name}</div>
            <div class="st-tla">${row.tla}</div>
          </div>
        </div>
      </td>
      <td>${row.played}</td>
      <td>${row.won}</td>
      <td>${row.drawn}</td>
      <td>${row.lost}</td>
      <td>${row.goals_for}</td>
      <td>${row.goals_against}</td>
      <td class="gd-cell ${gdCls}">${row.goal_difference > 0 ? '+' : ''}${row.goal_difference}</td>
      <td class="pts-cell">${row.points}</td>
    </tr>`;
  }).join('');

  return `
    <div class="group-card">
      <div class="group-card-header">
        <div class="group-letter">${group}</div>
        <div class="group-title">${I18n.t('group')} ${group}</div>
      </div>
      <table class="standings-table">
        <thead><tr>${headers}</tr></thead>
        <tbody>${rowsHtml}</tbody>
      </table>
    </div>
  `;
}

/* ── Renderizado: controles del header ──────────────────── */

/** Pobla el <select> de zonas horarias con las opciones disponibles */
function buildTimezoneSelect() {
  const sel  = document.getElementById('tz-select');
  if (!sel) return;

  sel.innerHTML = TZ.getTimezones().map(item => {
    if (item.disabled) {
      return `<option disabled>${item.label}</option>`;
    }
    const selected = item.tz === State.tz ? 'selected' : '';
    return `<option value="${item.tz}" ${selected}>${item.label}</option>`;
  }).join('');

  sel.addEventListener('change', () => {
    State.tz = sel.value;
    localStorage.setItem('wc_tz', State.tz);
    renderCurrentTab();   // Re-renderizar con la nueva zona
  });
}

/** Actualiza el indicador de última actualización */
function updateLastUpdatedBar() {
  const el  = document.getElementById('last-updated');
  if (!el || !State.data?.status?.last_updated) return;

  const dt  = new Date(State.data.status.last_updated);
  const fmt = new Intl.DateTimeFormat(State.lang === 'es' ? 'es-419' : 'en-US', {
    timeZone: State.tz, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  });

  const hasLive = State.data.status.has_live;
  el.innerHTML  = `
    ${hasLive ? '<span class="dot-live"></span>' : ''}
    ${I18n.t('last_updated')}: <strong>${fmt.format(dt)}</strong>
  `;
}

/** Muestra u oculta el banner de aviso de modo demo */
function updateDemoBanner() {
  const banner = document.getElementById('demo-banner');
  if (!banner) return;
  const isDemo = State.data?.status?.demo_mode;
  banner.style.display = isDemo ? 'flex' : 'none';
  banner.textContent   = isDemo ? I18n.t('demo_mode') : '';
}

/* ── Renderizado: pestaña activa ────────────────────────── */

function renderCurrentTab() {
  ['today', 'matches', 'groups'].forEach(t => {
    document.getElementById(`tab-${t}`).style.display = State.tab === t ? 'block' : 'none';
    document.getElementById(`btn-${t}`).classList.toggle('active', State.tab === t);
  });

  if (State.tab === 'today')   renderToday();
  if (State.tab === 'matches') renderAllMatches();
  if (State.tab === 'groups')  renderGroups();

  updateLastUpdatedBar();
  updateDemoBanner();
  updateLiveBadge();
}

/** Actualiza el badge "EN VIVO" en la pestaña Hoy */
function updateLiveBadge() {
  const badge   = document.getElementById('live-count');
  if (!badge) return;
  const today   = State.data?.today || [];
  const liveCount = today.filter(m => ['IN_PLAY', 'PAUSED'].includes(m.status)).length;
  badge.style.display  = liveCount > 0 ? 'inline' : 'none';
  badge.textContent    = liveCount;
}

/* ── Renderizado: filtros de grupo ──────────────────────── */

function buildGroupFilters() {
  const container = document.getElementById('group-filters');
  if (!container) return;

  const groups = ['A','B','C','D','E','F','G','H','I','J','K','L'];
  const all    = `<button class="group-filter-btn active" id="filter-all" onclick="setGroupFilter(null)">${I18n.t('all_groups')}</button>`;
  const btns   = groups.map(g =>
    `<button class="group-filter-btn" id="filter-${g}" onclick="setGroupFilter('${g}')">${I18n.t('group')} ${g}</button>`
  ).join('');

  container.innerHTML = all + btns;
}

/** Activa un filtro de grupo y recarga los datos */
async function setGroupFilter(group) {
  State.group = group;
  document.querySelectorAll('.group-filter-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById(group ? `filter-${group}` : 'filter-all');
  if (target) target.classList.add('active');
  await loadData();
}

/* ── Toast de notificación ──────────────────────────────── */

let _toastTimer;
function showToast(msg, type = 'success') {
  const toast  = document.getElementById('toast');
  if (!toast) return;
  clearTimeout(_toastTimer);
  toast.textContent = msg;
  toast.className   = `toast ${type} show`;
  _toastTimer = setTimeout(() => toast.classList.remove('show'), 3500);
}

/* ── Flujo principal ────────────────────────────────────── */

/**
 * Carga los datos desde el backend PHP y actualiza el estado.
 * Si la petición falla, muestra un toast de error y conserva
 * los datos anteriores en pantalla.
 */
async function loadData() {
  try {
    State.data = await API.loadData();
    renderCurrentTab();
  } catch (err) {
    console.error('Error cargando datos:', err);
    showToast(I18n.t('update_error'), 'error');
  }
}

/**
 * Dispara la actualización en el servidor (llama a football-data.org)
 * y luego recarga los datos locales.
 */
async function triggerUpdate() {
  if (State.updating) return;
  State.updating = true;

  const btn = document.getElementById('btn-update');
  if (btn) { btn.classList.add('loading'); btn.disabled = true; }

  try {
    const result = await API.update();
    if (result.success) {
      showToast(result.message || I18n.t('update_success'), 'success');
      await loadData();
    } else {
      showToast(result.message || I18n.t('update_error'), 'error');
    }
  } catch (err) {
    showToast(I18n.t('update_error'), 'error');
  } finally {
    State.updating = false;
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
  }
}

/** Cambia el idioma de la interfaz y re-renderiza */
async function setLanguage(lang) {
  await I18n.load(lang);
  document.querySelectorAll('.lang-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.lang === lang);
  });
  buildGroupFilters();   // Re-construir etiquetas de filtros
  renderCurrentTab();
}

/** Cambia la pestaña activa */
function setTab(tab) {
  State.tab = tab;
  renderCurrentTab();
}

/* ── Auto-refresh para partidos en vivo ─────────────────── */

let _autoRefreshInterval;

/**
 * Si hay partidos en vivo, programa auto-refresh cada 60 segundos.
 * Si no hay partidos en vivo, cancela el auto-refresh.
 */
function manageAutoRefresh() {
  const hasLive = State.data?.status?.has_live;

  clearInterval(_autoRefreshInterval);
  if (hasLive) {
    _autoRefreshInterval = setInterval(() => {
      if (!State.updating) triggerUpdate();
    }, 60_000);
  }
}

/* ── Inicialización ─────────────────────────────────────── */

/**
 * Punto de entrada: se llama cuando el DOM está listo.
 * Orden de operaciones:
 *  1. Cargar idioma
 *  2. Construir controles del header (timezone, filtros)
 *  3. Cargar datos iniciales
 *  4. Configurar auto-refresh si hay partidos en vivo
 */
async function init() {
  await I18n.load(State.lang);
  buildTimezoneSelect();
  buildGroupFilters();

  // Aplicar textos i18n a elementos estáticos del HTML
  document.querySelectorAll('[data-i18n]').forEach(el => {
    el.textContent = I18n.t(el.dataset.i18n);
  });

  // Mostrar spinner mientras carga
  ['today','matches','groups'].forEach(t => {
    const el = document.getElementById(`tab-${t}`);
    if (el) el.innerHTML = `<div class="loading-overlay"><div class="spinner"></div></div>`;
  });

  await loadData();
  manageAutoRefresh();
}

document.addEventListener('DOMContentLoaded', init);
