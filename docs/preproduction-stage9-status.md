# Etapa 9 - Estado de despliegue y piloto

Fecha de preparación: 18 de junio de 2026.

Commit base: `0e15ca0 Prepare preproduction deployment`.

## Resultado local

- PHP 8.4.14 compatible y extensiones requeridas disponibles.
- Lint PHP completo sin errores.
- Tests de etapas 1 a 7 correctos.
- Prueba nueva de instalación limpia correcta.
- SQLite `integrity_check`: `ok`.
- SQLite `foreign_key_check`: sin resultados.
- Backup local creado y validado.
- Healthcheck local: 401 sin token y 200 con token.
- Respuesta autenticada: App, DB, Storage y Environment OK.
- Rutas sensibles locales: redirección vacía a `login.php`; ningún archivo fue entregado.
- `.env`, SQLite, backups, PDFs generados, logs y configuración SMTP local siguen sin versionarse.

## Datos piloto verificados en instalación temporal

- Seis roles: Administrador, Director, Gerente, Supervisor, Ejecutivo y Asistente.
- Dos empresas.
- Tres contactos.
- Un contacto asociado a las dos empresas.
- Tipos de cambio activos para Paraguay y Colombia.
- Base temporal íntegra y sin referencias inválidas.

## Bug encontrado y corregido

En una instalación sin base previa, `db()` ejecutaba la compatibilidad incremental
antes de crear las tablas base. `scripts/install.php` fallaba con
`no such table: main.clients`.

La inicialización ahora ejecuta `migrations/init.sql` cuando faltan las tablas
base y luego aplica la compatibilidad incremental. La regresión queda cubierta
por `tests/installation_stage9_test.php`.

## Pendientes externos

- Definir URL y proveedor/servidor de pre-producción.
- Configurar HTTPS y document root real en `public/`.
- Crear el `.env` real de pre-producción.
- Rotar las credenciales SMTP antiguas en el proveedor.
- Configurar credenciales SMTP nuevas y realizar un envío autorizado.
- Ejecutar `scripts/validate_preproduction.php` contra la URL real.
- Ejecutar el recorrido funcional piloto por navegador.
- Crear y probar el backup inicial del servidor.
- Completar `docs/preproduction-pilot-report.md`.

## Decisión actual

**No listo todavía para demo interna.**

El código y el procedimiento quedaron preparados y validados localmente, pero
la aceptación de Etapa 9 requiere desplegar en un servidor real y completar los
pendientes externos anteriores.
