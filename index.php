<?php
/**
 * =========================================================
 * FIFA World Cup 2026 Tracker — Página Principal
 * =========================================================
 *
 * Shell HTML de la SPA. El contenido dinámico lo renderiza
 * app.js mediante llamadas AJAX al backend PHP.
 *
 * Versión: <?= trim(file_get_contents(__DIR__ . '/VERSION')) ?>
 */

// Inicializar la base de datos / seeds si es la primera visita
require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/database.php';
Database::connect();   // Crea DB, tablas y seeds si no existen

$version = APP_VERSION;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Calendario completo, resultados y tabla de posiciones de la Copa del Mundo FIFA 2026 USA·México·Canadá">
  <meta name="theme-color" content="#0D0D1A">
  <title>Copa del Mundo FIFA 2026 — Calendario y Resultados</title>

  <!-- Favicon minimalista SVG inline -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚽</text></svg>">

  <!-- Estilos principales -->
  <link rel="stylesheet" href="frontend/css/style.css?v=<?= $version ?>">
</head>
<body>

<!-- ── Header + Nav pegados al tope (sticky juntos) ──── -->
<div class="sticky-top-bar">

<!-- ── Header ──────────────────────────────────────────── -->
<header class="site-header" role="banner">
  <div class="header-inner">

    <!-- Logo y título -->
    <div class="header-logo">
      <div class="logo-badge">
        <span>FIFA</span>
        <span>2026</span>
      </div>
      <div class="logo-title">
        <h1 data-i18n="app_title">Copa del Mundo FIFA 2026</h1>
        <p data-i18n="app_subtitle">USA · México · Canadá</p>
      </div>
    </div>

    <!-- Controles del header -->
    <div class="header-actions">

      <!-- Selector de idioma -->
      <button class="lang-btn active" data-lang="es" onclick="setLanguage('es')">🇲🇽 ES</button>
      <button class="lang-btn"        data-lang="en" onclick="setLanguage('en')">🇺🇸 EN</button>

      <!-- Selector de zona horaria -->
      <select id="tz-select" class="tz-select" title="Zona Horaria" aria-label="Zona Horaria"></select>

      <!-- Timer de auto-actualización -->
      <div class="timer-control">
        <select id="refresh-timer" class="refresh-timer-select" onchange="setRefreshTimer(+this.value)" aria-label="Auto-refresh" title="Auto-actualizar">
          <option value="0" data-i18n="timer_off">Apagado</option>
          <option value="30">30s</option>
          <option value="60">1 min</option>
          <option value="120">2 min</option>
          <option value="300">5 min</option>
        </select>
        <span id="timer-badge" class="timer-badge" style="display:none"></span>
      </div>

      <!-- Botón Actualizar -->
      <button id="btn-update" class="btn-update" onclick="triggerUpdate()" aria-label="Actualizar resultados">
        <svg class="icon-update" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/>
          <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
        </svg>
        <svg class="spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="12" cy="12" r="10"/>
          <path d="M12 6v6l4 2"/>
        </svg>
        <span data-i18n="btn_update">Actualizar Resultados</span>
      </button>

      <!-- Cómprame una cerveza -->
      <a href="https://www.paypal.com/donate/?hosted_button_id=ZABFRXC2P3JQN"
         target="_blank" rel="noopener noreferrer"
         class="btn-beer" title="Buy me a beer">
        ☕ <span data-i18n="buy_beer">Cómprame una Cerveza</span>
      </a>

    </div>
  </div>
</header>

<!-- ── Barra de navegación por pestañas ─────────────────── -->
<nav class="tab-nav" role="navigation">
  <div class="tab-nav-inner">
    <button id="btn-today"   class="tab-btn active" onclick="setTab('today')">
      ⚽ <span data-i18n="tab_today">Hoy</span>
      <span id="live-count" class="live-badge" style="display:none">0</span>
    </button>
    <button id="btn-matches" class="tab-btn" onclick="setTab('matches')">
      📅 <span data-i18n="tab_matches">Todos los Partidos</span>
    </button>
    <button id="btn-groups"  class="tab-btn" onclick="setTab('groups')">
      🏆 <span data-i18n="tab_groups">Grupos</span>
    </button>
    <button id="btn-leaders" class="tab-btn" onclick="setTab('leaders')">
      📊 <span data-i18n="tab_leaders">Líderes</span>
    </button>
  </div>
</nav>

</div><!-- /.sticky-top-bar -->

<!-- ── Contenido principal ──────────────────────────────── -->
<main class="main-content" role="main">

  <!-- Banner de modo demo / aviso API -->
  <div id="demo-banner" class="info-banner" style="display:none" role="alert">
    ⚠️ <span data-i18n="demo_mode">Modo Demo</span>
  </div>

  <!-- Indicador de última actualización -->
  <div class="last-updated-bar" id="last-updated">
    <!-- Rellenado por JS -->
  </div>

  <!-- Filtros de grupo (visibles en Partidos y Grupos) -->
  <div class="filter-bar" id="group-filters">
    <!-- Rellenado por buildGroupFilters() en JS -->
  </div>

  <!-- ── Pestaña: Hoy ───────────────────────────────────── -->
  <section id="tab-today" role="tabpanel">
    <div class="loading-overlay"><div class="spinner"></div></div>
  </section>

  <!-- ── Pestaña: Todos los Partidos ───────────────────── -->
  <section id="tab-matches" style="display:none" role="tabpanel">
    <div class="loading-overlay"><div class="spinner"></div></div>
  </section>

  <!-- ── Pestaña: Grupos ────────────────────────────────── -->
  <section id="tab-groups" style="display:none" role="tabpanel">
    <div class="loading-overlay"><div class="spinner"></div></div>
  </section>

  <!-- ── Pestaña: Líderes ───────────────────────────────── -->
  <section id="tab-leaders" style="display:none" role="tabpanel">
    <div class="loading-overlay"><div class="spinner"></div></div>
  </section>

</main>

<!-- ── Footer ───────────────────────────────────────────── -->
<footer class="site-footer" role="contentinfo">
  <p>
    Copa del Mundo FIFA 2026 — Calendario &amp; Resultados &nbsp;|&nbsp;
    <span data-i18n="ver">Ver</span> <strong><?= htmlspecialchars($version) ?></strong>
    &nbsp;|&nbsp;
    Datos: <a href="https://www.football-data.org" target="_blank" rel="noopener">football-data.org</a>
    &nbsp;|&nbsp;
    Banderas: <a href="https://flagcdn.com" target="_blank" rel="noopener">flagcdn.com</a>
  </p>
  <p style="margin-top:0.4rem">
    ¿Te fue útil?
    <a href="https://www.paypal.com/donate/?hosted_button_id=ZABFRXC2P3JQN"
       target="_blank" rel="noopener">☕ Cómprame una cerveza</a>
  </p>
</footer>

<!-- ── Toast de notificaciones ──────────────────────────── -->
<div id="toast" class="toast" role="status" aria-live="polite"></div>

<!-- ── Scripts ──────────────────────────────────────────── -->
<script src="frontend/js/app.js?v=<?= $version ?>"></script>

</body>
</html>
