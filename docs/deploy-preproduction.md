# Deploy de pre-producción

Esta guía prepara una instalación sin agregar funcionalidades ni modificar reglas de negocio.

## 1. Requisitos del servidor

- Linux con Apache 2.4 o Nginx.
- PHP 8.2 como mínimo recomendado; PHP 8.3 o 8.4 preferido.
- SQLite 3 y acceso a CLI para las verificaciones y restauraciones.
- HTTPS obligatorio.
- Acceso de escritura del usuario de PHP únicamente sobre las carpetas indicadas abajo.
- Cron opcional para ejecutar backups periódicos con `php scripts/backup.php`.

Extensiones PHP requeridas:

- `pdo` y `pdo_sqlite`.
- `sqlite3`, requerida por las verificaciones operativas por CLI.
- `mbstring`.
- `gd`, usada para procesar assets PNG del PDF.
- `openssl`, requerida para SMTP TLS y HTTPS.
- `session`, `filter`, `json` y `fileinfo`, normalmente incluidas en una instalación estándar.

Validación rápida:

```bash
php -v
php -m | grep -E 'PDO|pdo_sqlite|sqlite3|mbstring|gd|openssl'
```

## 2. Estructura y document root

El document root recomendado y soportado es:

```text
/ruta/atex-proforma/public
```

Nunca publicar como document root la raíz completa del repositorio. No deben ser accesibles por HTTP:

```text
app/
docs/
migrations/
scripts/
storage/
tests/
.env
.git/
```

El proyecto incluye defensas `.htaccess` para Apache y hosting compartido, pero son una segunda barrera. La separación mediante `public/` sigue siendo obligatoria siempre que el proveedor permita configurar el document root.

## 3. Permisos

El código debe ser de solo lectura para el usuario de PHP. Únicamente estas carpetas requieren escritura:

```text
storage/database/
storage/backups/
storage/proformas/
storage/signatures/
storage/logs/
```

Ejemplo, ajustando usuario y grupo al servidor:

```bash
chown -R deploy:www-data /ruta/atex-proforma
find /ruta/atex-proforma -type d -exec chmod 0755 {} \;
find /ruta/atex-proforma -type f -exec chmod 0644 {} \;
chgrp -R www-data /ruta/atex-proforma/storage
chmod -R 0775 /ruta/atex-proforma/storage
```

No usar `0777`. La carpeta `storage/` debe permanecer fuera del document root.

## 4. Configuración `.env`

Copiar el ejemplo sin versionar el resultado:

```bash
cp .env.example .env
chmod 0600 .env
```

Configuración mínima de pre-producción:

```dotenv
APP_ENV=preproduction
APP_DEBUG=0
APP_URL=https://proforma-pre.example.com
APP_BASE_PATH=
APP_TIMEZONE=America/Asuncion
PROFORMA_PREFIX=002
DEFAULT_CURRENCY_CODE=USD

INITIAL_ADMIN_USERNAME=proforma-admin
INITIAL_ADMIN_PASSWORD=definir-una-clave-larga-y-unica

HEALTHCHECK_TOKEN=definir-un-token-aleatorio-largo
```

Reglas:

- `APP_ENV` admite `local`, `preproduction` o `production`.
- En `preproduction` y `production` la aplicación fuerza `APP_DEBUG=0`.
- `APP_URL` debe ser la URL HTTPS pública, sin barra final.
- `APP_BASE_PATH` queda vacío si la app usa un dominio o virtual host propio. En una subcarpeta, usar por ejemplo `/atex-proforma`.
- `APP_PUBLIC_URL` existe solo por compatibilidad; las instalaciones nuevas deben usar `APP_URL`.
- Los errores se registran en `storage/logs/app.log`; no se muestran detalles al usuario fuera de local.
- `.env`, contraseñas, tokens y credenciales SMTP nunca se agregan a Git.

## 5. Instalación de SQLite y administrador inicial

Desde la raíz del proyecto:

```bash
php scripts/install.php
```

El instalador es idempotente:

- crea las carpetas necesarias;
- verifica protección y permisos;
- crea `storage/database/app.sqlite` si no existe;
- ejecuta las migraciones actuales;
- crea el administrador únicamente si el usuario configurado no existe;
- no reemplaza usuarios ni datos existentes;
- carga impuestos iniciales faltantes.

Después del primer uso, retirar `INITIAL_ADMIN_PASSWORD` de `.env` o reemplazarlo por un valor gestionado fuera del archivo. El hash queda en SQLite; la contraseña no se imprime.

Si se despliega una base existente, copiarla antes de ejecutar el instalador:

```bash
install -m 0660 /ruta/segura/app.sqlite storage/database/app.sqlite
php scripts/install.php
```

## 6. SMTP

Configurar en `.env`:

```dotenv
SMTP_HOST=
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=
SMTP_FROM_NAME=Atex LATAM
SMTP_TIMEOUT=30
```

Usar credenciales exclusivas de pre-producción. Confirmar con el proveedor que las credenciales antiguas fueron rotadas antes de habilitar envíos. Mantener `SMTP_*` vacío si los emails no están autorizados en este entorno.

## 7. Configuración del servidor web

### Apache 2.4

```apache
<VirtualHost *:443>
    ServerName proforma-pre.example.com
    DocumentRoot /ruta/atex-proforma/public

    <Directory /ruta/atex-proforma/public>
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>

    <Directory /ruta/atex-proforma>
        AllowOverride None
        Require all denied
    </Directory>

    <Directory /ruta/atex-proforma/public>
        Require all granted
    </Directory>

    # Configurar certificado TLS, logs y PHP-FPM según el proveedor.
</VirtualHost>
```

### Nginx con PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name proforma-pre.example.com;
    root /ruta/atex-proforma/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

En proxy reverso, preservar `X-Forwarded-Proto: https`. La aplicación también usa `APP_URL=https://...` para marcar la cookie de sesión como `Secure`.

### Hosting compartido

Si no es posible apuntar a `public/`, subir también todos los `.htaccess` y mantener el `.htaccess` de la raíz. Verificar manualmente que `/app/`, `/storage/`, `/tests/`, `/docs/`, `/.env` y cualquier `.sqlite` respondan 403 o 404. Esta modalidad es una compatibilidad, no la recomendación principal.

## 8. Healthcheck

Ruta:

```text
GET /health.php
```

Con `HEALTHCHECK_TOKEN` configurado:

```bash
curl --fail --silent \
  -H "Authorization: Bearer $HEALTHCHECK_TOKEN" \
  https://proforma-pre.example.com/health.php
```

También acepta el header `X-Health-Token`. No enviar el token en query string.

Un resultado sano responde HTTP 200:

```text
OK
DB OK
Storage OK
Environment OK
```

Comprueba carga de la aplicación, acceso a SQLite, `PRAGMA integrity_check`, configuración de entorno y escritura en PDFs/backups. Un fallo responde HTTP 503 sin secretos ni rutas internas. Sin token la ruta queda semipública y solo expone esos estados resumidos.

## 9. Tests en servidor

Ejecutar desde la raíz, con una copia controlada de la base:

```bash
for file in app/*.php public/*.php scripts/*.php tests/*.php; do php -l "$file"; done
php tests/project_stage1_test.php
php tests/currency_stage2_test.php
php tests/commercial_stage3_test.php
php tests/authorization_stage4_test.php
php tests/pdf_notes_stage5_test.php
php tests/dashboard_stage6_test.php
php tests/preproduction_stage7_test.php
sqlite3 storage/database/app.sqlite "PRAGMA integrity_check;"
sqlite3 storage/database/app.sqlite "PRAGMA foreign_key_check;"
```

`integrity_check` debe devolver `ok`. `foreign_key_check` debe terminar sin filas.

## 10. Backup

Backup manual:

```bash
php scripts/backup.php
```

El archivo se crea fuera de `public/` como:

```text
storage/backups/backup_YYYY-MM-DD_HH-mm-ss.sqlite
```

Copiar periódicamente estos archivos a almacenamiento externo cifrado. Un backup que solo existe en el mismo servidor no cubre pérdida del host.

## 11. Restauración

Antes de restaurar:

1. activar mantenimiento o detener PHP-FPM para evitar escrituras concurrentes;
2. crear un backup de la base actual;
3. verificar el archivo fuente con `PRAGMA integrity_check`.

Por interfaz, usar `Backups` con una cuenta administradora. Por CLI:

```bash
php scripts/backup.php
sqlite3 storage/backups/backup_YYYY-MM-DD_HH-mm-ss.sqlite "PRAGMA integrity_check;"
cp storage/backups/backup_YYYY-MM-DD_HH-mm-ss.sqlite storage/database/app.sqlite
chmod 0660 storage/database/app.sqlite
sqlite3 storage/database/app.sqlite "PRAGMA integrity_check;"
sqlite3 storage/database/app.sqlite "PRAGMA foreign_key_check;"
```

Reiniciar el servicio y ejecutar el healthcheck. Nunca restaurar desde una ruta dentro de `public/`.

## 12. Checklist post-deploy

- [ ] HTTPS activo y redirección desde HTTP.
- [ ] `public/` configurado como document root.
- [ ] Carpetas internas y `.env` responden 403/404.
- [ ] Login de administrador.
- [ ] Crear usuario de prueba.
- [ ] Crear tipo de cambio.
- [ ] Crear empresa.
- [ ] Crear contacto.
- [ ] Crear proforma USD.
- [ ] Crear proforma local pendiente.
- [ ] Autorizar proforma local.
- [ ] Descargar PDF.
- [ ] Ver enlace público vigente.
- [ ] Ver enlace vencido.
- [ ] Revisar dashboard.
- [ ] Revisar disclaimers.
- [ ] Crear backup manual y validar su integridad.
- [ ] Verificar `/health.php`.
- [ ] Revisar `storage/logs/app.log`.
- [ ] Probar SMTP con un email autorizado.
- [ ] Confirmar que usuarios/datos QA no estén habilitados indebidamente.
- [ ] Confirmar nuevamente que las credenciales SMTP antiguas fueron rotadas.

## 13. Archivos que no deben desplegarse desde Git

Confirmar que no estén versionados:

```bash
git ls-files .env
git ls-files storage/config.php
git ls-files 'storage/*.sqlite'
git ls-files storage/backups
git ls-files tmp/pdfs
```

Además, no publicar credenciales SMTP, firmas reales, PDFs generados, backups ni bases extraídas de producción.
