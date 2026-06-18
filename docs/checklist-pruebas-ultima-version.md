# Checklist de pruebas — Última versión

Versión revisada: estado actual del workspace al **18 de junio de 2026**.  
Commit base: `0e15ca0 Prepare preproduction deployment`, incluyendo los ajustes locales de Etapa 9.

## 1. Instalación y acceso

- [ ] Realizar una instalación limpia sin base de datos previa.
- [ ] Reejecutar el instalador y verificar que no duplique datos.
- [ ] Verificar la creación automática de carpetas y tablas.
- [ ] Verificar la creación del administrador inicial.
- [ ] Iniciar sesión con credenciales correctas.
- [ ] Intentar iniciar sesión con credenciales incorrectas.
- [ ] Cerrar sesión.
- [ ] Cambiar la contraseña del usuario.
- [ ] Verificar que las páginas internas estén protegidas sin una sesión activa.
- [ ] Verificar la persistencia y seguridad de la sesión.

## 2. Usuarios, perfiles y permisos

- [ ] Crear un usuario.
- [ ] Editar un usuario.
- [ ] Validar que la contraseña tenga al menos 8 caracteres.
- [ ] Impedir nombres de usuario duplicados.
- [ ] Probar el rol Administrador.
- [ ] Probar el rol Director.
- [ ] Probar el rol Gerente.
- [ ] Probar el rol Supervisor.
- [ ] Probar el rol Ejecutivo comercial.
- [ ] Probar el rol Asistente comercial.
- [ ] Asignar una unidad país principal.
- [ ] Asignar varias unidades país.
- [ ] Configurar la relación jerárquica “Reporta a”.
- [ ] Impedir que un usuario reporte a sí mismo.
- [ ] Impedir ciclos en la jerarquía de usuarios.
- [ ] Impedir eliminar los permisos del último administrador.
- [ ] Editar los datos del perfil.
- [ ] Editar los datos utilizados en la firma comercial.
- [ ] Subir una firma PNG válida.
- [ ] Rechazar una firma con formato inválido.
- [ ] Rechazar una firma mayor a 2 MB.
- [ ] Verificar nombre, cargo, teléfono, email, unidad y firma en la proforma y el PDF.

## 3. Matriz de permisos

- [ ] Administrador: verificar acceso completo.
- [ ] Director: verificar acceso a indicadores, divisas, disclaimers y estado comercial.
- [ ] Director: verificar que no pueda crear proformas.
- [ ] Director: verificar que no pueda decidir autorizaciones.
- [ ] Gerente: verificar creación de proformas.
- [ ] Gerente: verificar autorización cuando la solicitud esté asignada.
- [ ] Gerente: verificar acceso a indicadores.
- [ ] Supervisor: verificar creación de proformas.
- [ ] Supervisor: verificar autorización cuando la solicitud esté asignada.
- [ ] Supervisor: verificar acceso a indicadores.
- [ ] Ejecutivo comercial: verificar creación de proformas.
- [ ] Ejecutivo comercial: verificar actualización del estado comercial.
- [ ] Ejecutivo comercial: verificar que no tenga acceso a indicadores ni configuración.
- [ ] Asistente comercial: verificar que solamente pueda acceder a Perfil.
- [ ] Probar el acceso directo a URLs restringidas para cada rol.

## 4. Empresas y contactos

- [ ] Crear una empresa.
- [ ] Editar una empresa.
- [ ] Validar que el RUC sea obligatorio.
- [ ] Normalizar RUC con puntos, espacios y guiones.
- [ ] Evitar empresas duplicadas por RUC.
- [ ] Buscar una empresa por RUC desde la proforma.
- [ ] Reutilizar una empresa existente.
- [ ] Crear una empresa desde el formulario de proforma.
- [ ] Crear un contacto.
- [ ] Editar un contacto.
- [ ] Eliminar un contacto.
- [ ] Asociar un contacto a varias empresas.
- [ ] Crear un email principal para un contacto.
- [ ] Crear emails secundarios para un contacto.
- [ ] Verificar que exista un solo email principal por contacto.
- [ ] Evitar contactos duplicados por nombre y email.
- [ ] Seleccionar un email específico para una proforma.
- [ ] Buscar empresas y clientes en los listados.

## 5. Productos e impuestos

- [ ] Crear un producto.
- [ ] Editar un producto.
- [ ] Configurar el precio de venta en USD.
- [ ] Configurar el precio diario de alquiler en USD.
- [ ] Rechazar precios negativos.
- [ ] Buscar productos por nombre o referencia.
- [ ] Crear un impuesto.
- [ ] Editar un impuesto.
- [ ] Configurar un impuesto por país.
- [ ] Activar un impuesto.
- [ ] Desactivar un impuesto.
- [ ] Verificar IVA 10%.
- [ ] Verificar IVA 5%.
- [ ] Verificar Exento.
- [ ] Mostrar solamente impuestos compatibles con el país seleccionado.

## 6. Proyectos y numeración

- [ ] Crear un proyecto nuevo.
- [ ] Buscar proyectos mediante autocompletado.
- [ ] Reutilizar un proyecto ignorando mayúsculas, acentos y espacios dobles.
- [ ] Generar automáticamente el prefijo del proyecto.
- [ ] Resolver colisiones entre prefijos.
- [ ] Generar el número con formato `[PREFIJO]-[AAAAMMDD]-[SECUENCIAL]`.
- [ ] Mantener un secuencial independiente por proyecto.
- [ ] Impedir números de proforma duplicados.
- [ ] Conservar los números de las proformas históricas.

## 7. Creación de proformas

- [ ] Crear una proforma en USD.
- [ ] Crear una proforma en moneda local.
- [ ] Seleccionar la unidad país.
- [ ] Seleccionar una empresa existente.
- [ ] Crear una empresa durante la carga de la proforma.
- [ ] Seleccionar un contacto existente.
- [ ] Crear o asociar un contacto durante la carga.
- [ ] Emitir una proforma sin contacto específico.
- [ ] Seleccionar vendedor cuando el rol lo permita.
- [ ] Probar una validez de 10 días.
- [ ] Probar una validez de 20 días.
- [ ] Probar una validez de 30 días.
- [ ] Probar una validez de 45 días.
- [ ] Verificar el cálculo de la fecha de vencimiento.
- [ ] Agregar productos.
- [ ] Eliminar productos.
- [ ] Crear ítems de venta.
- [ ] Crear ítems de alquiler con cantidad de días.
- [ ] Modificar la cantidad.
- [ ] Modificar el precio unitario.
- [ ] Aplicar un descuento.
- [ ] Verificar el subtotal.
- [ ] Verificar el descuento.
- [ ] Verificar los impuestos.
- [ ] Verificar el total.
- [ ] Probar una proforma local sin tipo de cambio vigente.
- [ ] Guardar observaciones extensas.
- [ ] Conservar los saltos de línea de las observaciones.
- [ ] Confirmar la creación automática de un backup.

## 8. Edición y clonación

- [ ] Editar una proforma emitida.
- [ ] Confirmar que la edición genere una nueva emisión.
- [ ] Verificar la relación `parent_proforma_id`.
- [ ] Verificar el incremento del número de versión.
- [ ] Verificar el incremento del secuencial del proyecto.
- [ ] Confirmar que la emisión anterior no sea modificada.
- [ ] Clonar una proforma.
- [ ] Cambiar la empresa destino al clonar.
- [ ] Confirmar que el clon reciba un número nuevo.

## 9. Divisas

- [ ] Verificar la unidad Paraguay.
- [ ] Verificar la unidad República Dominicana.
- [ ] Verificar la unidad Colombia.
- [ ] Verificar la unidad Panamá.
- [ ] Registrar el valor de moneda local por 1 USD.
- [ ] Rechazar un tipo de cambio igual a cero.
- [ ] Rechazar un tipo de cambio negativo.
- [ ] Mantener un único tipo de cambio activo por país.
- [ ] Consultar el historial de tipos de cambio.
- [ ] Confirmar el snapshot del tipo de cambio en la proforma.
- [ ] Confirmar que un cambio posterior no recalcule proformas existentes.
- [ ] Verificar el símbolo `₲` para Paraguay.
- [ ] Verificar el símbolo `RD$` para República Dominicana.
- [ ] Verificar el símbolo `COL$` para Colombia.
- [ ] Verificar el símbolo `฿` para Panamá.

## 10. Autorizaciones

- [ ] Confirmar que una proforma USD quede en estado `NOT_REQUIRED`.
- [ ] Confirmar que una proforma local quede en estado `PENDING`.
- [ ] Bloquear la descarga final mientras esté pendiente.
- [ ] Bloquear el envío final mientras esté pendiente.
- [ ] Solicitar autorización a un gerente.
- [ ] Solicitar autorización a un supervisor.
- [ ] Impedir solicitudes pendientes duplicadas.
- [ ] Mostrar una notificación interna al autorizador.
- [ ] Aprobar utilizando el tipo de cambio general vigente.
- [ ] Aprobar utilizando un tipo de cambio especial.
- [ ] Confirmar que el tipo especial no modifique el tipo general.
- [ ] Rechazar una solicitud con un comentario.
- [ ] Verificar que el comentario de rechazo sea obligatorio.
- [ ] Mostrar la aprobación o el rechazo en la trazabilidad.
- [ ] Permitir la descarga cuando la proforma esté aprobada.
- [ ] Comprobar que solamente el autorizador asignado pueda decidir.

## 11. Disclaimers y observaciones

- [ ] Crear un disclaimer.
- [ ] Editar un disclaimer.
- [ ] Activar un disclaimer.
- [ ] Desactivar un disclaimer.
- [ ] Impedir contenido HTML ejecutable.
- [ ] Aplicar automáticamente los disclaimers activos.
- [ ] Guardar un snapshot de los disclaimers en cada proforma.
- [ ] Confirmar que editar un disclaimer no modifique proformas históricas.
- [ ] Confirmar que una nueva versión tome los disclaimers vigentes.
- [ ] Mostrar observaciones y disclaimers en el resumen.
- [ ] Mostrar observaciones y disclaimers en el enlace público.
- [ ] Mostrar observaciones y disclaimers en el PDF.

## 12. PDF

- [ ] Generar un PDF corto de una página.
- [ ] Generar un PDF multipágina con muchos productos.
- [ ] Verificar que las observaciones largas no sean cortadas.
- [ ] Verificar el logo.
- [ ] Verificar el encabezado.
- [ ] Verificar la tabla de productos.
- [ ] Verificar el panel de totales.
- [ ] Verificar el pie de página.
- [ ] Verificar la firma comercial.
- [ ] Mostrar el nombre del producto como descripción.
- [ ] No mostrar la referencia interna del producto.
- [ ] Verificar valores y símbolos según la moneda.
- [ ] Verificar la fecha de emisión.
- [ ] Verificar la fecha de vencimiento.
- [ ] Probar el fallback visual cuando falten assets.
- [ ] Bloquear el PDF de una proforma pendiente.
- [ ] Bloquear el PDF de una proforma rechazada.

## 13. Envío y enlace público

- [ ] Enviar la proforma por email al cliente.
- [ ] Reenviar la proforma por email.
- [ ] Confirmar el destinatario seleccionado.
- [ ] Registrar un envío exitoso.
- [ ] Registrar y mostrar errores SMTP.
- [ ] Generar un token público único.
- [ ] Abrir un enlace público vigente.
- [ ] Registrar la primera visualización.
- [ ] Descargar el PDF desde el enlace público.
- [ ] Registrar la primera descarga.
- [ ] Mostrar el estado vencido del enlace.
- [ ] Bloquear la descarga cuando el enlace esté vencido.
- [ ] Solicitar una cotización actualizada.
- [ ] Notificar la solicitud al ejecutivo.
- [ ] Revisar los filtros del log de envíos.

## 14. Estado comercial

- [ ] Confirmar el estado inicial `OPEN`.
- [ ] Cambiar el estado a `WON`.
- [ ] Cambiar el estado a `LOST`.
- [ ] Cambiar el estado a `CANCELLED`.
- [ ] Registrar el usuario que realizó el cambio.
- [ ] Registrar la fecha del cambio.
- [ ] Registrar un comentario.
- [ ] Confirmar que el estado comercial no altere la autorización.
- [ ] Aplicar correctamente la visibilidad según equipo y jerarquía.

## 15. Indicadores y dashboard

- [ ] Filtrar por rango de fechas.
- [ ] Filtrar por unidad país.
- [ ] Filtrar por ejecutivo.
- [ ] Filtrar por estado comercial.
- [ ] Filtrar por moneda.
- [ ] Verificar emitidos por país.
- [ ] Verificar ganados y cerrados por país.
- [ ] Verificar la evolución por país.
- [ ] Verificar la efectividad por ejecutivo.
- [ ] Verificar el resumen Latam.
- [ ] Verificar el detalle por país y ejecutivo.
- [ ] Verificar montos comparables en USD.
- [ ] Probar el dashboard con datos.
- [ ] Probar el dashboard sin datos.
- [ ] Verificar visibilidad según jerarquía y unidades.
- [ ] Verificar el layout móvil del dashboard.
- [ ] Verificar el layout móvil de los listados.

## 16. Backups y restauración

- [ ] Crear un backup manual.
- [ ] Descargar un backup.
- [ ] Verificar backups automáticos antes de operaciones críticas.
- [ ] Restaurar un backup válido.
- [ ] Rechazar archivos que no sean backups permitidos.
- [ ] Verificar los datos después de una restauración.
- [ ] Validar la política de retención de backups.

## 17. Preproducción y seguridad

- [ ] Verificar HTTPS.
- [ ] Verificar la redirección desde HTTP hacia HTTPS.
- [ ] Configurar `public/` como document root.
- [ ] Bloquear el acceso HTTP a `app/`.
- [ ] Bloquear el acceso HTTP a `storage/`.
- [ ] Bloquear el acceso HTTP a `docs/`.
- [ ] Bloquear el acceso HTTP a `scripts/`.
- [ ] Bloquear el acceso HTTP a `tests/`.
- [ ] Bloquear el acceso HTTP a `.env`.
- [ ] Bloquear el acceso HTTP a `.git`.
- [ ] Verificar que los errores no expongan información sensible.
- [ ] Verificar que los errores se registren en `storage/logs/app.log`.
- [ ] Healthcheck sin token: verificar HTTP 401.
- [ ] Healthcheck con token incorrecto: verificar HTTP 401.
- [ ] Healthcheck con token válido: verificar HTTP 200.
- [ ] Healthcheck: verificar `App OK`.
- [ ] Healthcheck: verificar `DB OK`.
- [ ] Healthcheck: verificar `Storage OK`.
- [ ] Healthcheck: verificar `Environment OK`.
- [ ] Ejecutar `SQLite integrity_check` y obtener `ok`.
- [ ] Ejecutar `foreign_key_check` y obtener cero resultados.
- [ ] Confirmar que las credenciales SMTP anteriores fueron rotadas.
- [ ] Configurar credenciales SMTP nuevas.
- [ ] Realizar un envío SMTP real autorizado.

## 18. Aceptación final

- [ ] Ejecutar el recorrido funcional completo en un navegador.
- [ ] Revisar que no existan errores en la consola del navegador.
- [ ] Revisar que no existan errores inesperados en los logs.
- [ ] Registrar los bugs encontrados.
- [ ] Registrar los bugs corregidos.
- [ ] Registrar los pendientes.
- [ ] Completar el reporte del piloto de preproducción.
- [ ] Definir si la versión está lista para demo interna.

## Resultado

**Responsable:**  

**Fecha de ejecución:**  

**Entorno y URL:**  

**Resultado general:**  

- [ ] Aprobado.
- [ ] Aprobado con observaciones.
- [ ] Rechazado.

**Observaciones:**

