# Etapa 7 - Estabilización pre-producción y QA final

Fecha de ejecución: 18 de junio de 2026.

## Resultado general

La aplicación quedó estable para pre-producción. La suite PHP pasa, SQLite está íntegra, los cuatro registros históricos conservan sus campos críticos y los paths sensibles no están versionados.

Se corrigieron dos defectos de estabilización:

1. El dashboard agrupaba por la unidad principal del vendedor cuando la proforma pertenecía a otro país. Ahora agrupa por la unidad calculada de la proforma/empresa.
2. La sesión no configuraba explícitamente modo estricto ni atributos de cookie. Ahora usa `session.use_strict_mode`, solo cookies, `HttpOnly` y `SameSite=Lax`; `Secure` se activa bajo HTTPS.

## Auditoría funcional

Validado en navegador:

- Login administrativo y por roles QA.
- Alta de proyecto, empresa por RUC, contacto, email principal y secundarios.
- Reutilización de empresa existente.
- Contacto asociado a empresas de Paraguay y Colombia.
- Proforma USD y proforma local.
- Guardado, resumen, link público vigente y link vencido.
- Bloqueo de descarga para moneda local pendiente/rechazada.
- Solicitud de autorización y aprobación con cambio general.
- Evidencia de aprobación especial y rechazo con comentario.
- Edición con nueva emisión, `parent_proforma_id` y secuencial siguiente.
- Cambio comercial a Ganada y Perdida.
- Dashboard con datos, sin datos, Paraguay y Colombia.
- Layout móvil del listado y dashboard.
- PDF corto de una página.
- PDF largo de tres páginas con 13 ítems y observación extensa.

No hubo errores de consola en los recorridos verificados.

## Matriz de permisos implementada

| Rol | Divisas | Disclaimers | Indicadores | Crear/solicitar | Decidir autorización | Estado comercial | Descarga final visible |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Administrador | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| Director | Sí | Sí | Sí | No | No | Sí | Sí |
| Gerente | Sí | Sí | Sí | Sí | Sí, si está asignada | Sí | Sí |
| Supervisor | Sí | Sí | Sí | Sí | Sí, si está asignada | Sí | Sí |
| Ejecutivo comercial | No | No | No | Sí | No | Sí | Sí |
| Asistente comercial | No | No | No | No | No | No | No |

La descarga final está bloqueada para estados `PENDING` y `REJECTED` en todos los roles. Los accesos directos respetan la matriz: Director es redirigido fuera de creación; Ejecutivo fuera de Indicadores; Asistente fuera de cualquier página distinta de Perfil.

No se proporcionó una matriz externa distinta para comparar. Las restricciones destacables de la implementación son que Director no crea proformas ni decide autorizaciones, y Asistente solo accede a Perfil.

## Auditoría histórica

Verificados contra backups previos:

| Número | Moneda | Total USD | Cambio | Autorización | Vencimiento | PDF |
| --- | --- | ---: | ---: | --- | --- | --- |
| 002-000001 | USD | 7.524.000,00 | 1 | NOT_REQUIRED | 2026-05-25 23:59:59 | proforma_002_000001.pdf |
| 002-000002 | USD | 3.135.000,00 | 1 | NOT_REQUIRED | 2026-05-25 23:59:59 | proforma_002_000002.pdf |
| 002-000003 | USD | 0,14 | 1 | NOT_REQUIRED | 2026-05-25 23:59:59 | proforma_002_000003.pdf |
| 002-000004 | USD | 148,80 | 1 | NOT_REQUIRED | 2026-05-25 23:59:59 | proforma_002_000004.pdf |

Los snapshots comerciales permanecen intactos. Los tokens públicos históricos continúan sin generarse hasta que se requieran.

## Auditoría de secretos

Comandos revisados:

```bash
git status
git ls-files .env
git ls-files storage/config.php
git ls-files 'storage/*.sqlite'
git ls-files storage/backups
git ls-files tmp/pdfs
```

Resultado: ninguno de esos paths sensibles está versionado. `.env.example` contiene valores sensibles vacíos. `storage/config.example.php` no contiene credenciales.

`Proforma de Factura Atex Paraguay.pdf` sí está versionado porque es la plantilla de referencia requerida por el generador; `.htaccess` bloquea su acceso web directo.

Pendiente externo obligatorio: confirmar en el proveedor SMTP que las credenciales antiguas fueron rotadas. Esta verificación no puede resolverse desde la aplicación ni desde el repositorio.

## Datos demo

- Proyecto USD ganado.
- Proyecto local pendiente existente.
- Proyecto local aprobado.
- Proyecto local rechazado.
- Proyecto Colombia perdido.
- Empresa demo Paraguay con dos contactos.
- Contacto principal asociado a empresas de Paraguay y Colombia.
- Contacto con email principal y secundarios.
- Proforma multipágina con observación extensa.
- Dashboard con Paraguay y Colombia.

Los usuarios demo usan dominios `example.test` y se crean mediante `scripts/seed_demo.php`.
