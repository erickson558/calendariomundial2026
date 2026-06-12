# SDD — Spec Driven Development
## FIFA World Cup 2026 Tracker

**Versión:** V1.5.0  
**Fecha:** 2026-06-12  
**Autor:** Synyster Rick  

---

## 1. Descripción del Proyecto

Aplicación web PHP que muestra el calendario completo, resultados en tiempo real y tabla de posiciones de la Copa del Mundo FIFA 2026 (USA · México · Canadá). Se sincroniza con la API pública de ESPN (sin API key) usando PowerShell como proxy TLS 1.2 para compatibilidad con EasyPHP 14 (PHP 5.4 + OpenSSL 0.9.8z). Soporta múltiples idiomas, zonas horarias y timer de auto-actualización configurable.

---

## 2. Historia de Usuario (User Stories)

| ID   | Como…           | Quiero…                                              | Para…                                     | Criterio de aceptación                                   |
|------|-----------------|------------------------------------------------------|-------------------------------------------|----------------------------------------------------------|
| US1  | Aficionado      | Ver los partidos del día con hora local              | Saber a qué hora ver el partido           | Hora correcta en la zona del usuario                     |
| US2  | Aficionado      | Ver el marcador en vivo de partidos activos          | Seguir el torneo sin abrir otra app       | Marcador se actualiza al pulsar "Actualizar"             |
| US3  | Aficionado      | Ver las banderas de cada selección                   | Identificar equipos rápidamente           | Banderas correctas para las 48 selecciones               |
| US4  | Aficionado      | Filtrar partidos y posiciones por grupo              | Ver solo el grupo que me importa          | Filtros A–L filtran tanto partidos como tabla de posiciones |
| US5  | Aficionado      | Ver la tabla de posiciones de cada grupo             | Seguir la clasificación                   | Tabla ordenada por puntos/GD/GF                          |
| US6  | Aficionado      | Cambiar el idioma a inglés/español                   | Usar el idioma de mi preferencia          | Toda la UI cambia de idioma sin recargar                 |
| US7  | Aficionado      | Seleccionar mi zona horaria                          | Ver horas en mi tiempo local              | Persiste en localStorage entre visitas                   |
| US8  | Aficionado      | Ver la fecha en cada tarjeta de partido              | Saber exactamente cuándo es cada partido  | Cada tarjeta muestra día + mes en zona local del usuario |
| US9  | Aficionado      | Configurar un timer de auto-actualización            | Recibir datos frescos sin hacer clic      | Selector Off/30s/1min/2min/5min con cuenta regresiva     |
| US10 | Aficionado      | Ver una demo funcional sin conexión                  | Evaluar la app antes de configurarla      | Modo Demo activado automáticamente si ESPN falla         |
| US11 | Aficionado      | Apoyar al desarrollador                              | Agradecer el trabajo                      | Botón "Cómprame una Cerveza" funcional                   |

---

## 3. Arquitectura

```
calendariomundial2026/
├── index.php                  ← Shell HTML; inicializa DB en primera carga
├── backend/
│   ├── config.php             ← Constantes centrales (paths, TTL de caché)
│   ├── database.php           ← SQLite: schema + seed 48 equipos + 72 partidos + CRUD
│   ├── fetcher.php            ← HTTP client PowerShell (TLS 1.2) → ESPN API
│   └── data_service.php       ← Orquestación: fetch + persist + payload builder
├── api/
│   ├── get-data.php           ← GET  → JSON payload para el frontend
│   └── update-results.php     ← POST → Dispara sincronización con ESPN
├── frontend/
│   ├── css/style.css          ← Tema oscuro FIFA 2026
│   └── js/app.js              ← SPA: render, i18n, timezone, timer, fetch
├── i18n/
│   ├── es.json                ← Traducciones español
│   └── en.json                ← Traducciones inglés
├── data/
│   └── worldcup2026.db        ← SQLite (excluido de git)
├── .claude/
│   ├── agents/                ← Agentes Claude Code
│   └── skills/                ← Skills Claude Code
└── .github/workflows/
    └── release.yml            ← GitHub Actions: tag + release
```

### Flujo de datos

```
Usuario → index.php
         ↓ js/app.js (init)
         ↓ fetch("api/get-data.php[?group=X]")
         ↓ DataService::buildPayload()
         ↓ Database::getMatches() / getStandings()
         ↓ SQLite (worldcup2026.db) — 72 partidos sembrados
         ↓ JSON → renderCurrentTab()
         ↓ DOM actualizado

Usuario → "Actualizar" / Timer de auto-actualización
         ↓ fetch("api/update-results.php", POST)
         ↓ DataService::refreshData() [respeta TTL: 300s normal / 60s live]
         ↓ Fetcher::getAllMatches() — queries dia a dia (ESPN range queries rotas)
         ↓ Fetcher::getStandings() — ESPN standings?season=2026
         ↓ Database::upsertMatch() — 3 pasos: external_id / equipo+fecha / nuevo
         ↓ { success, updated, last_updated }
         ↓ loadData() → re-render
```

### Decisión técnica: PowerShell como proxy TLS

EasyPHP 14 usa PHP 5.4 + cURL 7.36 + OpenSSL 0.9.8z (2014). OpenSSL 0.9.8z no soporta TLS 1.2, que ESPN exige. Solución: `shell_exec('powershell -NonInteractive Invoke-WebRequest ...')` usa .NET Framework de Windows con soporte TLS 1.2 nativo. Latencia ~300ms por request, aceptable.

---

## 4. Modelo de Datos (SQLite)

### Tabla `teams`
| Campo         | Tipo    | Descripción                          |
|---------------|---------|--------------------------------------|
| id            | INTEGER | PK autoincrement                     |
| external_id   | INTEGER | ID ESPN (asignado al recibir datos)  |
| name          | TEXT    | Nombre oficial ESPN                  |
| name_es       | TEXT    | Nombre en español                    |
| name_en       | TEXT    | Nombre en inglés                     |
| short_name    | TEXT    | Nombre corto                         |
| tla           | TEXT    | Abreviatura 3 letras (MEX, ARG…)     |
| iso_code      | TEXT    | ISO 3166-1 alpha-2 para flagcdn.com  |
| confederation | TEXT    | UEFA/CONMEBOL/CONCACAF/CAF/AFC/OFC   |
| group_name    | TEXT    | Grupo A–L                            |
| is_host       | INTEGER | 1 = sede (USA, MEX, CAN)             |

### Tabla `matches`
| Campo         | Tipo    | Descripción                                      |
|---------------|---------|--------------------------------------------------|
| id            | INTEGER | PK autoincrement                                 |
| external_id   | INTEGER | UNIQUE — ID ESPN (NULL en partidos sembrados)    |
| home_team_id  | INTEGER | FK → teams.id                                    |
| away_team_id  | INTEGER | FK → teams.id                                    |
| home_score    | INTEGER | NULL si no jugado                                |
| away_score    | INTEGER | NULL si no jugado                                |
| home_score_ht | INTEGER | Marcador al descanso                             |
| away_score_ht | INTEGER | Marcador al descanso                             |
| match_date    | TEXT    | ISO 8601 UTC (el frontend convierte a local)     |
| status        | TEXT    | SCHEDULED/IN_PLAY/PAUSED/FINISHED                |
| stage         | TEXT    | GROUP_STAGE / ROUND_OF_32 etc.                   |
| group_name    | TEXT    | Grupo A–L (NULL en knockout)                     |
| matchday      | INTEGER | Jornada (1-3 en fase de grupos)                  |
| venue         | TEXT    | Estadio                                          |
| city          | TEXT    | Ciudad sede                                      |

**Seed**: 72 partidos de fase de grupos (jornadas 1-3) con fechas UTC reales, venues y grupos verificados. ESPN actualiza marcadores/estado vía `upsertMatch()` sin duplicar.

### Tabla `standings`
| Campo          | Tipo    | Descripción              |
|----------------|---------|--------------------------|
| team_id        | INTEGER | FK → teams.id            |
| group_name     | TEXT    | Grupo A–L                |
| position       | INTEGER | Posición en tabla        |
| played/won/…  | INTEGER | Stats estándar           |
| points         | INTEGER | Puntos acumulados        |

**Seed**: 48 filas (4 por grupo × 12 grupos), todas en 0. ESPN actualiza si devuelve standings.

### Tabla `settings`
| Campo      | Tipo | Descripción                     |
|------------|------|---------------------------------|
| key        | TEXT | PK ("last_updated", "is_demo"…) |
| value      | TEXT | Valor                           |
| updated_at | TEXT | Timestamp de última escritura   |

---

## 5. Contrato de API (JSON)

### GET `/api/get-data.php[?group=A]`

```json
{
  "status": {
    "version":      "V1.5.0",
    "demo_mode":    false,
    "last_updated": "2026-06-12T03:52:01+00:00"
  },
  "today":    [ "<match>", "…" ],
  "all":      { "2026-06-11": [ "<match>", "…" ], "…": [] },
  "standings":{ "A": [ "<standing>", "…" ], "…": [] },
  "has_live": false
}
```

**Nota**: el campo es `"all"` (no `"matches"`). El frontend re-agrupa por fecha LOCAL del usuario.

**Objeto `<match>`:**
```json
{
  "id": 1, "external_id": 760415,
  "match_date": "2026-06-11T19:00:00Z",
  "status": "FINISHED",
  "stage": "GROUP_STAGE", "group_name": "A", "matchday": 1,
  "home_score": 2, "away_score": 0,
  "home_name": "Mexico", "home_name_es": "México",
  "home_tla": "MEX", "home_iso": "mx", "home_is_host": 1,
  "away_name": "South Africa", "away_name_es": "Sudáfrica",
  "away_tla": "RSA", "away_iso": "za", "away_is_host": 0,
  "venue": "Estadio Azteca", "city": "Mexico City"
}
```

### POST `/api/update-results.php`
```json
{ "success": true, "message": "Se actualizaron 3 partidos.", "updated": 3,
  "last_updated": "2026-06-12T03:52:01+00:00", "has_live": false }
```

---

## 6. Fuente de Datos — ESPN API (sin autenticación)

| Endpoint | URL | Descripción |
|----------|-----|-------------|
| Scoreboard (un día) | `site.api.espn.com/…/scoreboard?dates=YYYYMMDD` | Partidos de un día |
| Standings | `site.api.espn.com/…/standings?season=2026` | Tabla de posiciones |

**Ruta ESPN**: `https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/`

**Aliases de nombres ESPN** (diferencias que generarían duplicados sin alias):
- `Bosnia-Herzegovina` → `Bosnia and Herzegovina`
- `Curaçao` (UTF-8) → `Curacao`
- `Türkiye` / `Turkiye` → `Turkey`
- `IR Iran` → `Iran`
- `Korea Republic` → `South Korea`
- `Côte d'Ivoire` → `Ivory Coast`
- `USA` → `United States`

**Query de rango `?dates=X-Y` está rota en ESPN**: devuelve partidos de eliminatoria en vez del rango solicitado. Se usa `?dates=YYYYMMDD` (un día) iterando dia a dia en ventana de 7 días.

---

## 7. Especificación de UI

### Pestañas
| Pestaña     | Contenido                                                    |
|-------------|--------------------------------------------------------------|
| Hoy         | Partidos de hoy + partidos en vivo (sin importar fecha UTC) |
| Todos los Partidos | Todos los partidos agrupados por fecha LOCAL del usuario |
| Grupos      | 12 tablas de posiciones A–L (filtrable por grupo)            |

### Filtro de grupo
- Visible en todas las pestañas
- Al seleccionar un grupo específico: filtra partidos Y muestra solo esa tabla de posiciones
- Al seleccionar "Todos los Grupos": muestra todos los partidos y los 12 grupos

### Timer de auto-actualización
- Selector: Off / 30s / 1min / 2min / 5min
- Badge dorado con cuenta regresiva visible mientras está activo
- Persiste en `localStorage['timerSeconds']`
- Se reinicia tras cada actualización exitosa

### Paleta de colores
| Variable       | Valor        | Uso                        |
|----------------|--------------|----------------------------|
| `--fifa-red`   | `#CC0000`    | Partidos EN VIVO, acciones |
| `--fifa-gold`  | `#FFB900`    | Acentos, tabs activos, badge timer |
| `--fifa-dark`  | `#0D0D1A`    | Fondo principal            |
| `--fifa-blue`  | `#002868`    | Header de grupos           |
| `--text-1`     | `#FFFFFF`    | Texto principal            |
| `--text-2`     | `#AAAACC`    | Texto secundario           |

### Puntos de quiebre responsive
| Breakpoint | Cambio                                     |
|------------|--------------------------------------------|
| ≤ 640px    | Nombres cortos, grid de grupos 1 columna   |
| ≤ 400px    | Timezone select más pequeño                |

---

## 8. Requisitos No-Funcionales

| Requisito       | Valor objetivo                                      |
|-----------------|-----------------------------------------------------|
| Tiempo de carga | < 1s sin API, < 3s con ESPN                         |
| Compatibilidad  | PHP 5.4.31+ (EasyPHP), Chrome/Firefox/Safari modernos |
| Seguridad       | No exponer credenciales en frontend                 |
| Accesibilidad   | Roles ARIA, alt en imágenes                         |
| Offline         | Sirve datos cacheados de SQLite (72 partidos seed)  |

---

## 9. Registro de Decisiones Técnicas (ADR)

| # | Decisión                             | Razón                                                               |
|---|--------------------------------------|---------------------------------------------------------------------|
| 1 | SQLite en lugar de MySQL             | EasyPHP local, sin servidor de DB externo                           |
| 2 | Zona horaria en el frontend          | La misma DB sirve a usuarios en distintos países                    |
| 3 | Modo Demo automático                 | Si ESPN falla, la app sigue funcional con datos del seed            |
| 4 | flagcdn.com para banderas            | CDN gratuita, URLs predictibles por código ISO                      |
| 5 | ESPN como fuente (sin API Key)       | API pública sin registro, cubre World Cup en tiempo real            |
| 6 | SPA con fetch() + PHP REST           | Sin framework, máxima compatibilidad con EasyPHP                    |
| 7 | Horas en UTC en DB                   | Conversión única en el cliente con Intl API                         |
| 8 | PowerShell como proxy TLS            | OpenSSL 0.9.8z de EasyPHP 14 no soporta TLS 1.2 que ESPN requiere  |
| 9 | Seed de 72 partidos en DB            | Calendario visible antes de recibir datos ESPN; ESPN actualiza in-place |
| 10| upsertMatch en 3 pasos               | Evita duplicar partidos seed al recibir datos ESPN con external_id  |

---

## 10. Roadmap

- [x] V1.0.0 — MVP: calendario, resultados, posiciones, idiomas, timezone
- [x] V1.1.0 — Auto-refresh cuando hay partidos en vivo (60s)
- [x] V1.2.0 — Próximo partido resaltado, timezone por país con banderas
- [x] V1.3.0 — Filtros de grupo A–L, "próximo partido" destacado
- [x] V1.4.0 — ESPN real-time, 72 partidos seed, 48 equipos correctos, fechas en tarjetas
- [x] V1.5.0 — Timer configurable (Off/30s/1m/2m/5m), filtro de grupos en pestaña Grupos
- [ ] V1.6.0 — Vista de bracket de knockout (rondas eliminatorias)
- [ ] V1.7.0 — Estadísticas de jugadores (goleadores, asistencias)
- [ ] V2.0.0 — PWA con modo offline completo
