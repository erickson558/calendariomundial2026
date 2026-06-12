# Changelog — FIFA World Cup 2026 Tracker

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).
Versionado según [Semantic Versioning](https://semver.org/lang/es/) — `Vx.x.x`.

---

## [V1.6.0] — 2026-06-12

### Añadido
- **Pestaña Líderes (📊)**: tabla global de los 48 equipos ordenada por puntos > DG > GF. Indica con badge dorado los equipos en zona de clasificación directa (top 2 de grupo) y con badge azul los potenciales mejores terceros (top 8 entre todos los 3ros, formato WC 2026). El filtro de grupos se oculta automáticamente en esta pestaña
- **Hora estimada de fin en partidos programados**: las tarjetas de partidos `SCHEDULED` ahora muestran la hora de inicio y una hora aproximada de finalización (`~HH:MM`) calculada como inicio + 115 min (90 min + 15 entretiempo + ~10 adicionados)

### Corregido
- **Partidos atascados en "EN VIVO"**: `clearStaleLiveMatches()` marca automáticamente como `FINISHED` cualquier partido que lleve más de 2 horas desde su horario programado con estado `IN_PLAY` o `PAUSED`. Se ejecuta tanto al refrescar datos de ESPN como en cada entrega del payload (incluso en cache hit), eliminando el caso donde ESPN dejaba de devolver el partido antes de cerrarlo

---

## [V1.5.0] — 2026-06-12

### Añadido
- **Timer de auto-actualización configurable**: nuevo selector en el header con opciones Off / 30s / 1min / 2min / 5min. Muestra cuenta regresiva en badge dorado y persiste la selección en localStorage. Se reinicia después de cada actualización exitosa

### Corregido
- **Grupos muestran la tabla filtrada**: al seleccionar un grupo en el filtro, la pestaña Grupos ahora muestra solo las posiciones de ese grupo. Al seleccionar "Todos los Grupos" se vuelve a mostrar la cuadrícula completa de 12 grupos

---

## [V1.4.0] — 2026-06-11

### Añadido
- **Datos en tiempo real**: reemplazado cURL (fallaba con OpenSSL 0.9.8z vs TLS 1.2) por PowerShell `Invoke-WebRequest` que usa .NET con soporte TLS 1.2 nativo en Windows
- **72 partidos reales sembrados**: calendario completo de fase de grupos (jornadas 1-3) con fechas UTC verificadas, venues y ciudades oficiales. La app muestra el calendario completo desde el primer clic
- **12 grupos correctos (A-L)**: 48 equipos clasificados con grupos verificados en ESPN, FIFA y NBC Sports. Eliminados equipos que no clasificaron (Italia, Dinamarca, Jamaica, etc.)
- **Fecha en tarjetas de partido**: cada tarjeta ahora muestra día + mes (ej. "Jue 11 Jun") en la zona horaria del usuario, además de la hora
- **Standings iniciales**: los 12 grupos se muestran con todos los equipos desde el arranque (todos en 0 pts), ESPN actualiza los puntajes reales cuando hay partidos jugados

### Corregido
- **Modo demo eliminado en inicio**: la app arranca en modo real desde el primer clic. Si ESPN falla, se activa modo demo automáticamente como fallback
- **Primera carga siempre consulta ESPN**: `last_updated` vacío dispara fetch a ESPN sin importar modo demo
- **Partidos sembrados vs ESPN sin duplicados**: al recibir datos de ESPN se eliminan los partidos del seed (sin `external_id`) antes de insertar los reales
- **Ciudad en partidos de ESPN**: `city` ya no se hardcodeaba como `null` en `upsertMatch()`
- **Grupo derivado del equipo local**: si ESPN no provee el grupo en las notas del partido, se deriva del `group_name` del equipo sembrado

---

## [V1.3.0] — 2026-06-11

### Añadido
- **Próximo partido**: el primer partido pendiente se resalta con borde dorado y etiqueta "▶ PRÓXIMO PARTIDO" en todas las pestañas
- **Selector de zona horaria por país**: dropdown reorganizado con banderas de país (emojis), agrupado por región. Guatemala, El Salvador, Honduras, Nicaragua, Costa Rica y Panamá ahora tienen entradas propias con su UTC offset
- **Orden cronológico**: partidos ordenados estrictamente por hora dentro de cada bloque de fecha

### Corregido
- **Bug crítico de tab "Todos"**: `State.data.matches` → `State.data.all` (la tab nunca mostraba partidos)
- **Bug de auto-refresh**: `State.data.status.has_live` → `State.data.has_live` (el refresh automático en partidos en vivo no funcionaba)
- **Modo demo ahora auto-migra a datos reales**: al cargar la página en demo, el JS dispara automáticamente una actualización ESPN en segundo plano sin que el usuario pulse nada
- **Tildes en nombres en español**: España, México, Panamá, Bélgica, Países Bajos, Turquía, Hungría, Túnez, Sudáfrica, Camerún, Japón, Irán, Uzbekistán — corregido en el seed y con migración automática para DBs existentes
- **TTL de caché en demo mode**: en modo demo siempre se intenta ESPN (sin respetar los 5 min de caché) para garantizar la migración a datos reales tan pronto como el torneo esté disponible
- **Limpieza de datos demo**: al recibir datos reales de ESPN, los partidos de muestra (sin external_id) se eliminan para no mezclarlos con datos reales

---

## [V1.2.0] — 2026-06-11

### Corregido
- **Compatibilidad PHP 5.4**: reescritura completa de todo el backend para eliminar sintaxis que requería PHP 7.0+ / 8.0+
  - `private const` → `const` en clases (PHP 7.1+ no disponible en EasyPHP 14)
  - Typed properties `?PDO` → variables sin tipo (PHP 7.4+)
  - Type hints escalares y return types → eliminados (PHP 7.0+)
  - Operador `??` → reemplazado con `isset()` ternarios (PHP 7.0+)
  - Arrow functions `fn() =>` → funciones anonimas con `use` (PHP 7.4+)
  - `str_contains()` → `strpos() !== false` (PHP 8.0+)
  - Separadores numéricos `250_000` → `250000` (PHP 7.4+)
  - `Throwable` → `Exception` en todos los catch (PHP 7.0+)
  - SQL UPSERT `ON CONFLICT DO UPDATE` → SELECT + UPDATE/INSERT (SQLite 3.24+)
- **Comentario de cron** en `update-results.php`: el literal `*/` cerraba el docblock provocando parse error; cambiado a descripción textual
- **refreshData()** ahora devuelve bool en lugar de array; `update-results.php` corregido en consecuencia

---

## [V1.1.0] — 2026-06-11

### Cambiado
- **Fuente de datos reemplazada**: de football-data.org (requería registro) a la **API pública de ESPN** sin ningún registro ni API Key
- El modo demo ahora se activa automáticamente solo cuando no hay internet, en lugar de cuando falta una API Key
- Mensajes de UI actualizados para reflejar que no se necesita configuración

### Corregido
- El seed de partidos demo ya no dependía del flag `DEMO_MODE` que ahora es siempre `false`
- La lógica de standings ahora procesa el array plano que devuelve ESPN en lugar del formato agrupado de football-data.org

---

## [V1.0.0] — 2026-06-11

### Añadido
- Calendario completo de los 64 partidos de la Copa del Mundo FIFA 2026
- Resultados en tiempo real via football-data.org API (capa gratuita)
- Tabla de posiciones por grupo (Grupos A–L, 48 equipos)
- Banderas de las 48 selecciones via flagcdn.com
- Horarios en zona horaria personalizable (más de 30 zonas disponibles)
- Selector de idioma: Español / Inglés
- Modo Demo con datos de muestra (sin necesidad de API Key)
- Auto-refresh automático cuando hay partidos EN VIVO (cada 60 segundos)
- Filtros por grupo (A–L)
- Vista "Hoy" con partidos del día actual
- Botón "Cómprame una Cerveza" con link a PayPal
- Tema oscuro con paleta FIFA 2026 (dorado / rojo / azul oscuro)
- Diseño responsive para móvil y escritorio
- SDD (Spec Driven Development) documentación completa
- Agentes y Skills de Claude Code configurados
- GitHub Actions CI/CD con release automático
