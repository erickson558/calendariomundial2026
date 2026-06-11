---
name: github-push
description: Sube cambios a GitHub para el proyecto FIFA World Cup 2026 Tracker. Hace bump de versión, actualiza CHANGELOG.md, hace commit, crea tag Vx.x.x y push. El tag dispara el release automático en GitHub Actions. Cuenta GitHub erickson558.
---

# Skill: GitHub Push + Release

Preparo y subo los cambios del proyecto FIFA World Cup 2026 Tracker a GitHub.

## Pasos que ejecuto

### 1. Determinar tipo de cambio

Analizo los cambios para determinar qué incrementar en `Vx.y.z`:
- **patch (z)**: bug fixes, ajustes de UI, correcciones de texto
- **minor (y)**: nueva funcionalidad compatible (nuevo idioma, nueva pestaña, nuevo endpoint)
- **major (x)**: rediseño, cambio de arquitectura, ruptura de compatibilidad

### 2. Bump de versión

```bash
# Leer versión actual
cat VERSION

# Escribir nueva versión (reemplazar x.y.z con la nueva)
echo "V1.0.1" > VERSION
```

### 3. Actualizar CHANGELOG.md

Agrego una nueva sección al inicio de CHANGELOG.md:
```markdown
## [V1.0.1] — YYYY-MM-DD

### Corregido
- Descripción del cambio

### Añadido
- Nueva funcionalidad (si aplica)
```

### 4. Actualizar SDD.md si es necesario

Si hay cambios en la arquitectura, API, modelo de datos o decisiones técnicas.

### 5. Git commit y push

```bash
git add -A
git commit -m "tipo(scope): descripción concisa del cambio

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"

# Push a main
git push origin main
```

### 6. Crear tag (dispara release automático)

```bash
# Crear tag anotado
git tag -a V1.0.1 -m "Release V1.0.1"

# Push del tag → GitHub Actions genera el release
git push origin V1.0.1
```

### 7. Verificar release

```bash
# Ver el release creado
gh release view V1.0.1

# O abrir en el navegador
gh release view V1.0.1 --web
```

## Cuenta GitHub

- **Usuario**: erickson558
- **Repo**: erickson558/calendariomundial2026
- **Rama principal**: main
- **Auth**: GitHub CLI autenticado (`gh auth status`)

## Notas importantes

- El tag `Vx.x.x` es lo que **dispara** el GitHub Actions release
- NO incluir `data/*.db` ni `backend/config.local.php` en el commit
- El `.gitignore` ya los excluye automáticamente
- Verificar que `gh auth status` muestra la cuenta erickson558 antes de push
