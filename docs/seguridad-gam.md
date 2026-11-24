# Seguridad de Granja Milpa

El navegador y Capacitor cruzan la frontera pública por HTTPS hacia el proxy de borde, Nginx web y el gateway. Laravel controla la identidad, la sesión y el alcance por unidad productiva. PostgreSQL guarda los datos operativos y financieros, y Redis mantiene sesiones, caché y colas. Reverb comunica eventos mediante WebSocket. Los backups cruzan la frontera de almacenamiento del host. CI obtiene código y dependencias de repositorios externos, construye las imágenes y el flujo de release entrega el SHA a la VPS.

Los activos principales son las credenciales, sesiones, datos de clientes, saldos, movimientos, stock, adjuntos, código, imágenes y respaldos. El operador solo debe ver los datos habilitados para su identidad y unidad. La operación de la VPS debe controlar los secretos, los archivos de producción y el acceso a backups. La cuenta de GitHub Actions que analiza un PR solo necesita lectura del código.

| Riesgo | Prioridad inicial | Control y evidencia requerida |
| --- | --- | --- |
| R1 · Acceso entre unidades | 6 | Laravel aplica alcance y permisos. Revisar `ProductionUnitIsolationTest` y verificar la operación denegada sin mutación. |
| R2 · Reuso o pérdida de credenciales de sesión | 6 | Cookie HTTPS, CSRF, expiración, revocación y límites de login y PIN. Revisar `AuthenticationTest`, `SharedDeviceAuthenticationTest` y `SessionManagementTest`. Verificar cookies efectivas en el borde. |
| R3 · Alteración de saldos, stock o auditoría | 9 | Validar entradas e invariantes de idempotencia y concurrencia. Revisar `CustomerAccountTest`, `CustomerAccountConcurrencyTest` y `DeliveryEndpointTest`. |
| R4 · Código o dependencia vulnerable | 6 | Semgrep y Trivy con reportes por revisión. Falta comprobar el paso obligatorio antes del release y la identidad de la imagen desplegada. |
| R5 · Configuración de producción insegura | 6 | Validar el Compose renderizado, el proxy de borde y el transporte TLS. Falta comprobar el entorno real autorizado. |
| R6 · Filtración por logs, respaldo o dispositivo | 6 | Revisar ocultamiento de secretos, almacenamiento seguro nativo, segregación de datos offline y restauración de un respaldo ficticio. |

La prioridad multiplica impacto y probabilidad de 1 a 3. Registrar el riesgo residual después de comprobar cada control. Backend y frontend mantienen las pruebas de su aplicación. El responsable de release conserva evidencia de las imágenes y quien administra la VPS verifica el borde, los volúmenes y los respaldos.

Ver el [plan SSDLC](../plan-ssdlc-gam.md) para el orden de implementación y los criterios de cierre.
