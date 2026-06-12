/**
 * =========================================================
 * FIFA World Cup 2026 Tracker — Frontend SPA
 * =========================================================
 *
 * Arquitectura:
 *  State  → objeto centralizado con todos los datos de la app
 *  render → reconstruye la UI a partir del estado
 *  API    → comunicacion con el backend PHP
 *  I18n   → traducciones ES/EN
 *  TZ     → conversion de zona horaria con Intl.DateTimeFormat
 */

/* ── Estado global ──────────────────────────────────────── */
const State = {
  lang:         localStorage.getItem('wc_lang') || 'es',
  tz:           localStorage.getItem('wc_tz')   || Intl.DateTimeFormat().resolvedOptions().timeZone,
  tab:          'today',
  group:        null,
  data:         null,
  updating:     false,
  i18n:         {},
  nextMatchId:  null,
  timerSeconds: 0,
};

/* ── Modulo i18n ────────────────────────────────────────── */
const I18n = {
  async load(lang) {
    const res  = await fetch(`i18n/${lang}.json?v=${Date.now()}`);
    State.i18n = await res.json();
    State.lang = lang;
    localStorage.setItem('wc_lang', lang);
  },
  t(key) { return State.i18n[key] || key; },
};

/* ── Modulo de Zona Horaria ─────────────────────────────── */
const TZ = {
  /**
   * Convierte una fecha UTC (ISO 8601) a la zona horaria del usuario.
   * Usa Intl.DateTimeFormat para soporte nativo de todas las IANA TZs.
   */
  format(utcStr, opts) {
    if (!utcStr) return '—';
    opts = opts || {};
    try {
      const date = new Date(utcStr);
      const defaultOpts = {
        timeZone: State.tz,
        hour:     '2-digit',
        minute:   '2-digit',
        hour12:   false,
      };
      const merged = Object.assign({}, defaultOpts, opts);
      return new Intl.DateTimeFormat(State.lang === 'es' ? 'es-419' : 'en-US', merged).format(date);
    } catch(e) { return utcStr.slice(11, 16) + ' UTC'; }
  },

  time(utcStr) {
    return this.format(utcStr, { hour: '2-digit', minute: '2-digit', hour12: false });
  },

  dateLabel(dateStr) {
    const parts = dateStr.split('-').map(Number);
    const date  = new Date(parts[0], parts[1] - 1, parts[2]);
    const locale = State.lang === 'es' ? 'es-419' : 'en-US';
    return new Intl.DateTimeFormat(locale, {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    }).format(date);
  },

  /**
   * Convierte una fecha UTC al dia LOCAL del usuario (formato YYYY-MM-DD).
   * Necesario para agrupar partidos por "el dia del usuario" y no por UTC,
   * ya que un partido a las 23:00 CT puede ser al dia siguiente en UTC.
   */
  localDateKey(utcStr) {
    if (!utcStr) return '';
    try {
      const date = new Date(utcStr);
      return new Intl.DateTimeFormat('sv-SE', {
        timeZone: State.tz, year: 'numeric', month: '2-digit', day: '2-digit',
      }).format(date);
    } catch(e) { return utcStr.slice(0, 10); }
  },

  /**
   * Lista de zonas horarias organizada por pais/region.
   * Guatemala y toda Centroamerica incluidos prominentemente.
   * Los emojis de banderas ayudan a identificar rapido el pais.
   */
  getTimezones() {
    return [
      // ── América del Norte ──────────────────────────────
      { label: '── América del Norte ──', disabled: true },
      { tz: 'America/New_York',    label: '🇺🇸 Estados Unidos — Este (ET, UTC-5/-4)' },
      { tz: 'America/Chicago',     label: '🇺🇸 Estados Unidos — Centro (CT, UTC-6/-5)' },
      { tz: 'America/Denver',      label: '🇺🇸 Estados Unidos — Montaña (MT, UTC-7/-6)' },
      { tz: 'America/Los_Angeles', label: '🇺🇸 Estados Unidos — Pacífico (PT, UTC-8/-7)' },
      { tz: 'America/Anchorage',   label: '🇺🇸 Alaska (AKT, UTC-9/-8)' },
      { tz: 'America/Toronto',     label: '🇨🇦 Canadá — Este (ET, UTC-5/-4)' },
      { tz: 'America/Vancouver',   label: '🇨🇦 Canadá — Pacífico (PT, UTC-8/-7)' },
      { tz: 'America/Mexico_City', label: '🇲🇽 México — Centro (CST, UTC-6/-5)' },
      { tz: 'America/Tijuana',     label: '🇲🇽 México — Pacífico (PST, UTC-8/-7)' },
      // ── Centroamérica ──────────────────────────────────
      { label: '── Centroamérica / Caribe ──', disabled: true },
      { tz: 'America/Guatemala',   label: '🇬🇹 Guatemala (CST, UTC-6)' },
      { tz: 'America/El_Salvador', label: '🇸🇻 El Salvador (CST, UTC-6)' },
      { tz: 'America/Tegucigalpa', label: '🇭🇳 Honduras (CST, UTC-6)' },
      { tz: 'America/Managua',     label: '🇳🇮 Nicaragua (CST, UTC-6)' },
      { tz: 'America/Costa_Rica',  label: '🇨🇷 Costa Rica (CST, UTC-6)' },
      { tz: 'America/Panama',      label: '🇵🇦 Panamá (EST, UTC-5)' },
      { tz: 'America/Havana',      label: '🇨🇺 Cuba (CDT, UTC-5/-4)' },
      { tz: 'America/Puerto_Rico', label: '🇵🇷 Puerto Rico (AST, UTC-4)' },
      { tz: 'America/Santo_Domingo', label: '🇩🇴 República Dominicana (AST, UTC-4)' },
      // ── Sudamérica ────────────────────────────────────
      { label: '── Sudamérica ──', disabled: true },
      { tz: 'America/Bogota',      label: '🇨🇴 Colombia / Perú / Ecuador (COT, UTC-5)' },
      { tz: 'America/Caracas',     label: '🇻🇪 Venezuela (VET, UTC-4)' },
      { tz: 'America/Sao_Paulo',   label: '🇧🇷 Brasil — Brasilia (BRT, UTC-3)' },
      { tz: 'America/Manaus',      label: '🇧🇷 Brasil — Amazonas (AMT, UTC-4)' },
      { tz: 'America/Argentina/Buenos_Aires', label: '🇦🇷 Argentina (ART, UTC-3)' },
      { tz: 'America/Santiago',    label: '🇨🇱 Chile (CLT, UTC-4/-3)' },
      { tz: 'America/Asuncion',    label: '🇵🇾 Paraguay (PYT, UTC-4/-3)' },
      { tz: 'America/La_Paz',      label: '🇧🇴 Bolivia (BOT, UTC-4)' },
      // ── Europa ───────────────────────────────────────
      { label: '── Europa ──', disabled: true },
      { tz: 'UTC',                 label: '🌐 UTC / GMT (UTC+0)' },
      { tz: 'Europe/London',       label: '🇬🇧 Reino Unido (BST, UTC+1)' },
      { tz: 'Europe/Lisbon',       label: '🇵🇹 Portugal (WEST, UTC+1)' },
      { tz: 'Europe/Madrid',       label: '🇪🇸 España / Francia / Alemania (CEST, UTC+2)' },
      { tz: 'Europe/Rome',         label: '🇮🇹 Italia / Países Bajos (CEST, UTC+2)' },
      { tz: 'Europe/Athens',       label: '🇬🇷 Grecia / Rumanía (EEST, UTC+3)' },
      { tz: 'Europe/Moscow',       label: '🇷🇺 Rusia (MSK, UTC+3)' },
      // ── África ───────────────────────────────────────
      { label: '── África ──', disabled: true },
      { tz: 'Africa/Casablanca',   label: '🇲🇦 Marruecos (WET, UTC+1)' },
      { tz: 'Africa/Cairo',        label: '🇪🇬 Egipto (EET, UTC+2)' },
      { tz: 'Africa/Lagos',        label: '🇳🇬 Nigeria / Senegal (WAT, UTC+1)' },
      { tz: 'Africa/Nairobi',      label: '🇰🇪 Kenia (EAT, UTC+3)' },
      { tz: 'Africa/Johannesburg', label: '🇿🇦 Sudáfrica (SAST, UTC+2)' },
      // ── Asia / Pacífico ───────────────────────────────
      { label: '── Asia / Pacífico ──', disabled: true },
      { tz: 'Asia/Riyadh',         label: '🇸🇦 Arabia Saudita / Irak (AST, UTC+3)' },
      { tz: 'Asia/Dubai',          label: '🇦🇪 Emiratos Árabes (GST, UTC+4)' },
      { tz: 'Asia/Karachi',        label: '🇵🇰 Pakistán (PKT, UTC+5)' },
      { tz: 'Asia/Kolkata',        label: '🇮🇳 India (IST, UTC+5:30)' },
      { tz: 'Asia/Tehran',         label: '🇮🇷 Irán (IRST, UTC+3:30)' },
      { tz: 'Asia/Bangkok',        label: '🇹🇭 Tailandia / Vietnam (ICT, UTC+7)' },
      { tz: 'Asia/Singapore',      label: '🇸🇬 Singapur / Malasia (SGT, UTC+8)' },
      { tz: 'Asia/Tokyo',          label: '🇯🇵 Japón (JST, UTC+9)' },
      { tz: 'Asia/Seoul',          label: '🇰🇷 Corea del Sur (KST, UTC+9)' },
      { tz: 'Australia/Sydney',    label: '🇦🇺 Australia (AEST, UTC+10/+11)' },
      { tz: 'Pacific/Auckland',    label: '🇳🇿 Nueva Zelanda (NZST, UTC+12)' },
    ];
  },
};

/* ── Modulo de API ──────────────────────────────────────── */
const API = {
  async loadData() {
    const url = 'api/get-data.php' + (State.group ? '?group=' + State.group : '');
    const res  = await fetch(url);
    const data = await res.json();
    if (data.error) throw new Error(data.message);
    return data;
  },
  async update() {
    const res = await fetch('api/update-results.php', { method: 'POST' });
    return await res.json();
  },
};

/* ── Helpers de banderas ────────────────────────────────── */

function flagUrl(iso) {
  if (!iso) return '';
  return 'https://flagcdn.com/w40/' + iso.toLowerCase() + '.png';
}

function flagImg(iso, tla, alt) {
  if (!iso) return '<span class="team-flag-placeholder">' + (tla || '?') + '</span>';
  return '<img class="team-flag" src="' + flagUrl(iso) + '" alt="' + alt + '" loading="lazy"' +
         ' onerror="this.outerHTML=\'<span class=\\\'team-flag-placeholder\\\'>' + (tla||'?') + '</span>\'">';
}

/* ── Badge de estado del partido ────────────────────────── */

function statusBadge(status) {
  const map = {
    SCHEDULED: ['scheduled', 'status_scheduled'],
    IN_PLAY:   ['live',      'status_in_play'],
    PAUSED:    ['paused',    'status_paused'],
    FINISHED:  ['finished',  'status_finished'],
    POSTPONED: ['postponed', 'status_postponed'],
    CANCELLED: ['postponed', 'status_cancelled'],
    SUSPENDED: ['postponed', 'status_suspended'],
  };
  const info = map[status] || ['scheduled', 'status_scheduled'];
  return '<span class="match-status-badge badge-' + info[0] + '">' + I18n.t(info[1]) + '</span>';
}

function stageLabel(stage) {
  const map = {
    GROUP_STAGE:    'stage_group',
    ROUND_OF_32:    'stage_r32',
    LAST_32:        'stage_r32',
    LAST_16:        'stage_r16',
    ROUND_OF_16:    'stage_r16',
    QUARTER_FINALS: 'stage_qf',
    SEMI_FINALS:    'stage_sf',
    THIRD_PLACE:    'stage_3rd',
    FINAL:          'stage_final',
  };
  return I18n.t(map[stage] || 'stage_group');
}

/* ── Detectar proximo partido ───────────────────────────── */

/**
 * Encuentra el primer partido PROGRAMADO despues de ahora,
 * ordenado cronologicamente. Usado para el indicador "PROXIMO PARTIDO".
 */
function findNextMatchId(matches) {
  const now = Date.now();
  const upcoming = matches
    .filter(function(m) { return m.status === 'SCHEDULED' && m.match_date; })
    .sort(function(a, b) { return new Date(a.match_date) - new Date(b.match_date); });
  const next = upcoming.filter(function(m) { return new Date(m.match_date).getTime() > now; });
  return next.length ? next[0].id : null;
}

/** Extrae todos los partidos como array plano desde el payload agrupado */
function getAllMatchesFlat() {
  if (!State.data || !State.data.all) return [];
  const flat = [];
  const groups = Object.values(State.data.all);
  for (let i = 0; i < groups.length; i++) {
    const dayMatches = groups[i];
    if (Array.isArray(dayMatches)) {
      for (let j = 0; j < dayMatches.length; j++) {
        flat.push(dayMatches[j]);
      }
    }
  }
  return flat;
}

/* ── Tarjeta de partido ─────────────────────────────────── */

/**
 * Genera el HTML completo de una tarjeta de partido.
 *
 * isNext: true si es el proximo partido programado.
 *   Se muestra un indicador dorado "▶ PROXIMO PARTIDO" encima.
 */
function renderMatchCard(match, isNext) {
  const isLive     = match.status === 'IN_PLAY' || match.status === 'PAUSED';
  const isFinished = match.status === 'FINISHED';
  const hasScore   = match.home_score !== null && match.away_score !== null;

  const homeName = (State.lang === 'es' ? match.home_name_es : match.home_name_en) || match.home_name;
  const awayName = (State.lang === 'es' ? match.away_name_es : match.away_name_en) || match.away_name;

  // Fecha en zona del usuario (dia semana + dia + mes corto)
  const localDate = TZ.format(match.match_date, { weekday: 'short', day: 'numeric', month: 'short' });

  // Marcador o linea de hora en zona del usuario
  let scoreHtml;
  if (hasScore) {
    const htHtml = (match.home_score_ht !== null)
      ? '<span class="score-ht">' + match.home_score_ht + '–' + match.away_score_ht + ' ' + I18n.t('halftime') + '</span>'
      : '';
    scoreHtml =
      '<div class="match-date-small">' + localDate + '</div>' +
      '<div class="score-main">' + match.home_score + ' <span class="score-dash">—</span> ' + match.away_score + '</div>' +
      htHtml + statusBadge(match.status);
  } else {
    const localTime = TZ.time(match.match_date);
    // Estimar hora de fin: inicio + 115 min (90 reglamento + 15 entretiempo + ~10 adicionados)
    const endDate = new Date(new Date(match.match_date).getTime() + 115 * 60000);
    const endTime = TZ.time(endDate.toISOString());
    scoreHtml =
      '<div class="match-date-small">' + localDate + '</div>' +
      '<div class="match-time">' + localTime + '<span class="match-end-approx"> ~' + endTime + '</span></div>' +
      statusBadge(match.status);
  }

  const groupInfo = match.group_name
    ? I18n.t('group') + ' ' + match.group_name + ' · ' + I18n.t('matchday') + ' ' + (match.matchday || 1)
    : stageLabel(match.stage);

  const venueHtml = match.venue
    ? '<span>🏟 ' + match.venue + (match.city ? ', ' + match.city : '') + '</span>'
    : '';

  // Hora completa con dia de semana en el tooltip del horario
  const fullTime = TZ.format(match.match_date, { weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false });

  // Banner "PROXIMO PARTIDO" — solo visible en el primer partido pendiente
  const nextBanner = isNext
    ? '<div class="next-match-label">▶ ' + I18n.t('up_next') + '</div>'
    : '';

  const cardClass = 'match-card' +
    (isLive     ? ' live'       : '') +
    (isFinished ? ' finished'   : '') +
    (isNext     ? ' next-match' : '');

  return (
    '<div class="' + cardClass + '" onclick="toggleMatchDetail(this)" style="--i:0">' +
      nextBanner +
      '<div class="team-block home">' +
        flagImg(match.home_iso, match.home_tla, homeName) +
        '<div>' +
          '<div class="team-name full">' + homeName + '</div>' +
          '<div class="team-tla">' + match.home_tla + '</div>' +
        '</div>' +
      '</div>' +
      '<div class="match-score">' +
        '<div class="match-meta">' + groupInfo + '</div>' +
        scoreHtml +
      '</div>' +
      '<div class="team-block away">' +
        flagImg(match.away_iso, match.away_tla, awayName) +
        '<div>' +
          '<div class="team-name full">' + awayName + '</div>' +
          '<div class="team-tla">' + match.away_tla + '</div>' +
        '</div>' +
      '</div>' +
      '<div class="match-extra">' +
        venueHtml +
        '<span>🕐 ' + fullTime + '</span>' +
      '</div>' +
    '</div>'
  );
}

function toggleMatchDetail(el) { el.classList.toggle('expanded'); }

/* ── Pestaña "Hoy" ──────────────────────────────────────── */

function renderToday() {
  const container = document.getElementById('tab-today');
  // Incluir partidos de hoy + partidos en vivo (pueden ser de ayer en UTC)
  const todayMatches = (State.data && State.data.today) ? State.data.today : [];

  // Ordenar cronologicamente por hora del partido
  const sorted = todayMatches.slice().sort(function(a, b) {
    return new Date(a.match_date) - new Date(b.match_date);
  });

  if (!sorted.length) {
    container.innerHTML = '<div class="empty-state"><h3>📅</h3><p>' + I18n.t('no_matches_today') + '</p></div>';
    return;
  }

  // Proximo partido entre los de hoy
  const nextId = findNextMatchId(sorted);
  container.innerHTML = sorted.map(function(m) {
    return renderMatchCard(m, m.id === nextId);
  }).join('');
}

/* ── Pestaña "Todos los Partidos" ────────────────────────── */

/**
 * Re-agrupa todos los partidos por fecha LOCAL del usuario,
 * no por UTC, para que los horarios nocturnos aparezcan el dia correcto.
 * Orden cronologico dentro de cada dia.
 */
function renderAllMatches() {
  const container = document.getElementById('tab-matches');

  // CLAVE CORRECTA: 'all', no 'matches' (buildPayload usa 'all')
  const matchesByDate = (State.data && State.data.all) ? State.data.all : {};

  // Re-agrupar por fecha LOCAL del usuario
  const localGrouped = {};
  const allFlat = [];
  Object.values(matchesByDate).forEach(function(dayArr) {
    if (!Array.isArray(dayArr)) return;
    dayArr.forEach(function(m) {
      const key = TZ.localDateKey(m.match_date);
      if (!localGrouped[key]) localGrouped[key] = [];
      localGrouped[key].push(m);
      allFlat.push(m);
    });
  });

  const keys = Object.keys(localGrouped).sort();
  if (!keys.length) {
    container.innerHTML = '<div class="empty-state"><h3>⚽</h3><p>' + I18n.t('no_matches') + '</p></div>';
    return;
  }

  // Ordenar cada dia cronologicamente
  keys.forEach(function(k) {
    localGrouped[k].sort(function(a, b) {
      return new Date(a.match_date) - new Date(b.match_date);
    });
  });

  // Proximo partido de todos (para el indicador global)
  State.nextMatchId = findNextMatchId(allFlat);

  // Fechas especiales para etiquetas
  const todayKey     = TZ.localDateKey(new Date().toISOString());
  const tomorrowKey  = TZ.localDateKey(new Date(Date.now() + 86400000).toISOString());
  const yesterdayKey = TZ.localDateKey(new Date(Date.now() - 86400000).toISOString());

  container.innerHTML = keys.map(function(dateKey) {
    let label = TZ.dateLabel(dateKey);
    let extra = '';
    if (dateKey === todayKey)     extra = ' — <strong style="color:var(--fifa-gold)">'  + I18n.t('today')     + '</strong>';
    if (dateKey === tomorrowKey)  extra = ' — <strong style="color:#66aaff">'            + I18n.t('tomorrow')  + '</strong>';
    if (dateKey === yesterdayKey) extra = ' — <span style="color:var(--text-3)">'        + I18n.t('yesterday') + '</span>';

    const cnt   = localGrouped[dateKey].length;
    const cards = localGrouped[dateKey].map(function(m) {
      return renderMatchCard(m, m.id === State.nextMatchId);
    }).join('');

    return (
      '<div class="date-block">' +
        '<div class="date-header">' +
          '<span class="date-label">' + label + extra + '</span>' +
          '<span class="date-sub">' + cnt + ' ' + (cnt === 1 ? 'partido' : 'partidos') + '</span>' +
        '</div>' +
        cards +
      '</div>'
    );
  }).join('');
}

/* ── Pestaña "Grupos" ───────────────────────────────────── */

function renderGroups() {
  const container = document.getElementById('tab-groups');
  const standings = (State.data && State.data.standings) ? State.data.standings : {};
  const allGroups = Object.keys(standings).sort();

  // Si hay un filtro activo, mostrar solo ese grupo; si no, mostrar todos
  const groups = State.group
    ? (standings[State.group] ? [State.group] : allGroups)
    : allGroups;

  if (!groups.length) {
    container.innerHTML = '<div class="empty-state"><h3>📊</h3><p>' + I18n.t('no_standings') + '</p></div>';
    return;
  }

  container.innerHTML = '<div class="groups-grid">' +
    groups.map(function(g) { return renderGroupCard(g, standings[g]); }).join('') +
  '</div>';
}

/**
 * Tarjeta de posiciones de un grupo.
 * Top-2: dorado (clasifican directo). Top-3: azul (posible mejor tercero).
 */
function renderGroupCard(group, rows) {
  const cols    = ['pos','team','played','won','drawn','lost','goals_for','goals_against','goal_diff','points'];
  const headers = cols.map(function(c) { return '<th>' + I18n.t(c) + '</th>'; }).join('');

  const rowsHtml = rows.map(function(row) {
    const name     = (State.lang === 'es' ? row.name_es : row.name_en) || row.name;
    const posClass = row.position <= 2 ? 'pos-' + row.position : (row.position === 3 ? 'pos-3' : '');
    const gdCls    = row.goal_difference > 0 ? 'positive' : (row.goal_difference < 0 ? 'negative' : '');
    const gdPfx    = row.goal_difference > 0 ? '+' : '';
    const flag     = row.iso_code
      ? '<img class="st-flag" src="' + flagUrl(row.iso_code) + '" alt="' + name + '" loading="lazy" onerror="this.style.display=\'none\'">'
      : '';
    return (
      '<tr>' +
        '<td class="pos-cell ' + posClass + '">' + row.position + '</td>' +
        '<td><div class="st-team">' + flag +
          '<div><div class="st-name">' + name + '</div><div class="st-tla">' + row.tla + '</div></div>' +
        '</div></td>' +
        '<td>' + row.played   + '</td>' +
        '<td>' + row.won      + '</td>' +
        '<td>' + row.drawn    + '</td>' +
        '<td>' + row.lost     + '</td>' +
        '<td>' + row.goals_for     + '</td>' +
        '<td>' + row.goals_against + '</td>' +
        '<td class="gd-cell ' + gdCls + '">' + gdPfx + row.goal_difference + '</td>' +
        '<td class="pts-cell">' + row.points + '</td>' +
      '</tr>'
    );
  }).join('');

  return (
    '<div class="group-card">' +
      '<div class="group-card-header">' +
        '<div class="group-letter">' + group + '</div>' +
        '<div class="group-title">' + I18n.t('group') + ' ' + group + '</div>' +
      '</div>' +
      '<table class="standings-table">' +
        '<thead><tr>' + headers + '</tr></thead>' +
        '<tbody>' + rowsHtml + '</tbody>' +
      '</table>' +
    '</div>'
  );
}

/* ── Pestaña "Líderes" ──────────────────────────────────── */

/**
 * Tabla global de equipos ordenada por puntos > DG > GF.
 * Indica zona de clasificacion directa (top 2 por grupo) y
 * mejor tercero (top 8 de todos los 3ros, formato WC 2026).
 */
function renderLeaderboard() {
  const container = document.getElementById('tab-leaders');
  const standings = (State.data && State.data.standings) ? State.data.standings : {};
  const groups    = Object.keys(standings);

  if (!groups.length) {
    container.innerHTML = '<div class="empty-state"><h3>📊</h3><p>' + I18n.t('no_leaders') + '</p></div>';
    return;
  }

  // Aplanar todos los equipos de todos los grupos
  const allTeams = [];
  groups.forEach(function(g) {
    const rows = standings[g];
    if (!Array.isArray(rows)) return;
    rows.forEach(function(row) {
      allTeams.push({
        group:           g,
        group_pos:       row.position,           // posicion dentro del grupo (1-4)
        name:            row.name,
        name_es:         row.name_es,
        name_en:         row.name_en,
        tla:             row.tla,
        iso_code:        row.iso_code,
        played:          row.played,
        won:             row.won,
        drawn:           row.drawn,
        lost:            row.lost,
        goals_for:       row.goals_for,
        goals_against:   row.goals_against,
        goal_difference: row.goal_difference,
        points:          row.points,
      });
    });
  });

  // Ordenar: puntos DESC → DG DESC → GF DESC → nombre ASC
  allTeams.sort(function(a, b) {
    if (b.points          !== a.points)          return b.points          - a.points;
    if (b.goal_difference !== a.goal_difference) return b.goal_difference - a.goal_difference;
    if (b.goals_for       !== a.goals_for)       return b.goals_for       - a.goals_for;
    return (a.name || '').localeCompare(b.name || '');
  });

  // Identificar top-8 terceros para la zona de "mejor tercero"
  const thirds = allTeams.filter(function(t) { return t.group_pos === 3; });
  const top8ThirdTlas = {};
  thirds.slice(0, 8).forEach(function(t) { top8ThirdTlas[t.tla] = true; });

  const MEDALS = ['🥇', '🥈', '🥉'];

  const rowsHtml = allTeams.map(function(row, idx) {
    const name    = (State.lang === 'es' ? row.name_es : row.name_en) || row.name;
    const isDirect   = row.group_pos <= 2;
    const isBest3rd  = row.group_pos === 3 && top8ThirdTlas[row.tla];
    const topClass   = idx < 3 ? ' lb-top-' + (idx + 1) : '';
    const rowClass   = (isDirect ? 'lb-qualify-direct' : (isBest3rd ? 'lb-qualify-third' : '')) + topClass;
    const qualBadge  = isDirect
      ? '<span class="lb-badge lb-badge-direct">✓</span>'
      : (isBest3rd ? '<span class="lb-badge lb-badge-third">3°</span>' : '');
    const gdCls  = row.goal_difference > 0 ? 'positive' : (row.goal_difference < 0 ? 'negative' : '');
    const gdPfx  = row.goal_difference > 0 ? '+' : '';
    const flag   = row.iso_code
      ? '<img class="st-flag" src="' + flagUrl(row.iso_code) + '" alt="' + name + '" loading="lazy" onerror="this.style.display=\'none\'">'
      : '';
    const rankDisplay = idx < 3
      ? '<span class="lb-medal">' + MEDALS[idx] + '</span>'
      : (idx + 1);

    return (
      '<tr class="' + rowClass + '">' +
        '<td class="lb-rank">' + rankDisplay + '</td>' +
        '<td><span class="lb-group-pill">' + row.group + '</span></td>' +
        '<td><div class="st-team">' + flag +
          '<div><div class="st-name">' + name + '</div><div class="st-tla">' + row.tla + '</div></div>' +
        '</div></td>' +
        '<td>' + row.played         + '</td>' +
        '<td>' + row.won            + '</td>' +
        '<td>' + row.drawn          + '</td>' +
        '<td>' + row.lost           + '</td>' +
        '<td>' + row.goals_for      + '</td>' +
        '<td>' + row.goals_against  + '</td>' +
        '<td class="gd-cell ' + gdCls + '">' + gdPfx + row.goal_difference + '</td>' +
        '<td class="pts-cell"><strong>' + row.points + '</strong></td>' +
        '<td>' + qualBadge + '</td>' +
      '</tr>'
    );
  }).join('');

  container.innerHTML = (
    '<div class="leaderboard-wrap">' +
      '<div class="lb-legend">' +
        '<span class="lb-badge lb-badge-direct">✓</span> ' + I18n.t('qualify_direct') +
        ' &nbsp;·&nbsp; ' +
        '<span class="lb-badge lb-badge-third">3°</span> ' + I18n.t('best_thirds') +
      '</div>' +
      '<div class="lb-table-scroll">' +
        '<table class="standings-table leaderboard-table">' +
          '<thead><tr>' +
            '<th>#</th>' +
            '<th>' + I18n.t('group') + '</th>' +
            '<th>' + I18n.t('team')  + '</th>' +
            '<th>' + I18n.t('played') + '</th>' +
            '<th>' + I18n.t('won')    + '</th>' +
            '<th>' + I18n.t('drawn')  + '</th>' +
            '<th>' + I18n.t('lost')   + '</th>' +
            '<th>' + I18n.t('goals_for') + '</th>' +
            '<th>' + I18n.t('goals_against') + '</th>' +
            '<th>' + I18n.t('goal_diff') + '</th>' +
            '<th>' + I18n.t('points') + '</th>' +
            '<th></th>' +
          '</tr></thead>' +
          '<tbody>' + rowsHtml + '</tbody>' +
        '</table>' +
      '</div>' +
    '</div>'
  );
}

/* ── Controles del header ────────────────────────────────── */

/** Pobla el select de zonas horarias organizado por pais */
function buildTimezoneSelect() {
  const sel = document.getElementById('tz-select');
  if (!sel) return;

  sel.innerHTML = TZ.getTimezones().map(function(item) {
    if (item.disabled) return '<option disabled>' + item.label + '</option>';
    return '<option value="' + item.tz + '"' + (item.tz === State.tz ? ' selected' : '') + '>' + item.label + '</option>';
  }).join('');

  sel.addEventListener('change', function() {
    State.tz = sel.value;
    localStorage.setItem('wc_tz', State.tz);
    renderCurrentTab();
  });
}

function updateLastUpdatedBar() {
  const el = document.getElementById('last-updated');
  if (!el || !State.data || !State.data.status || !State.data.status.last_updated) return;

  const dt  = new Date(State.data.status.last_updated);
  const fmt = new Intl.DateTimeFormat(State.lang === 'es' ? 'es-419' : 'en-US', {
    timeZone: State.tz, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  });

  // has_live esta en el nivel raiz del payload, no dentro de status
  const hasLive = State.data.has_live;
  el.innerHTML  =
    (hasLive ? '<span class="dot-live"></span>' : '') +
    I18n.t('last_updated') + ': <strong>' + fmt.format(dt) + '</strong>';
}

function updateDemoBanner() {
  const banner = document.getElementById('demo-banner');
  if (!banner) return;
  const isDemo = State.data && State.data.status && State.data.status.demo_mode;
  banner.style.display = isDemo ? 'flex' : 'none';
  banner.textContent   = isDemo ? I18n.t('demo_mode') : '';
}

/* ── Pestaña activa ─────────────────────────────────────── */

function renderCurrentTab() {
  ['today', 'matches', 'groups', 'leaders'].forEach(function(t) {
    document.getElementById('tab-' + t).style.display = State.tab === t ? 'block' : 'none';
    document.getElementById('btn-' + t).classList.toggle('active', State.tab === t);
  });

  if (State.tab === 'today')   renderToday();
  if (State.tab === 'matches') renderAllMatches();
  if (State.tab === 'groups')  renderGroups();
  if (State.tab === 'leaders') renderLeaderboard();

  // Ocultar filtro de grupos en la pestaña Lideres (muestra todos por definicion)
  const filterBar = document.getElementById('group-filters');
  if (filterBar) filterBar.style.display = State.tab === 'leaders' ? 'none' : '';

  updateLastUpdatedBar();
  updateDemoBanner();
  updateLiveBadge();
}

function updateLiveBadge() {
  const badge = document.getElementById('live-count');
  if (!badge) return;
  const today     = (State.data && State.data.today) ? State.data.today : [];
  const liveCount = today.filter(function(m) { return m.status === 'IN_PLAY' || m.status === 'PAUSED'; }).length;
  badge.style.display = liveCount > 0 ? 'inline' : 'none';
  badge.textContent   = liveCount;
}

/* ── Filtros de grupo ───────────────────────────────────── */

function buildGroupFilters() {
  const container = document.getElementById('group-filters');
  if (!container) return;

  const groups = ['A','B','C','D','E','F','G','H','I','J','K','L'];
  const allBtn = '<button class="group-filter-btn active" id="filter-all" onclick="setGroupFilter(null)">' + I18n.t('all_groups') + '</button>';
  const btns   = groups.map(function(g) {
    return '<button class="group-filter-btn" id="filter-' + g + '" onclick="setGroupFilter(\'' + g + '\')">' + I18n.t('group') + ' ' + g + '</button>';
  }).join('');

  container.innerHTML = allBtn + btns;
}

async function setGroupFilter(group) {
  State.group = group;
  document.querySelectorAll('.group-filter-btn').forEach(function(b) { b.classList.remove('active'); });
  const target = document.getElementById(group ? 'filter-' + group : 'filter-all');
  if (target) target.classList.add('active');
  await loadData();
}

/* ── Toast ──────────────────────────────────────────────── */

let _toastTimer;
function showToast(msg, type) {
  type = type || 'success';
  const toast = document.getElementById('toast');
  if (!toast) return;
  clearTimeout(_toastTimer);
  toast.textContent = msg;
  toast.className   = 'toast ' + type + ' show';
  _toastTimer = setTimeout(function() { toast.classList.remove('show'); }, 3500);
}

/* ── Flujo principal ────────────────────────────────────── */

/**
 * Carga datos del backend PHP.
 * Si el servidor responde con demo_mode=true, auto-dispara
 * una actualizacion contra ESPN (una sola vez por sesion)
 * para migrar a datos reales sin que el usuario pulse nada.
 */
let _autoUpdateDone = false;

async function loadData() {
  try {
    State.data = await API.loadData();

    // Recalcular proximo partido con los datos frescos
    State.nextMatchId = findNextMatchId(getAllMatchesFlat());

    renderCurrentTab();

    // Auto-actualizar si estamos en demo y aun no se intento en esta sesion
    if (!_autoUpdateDone && State.data && State.data.status && State.data.status.demo_mode) {
      _autoUpdateDone = true;
      triggerUpdate();  // No se await: corre en segundo plano
    }
  } catch(err) {
    console.error('Error cargando datos:', err);
    showToast(I18n.t('update_error'), 'error');
  }
}

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
      // Reiniciar cuenta atras despues de cada actualizacion exitosa
      if (_timerInterval && State.timerSeconds) {
        _timerCountdown = State.timerSeconds;
        updateTimerBadge(_timerCountdown);
      }
    } else {
      showToast(result.message || I18n.t('update_error'), 'error');
    }
  } catch(err) {
    showToast(I18n.t('update_error'), 'error');
  } finally {
    State.updating = false;
    if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
  }
}

async function setLanguage(lang) {
  await I18n.load(lang);
  document.querySelectorAll('.lang-btn').forEach(function(b) {
    b.classList.toggle('active', b.dataset.lang === lang);
  });
  buildGroupFilters();
  renderCurrentTab();
}

function setTab(tab) {
  State.tab = tab;
  renderCurrentTab();
}

/* ── Timer de auto-actualización configurable ───────────── */

let _timerInterval  = null;
let _timerCountdown = 0;

/**
 * Activa el timer periodico de actualizacion con el intervalo elegido
 * por el usuario. Persiste la seleccion en localStorage.
 */
function setRefreshTimer(seconds) {
  State.timerSeconds = seconds;
  localStorage.setItem('timerSeconds', seconds ? String(seconds) : '0');
  clearInterval(_timerInterval);
  _timerInterval  = null;
  _timerCountdown = 0;
  updateTimerBadge(0);
  if (!seconds) return;
  _timerCountdown = seconds;
  updateTimerBadge(_timerCountdown);
  _timerInterval = setInterval(function() {
    _timerCountdown--;
    updateTimerBadge(_timerCountdown);
    if (_timerCountdown <= 0) {
      _timerCountdown = seconds;
      if (!State.updating) triggerUpdate();
    }
  }, 1000);
}

function updateTimerBadge(remaining) {
  const badge = document.getElementById('timer-badge');
  if (!badge) return;
  if (!remaining || remaining <= 0) { badge.style.display = 'none'; return; }
  badge.style.display = 'inline-block';
  const m = Math.floor(remaining / 60);
  const s = remaining % 60;
  badge.textContent = m > 0 ? m + 'm ' + (s < 10 ? '0' : '') + s + 's' : remaining + 's';
}

/* ── Auto-refresh para partidos en vivo ─────────────────── */

let _autoRefreshInterval;

/**
 * Activa auto-refresh cada 60s cuando hay partidos en vivo.
 * has_live esta en State.data.has_live (nivel raiz), no en status.
 */
function manageAutoRefresh() {
  const hasLive = State.data && State.data.has_live;
  clearInterval(_autoRefreshInterval);
  if (hasLive) {
    _autoRefreshInterval = setInterval(function() {
      if (!State.updating) triggerUpdate();
    }, 60000);
  }
}

/* ── Inicializacion ─────────────────────────────────────── */

async function init() {
  await I18n.load(State.lang);
  buildTimezoneSelect();
  buildGroupFilters();

  // Restaurar timer guardado
  const savedTimer = parseInt(localStorage.getItem('timerSeconds') || '0', 10);
  const timerSel   = document.getElementById('refresh-timer');
  if (timerSel && savedTimer) timerSel.value = savedTimer;
  if (savedTimer) setRefreshTimer(savedTimer);

  document.querySelectorAll('[data-i18n]').forEach(function(el) {
    el.textContent = I18n.t(el.dataset.i18n);
  });

  // Spinner mientras carga
  ['today','matches','groups','leaders'].forEach(function(t) {
    const el = document.getElementById('tab-' + t);
    if (el) el.innerHTML = '<div class="loading-overlay"><div class="spinner"></div></div>';
  });

  await loadData();
  manageAutoRefresh();
}

document.addEventListener('DOMContentLoaded', init);
