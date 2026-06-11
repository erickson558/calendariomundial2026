# Changelog — FIFA World Cup 2026 Tracker

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).
Versionado según [Semantic Versioning](https://semver.org/lang/es/) — `Vx.x.x`.

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
