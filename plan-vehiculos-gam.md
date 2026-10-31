# Plan de implementación: vehículos, uso, combustible y mantenimiento

Estado: propuesta de implementación basada en Notion y en el código actual. No implementa el módulo.

## 1. Fuentes y decisiones

Fuentes revisadas mediante el MCP de Notion:

- [16 — Vehículos y uso](https://app.notion.com/p/3cb032f1caff80c89e03e956bbde37b2): ficha del vehículo, conductor habitual, uso y kilometraje; vinculación con repartos y referencia histórica.
- [17 — Mantenimiento de vehículos](https://app.notion.com/p/3cb032f1caff8083b36af4262ab9702f): mantenimiento preventivo y correctivo por fecha y kilometraje; costos, taller e intervenciones siguientes.
- [18 — Repartos, rutas y entregas](https://app.notion.com/p/3cb032f1caff8059b855c4834b930650): vehículo visible al iniciar y en el seguimiento; GPS del dispositivo, sincronización durable e idempotencia.
- [NOTAS MEET](https://app.notion.com/p/3c1032f1caff805f9b53d7122f643647): lecturas manuales, gastos de combustible y reparaciones.
- [20 — Alertas y notificaciones](https://app.notion.com/p/3cb032f1caff8029bbb9e0bfbceaf17c): futuro motor central y avisos de mantenimiento.

Decisiones confirmadas por el usuario:

1. El repartidor elige directamente el vehículo al iniciar el reparto.
2. El kilometraje es opcional en inicio y cierre del reparto.
3. Administración puede registrar kilometraje manualmente en cualquier momento. No hay una frecuencia de lectura impuesta por la aplicación.
4. El alcance incluye vehículos, uso, combustible y mantenimiento completo.
5. Cada reparto conserva un único vehículo. Ante una avería, el usuario cierra el reparto y luego inicia otro con el vehículo que elija; no hay cambios automáticos ni sustituciones dentro del mismo reparto.
6. Si quedan huevos al cerrar por avería, regresan físicamente a una UP y se cargan nuevamente al abrir el siguiente reparto, siguiendo el inventario existente.
7. Un service próximo o vencido genera advertencias. Solo estar fuera de servicio —además de no estar disponible por otro uso— bloquea nuevas salidas de un vehículo activo. Un vehículo retirado tampoco puede utilizarse.

## 2. Situación actual y puntos de integración

- No hay catálogo persistente de vehículos ni pantallas de gestión de flota.
- `LocalDeliveryVehicleReference` devuelve un vehículo demo únicamente en local/testing. En producción falla si se necesita ese reemplazo; el campo de texto `vehicle_reference` permite aportar una referencia sin validar un vehículo real.
- `StartDeliveryAction`, `StartDeliveryRequest` y los DTO de repartos tienen el punto de integración para elegir y validar un vehículo.
- `CloseDeliveryAction` ya concentra devolución de stock, cierre y resumen financiero. El cierre de uso del vehículo debe formar parte de esa misma transacción.
- La app tiene un outbox IndexedDB por usuario. Se debe extender su contrato y migrar los pendientes existentes.
- El panel de repartos ya muestra la referencia del vehículo y una cronología. Debe pasar a datos reales e historial de uso.
- Usuarios y permisos están centralizados; no se debe crear otro catálogo de conductores ni un nuevo rol obligatorio.
- Reportes tiene `ReportSourceRegistry`, exportaciones y filtros reutilizables.
- El mantenimiento existente en `FarmStructure` pertenece a galpones. Se pueden seguir sus convenciones de auditoría, dinero, versiones y correcciones, pero las entidades de vehículos tendrán su propio módulo.
- No se encontró una implementación funcional del motor central de alertas. La navegación actual contiene entradas que no equivalen a un servicio operativo.
- Media Library ya está declarada como dependencia y existe almacenamiento privado. No se encontró un componente de documentos de flota listo para reutilizar.

Archivos principales afectados durante la futura implementación:

- Backend: `app/{Models,Enums,Actions,Queries,Policies,Http/Requests,Http/Resources,Http/Controllers}/Vehicles`, `app/Services/Vehicles`, rutas API, permisos, migraciones y contratos OpenAPI.
- Integración: Actions/Requests/Resources de Deliveries, `LocalDeliveryVehicleReference`, proveedor de reportes y permisos de identidad.
- Frontend: nuevo `features/vehicles`, navegación/rutas administrativas, `core/auth/access-policy`, pantallas y contratos de Deliveries y su outbox.

## 3. Reglas de negocio

### Ficha, estados y disponibilidad

- Vehículos globales de la empresa, sin asignación restrictiva a una UP. Cada uso puede registrar contexto de una UP si corresponde.
- Matrícula normalizada y única; marca, modelo, año, fecha de alta, capacidad de carga, estado, notas y documentos.
- Capacidad expresada en kg, con unidad visible. Será informativa: el stock actual se mide en huevos y presentaciones, sin un peso confiable que permita validar automáticamente la carga contra kg.
- Conductor habitual opcional e informativo, vinculado a un usuario activo habilitado para conducir. Nunca limita la selección del repartidor ni crea una reserva automática.
- Estados propuestos: activo, fuera de servicio y retirado. Registrar motivo y responsable de las transiciones.
- Disponibilidad derivada: disponible, en uso o no disponible. No mantener un booleano editable que pueda contradecir un uso activo.
- No borrar físicamente vehículos referenciados. Los cambios de matrícula, nombre, conductor o taller no reescriben el historial.
- Vehículo en uso no puede retirarse hasta cerrar el uso. Una avería puede marcarlo fuera de servicio para impedir nuevas salidas, conservando la posibilidad de finalizar su uso actual.

### Repartos y usos

- Selección explícita de un vehículo real disponible antes del PIN de inicio. También se aplica a un reparto que sale sin carga inicial.
- Un vehículo admite un solo uso activo, sea reparto u otro uso de la empresa. Mantener la regla existente de un reparto activo por repartidor.
- La selección en pantalla no reserva por sí misma: el servidor comprueba y reserva al confirmar el inicio.
- Permitir uso no asociado a reparto: traslado, diligencia, visita al taller u otro motivo; con conductor/responsable, inicio y cierre. Así el kilometraje no depende exclusivamente de las entregas.
- Iniciar sin carga continúa siendo un reparto normal: puede cobrar, agregar carga después y entregar cuando tenga stock.
- Cierre libera el vehículo dentro de la transacción de cierre del reparto. Si falla devolución, dinero o validación, no liberar parcialmente el vehículo.
- Ante una avería, permitir registrar motivo/observaciones y cerrar por acción explícita del usuario. Los sobrantes vuelven a una UP mediante la devolución actual. El usuario inicia otro reparto, elige otro vehículo y realiza una nueva carga con su descuento de stock correspondiente.
- No crear un endpoint de sustitución de vehículo en un reparto activo, ni transferencias directas de stock entre camionetas. Ambos repartos conservan sus propios cobros, entregas, snapshots y duración registrada.

### Kilometraje

- Campos opcionales en reparto; no agregar pasos obligatorios para completar lecturas.
- Acción administrativa “Registrar kilometraje” disponible en cualquier momento. Registrar valor del tablero, fecha/hora de lectura, fecha/hora de recepción, responsable y observación.
- El vehículo puede tener kilometraje desconocido. Mostrar “Sin lectura registrada”, nunca asumir cero.
- Calcular kilómetros de un uso solo si existen lecturas de apertura y cierre válidas; si falta alguna, distancia desconocida. Una lectura administrativa posterior no completa artificialmente repartos anteriores.
- Lecturas ordinarias deben respetar el orden cronológico del contador. Registros atrasados validan las lecturas vecinas; nunca bajar el último valor por sincronizar un evento viejo.
- Correcciones administrativas requieren motivo y versión; conservan la lectura original, la rectificación y la auditoría. Cambio/reinicio de tablero se trata como un evento explícito, sin volver negativos los kilómetros recorridos.
- GPS permanece asociado al dispositivo y al reparto. No convertir distancia estimada por GPS en kilometraje oficial del tablero.

### Combustible y gastos

- Registrar vehículo, responsable, fecha/hora, litros, tipo de combustible, importe total en UYU, estación/proveedor opcional, comprobante y lectura opcional.
- Asociar al uso/reparto cuando exista; permitir registros administrativos independientes.
- Dinero con el objeto `Money` y decimales exactos existentes; litros con precisión decimal definida en el contrato, sin cálculos monetarios con float.
- Los gastos no reducen automáticamente las cobranzas ni alteran cuentas de clientes. Conciliación o reintegros requieren una decisión funcional adicional.
- Costos de reparación se registran en la intervención de mantenimiento; el resumen de gastos los incorpora una sola vez, sin duplicar el mismo importe en un gasto genérico.
- Litros/100 km solo cuando haya un intervalo y lecturas confiables y un método de medición declarado; no inferir consumo real de una carga parcial aislada.

### Mantenimiento completo

- Plan preventivo por tarea y vehículo: aceite, filtros, cubiertas, frenos, service u otra tarea configurable.
- Intervalos por días/meses y kilómetros. Si una tarea tiene varios límites, vence al alcanzarse el primero.
- Estado de vencimiento calculado: al día, próximo, vencido o sin datos suficientes. Mostrar fecha y antigüedad de la última lectura; un kilometraje desactualizado no garantiza que el service esté al día.
- Orden/intervención con tipo preventivo/correctivo, tareas, taller, responsable, fechas, lecturas opcionales, observaciones, costo y comprobantes.
- Ciclo de la intervención: planificada, en curso, completada o anulada. Indicar si inmoviliza el vehículo; una intervención efectivamente inmovilizante impide nuevas salidas.
- Los vencimientos de planes y documentos se muestran como advertencias y nunca cambian solos el vehículo a fuera de servicio. Administración decide cuándo inmovilizarlo; no impedir el cierre de un reparto por una advertencia ni por una avería.
- Validar disponibilidad antes de comenzar una intervención que inmoviliza: primero se debe finalizar el uso activo. Planificar un service futuro no reserva ni inmoviliza automáticamente el vehículo.
- Una intervención puede realizar varias tareas preventivas y una reparación. Actualizar exclusivamente las bases de las tareas realizadas, no todos los planes del vehículo.
- Actualizar próximos vencimientos al completar, nunca al agendar o anular. Si falta lectura para recalcular un límite por kilometraje, solicitar una base o próximo valor explícito al administrador y mostrar que faltan datos; no inventar una lectura.
- Permitir una reparación puntual sin plan periódico y una próxima intervención excepcional con fecha o lectura objetivo.
- Correcciones/anulaciones auditadas con motivo, control de versión y recálculo de vencimientos afectados.
- Recordatorios visibles dentro de Vehículos desde la primera versión. Integrar posteriormente su publicación con M20 mediante eventos/contrato acotado; no crear un segundo motor global de notificaciones ni presentar push como disponible cuando no lo está.

## 4. Persistencia propuesta

| Entidad | Responsabilidad |
| --- | --- |
| `vehicles` | Identidad, ficha, estado, capacidad, conductor habitual opcional, fecha de alta, versión y proyección de últimas lecturas válidas. |
| `vehicle_usages` | Uso por un conductor, motivo, reparto opcional, fechas de inicio/fin, lecturas opcionales y snapshot del vehículo/conductor. Un reparto tiene un único registro de uso. |
| `vehicle_meter_readings` | Lecturas de kilometraje con origen, momento de lectura/recepción, autor y relación con uso, combustible o mantenimiento; rectificaciones conservadas. |
| `vehicle_documents` | Tipo, título, vigencia opcional y archivo privado; versiones y adjuntos históricos. |
| `vehicle_fuel_entries` | Cargas de combustible, litros, importe, comprobante y contexto opcional de uso. |
| `vehicle_maintenance_plans` | Tareas preventivas e intervalos, base y siguientes límites. |
| `vehicle_maintenances` | Intervención, taller, ciclo de estado, inmovilización, costo total y comprobantes. |
| `vehicle_maintenance_items` | Tareas realizadas en cada intervención y vínculo opcional al plan preventivo. Evita duplicar costos y actualizar tareas no realizadas. |

Identificadores públicos ULID y claves internas según los módulos actuales. Relaciones con usuarios preservan el historial de cuentas desactivadas. Taller/proveedor puede vincularse al módulo existente sin exigir dar de alta un proveedor para cada texto de taller.

Repartos agrega `vehicle_id` y snapshot del vehículo seleccionado para nuevos registros. `vehicle_reference` se conserva como dato legado de solo lectura. La relación no cambia durante el reparto; el registro de uso concentra apertura/cierre y lecturas opcionales.

Índices y restricciones: matrícula normalizada única; un uso activo por vehículo; `delivery_id` único cuando existe en usos; claves idempotentes únicas; índices por vehículo/fecha, estado y próximos vencimientos. Las restricciones de concurrencia se prueban sobre PostgreSQL.

## 5. API, transacciones y permisos

Mantener el stack y la estructura existentes: Laravel 13 según Composer, Eloquent y PostgreSQL; Angular 22 standalone y zoneless, Ionic 9, Capacitor 8, TypeScript y RxJS. Al implementar, confirmar las versiones efectivamente instaladas antes de usar APIs de paquetes.

Frontend organizado en `features/vehicles/{pages,components,services,interfaces,types}` y `vehicles.routes.ts`. Estado local con signals, derivaciones con computed, formularios reactivos y peticiones cancelables con RxJS. Servicios del feature consumen rutas relativas mediante `ApiClient`; los wrappers nativos permanecen en `core/native`. Reutilizar selector buscable, filtros y foco de diálogos existentes. No introducir una nueva capa de repositorios ni un gestor global de estado.

Endpoints públicos en español, siguiendo el contrato actual. Propuesta de recursos:

| Recurso | Operaciones |
| --- | --- |
| `/vehiculos` | Listar con filtros/paginación, crear y editar ficha. |
| `/vehiculos/disponibles` | Selección operativa acotada; mostrar matrícula, identificación y disponibilidad. |
| `/vehiculos/{id}/estado` | Transiciones autorizadas con motivo. |
| `/vehiculos/{id}/lecturas` | Historial y registro manual; rectificación mediante comando específico. |
| `/vehiculos/{id}/usos` | Historial e inicio/cierre de usos externos al reparto. |
| `/vehiculos/{id}/combustible` | Cargas y correcciones/anulaciones auditadas. |
| `/vehiculos/{id}/documentos` | Metadatos, carga y descarga autorizada. |
| `/vehiculos/{id}/planes-mantenimiento` | Tareas e intervalos preventivos. |
| `/vehiculos/{id}/mantenimientos` | Intervenciones y comandos para iniciar, completar o anular. |

- Controllers delgados; FormRequests para autorización y validación; Actions para comandos; Queries paginadas; Resources para respuestas; enums para estados.
- Reutilizar `AuditRecorder` dentro de la misma transacción de cada cambio importante, con snapshots permitidos, actor, motivo, cambios y referencia de operación.
- Servicios de Vehículos exponen disponibilidad y apertura/cierre de uso para que las Actions de Repartos los compongan; evitar repetir reglas de flota dentro de Deliveries.
- Inicio atómico: autenticar usuario/PIN, bloquear recursos en orden uniforme, validar vehículo, registrar uso, iniciar reparto y descontar carga inicial si existe. Cualquier fallo revierte todo.
- Ante una avería, usar los comandos existentes de cierre y nuevo inicio, cada uno con su propia transacción e idempotencia y por acción del usuario. La devolución y recarga reflejan el regreso real de los huevos a una UP.
- Respuestas idempotentes antes de repetir efectos; mismo comando no duplica combustible, lecturas, mantenimiento, usos ni movimientos de inventario.
- Control optimista de versiones para formularios administrativos; responder conflicto comprensible si otro usuario cambió la ficha.
- Permisos propuestos: `vehicles.view`, `vehicles.manage`, `vehicles.use`, `vehicles.readings.manage`, `vehicles.fuel.view/manage`, `vehicles.maintenance.view/manage`, `vehicles.documents.view/manage` y permisos explícitos de rectificación cuando corresponda. Ajustar granularidad a los permisos existentes al implementar.
- El repartidor consulta vehículos disponibles y opera únicamente su uso/reparto. No obtiene acceso al CRUD, costos generales o documentos por poder conducir. Autorización siempre en backend.
- Archivos privados con validación de tipo/tamaño y descarga autorizada; usar capacidades ya instaladas. No convertir documentación en enlaces públicos permanentes.
- OpenAPI actualizado junto con cada etapa. Sin nuevas dependencias salvo necesidad concreta y aprobación correspondiente.

## 6. Experiencia y estética

### Panel administrativo

- Grupo global “Vehículos” dentro del shell actual, junto a los módulos operativos. Rutas lazy; permisos en navegación, guards y API.
- Listado con búsqueda por matrícula/marca/modelo, filtros por estado/disponibilidad y acceso a “Nuevo vehículo”.
- Mantener la composición de Clientes/Usuarios: título, acción principal, búsqueda, diálogo de filtros y tarjetas adaptables; en escritorio aprovechar espacio sin duplicar la lógica de pantalla.
- Ficha con resumen (matrícula, estado, uso actual, último kilometraje y fecha), conductor habitual y capacidad.
- Secciones: General, Uso y lecturas, Combustible/gastos, Mantenimiento y Documentos. Cargar historiales paginados bajo demanda.
- “Registrar kilometraje” a mano del administrador sin pedir crear un reparto ni configurar periodicidad.
- Cronología con lecturas, usos/repartos, combustible, intervenciones, cambios de estado y rectificaciones; enlaces a sus registros. Distinguir fecha de acción y recepción tardía.
- Mantenimiento: vista de próximos/vencidos, planes editables y formulario de intervención con acciones explícitas de inicio/cierre/anulación.
- Tokens `--gam-color-*`, superficies, tipografía y botones actuales; claro/oscuro, foco visible, etiquetas y estados con texto además de color.

### App de repartidor

- Antes del PIN, selector sencillo de vehículo disponible con matrícula destacada y marca/modelo. Sin asignación obligatoria por conductor habitual.
- Kilometraje de salida opcional, con última lectura y su fecha como referencia. Omitirlo no bloquea ni altera el flujo sin carga inicial.
- Vehículo visible durante el reparto y en el resumen; acción de combustible si el permiso lo permite.
- Al cerrar, kilometraje opcional, resumen del uso y observaciones. Conservar las confirmaciones y la validación de stock/dinero actuales.
- Si no hay vehículos disponibles, mostrar el motivo y permitir actualizar; ante conflicto concurrente, conservar datos de carga y pedir elegir otro.
- Ante avería, el cierre permite dejar el motivo y devolver el stock. Una vez cerrado, se presenta el flujo normal de nuevo reparto, con selección directa de vehículo; no encadenar cierres, cargas ni nuevos inicios automáticamente.
- Controles táctiles grandes, teclado numérico, safe areas y compatibilidad con el flujo Capacitor/Android 12 y tablet.

## 7. Offline e historial

- Mantener inicio y cierre con validación online/PIN como en la aplicación actual. No permitir reservar un vehículo usando solo una lista cacheada.
- Extender el outbox existente para combustible y lecturas asociadas al reparto; no crear una cola global paralela. Cambios administrativos de ficha y mantenimiento pueden ser online en la primera entrega.
- Migrar versión de IndexedDB/contratos sin perder pendientes, respetando aislamiento por actor e idempotencia.
- Toda operación permanece asociada al identificador original de reparto y actor. Pendientes de un reparto anterior no se reasignan al nuevo reparto ni a su vehículo.
- Mantener la revisión/sincronización de operaciones pendientes antes de confirmar el cierre, para que una lectura, un combustible o un movimiento de dinero no queden omitidos del resumen. No descartar pendientes para liberar el vehículo.
- Una pérdida de red durante una confirmación no libera el vehículo por suposición: consultar/reintentar con la misma identidad de comando.
- GPS mantiene su origen en el dispositivo: finalizar el reparto detiene su seguimiento, y el nuevo reparto inicia su propio recorrido mediante la acción del usuario.

## 8. Etapas de ejecución y aceptación

1. **Contrato y base de flota.** Migraciones aditivas, estados, modelo de lecturas, permisos, OpenAPI y ficha/API. Aceptación: alta/edición/baja lógica, matrícula única y lectura manual auditada en cualquier momento.
2. **Panel administrativo básico.** Navegación, listado, filtros, ficha, formularios y documentos privados. Aceptación: permisos reales, claro/oscuro, teléfono/escritorio, vacíos y errores.
3. **Uso y conexión con repartos.** Selección directa, reserva exclusiva, snapshots, inicio/cierre transaccionales, lecturas opcionales, cronología y retiro del servicio demo. Aceptación: dos usuarios no reservan el mismo vehículo; iniciar sin carga y cargar/entregar después funciona; cerrar sin lectura es válido.
4. **Sincronización y cierre por avería.** Contexto estable por reparto, pendientes durables y devolución a UP. Aceptación: usuario cierra y luego inicia otro; devolución y recarga quedan contabilizadas una vez cada una; no se reasignan cobros ni entregas anteriores.
5. **Combustible y uso fuera de reparto.** Registros manuales/móviles, comprobantes, outbox y resumen de gastos. Aceptación: idempotencia, uso exclusivo también fuera de repartos y ausencia de efectos sobre deuda/cobranzas de clientes.
6. **Mantenimiento preventivo y correctivo.** Planes, intervenciones, talleres, inmovilización, costos, lecturas y próximos límites. Aceptación: vence por el primer criterio aplicable; completar varias tareas actualiza solo esas tareas; anulaciones no reinician ciclos; faltantes de datos quedan visibles; vencimiento advierte y fuera de servicio bloquea nuevas salidas.
7. **Reportes y recordatorios.** Fuentes de uso/km, combustible/gastos y mantenimiento registradas en `ReportSourceRegistry`; filtros por vehículo, conductor y fechas. Indicadores de vencimientos dentro del módulo y contrato de integración con M20. Aceptación: exportaciones consistentes y sin gastos/alertas duplicados.
8. **Migración operativa y validación integral.** Adaptar histórico, pendientes y casos de concurrencia, y documentar despliegue/recuperación. Habilitar flota real antes de exigir el nuevo selector en producción.

## 9. Migración y validación

- Preservar los cambios actuales de repartos sin carga y cronología presentes en el workspace; no reemplazar ni revertir su lógica al integrar Vehículos.
- Agregar relaciones de vehículo como nullable para el histórico; exigir selección solo para nuevos inicios una vez desplegado el catálogo.
- Mantener referencias antiguas como legado; no crear vehículos reales a partir de `demo-vehicle-001` ni adjudicar kilómetros desconocidos.
- Resolver explícitamente repartos activos al desplegar: vinculación administrativa auditada a un vehículo real o compatibilidad de cierre legado; no dejarlos imposibles de cerrar ni completar datos ficticios.
- Pendientes offline anteriores a la nueva versión conservan su identidad y pueden sincronizar con el esquema de transición documentado.
- Registrar algunos vehículos reales antes de retirar el fallback demo y activar el requisito en el móvil. Eliminar la posibilidad de iniciar nuevos repartos mediante texto libre no verificado.
- Tests backend de autorización, transacciones, exclusividad real con dos conexiones PostgreSQL, idempotencia, contadores/rectificaciones, costos exactos, documentos privados y todos los ciclos de mantenimiento.
- Tests frontend de selector y conflictos sin perder carga, omisión de kilometraje, lectura manual libre, formularios, guards, combustible offline y recuperación de pendientes.
- Casos integrales: salida sin huevos → cobro → carga posterior → entrega → devolución/cierre; cierre sin kilometraje; avería → devolución a UP → cierre explícito → nuevo reparto y recarga; pendientes previos nunca pasan al nuevo vehículo; service por fecha con odómetro desconocido; lectura administrativa atrasada; service vencido permite salir con advertencia, vehículo fuera de servicio no permite iniciar.
- Ejecutar Pint, validación PHP/análisis configurado y PHPUnit focalizado. Frontend: lint, tests y build dentro de Docker según sus instrucciones; validación visual en claro/oscuro y teléfono/tablet/escritorio, más recorrido en Android 12 cuando exista dispositivo.
- En esta tarea solo se redacta el plan: no se ejecutan migraciones, tests ni cambios funcionales del módulo.
