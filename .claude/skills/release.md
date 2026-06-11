---
name: release
description: Gestiona el ciclo completo de versionado y release para el FIFA World Cup 2026 Tracker. Implementa semver Vx.x.x, sincroniza la versión en VERSION, CHANGELOG.md, SDD.md y README.md, crea el tag Git y el release en GitHub. Compatible con la cuenta erickson558.
---

# Skill: Release Manager — FIFA World Cup 2026 Tracker

Implemento el ciclo completo de release profesional con versionado semántico.

## Estrategia de Versionado — `Vx.y.z`

| Segmento | Cuándo incrementar | Ejemplo |
|----------|-------------------|---------|
| **x** (major) | Rediseño completo, cambio de DB schema incompatible, nueva arquitectura | V1→V2 |
| **y** (minor) | Nueva funcionalidad: nuevo idioma, nueva pestaña, bracket knockout | V1.0→V1.1 |
| **z** (patch) | Bug fix, ajuste CSS, corrección de texto, nuevo equipo seed | V1.0.0→V1.0.1 |

## Archivos que sincronizan la versión

| Archivo | Cómo se actualiza |
|---------|-------------------|
| `VERSION` | Sobreescribir con la nueva versión (sin salto de línea extra) |
| `CHANGELOG.md` | Agregar sección `## [Vx.y.z] — YYYY-MM-DD` al inicio |
| `SDD.md` | Actualizar campo Versión en el encabezado |
| `README.md` | El badge de versión lo lee de GitHub tags automáticamente |

## Pasos del Release

### 1. Analizar cambios pendientes

```bash
git diff HEAD --stat
git log --oneline origin/main..HEAD
```

### 2. Determinar nueva versión

Analizo los cambios y decido el tipo de bump.

### 3. Actualizar VERSION

```bash
echo "V1.0.1" > VERSION
```

### 4. Actualizar CHANGELOG.md

Agrego al inicio (después del encabezado principal):
```markdown
## [V1.0.1] — 2026-06-15

### Corregido
- Descripción precisa del fix

### Añadido (si aplica)
- Nueva funcionalidad

### Cambiado (si aplica)
- Comportamiento modificado
```

### 5. Actualizar SDD.md (si hay cambios arquitectónicos)

Solo si hubo cambios en el modelo de datos, API, o decisiones técnicas.

### 6. Commit y Tag

```bash
# Staging de todos los cambios rastreados
git add VERSION CHANGELOG.md SDD.md README.md
# (también cualquier archivo de código modificado)

# Commit con mensaje convencional
git commit -m "chore(release): bump versión a V1.0.1 y sync docs

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"

# Crear tag anotado (con mensaje descriptivo)
git tag -a V1.0.1 -m "Release V1.0.1 — [descripción breve]"

# Push del código y el tag
git push origin main
git push origin V1.0.1
```

### 7. Verificar GitHub Actions

```bash
# Ver el estado del workflow
gh run list --workflow=release.yml

# Ver el release creado
gh release list

# Ver detalles del release más reciente
gh release view V1.0.1
```

## Comandos de verificación post-release

```bash
# Confirmar que el tag existe en remoto
git ls-remote --tags origin

# Ver la versión actual del repo
cat VERSION

# Confirmar que el release tiene el ZIP adjunto
gh release view V1.0.1 --json assets
```

## Notas Importantes

- El tag `Vx.x.x` **dispara automáticamente** el GitHub Actions que crea el release con ZIP
- Verificar cuenta activa antes del push: `gh auth status`
- No crear releases manuales en GitHub.com, dejar que el workflow los genere
- Si el workflow falla, verificar `.github/workflows/release.yml` y los permisos del token
- Los archivos `data/*.db` y `backend/config.local.php` están en `.gitignore` y nunca se subirán
