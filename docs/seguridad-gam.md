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

## Línea base de dependencias

El análisis de los tres lockfiles detectó el aviso de severidad alta de `league/commonmark` 2.10.0. Se actualizó el lock de Composer a 2.10.8. La corrida local posterior de SAST y SCA terminó con código 0 y 27 hallazgos informativos según la política del plan. [Aviso de CommonMark](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8)

| Aviso alto exclusivo de desarrollo | Propietario del seguimiento | Verificación |
| --- | --- | --- |
| CVE-2026-109413 · `symfony/yaml` | Mantenimiento backend | Revisar una actualización compatible y repetir SCA con el lock de Composer. |
| CVE-2026-93749 · `source-map-js` | Mantenimiento frontend | Revisar la resolución transitiva de Angular y comprobar el lock npm. |
| CVE-2026-112942 · `vite` | Mantenimiento frontend | Revisar el actualizador de Angular y comprobar build y SCA con el lock resultante. |

Los responsables deben revisar estos hallazgos en el siguiente ciclo de mantenimiento. Si aparecen en el inventario de producción, se aplica el bloqueo de severidad alta.

## Verificación local del runtime

La suite completa del commit `ac475e3` construyó las imágenes, validó su identidad en el fixture y terminó con código 10, sin errores operativos. Las imágenes de web, gateway y Redis quedaron sin hallazgos bloqueantes. La imagen Debian de PHP tuvo 69 hallazgos bloqueantes, PostgreSQL 27 y Swagger UI uno. Conservar esa decisión del gate mientras se evalúan las correcciones.

ZAP 2.17.0 generó el reporte sobre las cinco solicitudes previstas. La alerta de cookie corresponde a `XSRF-TOKEN`, que el cliente debe poder leer. El comprobador HTTP confirmó `Secure`, `HttpOnly` y `SameSite=Lax` en la cookie de sesión. La alerta media de CSP requiere validar la política en staging con el navegador, Mapbox y Reverb.

El PostgreSQL parcheado usa `su-exec` para la transición de usuario del entrypoint oficial. La prueba local arrancó con un volumen inicializado por la imagen original de PostgreSQL 18.7 y conservó un registro sintético. El procedimiento de release debe construir `api`, `gateway`, `postgres` y `swagger-ui` con el mismo SHA o cargar los artefactos aprobados antes de recrearlos. Verificarlo en staging antes de adaptar la VPS del cliente.

Como alternativa a los paquetes Debian que Trivy señaló sin versión corregida, se construyó PHP 8.5.13 sobre Alpine 3.24. El escaneo del candidato reportó cero hallazgos bloqueantes. La imagen conserva UID/GID 33 para el almacenamiento persistente y sus herramientas PostgreSQL produjeron y restauraron un backup de prueba que conservó el dato sintético. Dos corridas de código concurrentes aprobaron la política de frescura de Trivy y conservaron los IDs de los contenedores de desarrollo.

## Evidencia de aceptación

- La revisión `00d2d9e` ejecutó localmente la suite completa con código 0. Se construyeron y analizaron seis imágenes. Las cinco comprobaciones HTTP pasaron y ZAP emitió el reporte. Dos corridas completas simultáneas conservaron los IDs de contenedores y los volúmenes del stack de desarrollo.
- Se simuló la indisponibilidad de la base de Trivy y se verificó un código 20, diagnóstico y limpieza del proyecto descartable. La prueba de política comprueba los códigos 0, 10 y 20.
- Los workflows de componente aprobaron y los artefactos descargados contienen únicamente `summary.json` y `summary.md`. [Backend](https://github.com/ProyectoGAM/GAM-Backend/actions/runs/45482039783) · [Frontend](https://github.com/ProyectoGAM/GAM-Frontend/actions/runs/45482042540)
- La prueba del grafo de release en un ref dedicado falló con código 10 y GitHub omitió los jobs de etiquetado y despliegue. [Prueba del gate](https://github.com/ProyectoGAM/Granja-Milpa/actions/runs/45481415178)
- Cosign verificó la firma del índice de Trivy 0.76.2 mediante el emisor de GitHub Actions. El índice firmado contiene el manifiesto amd64 que usa la suite. [Procedimiento del proveedor](https://trivy.dev/docs/latest/getting-started/signature-verification/)
- Se leyó y escribió un volumen sintético previamente inicializado con UID/GID 33 en Debian desde el runtime PHP Alpine. Se verificó una restauración de PostgreSQL con los clientes del nuevo runtime.
