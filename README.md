# ATEX Proforma

Sistema simple en PHP 8 + SQLite para cargar clientes y productos, administrar impuestos, generar proformas en PDF y operar backups/restauracion de la base de datos.

## Requisitos

- PHP 8+
- Extension PDO SQLite habilitada
- Servidor web apuntando a `public/`

No requiere framework ni dependencias de Composer para funcionar.

## Instalacion

Desde la raiz del proyecto:

```bash
php scripts/install.php
```

El instalador:

- Crea `storage/database`, `storage/backups` y `storage/proformas`.
- Ejecuta `migrations/init.sql`.
- Crea el usuario inicial definido por `INITIAL_ADMIN_USERNAME`.
- Guarda la contraseña inicial con `password_hash()`.
- Crea IVA 10%, IVA 5% y Exento.
- Verifica permisos de escritura.
- Advierte si falta `Proforma de Factura Atex Paraguay.pdf`.

Antes de ejecutar el instalador, copiar `.env.example` a `.env` y definir al menos:

```txt
INITIAL_ADMIN_PASSWORD=una-clave-segura
```

## Uso local rapido

```bash
php -S 127.0.0.1:8000 -t public
```

Abrir `http://127.0.0.1:8000`.

## Publicacion en hosting compartido

La aplicacion puede publicarse completa en una subcarpeta, por ejemplo:

```txt
public_html/atex-proforma-dev/
```

El archivo `.htaccess` de la raiz redirige las solicitudes hacia `public/`, bloquea acceso directo a carpetas internas y permite que la app funcione como:

```txt
http://atex.com.py/atex-proforma-dev/
```

Importante al subir por FTP/cPanel:

- Subir tambien archivos ocultos, especialmente `.htaccess`.
- La URL publica recomendada es `/atex-proforma-dev/`, no `/atex-proforma-dev/public/`.
- Si el hosting no detecta bien el subdirectorio, definir `APP_BASE_PATH=/atex-proforma-dev` como variable de entorno.
- Verificar que `storage/database`, `storage/backups` y `storage/proformas` tengan permisos de escritura para PHP.

## Configuracion

Las constantes principales estan en `app/bootstrap.php`:

```php
BACKUP_RETENTION_DAYS = 30;
MIN_BACKUPS_TO_KEEP = 5;
DEFAULT_CURRENCY_CODE = 'USD';
```

La aplicación carga variables del entorno del sistema y, para desarrollo o hosting sin gestor de secretos, desde el archivo local `.env`. Las variables ya definidas por el servidor tienen prioridad. El archivo `.env` no debe versionarse.

Tambien se puede definir `DEFAULT_CURRENCY_CODE`, `APP_TIMEZONE` y `APP_PUBLIC_URL` como variables de entorno antes de cargar la aplicacion.

## Proyectos y numeracion

Los proyectos se guardan una sola vez con nombre normalizado y prefijo unico. El formulario de proforma busca proyectos existentes mientras se escribe y crea uno nuevo solo si no hay coincidencia normalizada.

La numeracion publica usa:

```txt
[PREFIJO]-[AAAAMMDD]-[SECUENCIAL]
```

El secuencial pertenece al proyecto. Editar una proforma emitida crea una nueva emision, conserva la anterior y registra `parent_proforma_id`, `version_number` y `project_sequence`.

Al abrir la aplicacion, `ensureSchemaCompatibility()` crea la tabla `projects`, agrega las columnas nuevas y vincula de forma incremental las proformas historicas sin cambiar sus numeros publicos.

## Monedas, unidades país y cambio de divisas

La lista de productos se administra en USD y se muestra con `US$`. Las unidades país disponibles son Paraguay (`₲`), República Dominicana (`RD$`), Colombia (`COL$`) y Panamá (`฿`).

Los usuarios pueden pertenecer a varias unidades mediante `user_country_units`, manteniendo `users.unit` como unidad principal para compatibilidad con firmas, equipos y filtros existentes.

El apartado `Configuración > Cambio de divisas` permite registrar manualmente el valor vigente de moneda local por 1 US$. Solo pueden acceder:

- Administrador.
- Director.
- Gerente.
- Supervisor.

Cada actualización conserva el historial y deja un solo registro activo por unidad país. No se consultan APIs externas.

Las proformas guardan sus importes base en USD. Si se emiten en moneda local, almacenan la unidad, símbolo y tipo de cambio usado como snapshot. Las proformas USD quedan `NOT_REQUIRED`; las locales quedan `PENDING` para el flujo de autorización futuro. Si falta tipo de cambio, la proforma puede guardarse sin enviar y muestra una advertencia.

La migración incremental crea automáticamente un backup `backup_YYYY-MM-DD_HH-mm-ss_pre-currency-stage2.sqlite` antes de modificar una base existente. Las proformas históricas conservan número e importes y reciben valores seguros USD sin recalcularse.

Para el envio de propuestas por email se usan las variables `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` y `SMTP_TIMEOUT`. Ver `.env.example`; `storage/config.php` ya no se utiliza para configurar SMTP.

> **Advertencia de seguridad:** Las credenciales SMTP que estaban en `storage/config.php` deben considerarse expuestas. Rotarlas en el proveedor antes de usar este entorno en producción.

## PDF

El PDF se genera en `app/pdf.php` con dibujo directo en PHP, sin dependencias externas. El layout replica el template visual:

- Logo ATEX LATAM, panel de totales y footer tomados desde assets extraidos del template para evitar redibujar esas zonas.
- Encabezado de proforma arriba a la derecha.
- Datos del cliente, tabla naranja, filas alternadas y valores de totales.
- La descripcion del item en PDF usa `products.nombre`; `products.descripcion` queda solo para uso interno.

La validez de una nueva proforma se selecciona entre 10, 20, 30 o 45 días. El sistema guarda `validity_days`, calcula `expires_at` desde la creación y aplica ese vencimiento al enlace público y a la descarga.

Si faltan los assets estaticos del PDF, se dibuja un fallback vectorial `atex LATAM` y el footer/panel de totales se reconstruyen desde PHP.

## Backups

La funcion `createDatabaseBackup()` copia la base actual a:

```txt
storage/backups/backup_YYYY-MM-DD_HH-mm-ss.sqlite
```

Se ejecuta antes de:

- Crear o editar clientes.
- Crear o editar productos.
- Crear o editar impuestos.
- Crear proformas y enviar enlaces de propuesta por email.
- Restaurar backups.

Tambien se puede crear manualmente desde `Backups` o por CLI:

```bash
php scripts/backup.php
```

La restauracion valida que el archivo sea `.sqlite` y que exista dentro de `storage/backups/`.

## Seguridad minima

- Sesiones PHP.
- Paginas internas protegidas.
- Administracion de usuarios para crear accesos, cambiar contrasenas y asignar rol administrador.
- `password_hash()` y `password_verify()`.
- PDO con prepared statements.
- CSRF en formularios.
- `storage/` incluye `.htaccess` para bloquear acceso directo si el servidor respeta Apache directives.
- Los PDFs quedan en `storage/proformas`.
- La descarga interna directa de proformas esta deshabilitada.
- El cliente recibe un enlace publico con token unico para descargar la propuesta dentro del plazo de validez.
- Se registra la primera hora de ingreso al enlace, la primera descarga del PDF y la solicitud de cotizacion actualizada.

## Pruebas manuales minimas

1. Login correcto con `proforma-admin`.
2. Login incorrecto.
3. Crear cliente.
4. Crear producto venta.
5. Crear producto alquiler.
6. Crear impuesto.
7. Crear proforma venta.
8. Crear proforma alquiler con dias.
9. Verificar subtotal, impuesto y total.
10. Verificar que la proforma se envia al email del cliente.
11. Verificar que el PDF respeta el template visual.
12. Verificar que el nombre del producto aparece como Descripcion.
13. Verificar que la descripcion interna del producto no aparece en el PDF.
14. Verificar backup automatico al crear proforma.
15. Verificar que la lista de proformas muestra quien creo cada proforma.
16. Verificar que la lista muestra el estado como Vigente o Vencido.
17. Abrir el enlace del cliente y verificar que registra la hora de ingreso.
18. Descargar el PDF desde el enlace del cliente y verificar que registra la hora de descarga.
19. Cambiar la fecha de vencimiento a una fecha pasada y verificar que el enlace muestra estado Vencido y permite pedir cotizacion actualizada.
20. Crear usuario normal desde Usuarios.
21. Cambiar un usuario a Administrador.
22. Crear backup manual.
23. Restaurar backup.
24. Confirmar que los datos restaurados coinciden.
25. Crear `Edificio Puerto Ibiza` y verificar numero `EPI-AAAAMMDD-001`.
26. Escribir el mismo proyecto con mayusculas, acentos o espacios dobles y verificar que se reutiliza.
27. Crear `Establos Pedro Iriarte` y verificar que resuelve la colision con `EsPI`.
28. Emitir otra proforma para el primer proyecto y verificar secuencial `002`.
29. Editar una proforma y verificar que se crea una nueva emision sin sobrescribir la anterior.

Prueba automatica de esta etapa:

```bash
php tests/project_stage1_test.php
php tests/currency_stage2_test.php
php tests/commercial_stage3_test.php
php tests/authorization_stage4_test.php
```

## Empresas, contactos y validez

`clients` se conserva como tabla de empresas para no romper datos históricos. El RUC se normaliza en `ruc_normalized` y se busca desde la carga de proforma mediante un endpoint autenticado.

Los contactos existentes se conservan en `client_contacts`. La relación muchos-a-muchos se administra en `company_contacts`, y los correos principal/secundarios en `contact_emails`. Las proformas guardan los IDs normalizados y también snapshots de empresa, contacto y correo para mantener el dato comercial emitido.

La migración incremental de Etapa 3 crea automáticamente un backup `backup_YYYY-MM-DD_HH-mm-ss_pre-commercial-stage3.sqlite` antes de modificar una base existente. Las proformas históricas mantienen su número, moneda, importes y snapshot de tipo de cambio.

## Autorización, acciones, versiones y firma comercial

La carga de proformas termina con el botón principal `Guardar` y redirige a un resumen con número, estado, moneda, empresa, contacto, validez, vencimiento, link público y acciones.

- Las proformas USD quedan `NOT_REQUIRED` y permiten descarga directa.
- Las proformas en moneda local quedan `PENDING` y bloquean la descarga final hasta su aprobación.
- La solicitud se asigna a un Supervisor o Gerente de la misma unidad país y genera una notificación interna.
- El autorizador puede confirmar el cambio general vigente, aprobar un cambio `SPECIAL` exclusivo para la proforma o rechazar con comentario.
- Un cambio especial actualiza únicamente el snapshot de la proforma y no modifica `exchange_rates`.
- Editar una proforma crea una nueva fila, usa el siguiente secuencial del proyecto y conserva `parent_proforma_id`.

La trazabilidad se guarda en:

- `proforma_authorizations`
- `notifications`
- `proforma_events`

La firma comercial usa los datos del usuario (`first_name`, `last_name`, `commercial_position`, `email`, `phone`, `unit`, `signature_image`) y conserva snapshots en la proforma. La imagen manuscrita es opcional, debe ser PNG y se guarda en `storage/signatures`.

La migración incremental de Etapa 4 crea automáticamente un backup `backup_YYYY-MM-DD_HH-mm-ss_pre-authorization-stage4.sqlite` antes de agregar el esquema nuevo. Las proformas históricas quedan `NOT_REQUIRED`, sin renumeración ni recálculo de importes, monedas o vencimientos.
