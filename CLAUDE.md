# CLAUDE.md — FIFA World Cup 2026 Tracker

Instrucciones para Claude Code al trabajar en este proyecto.

## Descripción del Proyecto

Aplicación web PHP (EasyPHP) que muestra el calendario, resultados y tabla de posiciones de la Copa del Mundo FIFA 2026. PHP backend con SQLite, frontend SPA en JavaScript puro.

## Cómo Ejecutar

```
http://localhost/monitoreos/calendariomundial2026/
```
Requiere EasyPHP corriendo con PHP 7.4+ y las extensiones `pdo_sqlite` y `curl` activas.

## Stack Tecnológico

- **Backend:** PHP 7.4+, SQLite (PDO), cURL
- **Frontend:** Vanilla JavaScript (ES2020), CSS Custom Properties
- **API:** football-data.org v4 (gratuita, 10 req/min)
- **i18n:** JSON files cargados dinámicamente
- **DB:** SQLite en `data/worldcup2026.db`

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `backend/config.php` | Constantes: API key, paths, TTL de caché |
| `backend/database.php` | Schema SQLite, seed de 48 equipos, CRUD |
| `backend/fetcher.php` | Cliente HTTP para football-data.org |
| `backend/data_service.php` | Orquestación fetch + persist + build payload |
| `api/get-data.php` | Endpoint JSON consumido por el frontend |
| `api/update-results.php` | Trigger de sincronización (botón Actualizar) |
| `frontend/js/app.js` | SPA: render, i18n, timezone, update |
| `frontend/css/style.css` | Tema oscuro FIFA 2026 |
| `i18n/es.json` / `en.json` | Traducciones |

## Convenciones de Código

- PHP: camelCase para métodos, snake_case para variables locales
- SQL: MAYÚSCULAS para palabras clave, snake_case para nombres de columna
- JS: camelCase en todo, `const` por defecto, `let` solo si muta
- CSS: `kebab-case`, variables CSS con prefijo `--`
- Cada función PHP y JS debe tener un comentario breve que explique el PORQUÉ

## Workflow de Desarrollo

1. Editar archivos PHP/JS/CSS
2. Probar en `http://localhost/monitoreos/calendariomundial2026/`
3. Verificar sin API Key (modo demo) y con API Key configurada
4. Bump de versión en `VERSION` si es un cambio publicable
5. Actualizar `CHANGELOG.md` con el resumen del cambio
6. `git add`, `git commit -m "tipo: descripción"`, `git push`
7. El tag `Vx.x.x` dispara el release automático en GitHub Actions

## Convención de Commits

```
feat:     Nueva funcionalidad
fix:      Corrección de bug
chore:    Tareas de mantenimiento (version bump, docs)
style:    Cambios de UI/CSS sin lógica
refactor: Refactoring sin cambio funcional
docs:     Solo documentación
```

## Seguridad

- NUNCA exponer `FOOTBALL_API_KEY` en el frontend (solo en PHP)
- La API Key va en `backend/config.local.php` (en `.gitignore`)
- Sanitizar inputs con `htmlspecialchars()` antes de mostrar en HTML
- Los endpoints API solo aceptan parámetros validados (regex en grupo A–L)

## Notas sobre la API de football-data.org

- Rate limit gratuito: **10 requests/minuto**
- Cache TTL: 300s normal, 60s cuando hay partidos en vivo
- Competencia World Cup: código `WC`
- Endpoints: `/competitions/WC/matches`, `/standings`, `/teams`
- El campo de fecha viene en UTC (`utcDate`): el frontend convierte con `Intl.DateTimeFormat`
