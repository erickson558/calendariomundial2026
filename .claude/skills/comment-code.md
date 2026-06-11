---
name: comment-code
description: Agrega o mejora comentarios en los archivos PHP y JavaScript del proyecto FIFA World Cup 2026 Tracker. Explica el PORQUÉ de cada función, clase y bloque lógico no obvio. No documenta lo evidente.
---

# Skill: Comentar Código

Reviso y mejoro los comentarios en el código PHP y JavaScript del tracker.

## Filosofía de comentarios

**Comentar el PORQUÉ, no el QUÉ.**

```php
// MAL: Obtiene los partidos (el nombre ya lo dice)
function getMatches() { ... }

// BIEN: Agrupa por fecha LOCAL del usuario, no por UTC, para que
// un partido a las 23:00 ET aparezca el día correcto en el calendario.
function groupMatchesByLocalDate() { ... }
```

## Qué comento

### PHP — Archivos a revisar:
- `backend/database.php`: explicar por qué WAL mode, por qué UPSERT, por qué el seed de equipos tiene ciertos campos
- `backend/fetcher.php`: explicar la lógica de reintentos, los códigos de error de la API
- `backend/data_service.php`: explicar la lógica de caché dual (TTL_LIVE vs TTL_NORMAL)
- `api/*.php`: explicar las validaciones de parámetros y por qué los headers

### JavaScript — Archivos a revisar:
- `frontend/js/app.js`: explicar la lógica de agrupamiento por fecha local, la lógica de auto-refresh, el sistema de i18n
- Funciones de renderizado: explicar el algoritmo de clases CSS condicionales

## Formato de comentarios

### PHP (PHPDoc para clases y métodos públicos):
```php
/**
 * Sincroniza partidos desde football-data.org.
 *
 * Respeta el rate limit usando un caché TTL dual:
 *  - 60s cuando hay partidos EN VIVO (IN_PLAY/PAUSED)
 *  - 300s en estado normal
 *
 * Si la API falla, los datos en SQLite se mantienen inalterados
 * para que el frontend pueda seguir sirviendo el último estado conocido.
 *
 * @return array { success, message, updated }
 */
public static function refreshData(): array { ... }
```

### JavaScript (comentarios JSDoc para funciones públicas):
```javascript
/**
 * Convierte la fecha UTC del partido a la fecha local del usuario.
 * Se necesita porque un partido a las 23:00 UTC del día 1
 * puede ser a las 19:00 ET del mismo día en NY pero 00:00 del día 2 en Madrid.
 *
 * @param {string} utcStr   ISO 8601 UTC — ej: "2026-06-11T23:00:00Z"
 * @returns {string}        Fecha local "YYYY-MM-DD" según State.tz
 */
function localDateKey(utcStr) { ... }
```

### CSS (comentarios de sección):
```css
/* ── Tarjeta de partido — layout de 3 columnas: local | marcador | visitante ─ */
.match-card { ... }
```

## Qué NO comento

- Lo que ya es obvio por el nombre de la función/variable
- Código que se explica solo (`return $db->query(...)->fetchAll()`)
- Código generado automáticamente o de terceros

## Pasos cuando ejecuto este skill

1. Leo cada archivo PHP y JS del proyecto
2. Identifico funciones/clases sin comentario o con comentarios incompletos
3. Agrego comentarios que explican el PORQUÉ o una restricción no obvia
4. No modifico la lógica, solo los comentarios
5. Verifico que no hay comentarios redundantes que explican lo obvio y los elimino
