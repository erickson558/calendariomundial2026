# SDD — Spec Driven Development
## FIFA World Cup 2026 Tracker

**Versión:** V1.0.0  
**Fecha:** 2026-06-11  
**Autor:** Synyster Rick  

---

## 1. Descripción del Proyecto

Aplicación web PHP que muestra el calendario completo, resultados en tiempo real y tabla de posiciones de la Copa del Mundo FIFA 2026 (USA · México · Canadá). Se sincroniza automáticamente con la API de football-data.org y soporta múltiples idiomas y zonas horarias.

---

## 2. Historia de Usuario (User Stories)

| ID  | Como…           | Quiero…                                           | Para…                                   | Criterio de aceptación                            |
|-----|-----------------|---------------------------------------------------|-----------------------------------------|---------------------------------------------------|
| US1 | Aficionado      | Ver los partidos del día con hora local           | Saber a qué hora ver el partido         | Hora correcta en la zona del usuario              |
| US2 | Aficionado      | Ver el marcador en vivo de partidos activos       | Seguir el torneo sin abrir otra app     | Marcador se actualiza al pulsar "Actualizar"      |
| US3 | Aficionado      | Ver las banderas de cada selección                | Identificar equipos rápidamente         | Banderas correctas para las 48 selecciones        |
| US4 | Aficionado      | Filtrar partidos por grupo                        | Ver solo los partidos del grupo que me importa | Filtros A–L funcionales                    |
| US5 | Aficionado      | Ver la tabla de posiciones de cada grupo          | Seguir la clasificación                 | Tabla ordenada por puntos/GD/GF                   |
| US6 | Aficionado      | Cambiar el idioma a inglés/español                | Usar el idioma de mi preferencia        | Toda la UI cambia de idioma sin recargar          |
| US7 | Aficionado      | Seleccionar mi zona horaria                       | Ver horas en mi tiempo local            | Persiste en localStorage entre visitas            |
| US8 | Desarrollador   | Configurar una API Key sin editar archivos del repo | Separar credenciales del código       | config.local.php excluido de git                  |
| US9 | Aficionado      | Ver una demo funcional sin API Key                | Evaluar la app antes de configurarla   | Modo Demo con 8 partidos de muestra               |
| US10| Aficionado      | Apoyar al desarrollador                           | Agradecer el trabajo                    | Botón "Cómprame una Cerveza" funcional            |

---

## 3. Arquitectura

```
calendariomundial2026/
├── index.php                  ← Shell HTML; inicializa DB en primera carga
├── backend/
│   ├── config.php             ← Constantes centrales (API key, paths, TTL)
│   ├── database.php           ← SQLite: schema + seed + CRUD
│   ├── fetcher.php            ← HTTP client cURL → football-data.org
│   └── data_service.php       ← Orquestación: fetch + persist + query
├── api/
│   ├── get-data.php           ← GET  → JSON payload para el frontend
│   └── update-results.php     ← POST → Dispara sincronización con API
├── frontend/
│   ├── css/style.css          ← Tema oscuro FIFA 2026
│   └── js/app.js              ← SPA: render, i18n, timezone, fetch
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
         ↓ fetch("api/get-data.php")
         ↓ DataService::buildPayload()
         ↓ Database::getMatches() / getStandings()
         ↓ SQLite (worldcup2026.db)
         ↓ JSON → renderCurrentTab()
         ↓ DOM actualizado

Usuario → "Actualizar Resultados"
         ↓ fetch("api/update-results.php", POST)
         ↓ DataService::refreshData()
         ↓ Fetcher::getMatches() / getStandings()
         ↓ football-data.org API (10 req/min)
         ↓ Database::upsertMatch() / upsertStanding()
         ↓ { success, updated, last_updated }
         ↓ loadData() → re-render
```

---

## 4. Modelo de Datos (SQLite)

### Tabla `teams`
| Campo         | Tipo    | Descripción                          |
|---------------|---------|--------------------------------------|
| id            | INTEGER | PK autoincrement                     |
| external_id   | INTEGER | ID en football-data.org              |
| name          | TEXT    | Nombre oficial                       |
| name_es       | TEXT    | Nombre en español                    |
| name_en       | TEXT    | Nombre en inglés                     |
| short_name    | TEXT    | Nombre corto                         |
| tla           | TEXT    | Abreviatura 3 letras (MEX, ARG…)     |
| iso_code      | TEXT    | ISO 3166-1 alpha-2 para flag CDN     |
| confederation | TEXT    | UEFA/CONMEBOL/CONCACAF/CAF/AFC/OFC   |
| group_name    | TEXT    | Grupo A–L                            |
| is_host       | INTEGER | 1 = sede (USA, MEX, CAN)             |

### Tabla `matches`
| Campo         | Tipo    | Descripción                          |
|---------------|---------|--------------------------------------|
| id            | INTEGER | PK autoincrement                     |
| external_id   | INTEGER | UNIQUE — ID en football-data.org     |
| home_team_id  | INTEGER | FK → teams.id                        |
| away_team_id  | INTEGER | FK → teams.id                        |
| home_score    | INTEGER | NULL si no jugado                    |
| away_score    | INTEGER | NULL si no jugado                    |
| home_score_ht | INTEGER | Marcador al descanso                 |
| away_score_ht | INTEGER | Marcador al descanso                 |
| match_date    | TEXT    | ISO 8601 UTC (el frontend convierte) |
| status        | TEXT    | SCHEDULED/IN_PLAY/PAUSED/FINISHED    |
| stage         | TEXT    | GROUP_STAGE / ROUND_OF_32 etc.       |
| group_name    | TEXT    | Grupo (null en knockout)             |
| matchday      | INTEGER | Jornada                              |
| venue         | TEXT    | Estadio                              |
| city          | TEXT    | Ciudad sede                          |

### Tabla `standings`
| Campo          | Tipo    | Descripción        |
|----------------|---------|--------------------|
| team_id        | INTEGER | FK → teams.id      |
| group_name     | TEXT    | Grupo A–L          |
| position       | INTEGER | Posición en tabla  |
| played/won/… | INTEGER | Stats estándar     |
| points         | INTEGER | Puntos acumulados  |

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
    "version":      "V1.0.0",
    "demo_mode":    false,
    "api_key_set":  true,
    "last_updated": "2026-06-11T19:05:00+00:00",
    "has_live":     true
  },
  "today":    [ <match>, … ],
  "matches":  { "2026-06-11": [ <match>, … ], … },
  "standings":{ "A": [ <standing>, … ], … },
  "teams":    [ <team>, … ]
}
```

**Objeto `<match>`:**
```json
{
  "id": 1, "external_id": 123456,
  "match_date": "2026-06-11T19:00:00Z",
  "status": "FINISHED",
  "stage": "GROUP_STAGE", "group_name": "A", "matchday": 1,
  "home_score": 2, "away_score": 0,
  "home_score_ht": 1, "away_score_ht": 0,
  "home_name": "Mexico", "home_name_es": "México",
  "home_tla": "MEX", "home_iso": "mx",
  "away_name": "Ecuador", "away_name_es": "Ecuador",
  "away_tla": "ECU", "away_iso": "ec",
  "venue": "AT&T Stadium", "city": "Arlington"
}
```

### POST `/api/update-results.php`
```json
{ "success": true, "message": "Se actualizaron 64 partidos.", "updated": 64,
  "last_updated": "2026-06-11T19:05:00+00:00", "has_live": true }
```

---

## 6. Fuentes de Datos

| Fuente          | URL                            | Plan    | Límite       |
|-----------------|--------------------------------|---------|--------------|
| football-data.org | api.football-data.org/v4     | Gratuito | 10 req/min  |
| Flag CDN        | flagcdn.com/w40/{iso}.png      | Gratuito | Sin límite  |

### Obtener API Key (football-data.org):
1. Ir a https://www.football-data.org/client/register
2. Registrarse (cuenta gratuita)
3. Copiar el API Token
4. Crear `backend/config.local.php`:
   ```php
   <?php define('FOOTBALL_API_KEY', 'tu_token_aqui');
   ```

---

## 7. Especificación de UI

### Paleta de colores
| Variable       | Valor        | Uso                        |
|----------------|--------------|----------------------------|
| `--fifa-red`   | `#CC0000`    | Partidos EN VIVO, acciones |
| `--fifa-gold`  | `#FFB900`    | Acentos, tabs activos      |
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

| Requisito       | Valor objetivo                              |
|-----------------|---------------------------------------------|
| Tiempo de carga | < 1s sin API, < 3s con API                  |
| Compatibilidad  | PHP 7.4+, Chrome/Firefox/Safari modernos    |
| Seguridad       | No exponer API Key en frontend              |
| Accesibilidad   | Roles ARIA, alt en imágenes                 |
| Offline         | Sirve datos cacheados de SQLite             |

---

## 9. Registro de Decisiones Técnicas (ADR)

| # | Decisión                        | Razón                                              |
|---|---------------------------------|----------------------------------------------------|
| 1 | SQLite en lugar de MySQL        | EasyPHP local, sin servidor de DB externo          |
| 2 | Zona horaria en el frontend     | La misma DB sirve a usuarios en distintos países   |
| 3 | Modo Demo sin API Key           | Evaluar UI sin configuración                       |
| 4 | flagcdn.com para banderas       | CDN gratuita, URLs predictibles por código ISO     |
| 5 | football-data.org como fuente   | Capa gratuita, cubre World Cup, API documentada    |
| 6 | SPA con fetch() + PHP REST      | Sin framework, máxima compatibilidad con EasyPHP   |
| 7 | Horas en UTC en DB              | Conversión única en el cliente con Intl API        |

---

## 10. Roadmap

- [x] V1.0.0 — MVP: calendario, resultados, posiciones, idiomas, timezone
- [ ] V1.1.0 — Notificaciones push cuando empieza un partido
- [ ] V1.2.0 — Vista de knockout (rondas eliminatorias con bracket)
- [ ] V1.3.0 — Estadísticas de jugadores (goleadores, asistencias)
- [ ] V2.0.0 — PWA con modo offline completo
