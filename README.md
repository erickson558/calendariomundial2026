# ⚽ FIFA World Cup 2026 Tracker

> Calendario completo, resultados en tiempo real y tabla de posiciones de la **Copa del Mundo FIFA 2026** (USA · México · Canadá).

[![Version](https://img.shields.io/badge/version-V1.0.0-gold)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-Apache%202.0-green)](LICENSE)

---

## Funcionalidades

- ⚽ **Calendario completo** — 64 partidos de fase de grupos y rondas eliminatorias
- 📊 **Tabla de posiciones** — todos los grupos A–L con banderas
- 🏳️ **Banderas** — 48 selecciones con banderas via flagcdn.com
- 🕐 **Zona horaria personalizable** — +30 zonas para ver horarios locales
- 🌐 **Multi-idioma** — Español / Inglés
- 🔴 **Resultados en vivo** — sincroniza con football-data.org API
- 🔄 **Auto-refresh** — se actualiza automáticamente durante partidos en vivo
- 🌙 **Tema oscuro** — paleta FIFA 2026

---

## Requisitos

- **PHP** 7.4 o superior con extensión `pdo_sqlite` y `curl` habilitadas
- **EasyPHP** (o cualquier servidor web PHP como XAMPP, WAMP, Apache)
- **API Key gratuita** de [football-data.org](https://www.football-data.org/client/register) (opcional, activar para datos reales)

---

## Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/erickson558/calendariomundial2026.git
```

Colocar en la carpeta raíz del servidor web:
```
EasyPHP/www/monitoreos/calendariomundial2026/
```

### 2. Configurar API Key (opcional)

1. Registrarse en [football-data.org](https://www.football-data.org/client/register) (gratuito)
2. Copiar el **API Token**
3. Crear el archivo `backend/config.local.php`:

```php
<?php
// Este archivo está excluido del repositorio (.gitignore)
define('FOOTBALL_API_KEY', 'tu_token_aqui');
```

> **Sin API Key:** la app funciona en **Modo Demo** con datos de muestra.

### 3. Abrir en el navegador

```
http://localhost/monitoreos/calendariomundial2026/
```

---

## Uso

| Acción | Descripción |
|--------|-------------|
| **Pestaña "Hoy"** | Partidos programados para hoy con hora local |
| **Pestaña "Todos"** | Calendario completo agrupado por fecha |
| **Pestaña "Grupos"** | Tablas de posiciones A–L |
| **"Actualizar"** | Sincroniza con la API (respeta rate limit 5 min) |
| **Selector de zona** | Convierte todos los horarios a tu hora local |
| **ES / EN** | Cambia el idioma de la interfaz |
| **Filtros A–L** | Muestra solo partidos de un grupo |

---

## Estructura del Proyecto

```
calendariomundial2026/
├── index.php              ← Entrada principal
├── backend/
│   ├── config.php         ← Configuración (crea config.local.php para tu key)
│   ├── database.php       ← SQLite: esquema, seeds, CRUD
│   ├── fetcher.php        ← Cliente HTTP para football-data.org
│   └── data_service.php   ← Lógica de negocio
├── api/
│   ├── get-data.php       ← Endpoint JSON para el frontend
│   └── update-results.php ← Trigger de sincronización
├── frontend/
│   ├── css/style.css      ← Estilos FIFA 2026
│   └── js/app.js          ← SPA JavaScript
├── i18n/
│   ├── es.json            ← Traducciones español
│   └── en.json            ← Traducciones inglés
├── data/                  ← Base de datos SQLite (auto-creada)
├── SDD.md                 ← Especificación técnica completa
└── CHANGELOG.md           ← Historial de cambios
```

---

## Versionado

`Vx.y.z` — Semántico:
- **x** (Mayor): Cambio de arquitectura o rediseño completo
- **y** (Menor): Nueva funcionalidad compatible
- **z** (Patch): Corrección de errores

---

## Fuentes de Datos

| Servicio | Uso | Costo |
|----------|-----|-------|
| [football-data.org](https://football-data.org) | Partidos, marcadores, posiciones | Gratuito |
| [flagcdn.com](https://flagcdn.com) | Banderas de selecciones | Gratuito |

---

## Licencia

[Apache License 2.0](LICENSE)

---

## ☕ ¿Te fue útil?

Si este proyecto te ayudó a seguir el Mundial, considera invitarme una cerveza:

[![Donate](https://img.shields.io/badge/PayPal-Donar-blue)](https://www.paypal.com/donate/?hosted_button_id=ZABFRXC2P3JQN)
