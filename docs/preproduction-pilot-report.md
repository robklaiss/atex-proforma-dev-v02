# Reporte de despliegue y piloto de pre-producción

Fecha:

Responsable:

Commit desplegado:

## Infraestructura

1. URL de pre-producción:
2. Servidor/hosting:
3. Versión PHP:
4. Document root confirmado:
5. HTTPS activo:
6. `.env` creado en servidor:

## Validación técnica

7. Resultado de `php scripts/validate_preproduction.php`:
8. Healthcheck sin token (esperado 401):
9. Healthcheck con token (esperado 200):
10. SQLite `integrity_check`:
11. SQLite `foreign_key_check`:
12. Ubicación del backup inicial:
13. Restauración del backup verificada:
14. SMTP rotado/configurado:
15. Envío SMTP de prueba:
16. Rutas sensibles verificadas:
17. Errores de consola/logs:

## Prueba funcional

- [ ] Login.
- [ ] Roles y permisos.
- [ ] Proforma USD.
- [ ] Proforma local pendiente.
- [ ] Solicitud de autorización.
- [ ] Aprobación con cambio general.
- [ ] Aprobación con cambio especial.
- [ ] Rechazo con comentario.
- [ ] Edición y nueva versión.
- [ ] PDF.
- [ ] PDF multipágina.
- [ ] Link público vigente.
- [ ] Link público vencido.
- [ ] Disclaimer y snapshot.
- [ ] Estado comercial WON.
- [ ] Estado comercial LOST.
- [ ] Dashboard Latam.
- [ ] Dashboard por ejecutivo.

## Incidencias

Bugs encontrados:

Bugs corregidos:

Pendientes:

## Decisión

- [ ] Listo para demo interna.
- [ ] No listo.

Justificación:
