# Changelog — FIFA World Cup 2026 Tracker

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).
Versionado según [Semantic Versioning](https://semver.org/lang/es/) — `Vx.x.x`.

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
