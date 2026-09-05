# Plan de implementación del multi-login de GAM

Estado: plan de trabajo; no se implementaron cambios en los repositorios.
Fecha del análisis: 2026-09-05.
Destinatario: agente de implementación de IA, con tareas acotadas y verificables.

## 1. Instrucción de ejecución

Implementar este plan sobre los repositorios GAM-Backend y GAM-Frontend. Antes de modificar archivos, releer sus AGENTS.md y comparar el HEAD de trabajo con los commits analizados abajo. Si hay cambios posteriores, adaptar los puntos de integración a su implementación real, conservando los requisitos y las invariantes de este documento.

Trabajar por tarea y ejecutar sus pruebas antes de avanzar. Entregar por cada tarea: archivos cambiados, comportamiento implementado, pruebas ejecutadas con resultado y limitaciones pendientes. No dar por terminada una tarea con código sin integrar, pruebas deshabilitadas o un TODO en una invariante de seguridad.

Este documento distingue hechos observados de decisiones propuestas. Los nombres de archivos nuevos son propuestas; los archivos identificados como existentes se comprobaron en los repositorios. No crear todas las carpetas o clases de antemano: mantener las convenciones y crear únicamente las que se utilicen.

### Requisitos del usuario que quedan fijados

- La pantalla inicial habitual muestra usuario y contraseña en web y mobile. El identificador actual del backend es el correo electrónico; conservar `correo_electronico` y etiquetarlo como «Correo electrónico». No introducir un campo username adicional sin necesidad.
- Un administrador genera un código temporal que permite vincular una instalación como dispositivo compartido. La instalación cambia entonces a selección de empleado y PIN.
- El dispositivo guarda localmente qué pantalla corresponde abrir. Esa preferencia nunca demuestra autorización.
- El servidor comprueba la credencial y el estado del dispositivo al entregar la lista de empleados, al aceptar un PIN y durante las operaciones de una sesión compartida.
- Los PIN tienen exactamente 4 dígitos. Se guardan hasheados, en PostgreSQL, junto a las contraseñas existentes, en columnas separadas de la misma tabla `users`.
- Las sesiones personales y la vinculación del dispositivo deben durar mucho. Las sesiones de empleados en una tablet compartida tienen inactividad y duración máxima propias.
- Incluir buenas prácticas de desarrollo, pruebas, despliegue y una tarea explícita para actualizar los Markdown afectados.

Se conserva la restricción del pedido original: la web personal es administrativa; el móvil personal admite cuentas autorizadas de administradores y repartidores; los empleados usan el dispositivo compartido. La web de una instalación vinculada puede mostrar el acceso operativo con PIN. «Login normal en todos los dispositivos» define la pantalla predeterminada, no concede a todos los usuarios acceso por todos los canales.

No es posible demostrar que una petición vino de una app nativa porque traiga un Origin, User-Agent, device_name o campo platform determinado. El backend protege identidades, credenciales, contextos y permisos; no prometer una prohibición absoluta de usar un cliente HTTP alternativo con credenciales válidas.

## 2. Base analizada y hallazgos

### 2.1 Versiones y alcance

| Repositorio | Commit analizado | Versiones del lockfile |
| --- | --- | --- |
| [GAM-Backend](https://github.com/ProyectoGAM/GAM-Backend/tree/26a78324fad69f9f513df460fee642a66d5fa920) | 26a78324fad69f9f513df460fee642a66d5fa920 | Laravel 13.29.0; Sanctum 4.3.3; Spatie Permission 8.3.0; Activitylog 5.1.0; PHPUnit 12.5.34; Pint 1.30.5; Larastan 3.10.0 |
| [GAM-Frontend](https://github.com/ProyectoGAM/GAM-Frontend/tree/32558a3143384a753d30412c4249d823509224b1) | 32558a3143384a753d30412c4249d823509224b1 | Angular 22.1.5; Ionic Angular 9.0.2; Capacitor 8.5.1; TypeScript 6.0.3; Vitest 4.1.11 |

El Dockerfile del backend usa PHP 8.5. El frontend fue comprobado con Node 24.19.0. Las versiones anteriores son las resueltas, no solamente los rangos de composer.json/package.json.

### 2.2 Backend: implementación actual

| Archivo existente | Hecho observado | Consecuencia para el plan |
| --- | --- | --- |
| `routes/api.php` | API bajo `/api/v1`; registro y login públicos; resto de operaciones bajo `auth:sanctum` | Conservar versionado y centralizar la nueva validación de contexto |
| `app/Modules/IdentityAndAccess/Http/Controllers/AuthController.php` | Registro, login, logout y perfil; registro también emite token | Cerrar el registro público y separar transportes de autenticación |
| `.../Application/Actions/LoginUserAction.php` | Busca por email y verifica Hash::check; no crea sesión web | Reutilizar la verificación de contraseña, agregar política de acceso y auditoría |
| `.../Application/Actions/IssueAccessTokenAction.php` | Emite PAT con `api:access` y calcula expires_at desde sanctum.expiration | Eliminar la dependencia entre todos los tipos de credencial y un único TTL |
| `config/sanctum.php`, `.env.example` | SANCTUM_TOKEN_EXPIRATION=10080: 7 días; se convierte a entero | Evitar que ese límite siga invalidando tokens nuevos de 90 días |
| `.../Http/Responders/LoginResponder.php` | Todo login exitoso devuelve Bearer; fallo devuelve solo message | Agregar respuesta web sin token y códigos de error estables |
| `.../Http/Resources/UserResource.php` | Expone identidad, correo, roles y permisos actuales | No reutilizarlo para la lista previa al PIN |
| `app/Models/User.php` | HasApiTokens, HasRoles, SoftDeletes y password con cast hashed | Mantener el modelo; agregar campos PIN ocultos y controlados |
| `app/Providers/AppServiceProvider.php` | Rate limit 5/min por combinación email+IP | Agregar límites por cuenta independientes de IP y límites específicos de PIN/vinculación |
| `bootstrap/app.php` | No llama statefulApi(); registra aliases de abilities, pero no restringe globalmente por tipo de sesión | Agregar soporte web y una barrera de contexto que no dependa de los aliases |
| `bootstrap/app.php` | El renderer genérico de HttpException crea una nueva respuesta sin copiar sus headers | Preservar Retry-After al devolver 429 |
| `config/cors.php` | Solo localhost:4200; supports_credentials=false; paths api/* | Adaptar cookies, CSRF y orígenes explícitos |
| `config/session.php`, `compose.dev.yaml` | Existe configuración de sesiones; Compose usa Redis; lifetime predeterminado 120 minutos | Una configuración de sesión existente no significa que el login actual la utilice |
| `database/seeders/AdminUserSeeder.php` | Siembra rol admin y permisos; vuelve a asignar contraseña y roles al ejecutarse | Separar sincronización de permisos del aprovisionamiento inicial |
| `.../Application/Actions/RegisterUserAction.php`, `LogoutUserAction.php` | Auditoría transaccional en registro/logout; login no registra evento | Reutilizar AuditRecorder y completar eventos de seguridad |
| `contracts/openapi/authentication.yaml` | Contrato Bearer; respuesta de login plana y perfil envuelto en data | Evolucionar explícitamente y guiar al frontend por contrato |
| `tests/Feature/IdentityAndAccess/` | Cubre registro, contraseña, perfil, logout, expiración, SoftDeletes y permisos | Extender pruebas reales, actualizar las expectativas de registro que cambiarán |

Referencias de código: [rutas](https://github.com/ProyectoGAM/GAM-Backend/blob/26a78324fad69f9f513df460fee642a66d5fa920/routes/api.php), [IdentityAndAccess](https://github.com/ProyectoGAM/GAM-Backend/tree/26a78324fad69f9f513df460fee642a66d5fa920/app/Modules/IdentityAndAccess), [configuración Sanctum](https://github.com/ProyectoGAM/GAM-Backend/blob/26a78324fad69f9f513df460fee642a66d5fa920/config/sanctum.php), [contrato actual](https://github.com/ProyectoGAM/GAM-Backend/blob/26a78324fad69f9f513df460fee642a66d5fa920/contracts/openapi/authentication.yaml).

### 2.3 Frontend: implementación actual

- Existe un ApiClient genérico en `src/app/core/api/api-client.ts`, con headers, params, HttpContext, withCredentials y baseUrl opcional. Conservarlo.
- La composición real está en `src/main.ts`. Los documentos sugieren app.config.ts, pero ese archivo no existe: no asumir que está implementado.
- `src/app/app.routes.ts` solo contiene home y su redirect.
- No existen aún páginas de autenticación, almacenamiento de sesión, guards, interceptores de autenticación ni un estado de arranque autenticado.
- Las URLs de environments terminan en `/api`, mientras el contrato del backend está en `/api/v1`. Fijar una sola convención antes de implementar los servicios. Utiliza '/api/v1'
- Las 2 pruebas actuales solo comprueban creación de componentes; no cubren ApiClient ni seguridad.
- No hay plataformas android/ios versionadas ni dependencias @capacitor/android/@capacitor/ios en package.json; deben prepararse para la validación nativa.
- No hay workflows .github en ninguno de los dos snapshots.

Referencias: [ApiClient](https://github.com/ProyectoGAM/GAM-Frontend/blob/32558a3143384a753d30412c4249d823509224b1/src/app/core/api/api-client.ts), [main.ts](https://github.com/ProyectoGAM/GAM-Frontend/blob/32558a3143384a753d30412c4249d823509224b1/src/main.ts), [AGENTS.md](https://github.com/ProyectoGAM/GAM-Frontend/blob/32558a3143384a753d30412c4249d823509224b1/AGENTS.md).

### 2.4 Verificación ejecutada para este plan

| Comprobación | Resultado |
| --- | --- |
| npm ci --ignore-scripts --no-audit --no-fund | Correcto, con dependencias del lockfile |
| npm run lint | Correcto |
| npm run test:ci | 2 archivos, 2 pruebas correctas |
| npm run build | Correcto |
| npm audit --omit=dev | 0 vulnerabilidades reportadas en esa ejecución |
| PHP / PHPUnit / Artisan / PostgreSQL del backend | No ejecutados: el entorno no tiene PHP, Composer, Docker, Podman ni PostgreSQL |
| Android / iOS | No compilados ni probados en dispositivo |

No atribuir cobertura de autenticación a las 2 pruebas existentes del frontend. No presentar el análisis estático del backend como validación de ejecución.

## 3. Diseño propuesto

### 3.1 Autenticación, autorización y transportes

Continuar con Sanctum. No agregar JWT, Passport, un proveedor SaaS ni un sistema de refresh tokens en este alcance.

| Contexto | Credencial del usuario | Credencial de dispositivo compartido |
| --- | --- | --- |
| Web personal | Sesión Laravel en cookie HttpOnly | No |
| App personal | PAT Sanctum persistido de forma segura | No |
| Web compartida | Sesión Laravel breve del empleado, sin recuerdo automático | Cookie HttpOnly independiente |
| App compartida | PAT del empleado solo en memoria | Secreto persistido en almacenamiento nativo seguro |

Los transportes se implementan con endpoints explícitos para web y para Bearer. La selección del frontend no concede permisos. El servidor aplica la política correspondiente al endpoint y luego comprueba el contexto en cada request.

Usar el soporte documentado de [Sanctum para SPA y mobile](https://laravel.com/framework/docs/13.x/sanctum). La adopción de cookies web es un cambio deliberado frente al código y a los MD actuales, que hoy dicen «Bearer en todos los clientes».

### 3.2 Duraciones iniciales configurables

| Elemento | Valor inicial | Regla |
| --- | --- | --- |
| Token personal nativo | 90 días absolutos | Se conserva al cerrar la app; al vencer pide contraseña |
| Sesión web personal | 90 días absolutos | Cookie persistente y almacenamiento de sesión con vida suficiente |
| Credencial de dispositivo compartido | 365 días absolutos | Se conserva al reiniciar; al vencer se vincula nuevamente |
| Sesión de empleado | Máximo 8 horas | Finaliza antes por logout, inactividad, reemplazo o revocación |
| Inactividad de empleado | 120 segundos | Aviso local unos 20 segundos antes; validación autoritativa en servidor |
| Código de vinculación | 10 minutos, un solo uso | No es el secreto permanente del dispositivo |
| Confirmación de contraseña para administración sensible | 10 minutos | Registrar fecha de confirmación en la sesión del actor |

Son parámetros elegidos para este proyecto, no garantías universales ni valores exigidos por una norma. La vigencia larga aumenta el período de exposición si se roba una credencial; se compensa con almacenamiento adecuado, revocación y confirmación reciente para cambios sensibles.

Para reducir complejidad, la persistencia web inicial usa una cookie de sesión Laravel persistente con SESSION_EXPIRE_ON_CLOSE=false y SESSION_LIFETIME=129600, más el límite absoluto de auth_sessions. No implementar simultáneamente un recaller «remember me» de duración indefinida: podría recrear sesiones después de revocarlas. Redis/DB debe conservar los datos durante el plazo configurado; borrar cookies o perder datos del servidor puede exigir nuevo login.

Configurar sanctum.expiration como null de forma explícita y asignar expires_at finito a cada PAT nuevo desde su Action. Corregir el cast actual y IssueAccessTokenAction a la vez. No basta con aumentar expires_at si permanece el límite global de 7 días. Los tokens anteriores conservan su vencimiento original; no extenderlos retroactivamente.

No renovar plazos por consultar el perfil ni emitir tokens nuevos en cada arranque. [Sanctum documenta vencimiento por token y revocación](https://laravel.com/framework/docs/13.x/sanctum#token-expiration).

### 3.3 PIN de cuatro dígitos

- Contrato: string ASCII que cumple `\A[0-9]{4}\z`. «0007» es válido y debe conservarse; un número JSON 7 no equivale a «0007».
- No guardar PIN en claro, cifrado reversible, logs, excepciones, auditoría, analytics, trazas ni respuestas.
- No descargar hashes al cliente ni verificar el PIN offline.
- Agregar `users.pin_hash`, separado de `users.password`. No reutilizar la columna password, no concatenar ambos hashes y no exigir PIN único entre personas.
- Generar PIN inicial aleatorio con CSPRNG o aceptar el nuevo PIN mediante formulario administrativo con confirmación. No usar documento de identidad, fecha de nacimiento ni PIN universal.
- Usar un PinHasher central con Argon2id y salt aleatorio gestionado por el hasher. Mantener compatibles los hashes de contraseñas actuales; no cambiar a ciegas HASH_DRIVER para todas las contraseñas.
- Por haber solo 10.000 combinaciones, incorporar un pepper secreto del backend: HMAC-SHA-256 sobre el PIN con separación de propósito, seguido del hash Argon2id. El pepper reside fuera de PostgreSQL, en un secreto de despliegue; su identificador de versión puede estar en la fila.
- El pepper no se entrega al frontend ni se deriva de datos públicos. Su respaldo se gestiona separado del backup de la base. Si se pierde, los PIN afectados deben restablecerse; las contraseñas continúan funcionando.
- Fallar de forma controlada si falta el pepper al habilitar PIN; no aceptar una clave vacía ni generar una nueva en cada arranque.
- Excluir pin y pin_confirmation del trim de entrada y del flashing de datos sensibles. Rechazar espacios, signos, Unicode numérico, longitudes distintas y campos inesperados sensibles.

Guardar los dos hashes en la misma base cumple el requisito. El pepper separado dificulta la búsqueda offline cuando solo se filtra la base; no protege frente al compromiso conjunto de base y secreto. El hashing y el rate limit no evitan que un compañero observe o comparta un PIN. [OWASP: hashing y pepper](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html).

### 3.4 Modelo de persistencia

Mantener la lógica en IdentityAndAccess, con modelos nuevos en `app/Models/IdentityAndAccess/`. Las tablas propuestas son:

| Tabla | Campos relevantes propuestos | Invariantes |
| --- | --- | --- |
| users | pin_hash nullable, pin_enabled false, pin_changed_at nullable, pin_pepper_version nullable, contadores/ventanas/bloqueo del PIN | Campos sensibles ocultos y fuera de asignación masiva genérica; preservar password y SoftDeletes |
| shared_devices | id UUID, name, credential_hash único, credential_expires_at, enrolled_by, enrolled_at, revoked_at, last_seen_at, session_generation | Una credencial de 256 bits generada por servidor; nunca pertenece a un administrador como usuario técnico |
| shared_device_pairing_codes | id, code_hash único, name, created_by, expires_at, consumed_at, shared_device_id nullable | Canje atómico una sola vez; verificar también que su creador siga habilitado y autorizado |
| auth_sessions | id UUID, user_id, kind, transport, auth_method, shared_device_id nullable, device_generation nullable, personal_access_token_id nullable único, issued_at, expires_at, last_user_activity_at, password_confirmed_at nullable, revoked_at, revoked_reason | Fuente común de duración, contexto y revocación; kind personal/shared_user; transport cookie/bearer |

Un PAT autentica al usuario y se vincula a auth_sessions por su ID interno. Una sesión web guarda auth_session_id solo en el almacenamiento de sesión del servidor. El cliente no puede elegir ni modificar esos campos.

La credencial del dispositivo tiene formato identificador público + secreto aleatorio. Consultar por identificador y comparar el hash del secreto en tiempo constante. Puede usarse SHA-256 para este secreto aleatorio de alta entropía; no confundir este caso con el PIN corto.

No hacer del dispositivo un User administrador ni darle `api:access`. Su middleware resuelve un SharedDevice en contexto de request y no lo autentica como empleado.

Índices: hashes únicos; usuarios/sesiones activas; dispositivo/sesiones; vencimientos para limpieza. FKs preservan trazabilidad. Si la limpieza elimina un PAT, su referencia nullable no debe impedir limpiar ni volver utilizable una sesión. Guardar revoked_at antes de borrar credenciales en acciones explícitas.

Los contadores persistentes de PIN protegen el presupuesto de intentos; Redis puede complementar límites de requests. La limpieza de cache no debería regalar inmediatamente un nuevo presupuesto diario.

### 3.5 Permisos y contexto

Extender Spatie con permisos estables en inglés, manteniendo guard_name=web; ese nombre no significa «solo navegador».

| Permiso nuevo | Asignación inicial |
| --- | --- |
| identity.personal.login | admin y delivery |
| identity.web.login | admin |
| identity.shared.login | employee |
| identity.users.manage | admin |
| identity.pins.manage | admin |
| identity.shared-devices.manage | admin |
| identity.sessions.manage | admin |

Crear los roles employee/delivery si no existen. Conservar el rol admin actual. Sembrar permisos funcionales operativos mínimos de forma explícita; no asignar todos los permisos a los nuevos roles. No implementar el módulo de repartos como parte de este trabajo.

Para una sesión PIN, la autorización efectiva exige simultáneamente:

1. Usuario existente, no eliminado y habilitado para PIN.
2. Credencial de dispositivo válida y mismo dispositivo que auth_sessions.
3. auth_session vigente, no revocada y misma session_generation.
4. Ruta expresamente habilitada para operación compartida.
5. Permiso funcional actual del usuario en la Policy/FormRequest correspondiente.

Ni `api:access` ni la existencia de una sesión sustituyen estas comprobaciones. Para sesiones web, tokenCan puede devolver true por diseño de Sanctum; no usarlo como única barrera para el contexto PIN.

Conjunto inicial propuesto de rutas compartidas: perfil propio, logout, actividad de sesión, referencias necesarias, lectura de lotes/galpones y registro/consulta de recolecciones y mortalidad. Usar nombres reales de rutas, por ejemplo `api.v1.lots.collections.store` y `api.v1.lots.mortality.store`, junto a sus permisos actuales. Revisar también los catálogos expuestos por ReferenceOptionsController. Todo endpoint nuevo queda denegado hasta incorporarlo expresamente.

Administración de usuarios/PIN/dispositivos, auditoría general, generación de enlaces de reportes, ajustes sensibles y demás rutas no listadas quedan fuera del contexto compartido. Ampliar la lista cuando exista un caso de uso definido, con prueba positiva y negativa; no agregar comodines.

No habilitar PIN a cuentas administradoras. Validar esta incompatibilidad al cambiar permisos/roles, al configurar PIN y al autenticarlo, incluso si los datos se modificaron fuera de la UI.

### 3.6 Vinculación y modo local

Flujo de vinculación:

1. Administrador autenticado por contraseña, con permiso y confirmación reciente, genera un código aleatorio de 10 caracteres Base32 sin caracteres confusos, válido por 10 minutos.
2. En la pantalla normal existe «Configurar como dispositivo compartido». Se ingresa el código; no se necesita iniciar una sesión administrativa en la tablet.
3. El servidor valida y consume el código dentro de una transacción con bloqueo, crea el dispositivo, genera su secreto y registra auditoría.
4. App: devuelve el secreto una sola vez y lo guarda en almacenamiento seguro. Web: lo coloca en cookie HttpOnly independiente y no lo incluye en JSON.
5. Solo después de guardar la credencial se cambia la preferencia local a shared. Invalidar una sesión personal previa en esa misma instalación y borrar su estado; nunca dejar el token/cookie del administrador detrás del selector de empleados.
6. Consultar el estado y listado del dispositivo para confirmar el arranque compartido.

El administrador puede revocar dispositivos desde su panel. Una revocación bloquea lista, PIN y operaciones de sesiones ya abiertas desde la siguiente validación. Las operaciones que ya estaban autorizadas y en curso conservan su actor; no prometer deshacer automáticamente un commit anterior.

La aplicación guarda únicamente una preferencia no secreta como `gam.deviceMode.v1=personal|shared`. En web puede usar localStorage; en nativo, Preferences. El identificador público opcional tampoco es autenticación.

| Situación de arranque | Comportamiento |
| --- | --- |
| Sin preferencia ni credencial | Mostrar login normal |
| Preferencia shared | Mostrar estado de comprobación del dispositivo; pedir lista al servidor |
| Preferencia personal pero existe credencial segura | Consultar estado del dispositivo y reconciliar el modo |
| Credencial inválida, vencida o revocada confirmada por API | Borrar credencial y datos compartidos; mostrar login normal y opción de vincular |
| Sin red / timeout / 5xx | Mantener preferencia y credencial; mostrar «No se pudo conectar» y reintentar |
| Sesión PIN vencida pero dispositivo válido | Limpiar al empleado y volver al selector, conservando vinculación |

Un booleano manipulado no debe obtener empleados ni iniciar una sesión PIN. Tampoco usar device_name, ID de hardware, tamaño de pantalla o estar conectado al Wi-Fi como comprobante.

Desvinculación local: ofrecer una acción administrativa que pide credenciales del administrador, verifica el permiso y revoca el dispositivo sin emitir una sesión personal en la tablet. Como alternativa cotidiana, el administrador puede revocarla desde otro equipo. Borrar datos/reinstalar puede eliminar la configuración local; eso nunca vuelve válida una credencial inexistente.

Si el canje se completó en el servidor pero se perdió la respuesta antes de guardar el secreto, no reemitir el secreto ni permitir reutilizar el código. Mostrar recuperación mediante un nuevo código; el administrador revoca el dispositivo huérfano. Documentar este caso infrecuente.

### 3.7 Sesiones compartidas, inactividad y peticiones simultáneas

Al aceptar un PIN, bloquear shared_devices, verificar nuevamente su estado, incrementar session_generation y revocar la sesión anterior antes de crear la nueva. Guardar la generación en auth_sessions. Dos logins concurrentes no pueden dejar dos empleados activos en una misma instalación.

En cada request protegida, resolver el contexto y comprobar expiración/inactividad ANTES de actualizar la actividad. No basar esta comprobación en personal_access_tokens.last_used_at: Sanctum puede actualizar ese valor durante la autenticación, antes de llegar al middleware propio.

Usar auth_sessions.last_user_activity_at. Los polls, consultas de estado, refresh del perfil y tráfico en segundo plano no prolongan la sesión. El frontend registra interacción real local y, como máximo cada 30 segundos cuando haya interacción y la app esté visible, envía una actividad autenticada. El servidor usa su reloj, nunca una hora ni un expires_at enviados por el cliente. Un heartbeat no puede revivir una sesión vencida. Ningún servidor puede demostrar presencia física humana solo por recibir actividad: el límite absoluto sigue siendo necesario.

Al bloquearse, pasar a segundo plano o elegir «Terminar», limpiar pantalla, PIN, formularios y datos del empleado, e intentar finalizar la sesión en servidor. Si no hay red, limpiar igualmente la identidad local; no habilitar operaciones offline y dejar actuar el vencimiento del servidor. Al reanudar, el modo compartido vuelve al selector y exige PIN, aun si quedó un token remoto sin revocar.

Ofrecer un endpoint de finalización autenticado por el dispositivo para poder cerrar la última sesión después de un reinicio nativo, cuando ya no queda el PAT del empleado en memoria. Nunca permitir que finalice sesiones de otro dispositivo.

Esa finalización recibe el ID de sesión esperado y compara su generación bajo bloqueo; no ejecuta «cerrar cualquiera que esté activa» sin condición. El estado del dispositivo puede devolver su active_session_id, sin identidad del empleado, para recuperación tras reinicio. Si entretanto cambió el empleado, responder conflicto/no-op y no cerrar su nueva sesión. En web compartida, el arranque siempre muestra selector y exige PIN; no restaurar la identidad anterior desde una cookie que aún viva.

En web, las cookies se comparten entre pestañas. Enviar un header no secreto `X-GAM-Session` con el ID de la sesión PIN que originó cada operación; el backend debe cotejarlo con la sesión autenticada y la generación vigente. Así un formulario antiguo de A no se ejecuta con la cookie nueva de B. Usar también una generación local del estado para ignorar respuestas HTTP tardías. BroadcastChannel puede ayudar a limpiar otras pestañas, pero no sustituye la comprobación del servidor.

No reenviar automáticamente una mutación fallida después de cambiar de empleado ni actualizar su identidad con la de una respuesta vieja. En un error de sesión obsoleta, limpiar solo ese contexto: no revocar por accidente la nueva sesión de otro tab.

### 3.8 Límites y errores

Valores iniciales propuestos, todos configurables:

| Operación | Límite |
| --- | --- |
| Contraseña | 5 intentos fallidos/minuto por cuenta normalizada y 30 requests/minuto por IP |
| PIN | 5 fallos por usuario en ventana de 15 minutos: bloqueo de 15 minutos |
| PIN sostenido | 20 fallos por usuario en 24 horas: bloqueo hasta completar la ventana o desbloqueo administrativo |
| PIN por dispositivo | 30 intentos/minuto, además del presupuesto global por usuario |
| Canje de código | 5 intentos/minuto por IP y límite acumulado conservador por ventana |
| Generación de código | 5/minuto por administrador |
| Listado de empleados | 30/minuto por dispositivo, paginado y limitado |

Normalizar correos de forma coherente entre login, altas y rate limits. Antes de agregar una restricción de unicidad normalizada, detectar colisiones existentes sin borrar ni fusionar usuarios automáticamente.

Contar fallos de PIN de forma atómica. Si la Action arroja una excepción dentro de la misma transacción que incrementa el contador, el rollback podría borrar el fallo: persistir el resultado y devolver/convertir el error después del commit. No reiniciar el presupuesto de seguridad al reinstalar la app, cambiar IP o cambiar device_name. Limitar también identificadores inexistentes sin crear filas de usuario falsas.

Conservar respuestas en español y agregar `code` estable. Mantener `application/problem+json`, `message` y `errors` para validaciones. Códigos iniciales:

| HTTP | code | Tratamiento del cliente |
| --- | --- | --- |
| 401 | INVALID_CREDENTIALS | Mostrar error de contraseña sin revelar existencia de cuenta |
| 401 | INVALID_PIN | Borrar solo el campo PIN; conservar la vinculación |
| 401 | SHARED_DEVICE_UNAUTHORIZED | Quitar credencial inválida confirmada y ofrecer vinculación |
| 401 | SESSION_EXPIRED / SESSION_REVOKED / SESSION_CHANGED | Limpiar la sesión del usuario; preservar el dispositivo si sigue válido |
| 403 | FORBIDDEN | Mostrar falta de permiso; no hacer logout global |
| 403 | REAUTHENTICATION_REQUIRED | Pedir contraseña del actor antes de continuar la acción sensible |
| 419 | CSRF_TOKEN_MISMATCH | Recuperar CSRF y pedir reintento controlado |
| 422 | VALIDATION_FAILED | Mostrar errores del formulario |
| 422 | INVALID_PAIRING_CODE | Mensaje común para código inexistente, usado o vencido |
| 409 | CONFLICT | Conflicto de estado cuando corresponda |
| 429 | RATE_LIMITED | Respetar Retry-After y mostrar espera |

No diferenciar públicamente usuario inexistente, deshabilitado o PIN incorrecto mediante detalles de identidad. Los límites de PIN solo se consultan tras autenticar al dispositivo. Preservar headers de HttpException, especialmente Retry-After, al renderizar errores.

## 4. Contrato HTTP que debe concretarse en OpenAPI

Todas las rutas siguientes usan prefijo `/api/v1`, salvo `/sanctum/csrf-cookie`. Las rutas nuevas son propuestas concretas para T01. Mantener nombres de clases/columnas/permisos en inglés y rutas/mensajes en español, conforme a AGENTS.md.

### 4.1 Sesión personal y administración

| Método y ruta | Autenticación / propósito |
| --- | --- |
| POST /autenticacion/inicio-sesion | Actual; correo_electronico, password, device_name. Emite PAT personal solo a cuenta con identity.personal.login |
| GET /sanctum/csrf-cookie | Fuera de /api/v1; prepara CSRF del navegador |
| POST /autenticacion/web/inicio-sesion | Nueva; verifica contraseña y identity.web.login; establece sesión web, nunca devuelve PAT |
| GET /mi-perfil | Actual; identidad vigente y metadatos del contexto autenticado |
| POST /autenticacion/cerrar-sesion | Bearer; revoca sesión/PAT actual |
| POST /autenticacion/web/cerrar-sesion | Cookie + CSRF; Auth::guard('web')->logout, invalidación y regeneración del token CSRF |
| POST /autenticacion/confirmar-password | Bearer; verifica contraseña del usuario actual y registra password_confirmed_at |
| POST /autenticacion/web/confirmar-password | Versión cookie + CSRF del mismo caso de uso |
| GET /mis-sesiones | Sesión personal; devuelve solo metadatos propios, nunca secretos |
| DELETE /mis-sesiones/{sesion} | Sesión personal; propietario y revalidación según sensibilidad |
| GET, POST /usuarios | Administración autenticada por contraseña; listar paginado/crear, sin emitir token del usuario creado |
| PATCH /usuarios/{usuario}/estado | Administración; habilitar/deshabilitar según SoftDeletes |
| PUT /usuarios/{usuario}/roles | Administración; solo roles permitidos, sin asignación masiva |
| PUT /usuarios/{usuario}/password | Administración; reset controlado, revocación de sesiones existentes |
| PUT /usuarios/{usuario}/pin | Administración; pin y pin_confirmation; hashear y habilitar |
| DELETE /usuarios/{usuario}/pin | Administración; deshabilitar PIN y revocar sesiones PIN |
| POST /usuarios/{usuario}/pin/desbloqueo | Administración; desbloqueo auditado tras confirmar identidad del empleado |
| DELETE /usuarios/{usuario}/sesiones | Administración; revocar todas las sesiones del usuario |

Retirar la ruta pública POST /autenticacion/registro y actualizar sus tests/consumidores: pasa a devolver 404, sin crear usuario ni token. El alta administrativa nueva devuelve 201 con UserResource, no AuthResponseResource. Evitar que el cambio rompa silenciosamente el contrato: documentarlo como migración.

### 4.2 Dispositivos compartidos

| Método y ruta | Autenticación / propósito |
| --- | --- |
| POST /dispositivos-compartidos/codigos | Administrador con permiso y contraseña confirmada; nombre del dispositivo; devuelve código una vez |
| GET /dispositivos-compartidos | Administrador; lista de dispositivos y estado, sin credential_hash |
| DELETE /dispositivos-compartidos/{dispositivo}/vinculacion | Administrador; revoca dispositivo y todas sus sesiones |
| POST /dispositivos-compartidos/vinculacion | Canje para nativo; codigo + device_name; devuelve device_token una vez |
| POST /dispositivos-compartidos/web/vinculacion | Canje para web, protegido por CSRF; establece cookie del dispositivo |
| GET /dispositivo-compartido | Credencial de dispositivo; estado y vencimiento, sin empleados ni identidad privilegiada |
| GET /dispositivo-compartido/usuarios | Credencial de dispositivo; lista mínima paginada de empleados elegibles |
| POST /dispositivo-compartido/inicio-sesion-pin | Nativo; credencial de dispositivo + usuario_id + pin; emite PAT operativo y contexto |
| POST /dispositivo-compartido/web/inicio-sesion-pin | Web; credencial cookie + CSRF + usuario_id + pin; establece sesión operativa |
| POST /dispositivo-compartido/actividad | Nativo; usuario PIN + dispositivo + X-GAM-Session |
| POST /dispositivo-compartido/web/actividad | Web; usuario PIN + cookie de dispositivo + X-GAM-Session + CSRF |
| POST /dispositivo-compartido/finalizar-sesion | Nativo; dispositivo + session_id esperado; revoca únicamente esa sesión |
| POST /dispositivo-compartido/web/finalizar-sesion | Web; dispositivo cookie + session_id esperado + CSRF |
| DELETE /dispositivo-compartido/vinculacion | Nativo; dispositivo + credenciales administrativas verificadas sin emitir login personal |
| DELETE /dispositivo-compartido/web/vinculacion | Cookie + CSRF; misma desvinculación local |

En nativo, enviar la credencial de dispositivo en `X-Shared-Device-Token`. Después del PIN, enviar además el PAT en Authorization y X-GAM-Session. En web, el secreto de dispositivo va en una cookie HttpOnly distinta de gam_session; nunca en localStorage ni en una cookie legible desde JavaScript.

Registrar las rutas web de establecimiento/mutación de cookies bajo middleware web explícito, incluso cuando la petición no tenga Origin. Para las APIs comunes, integrar statefulApi() y exigir CSRF cuando se autentique mediante cookies, incluida la credencial de dispositivo sin usuario. No confiar en CORS para prevenir CSRF. No excluir del CSRF login web, canje web, PIN web ni desvinculación.

Las versiones nativas de esos endpoints no aceptan cookies como sustituto de sus headers. Los GET comunes de estado/lista sí pueden resolver la credencial por cookie o header. Para las mutaciones de negocio comunes, una petición web que no logró iniciar el middleware de sesión queda sin autenticar: no implementar un fallback que lea manualmente cookies para evitar CSRF.

No mezclar dos identidades: si llegan cookie autenticada y Bearer de usuario al mismo endpoint, rechazar la combinación ambigua o aplicar una política explícita que jamás permita un fallback a un usuario más privilegiado. Los endpoints de dispositivo no pueden aceptar un token personal como sustituto de la credencial de dispositivo.

### 4.3 Formato de datos

- Login Bearer conserva `access_token`, `token_type`, `expires_at`, `abilities`, `user` y agrega `session`.
- Login web devuelve `user` y `session`; no access_token.
- `session`: id, kind, auth_method, expires_at, idle_timeout_seconds nullable y capacidades efectivas para la UI. Los datos son emitidos por servidor.
- Perfil conserva `data` como UserResource; agregar `session` fuera de data para no romper los campos existentes.
- Listado previo a PIN: `data: [{id, nombre}]` y metadatos de paginación. No correo, password, pin_hash, roles, permissions ni datos de actividad. No necesita fotos en esta entrega.
- PIN/código/password se envían en body HTTPS, nunca query string, rutas, analytics o cabeceras de trazas.
- Fijar en OpenAPI todos los responses, headers, ejemplos con secretos ficticios y securitySchemes. Para PIN nativo, dispositivo y PAT cuando corresponda son requisitos conjuntos, no alternativas OR.

## 5. Plan de tareas

| ID | Tarea | Depende de |
| --- | --- | --- |
| T00 | Confirmar baseline y reglas | — |
| T01 | Fijar contratos y decisión de autenticación | T00 |
| T02 | Configuración y persistencia | T01 |
| T03 | Cuentas, permisos y administración de PIN | T02 |
| T04 | Login personal persistente web/mobile | T02, T03 |
| T05 | Vinculación y credencial de dispositivo | T02, T04 |
| T06 | Contexto de sesión y autorización compartida | T02, T04, T05 |
| T07 | Login PIN, límites e inactividad | T03, T05, T06 |
| T08 | Revocación, auditoría y limpieza | T04, T05, T07 |
| T09 | Base frontend y almacenamiento por plataforma | T01 |
| T10 | Estado de autenticación, arranque e interceptores | T04, T05, T06, T09 |
| T11 | Pantallas de acceso personal | T10 |
| T12 | Vinculación y experiencia compartida | T07, T08, T10 |
| T13 | Panel administrativo | T03, T05, T08, T11 |
| T14 | Completar pruebas de seguridad backend | T03–T08 |
| T15 | Completar pruebas frontend y dispositivos | T11–T13 |
| T16 | CI, migración y operación | T14, T15 |
| T17 | Actualizar los MD y verificar entrega | T16 |

Las pruebas acompañan cada tarea funcional. T14 y T15 completan la matriz transversal; no justifican postergar todas las pruebas hasta el final.

### T00 — Confirmar baseline y reglas

**Archivos:** AGENTS.md de ambos; backend architecture.md, libraries.md, module-structure-example.md, contracts/openapi/authentication.yaml y tests/Feature/IdentityAndAccess; frontend docs obligatorios y ADR 001–006.

**Trabajo:**

1. Registrar commits de inicio y git status; preservar cambios previos del usuario.
2. Verificar versiones instaladas contra lockfiles. En backend usar PHP 8.5 y PHPUnit 12; una referencia genérica de la skill a PHPUnit 13 no autoriza actualizarlo.
3. Leer las habilidades locales laravel-best-practices y testing-best-practices y sus reglas relevantes. No existe .ai/rules en el snapshot: si aparece en el HEAD nuevo, leer su índice y reglas aplicables.
4. Ejecutar baseline backend en el entorno Compose de prueba aislado y frontend con los comandos de la sección 7.
5. Distinguir fallos previos de regresiones; no cambiar versiones ni reglas de seguridad para hacer pasar el baseline.

**Aceptación:** versiones, rutas actuales y resultados reales documentados; no se usó una base de desarrollo/producción para pruebas.

### T01 — Fijar contratos y decisión de autenticación

**Archivos:** backend contracts/openapi/authentication.yaml; nuevo identity-access-implementation.md; frontend nuevo docs/adr/007-multi-login-and-sessions.md.

**Trabajo:**

1. Convertir secciones 3 y 4 en contrato implementable, con permisos, tipos de sesión, TTL y errores explícitos.
2. Documentar los cambios intencionales: registro privado, web por cookie, duración de tokens, cuatro dígitos y credencial de dispositivo separada.
3. Conservar nombres públicos españoles y campos de protocolo existentes. No unificar accidentalmente el wrapper de login y perfil.
4. Definir el conjunto inicial de rutas operativas y mapearlo a permisos actuales. Mantener los permisos globales de la empresa; no inventar aislamiento por unidad productiva.
5. Fijar origen web: preferir mismo origen mediante reverse proxy tanto en desarrollo como en producción. Native usa una URL HTTPS absoluta configurada.

**Pruebas/aceptación:** OpenAPI válido, sin referencias rotas; contrato explica todas las combinaciones de credenciales y quién puede invocar cada endpoint. El frontend puede implementarse sin adivinar campos.

### T02 — Configuración y persistencia

**Archivos existentes:** config/auth.php, config/sanctum.php, config/session.php, .env.example, compose.dev.yaml, app/Models/User.php y migraciones de usuarios/tokens.
**Nuevos:** config/identity.php, modelos y migraciones de la sección 3.4, factories correspondientes.

**Trabajo:**

1. Crear migraciones aditivas; no editar migraciones antiguas ya aplicadas.
2. Agregar campos PIN nullable/false para que usuarios existentes sigan funcionando.
3. Crear shared_devices, shared_device_pairing_codes y auth_sessions con índices, FKs y timestamps coherentes.
4. Implementar configuración con unidades explícitas: personal_session_days=90, shared_device_days=365, shared_session_hours=8, shared_idle_seconds=120, pairing_minutes=10.
5. Eliminar cast ambiguo de sanctum.expiration; fijar null y exigir expires_at finito desde todas las rutas de emisión de PAT.
6. Agregar pepper y costos del hasher como configuración del servidor, con validación y ejemplo sin secreto.
7. Registrar datos nuevos como hidden y no fillable en flujos genéricos. Las Actions específicas controlan su escritura.

**Pruebas/aceptación:** migración sobre esquema vacío y esquema con usuarios/tokens; credenciales previas sin cambios; hashes ausentes en serialización; unicidad/canje y relaciones comprobadas en PostgreSQL. No ejecutar migrate:fresh sobre datos existentes.

### T03 — Cuentas, permisos y administración de PIN

**Archivos existentes:** RegisterUserAction, RegisterRequest, AuthController, AdminUserSeeder, DatabaseSeeder, LocalDemoDataSeeder, PublicInputMapper, UserResource.
**Nuevos:** FormRequests/Controllers/Actions/Policies de gestión de usuarios y PIN; servicio PinHasher; seeder de permisos de identidad.

**Trabajo:**

1. Retirar el registro público y agregar alta administrativa sin token para el nuevo usuario.
2. Aplicar permisos de sección 3.5 a login/cuentas; no confiar en role enviado por el cliente.
3. Crear roles y permisos idempotentemente. Separar su seeder del seeder de contraseña del administrador, para poder desplegar sin resetear contraseñas ni restaurar cuentas deshabilitadas.
4. Mantener SoftDeletes como semántica de deshabilitación. Al restaurar, no restaurar sesiones antiguas.
5. Configurar/restablecer/deshabilitar PIN con confirmación, hasher central, contadores reiniciados por acción administrativa y auditoría sin valores secretos.
6. Al cambiar PIN, revocar solo las sesiones PIN afectadas; al cambiar contraseña o deshabilitar usuario, revocar todas.
7. Impedir PIN administrativo y autoescalamiento de privilegios. Proteger al último administrador operativo frente a una baja/cambio de rol accidental mediante una comprobación transaccional.
8. Agregar datos demo solo locales, con PIN ficticios hasheados; no reutilizarlos en producción.

**Pruebas/aceptación:** anónimo no registra cuentas; un empleado no administra PIN; alta no autentica al nuevo usuario en el dispositivo del admin; «0007» funciona como string; PIN duplicado entre personas produce hashes distintos; PIN/password/pepper nunca se serializan.

### T04 — Login personal persistente web/mobile

**Archivos existentes:** LoginUserAction, IssueAccessTokenAction, LoginResponder, AuthController, AuthResponseResource, AccessTokenResource, UserResource, bootstrap/app.php, routes/api.php, routes/web.php, config/cors.php.

**Trabajo:**

1. Reutilizar la verificación de contraseña; separar emisión Bearer de establecimiento de sesión web.
2. Validar el permiso de canal antes de crear PAT, cookie o auth_session.
3. Crear PAT con 90 días y su auth_session en una operación coherente. No aceptar TTL/abilities/auth_method del cliente.
4. Para web, autenticar mediante guard web, regenerar ID de sesión y guardar auth_session_id. Establecer cookie persistente segura y límite absoluto. No devolver PAT a la SPA.
5. Agregar confirmación de contraseña reciente para gestión sensible y logout que cierre realmente guard, sesión y contexto.
6. Integrar cookies stateful de la API, CSRF y orígenes explícitos. Evitar incluir el Origin nativo por defecto como SPA confiable. No usar allowed_origins=* con credenciales.
7. Agregar Cache-Control: no-store a respuestas de autenticación/perfil; no almacenar cuerpos sensibles.

**Pruebas/aceptación:** app conserva sesión tras reiniciar; web tras reabrir navegador; día 8 sigue válido, día 91 no; web de repartidor no crea sesión administrativa; login inválido no produce artefactos; regeneración evita fijación de sesión; logout invalida replay.

### T05 — Vinculación y credencial de dispositivo

**Archivos nuevos:** Actions/Requests/Resources/Controller de dispositivos y códigos; middleware ResolveSharedDevice en la ubicación consistente con el proyecto.

**Trabajo:**

1. Crear código temporal con CSPRNG y almacenar solo digest, autor y vencimiento.
2. Canjear atómicamente con bloqueo de fila, comprobar autor aún autorizado, crear dispositivo y secreto.
3. Retornar secreto una vez para nativo o cookie HttpOnly para web. No usar el PAT del administrador como credencial de dispositivo.
4. Eliminar/inactivar autenticación personal anterior de la instalación durante la transición, sin cerrar otros dispositivos del mismo usuario.
   En nativo, si existía PAT personal local, adjuntarlo únicamente al canje autorizado para revocar esa sesión como parte de la transición; en web revocar el contexto personal de la cookie existente. Si la credencial anterior ya era inválida, limpiar sus restos sin convertirla en una identidad válida. El código sigue siendo obligatorio para vincular.
5. Resolver dispositivo en cada endpoint dedicado y comprobar hash, expiración y revoked_at.
6. Implementar estado y listado mínimo de empleados elegibles con filtros acotados.

**Pruebas/aceptación:** códigos usados/vencidos/falsos rechazados; dos canjes concurrentes generan un único dispositivo; flag local/token personal/UUID público no habilitan listado; credencial de dispositivo sola no ejecuta operaciones de negocio; no queda sesión de admin en la tablet.

### T06 — Contexto y autorización compartida

**Archivos existentes:** grupos protegidos de routes/api.php, /administracion de routes/web.php, FormRequests/Policies afectadas.
**Nuevos:** middleware de resolución/validación de auth_sessions y política del contexto compartido.

**Trabajo:**

1. Resolver auth_session a partir del PAT autenticado o del ID guardado en sesión web. Comparar usuario real; denegar filas faltantes/ajenas/revocadas.
2. En sesiones PIN exigir dispositivo vigente, mismo ID, misma generación y X-GAM-Session correcto.
3. Aplicar allowlist de rutas y permiso funcional vigente. La política debe cubrir también rutas administrativas fuera del prefijo API y accesos indirectos a reportes/referencias.
4. Rechazar mezcla ambigua de credenciales y payloads que pretendan fijar actor, roles, permisos o modo.
5. Exponer capacidades efectivas para mostrar la UI; el backend continúa comprobándolas.
6. Preparar tratamiento explícito de tokens previos durante la migración de T16, sin un fallback permanente que saltee auth_sessions.

**Pruebas/aceptación:** un token PIN no sirve desde otro dispositivo ni para administración aunque el usuario tenga permisos extra; nuevas rutas no quedan habilitadas automáticamente; contexto de A con cookie de B no muta datos; permisos actuales se respetan sin reemitir tokens.

### T07 — Login PIN, límites e inactividad

**Archivos:** Actions/Requests de PIN y actividad; AppServiceProvider; middleware de contexto; bootstrap/app.php para errores.

**Trabajo:**

1. Verificar el dispositivo antes de consultar usuario/PIN; validar string de cuatro dígitos sin normalizar secretos.
2. Comprobar elegibilidad y hash con la misma política que usa el listado; un ID adivinado no evita restricciones.
3. Actualizar contadores y bloqueos atómicamente sin perderlos en rollback por credenciales inválidas.
4. Crear sesión operativa con generación única; revocar la anterior en la misma transacción.
5. Implementar inactividad comprobada antes de marcar actividad; excluir polling y permitir actividad limitada solo si la sesión aún vive.
6. Implementar «Terminar» y finalización tras reinicio, limitada al dispositivo autenticado.
7. Devolver errores tipados, Retry-After y fechas del servidor para el contador visual.

**Pruebas/aceptación:** 5 fallos bloquean aunque cambie IP/dispositivo; límite diario subsiste; éxito no borra un bloqueo aún vigente; a 119 s puede actuar, a 121 s no; actividad vencida no revive sesión; background/poll no la mantienen abierta; login B invalida A.

### T08 — Revocación, auditoría y limpieza

**Archivos existentes:** LogoutUserAction, AuditRecorder/AuditEntryData, SpatieAuditRecorder, AssignTraceContext, routes/console.php; nuevas Actions de revocación.

**Trabajo:**

1. Revocar sesión actual, sesión propia por ID, todas las sesiones de un usuario y todas las del dispositivo según permisos.
2. Mantener separadas la acción «Terminar como empleado» y «Desvincular dispositivo».
3. Implementar la desvinculación local verificando administrador sin dejar su sesión en el equipo compartido.
4. Completar auditoría transaccional de login exitoso, canje, reset PIN, bloqueos relevantes, cambios de permisos/estado y revocación. El alta administrativa registra al actor real.
5. Incorporar shared_device_id/auth_session_id/auth_method al contexto de auditoría de operaciones existentes desde datos resueltos por servidor. No confiar en IDs de auditoría enviados en headers.
6. Ajustar las escrituras comunes de auditoría para propagar esos metadatos sin romper los snapshots ni las transacciones de otros módulos.
7. Programar limpieza de PAT expirados, códigos consumidos/vencidos y sesiones obsoletas; conservar eventos append-only. La expiración/revocación no depende de que haya corrido el scheduler.

**Pruebas/aceptación:** replay tras logout/revocación falla; empleado finalizado conserva dispositivo; cambiar PIN no cierra al dueño en su teléfono; revocar dispositivo invalida una sesión PIN ya abierta; un fallo del AuditRecorder revierte las mutaciones críticas.

### T09 — Base frontend y almacenamiento por plataforma

**Archivos existentes:** src/main.ts, src/app/core/api/*, src/app/core/config/api.config.ts, src/environments/*, angular.json, capacitor.config.ts, package.json/package-lock.json.
**Nuevos:** servicios de almacenamiento en core/native/, configuración y tipos de autenticación en core/auth/, configuración de proxy local y proyectos nativos.

**Trabajo:**

1. Fijar API_CONFIG.baseUrl en /api/v1 para web y URL absoluta HTTPS terminada en /api/v1 para nativo. Los servicios usan rutas relativas sin repetir v1.
2. Configurar proxy web de /api y /sanctum hacia Laravel. Mismo origen evita complejidad de lectura de cookies XSRF entre subdominios.
3. Mantener ApiClient genérico y comprobar que baseUrl override no pueda recibir credenciales fuera del origen/ruta de API autorizados por el interceptor.
4. Agregar @capacitor/android y @capacitor/ios compatibles con Capacitor 8.5.1 y generar sus plataformas si siguen ausentes. Mantener appId dev.huevossanjose.gam.
5. Agregar almacenamiento seguro nativo mediante una librería compatible; candidato concreto: @aparajita/capacitor-secure-storage. Fijar versión compatible después de verificar peer dependencies, licencia, mantenimiento y compilación real con el lock actual. No actualizar todo el stack para hacerlo compilar.
6. Implementar un wrapper pequeño en core/native/ y guardar solo PAT personal y secreto de dispositivo. El PAT del empleado vive en memoria.
7. Para modo local/identificador no secreto, usar @capacitor/preferences en nativo y almacenamiento web apropiado. No guardar nombres/listas/PIN/contraseñas como parte del indicador.
8. Desactivar sincronización iCloud de las credenciales del dispositivo y definir exclusión de backups/migración de secretos en Android. Probar comportamiento de reinstalación: en iOS Keychain puede sobrevivirla.
9. Mantener un marcador de instalación no secreto; si una reinstalación dejó secretos de otra instalación en Keychain, descartarlos o revalidarlos conforme a la política documentada antes de usarlos. Diferenciarlo de perder únicamente la preferencia de modo.

**Pruebas/aceptación:** navegador usa cookies, jamás el fallback web del plugin; nativo recupera secretos tras reinicio; una falla del almacenamiento no finge persistencia exitosa; npx cap sync y builds de plataforma se ejecutan en sus entornos adecuados.

El [README del plugin propuesto](https://github.com/aparajita/capacitor-secure-storage) indica Keychain en iOS, AES-GCM con clave Keystore en Android y un fallback web de localStorage sin cifrar. Ese fallback web no es aceptable para este diseño. No necesita un servicio de pago.

### T10 — Estado de autenticación, arranque e interceptores

**Ubicación:** core/auth, core/guards, core/interceptors, core/native; composición existente en src/main.ts.

**Trabajo:**

1. Mantener una sola fuente de verdad con signals readonly y transiciones explícitas. Estados mínimos: initializing, personal_guest, personal_authenticated, shared_verifying, shared_selector, shared_authenticated y connection_error.
2. Separar el estado de dispositivo vinculado del de usuario autenticado. Un 401 del empleado no debe borrar automáticamente la credencial del dispositivo.
3. Implementar bootstrap que recupere credenciales, consulte servidor, reconcilie la preferencia y solo entonces habilite navegación protegida.
4. Preferir servicios pequeños de sesión/dispositivo. Las páginas de features/auth dependen de servicios; guards/interceptores dependen de infraestructura core y no de páginas.
5. Interceptor funcional: Authorization solo para API Bearer autorizada; X-Shared-Device-Token solo donde corresponde; X-GAM-Session para contexto PIN; cookies/XSRF para web. Nunca adjuntar secretos a una URL de imágenes, Swagger, telemetry o baseUrl arbitraria.
6. Mapear 401/403/419/429/red/5xx según contrato. No convertir cualquier error en logout ni eliminar información útil de validación.
7. Invalidar solicitudes/estado de la generación anterior y limpiar caches al cambiar de usuario. No almacenar al usuario como prueba de autenticación ni confiar en roles guardados.
8. Los guards esperan inicialización y devuelven navegación declarativa; no usar redirecciones repetidas ni un booleano local como autorización.

**Pruebas/aceptación:** no hay flash de home protegido al iniciar; token válido recupera usuario; token revocado lleva al login correcto; red caída conserva credencial; interceptor no fuga secretos; una respuesta tardía de A no aparece en la sesión de B.

### T11 — Pantallas de acceso personal

**Ubicación:** features/auth/pages, services, interfaces, types y auth.routes.ts; routing raíz.

**Trabajo:**

1. Crear login con correo, contraseña, mostrar/ocultar contraseña, loading y errores en español.
2. Usar formularios tipados coherentes, autocomplete username/current-password y validación accesible.
3. No ofrecer selector de rol ni lista de empleados en modo normal.
4. Elegir flujo cookie/Bearer desde infraestructura de plataforma y delegar política al servidor.
5. Tras autenticar, mostrar navegación por capacidades devueltas. Si una función del repartidor no existe, ofrecer un estado honesto; no inventar endpoints.
6. Incluir «Configurar como dispositivo compartido» y logout explícito. No agregar autorregistro.
7. Implementar confirmación de contraseña al recibir el requisito de reautenticación para una operación sensible.

**Pruebas/aceptación:** enter/envío doble no duplican login; mensaje correcto para credenciales/red; funciona con teclado abierto, en móvil estrecho y web; cierre y reapertura conservan sesión personal dentro del plazo.

### T12 — Vinculación y experiencia compartida

**Ubicación:** features/auth para vinculación, selector y PIN; servicios core para ciclo de vida.

**Trabajo:**

1. Implementar captura del código con estado de canje; guardar el modo solo al completar la persistencia de la credencial.
2. Selector con nombres grandes, búsqueda solo si el volumen la justifica y paginación del contrato. No precargar el listado sin validación del dispositivo.
3. PIN como input password con inputmode=numeric y longitud 4, tratado como string. No usar type=number. Evitar exposición a lectores de pantalla y conservar etiquetas accesibles.
4. Mostrar claramente el empleado activo y un botón grande «Terminar». El éxito al terminar limpia al usuario y conserva el dispositivo.
5. Implementar aviso de inactividad y actividad limitada. Una operación automática o un spinner no simulan interacción.
6. En background, bloqueo o reinicio, volver a PIN; registrar/desregistrar listeners del plugin App una sola vez para no duplicar temporizadores.
7. Cancelar/ignorar respuestas viejas, limpiar formularios, caches y navegación Ionic que conserve páginas anteriores. No restaurar datos del último empleado mediante route reuse.
8. Manejar pérdida de red, PIN bloqueado, cambio de permisos y dispositivo revocado. El usuario ve una acción de recuperación adecuada.
9. Resolver pestañas compartidas y sesión obsoleta con X-GAM-Session; la coordinación local no reemplaza validación servidor.

**Pruebas/aceptación:** dos empleados seguidos no ven datos del anterior; cerrar app obliga PIN pero no nuevo código; «0007» se envía íntegro; flag manipulado no carga lista; 429 muestra espera; perder red no desvincula ni permite PIN offline.

### T13 — Panel administrativo

**Ubicación:** nuevas features/users y features/shared-devices, con la estructura simple indicada por AGENTS.md.

**Trabajo:**

1. Crear/listar/deshabilitar usuarios y asignar roles permitidos.
2. Configurar/resetear/deshabilitar/desbloquear PIN; nunca «ver PIN actual».
3. Generar código con nombre de dispositivo, vencimiento y una vista que desaparezca al salir; no persistirlo en el navegador.
4. Listar dispositivos con estado y última actividad; revocar el correcto con una confirmación identificable.
5. Mostrar sesiones propias y opción administrativa de revocación según permisos.
6. La administración debe funcionar desde web y móvil personal; todos los endpoints siguen protegidos por backend y confirmación de contraseña.

**Pruebas/aceptación:** un empleado no alcanza estas funciones por URL; un admin distingue revocar sesión de revocar tablet; la UI nunca necesita leer un hash o secreto anterior; errores del servidor no se disfrazan de éxito.

### T14 — Completar pruebas de seguridad backend

**Ubicación:** tests/Feature/IdentityAndAccess y tests/Unit/IdentityAndAccess cuando haya lógica pura.

**Trabajo:**

1. Completar la matriz de sección 6 con PHPUnit, factories y reloj controlado.
2. Conservar AuthenticationTest, AuthorizationTest y AdminSeederTest actualizando el contrato que este plan cambia; no eliminarlos para ocultar fallos.
3. Para la nueva capa de auth_sessions, ajustar helpers de pruebas existentes que usan Sanctum::actingAs. No abrir una excepción de producción para que actingAs funcione sin contexto.
4. Probar endpoints críticos con tokens/cookies/dispositivo reales. actingAs no demuestra expiración, parsing Bearer, asociación de dispositivo ni resistencia al replay.
5. Probar la matriz completa de permisos en la política y agregar pruebas HTTP que demuestren su integración.
6. Reutilizar el patrón PostgreSQL de tests/Feature/Lots/LotsConcurrencyTest.php para las carreras de canje/cambio de empleado; no reemplazarlo por SQLite.
7. Probar middleware CSRF habilitado realmente: el entorno testing suele evitarlo por defecto, por lo que un simple postJson exitoso no demuestra protección.
8. Probar límites persistentes y comportamiento Redis con un namespace/base aislados cuando corresponda; CACHE_STORE=array no demuestra coordinación entre procesos.

**Aceptación:** cada prueba captura un defecto concreto; las nuevas rutas no quedan verificadas solo mediante mocks; cambios de tiempo no usan sleep; pruebas pasan solas y con las suites afectadas.

### T15 — Completar pruebas frontend y dispositivos

**Ubicación:** specs junto a core/auth, guards, interceptors, native wrappers y features; docs/testing.md.

**Trabajo:**

1. Probar el ApiClient faltante: versión de URL, métodos, params, headers y override. Probarlo una vez, no repetir su implementación en cada feature.
2. Probar servicios de sesión y estados de la sección 6 con Vitest y HTTP testing de Angular.
3. Probar guards/interceptores con respuestas 401/403/419/429 y requests fuera del origen permitido.
4. Probar pantallas por comportamiento observable y temporizadores falsos; cubrir PIN con ceros iniciales y limpieza entre empleados.
5. Ejecutar pruebas de integración web con el backend real para cookies, CSRF, reload, múltiples pestañas y revocación. Registrar resultados; jsdom no sustituye un navegador real.
6. Validar persistencia, suspensión y revocación en Android y iOS reales o emuladores. Una prueba mock del plugin no demuestra almacenamiento seguro.
7. Usar herramientas instaladas para la automatización de esta entrega. No agregar Dusk ni un framework E2E completo por defecto; el recorrido integrado puede ejecutarse manualmente y quedar documentado. Si se acuerda automatizarlo después, incorporarlo como decisión explícita.

**Aceptación:** lint/test:ci/build pasan y el recorrido integrado de cada plataforma queda ejecutado o claramente marcado pendiente con motivo. No declarar compatibilidad iOS solo por compilar TypeScript.

### T16 — CI, migración y operación

**Archivos:** nuevos workflows .github/workflows/checks.yml; configuración de análisis estático; compose/env/proxy de desarrollo; rutas de scheduler y documentación de despliegue.

**Trabajo:**

1. Backend CI: PHP 8.5, dependencias del lock, PostgreSQL aislado, Redis de pruebas si se necesita, migraciones y suites; agregar control de formato conforme al proyecto.
2. Larastan ya está instalado pero no hay phpstan.neon en el snapshot. Crear una configuración útil para código nuevo/afectado y separar deuda previa sin silenciar errores nuevos.
3. Frontend CI: npm ci, lint, test:ci, build y audit de producción. Mantener lockfiles; no usar audit fix --force ni downgrades para ocultar alertas.
4. No exponer secretos de despliegue a jobs no confiables; usar credenciales ficticias de testing y permisos mínimos.
5. Migrar con las fases de la sección 8. Respaldar antes de aplicar migraciones; no usar migrate:fresh ni down -v.
6. Instalar el pepper una sola vez como secreto estable y configurar HTTPS/cookies/orígenes/reloj/scheduler.
7. Verificar que limpieza y reinicios no cambian el modo del dispositivo ni resetean el PIN/presupuesto de intentos.
8. Dejar instrucciones de recuperación: pérdida de tablet/teléfono, PIN olvidado, cambio de administrador y vencimiento anual del dispositivo.

**Aceptación:** CI reproducible, migración ensayada con datos ficticios existentes y rollback de aplicación descrito; no se necesita un servicio SaaS nuevo ni intervención diaria del desarrollador.

### T17 — Actualizar los MD y verificar entrega

Esta es una tarea obligatoria del pedido, no una mejora opcional.

| Repositorio / archivo | Cambio requerido |
| --- | --- |
| Backend AGENTS.md y CLAUDE.md | Incorporar invariantes PIN/dispositivo/contexto; mantener ambas guías coherentes |
| Backend architecture.md | Reemplazar la afirmación Bearer-only; explicar sesiones web y separación dispositivo/usuario |
| Backend libraries.md | Uso real de Sanctum y restricciones de sus abilities; no afirmar que cookies/CSRF no se usan |
| Backend README.md | Configuración, pruebas, vinculación, revocación y recuperación |
| Backend nuevo identity-access-implementation.md | Modelo, endpoints, TTL, PIN/pepper, límites y guía operativa |
| Backend notion.md | Registrar entregables completados únicamente cuando estén implementados |
| Backend .ai/rules, si existe entonces | Registrar reglas duraderas aplicables usando la herramienta del proyecto si está disponible |
| Frontend AGENTS.md | Arranque, modo local no autoritativo, almacenamiento seguro y prohibición de recuperar empleado automáticamente |
| Frontend docs/architecture.md | Estado de sesión, responsabilidades de core/features y flujos |
| Frontend docs/api-integration.md | Contrato /api/v1, cookies/Bearer, errores, headers y proxy |
| Frontend docs/security.md | PIN/cookies/secretos, fugas por interceptores, XSRF y limpieza |
| Frontend docs/mobile-native-guidelines.md | Keychain/Keystore, suspensión, backups, reinstalación y pruebas |
| Frontend docs/testing.md | Matriz y limitaciones reales de cada nivel de prueba |
| Frontend docs/development-workflow.md | Baseline, comandos y nuevas validaciones |
| Frontend nuevo docs/adr/007-multi-login-and-sessions.md | Decisión, alternativas descartadas y consecuencias |
| Frontend architecture.md y bootstrap.md | Alinear solo las afirmaciones que hayan quedado obsoletas respecto del código |

Además, actualizar contracts/openapi/authentication.yaml y su configuración Swagger, aunque no sean MD. No copiar una matriz distinta en cada archivo: mantener el detalle en una fuente canónica y enlaces desde las guías.

**Aceptación:** búsquedas de «no usa cookies», «Bearer en todos», «registro público», TTL anteriores y almacenamiento inseguro no encuentran instrucciones contradictorias vigentes. Las versiones/limitaciones y ejemplos son reales. Documento final describe lo implementado, no aspiraciones.

## 6. Matriz de pruebas de aceptación

### 6.1 Backend

| ID | Escenario / defecto que debe detectar | Resultado esperado |
| --- | --- | --- |
| B01 | Login por contraseña autorizado y no autorizado por canal | Solo crea credencial/contexto para el canal permitido |
| B02 | Token personal nuevo al día 8, 89 y 91 | Válido, válido, 401; detecta límite global viejo |
| B03 | PAT expirado o revocado reutilizado | 401, sin regeneración automática |
| B04 | Cookie web después de cerrar/reabrir; pasado límite absoluto | Persiste dentro de vigencia; luego login |
| B05 | ID de sesión pre-login reutilizado | No autentica; el login regeneró ID |
| B06 | Mutaciones web sin CSRF / CSRF correcto | Rechazo / éxito autorizado; cubrir login, pairing, PIN, logout y desvinculación |
| B07 | Registro público y alta administrativa | Público 404; admin 201 sin token del empleado |
| B08 | Cambio de password/baja/restauración de usuario | Sesiones antiguas inválidas incluso tras restaurarlo |
| B09 | PIN «0007», cuatro dígitos repetidos y mismo PIN para dos usuarios | Se verifica por usuario sin perder ceros; hashes diferentes |
| B10 | PIN como entero, 3/5 dígitos, espacios o dígitos Unicode | 422 |
| B11 | Serialización de User, Resources, errores y eventos | Ausencia de PIN, hash, pepper, password y secretos |
| B12 | PIN incorrecto/deshabilitado/usuario no elegible | Error genérico, ninguna sesión emitida |
| B13 | Cuenta administrativa con PIN inyectado en DB | No puede iniciar sesión PIN |
| B14 | Falta/rotación controlada de pepper | Fallo seguro o migración explícita; nunca verificación con secreto vacío |
| B15 | Lista de empleados sin credencial, con UUID o PAT personal | 401; no filtra usuarios |
| B16 | Lista correcta | Solo empleados habilitados, id/nombre y paginación |
| B17 | Código vencido/usado/falso y autor ya deshabilitado | Canje rechazado, sin dispositivo usable |
| B18 | Dos procesos canjean el mismo código | Un solo consumo/dispositivo |
| B19 | Credencial dispositivo intenta una operación de negocio | Rechazo: no autentica a ningún usuario |
| B20 | PIN válido desde dispositivo revocado/vencido | 401 antes de emitir sesión |
| B21 | Credencial dispositivo de B + PAT PIN de A | Rechazo aunque ambos sean válidos por separado |
| B22 | PIN A seguido de PIN B, incluyendo concurrencia | Solo la generación final puede operar |
| B23 | Formulario/tab viejo de A con cookie nueva de B | Rechazo por X-GAM-Session; no registrar actor B por error |
| B24 | Usuario PIN con permisos extra intenta admin/report share/ajuste no listado | 403 por contexto |
| B25 | Ruta nueva no agregada a allowlist | Denegada para PIN |
| B26 | Inactividad a 119/121 s y máximo a 8 h | Límite correcto; polling/heartbeat vencido no lo esquivan |
| B27 | Cinco fallos cambiando IP/dispositivo | Bloqueo global por cuenta; ningún hash innecesario durante bloqueo |
| B28 | Veinte fallos acumulados y reinicio de app/cache | Se conserva presupuesto persistente y Retry-After |
| B29 | Fallo PIN provoca rollback de respuesta | El contador de fallo no se revierte |
| B30 | Logout empleado / desvincular dispositivo | El primero conserva vinculación; el segundo invalida todas sus sesiones |
| B31 | Revocación de sesión ajena sin permiso | Rechazo sin cambiar filas |
| B32 | Revocación por admin con contraseña confirmada | Efectiva en siguiente request |
| B33 | Fallo de auditoría en alta PIN/canje/revocación | Rollback de cambio crítico, sin secreto emitido como éxito |
| B34 | Cambio de roles/permisos con sesión vigente | Siguiente petición respeta permisos actuales |
| B35 | Cookie + Bearer de identidades distintas | No hay fallback a una identidad privilegiada |
| B36 | Limpieza de expirados | Elimina solo candidatos previstos; historial auditado permanece |

Los casos de campos sensibles deben revisar respuestas y sinks reales usados por la aplicación. No afirmar una auditoría exhaustiva de logs de infraestructura si no se inspeccionaron.

### 6.2 Frontend e integración

| ID | Escenario | Resultado esperado |
| --- | --- | --- |
| F01 | Arranque sin datos | Login de contraseña, sin listado de empleados |
| F02 | Reabrir app/browser personal | Restaura identidad validada por servidor |
| F03 | Cambiar manualmente modo local a shared | No obtiene lista sin credencial |
| F04 | Perder solo preferencia local con credencial válida | Reconciliación segura con servidor |
| F05 | Credencial revocada y sin red, por separado | Revocada limpia; sin red conserva y muestra recuperación |
| F06 | Respuesta del canje perdida / falla de secure storage | No anuncia vinculación completa; recuperación explícita |
| F07 | PIN con ceros, backspace y reenvío | Payload string correcto; no doble sesión accidental |
| F08 | Inactividad, background, resume y cierre del proceso | Empleado vuelve a PIN; dispositivo sigue vinculado |
| F09 | A/B con HTTP tardío y navegación Ionic cacheada | Ningún dato/formulario de A se muestra como B |
| F10 | 401 usuario, 401 dispositivo, 403, 419, 429 y timeout | Estados diferenciados, sin logout/desvinculación indiscriminados |
| F11 | ApiClient apunta a host externo por override | No envía Authorization, dispositivo ni XSRF |
| F12 | Uso web del wrapper seguro nativo | No cae a localStorage para secretos |
| F13 | Logout web y replay de cookie / logout nativo y replay de PAT | Backend rechaza acceso |
| F14 | Administrador configura/reset PIN y revoca tablet | UI y servidor coinciden sin exponer secreto anterior |
| F15 | Dos tabs de navegador compartido | X-GAM-Session evita mutación con identidad equivocada |
| F16 | Teclado, lector de pantalla, pantalla estrecha, safe areas | Acceso y acción principal utilizables |
| F17 | Android/iOS: kill/restart, reinicio equipo, reinstalación | Persistencia según política; credenciales no se copian inadvertidamente |
| F18 | API_URL móvil configurada con localhost por error | Detección en validación/configuración; usar host accesible desde dispositivo |

## 7. Comandos y política de verificación

No ampliar suites continuamente sin un motivo. Durante una tarea, ejecutar lo más acotado que demuestre su cambio. Al integrar autenticación, correr también las suites de negocio afectadas porque su middleware es transversal.

### Backend

El README exige pruebas dentro del contenedor api con variables inyectadas antes de iniciar PHP. Usar una base gam_test existente y aislada. Este comando reproduce esa estrategia; se puede agregar al final un archivo/directorio o --filter:

~~~bash
docker compose -f compose.dev.yaml exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=gam_test \
  -e CACHE_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  -e MAIL_MAILER=array \
  -e BROADCAST_CONNECTION=null \
  -e PULSE_ENABLED=false \
  -e TELESCOPE_ENABLED=false \
  -e NIGHTWATCH_ENABLED=false \
  api vendor/bin/phpunit --configuration phpunit.xml tests/Feature/IdentityAndAccess
~~~

Para pruebas de persistencia real de sesiones o coordinación Redis, cambiar únicamente la configuración de ese grupo de pruebas a un almacén aislado. No reutilizar el Redis de producción ni afirmar persistencia entre procesos usando array.

Comandos adicionales, después de confirmar configuración y versión:

~~~bash
docker compose -f compose.dev.yaml exec -T api composer show --direct
docker compose -f compose.dev.yaml exec -T api php artisan route:list --path=api/v1
docker compose -f compose.dev.yaml exec -T api vendor/bin/pint --dirty --format agent
docker compose -f compose.dev.yaml exec -T api vendor/bin/phpstan analyse
~~~

phpstan requiere primero la configuración de T16. Pint debe seguir el comando del AGENTS del repositorio; en CI comprobar luego que no generó diff. No ejecutar comandos destructivos para resolver una base de pruebas ausente.

### Frontend

~~~bash
npm ci
npm run lint
npm run test:ci
npm run build
npm audit --omit=dev
~~~

Cuando cambie integración nativa, tras instalar las plataformas:

~~~bash
npx cap sync
~~~

Compilar Android con el toolchain compatible y iOS con macOS/Xcode. Registrar el dispositivo/simulador, versión OS y resultado. No reemplazar estas comprobaciones por npx cap sync: sincronizar no compila ni prueba el plugin.

## 8. Migración, compatibilidad y recuperación

1. **Preparar:** respaldar base y configuración, instalar pepper estable, configurar TTL y despliegue HTTPS. Registrar tokens/cuentas existentes solo como metadatos; nunca volcar secretos.
2. **Expandir esquema:** aplicar migraciones aditivas; pin_enabled=false y pin_hash=null en usuarios previos.
3. **Actualizar permisos:** ejecutar un seeder de permisos separado; no volver a ejecutar AdminUserSeeder para resetear cuentas existentes. Asignar explícitamente acceso personal a cuentas legítimas y revisar usuarios creados por el registro público.
4. **Compatibilidad de PAT existentes:** crear auth_sessions personales para PAT previos de usuarios habilitados y autorizados, preservando expires_at. Revocar los que no cumplan la nueva política. No inferir que son administradores por el nombre browser/login del token.
5. **Desplegar backend y frontend coordinadamente:** el backend ofrece los nuevos contratos antes de habilitar la UI. Si hay clientes externos usando /autenticacion/registro, comunicar el cambio de contrato; no mantener un bypass público de producción.
6. **Habilitar:** verificar login personal, una vinculación y dos empleados de prueba; configurar PIN reales por flujo administrativo.
7. **Operar:** limpieza programada, backups y recuperación descritos. La empresa gestiona cuentas/PIN/dispositivos desde el panel.
8. **Rollback:** preferir revertir código/flags preservando las tablas nuevas. Deshabilitar canje/PIN si hay un problema; no reabrir el registro público, no borrar datos de negocio ni el historial. Si se revocaron tokens durante la migración, el rollback no debe restaurarlos.

Los PAT heredados sin expires_at necesitan una política explícita: usar su fecha de creación y un límite máximo acordado de 90 días, o revocarlos si ya lo superaron. No hacerlos perpetuos al quitar el límite global.

Los tokens nuevos siempre tienen fecha; si un cliente exige conservar una sesión más allá de 90 días, tratar renovación como una evolución con diseño/pruebas propios. No agregar un refresh token casero de forma implícita.

## 9. Instrucciones de calidad para el agente implementador

- Código e identificadores en inglés; mensajes públicos, comentarios y PHPDoc en español, según las reglas del backend.
- Backend: Controllers delgados, FormRequests custom, Actions dueñas de transacción y auditoría; Queries para lecturas; conservar APIs públicas entre módulos.
- Frontend: standalone, zoneless, signals, features simples. No introducir NgRx, Zone.js, capas de repositorios o nuevas abstracciones por rutina.
- No transformar ApiClient en servicio de negocio/autenticación ni importar environment desde features.
- No atribuir los permisos de una sesión a lo que declare localStorage, un JWT decodificado, un DTO del cliente o el tamaño de pantalla.
- No guardar contraseña para reautenticar automáticamente. No almacenar PIN/hashes offline.
- No resolver CORS/CSRF desactivando protección, usando comodines o agregando todos los orígenes como stateful.
- No mover el PIN al campo password, truncar un número a cuatro dígitos ni cambiar su longitud a seis.
- No saltarse controles de producción para acomodar factories o pruebas existentes.
- No hacer actualizaciones masivas de dependencias ni reescribir módulos no relacionados.
- Las dependencias nativas propuestas son parte de este plan; verificar compatibilidad y documentar su incorporación. No agregar dependencias de testing o SaaS fuera del alcance por comodidad.
- Si una decisión necesaria no está cubierta, elegir la alternativa coherente con este contrato y documentarla; detenerse solo ante un conflicto material que no pueda resolverse con los requisitos visibles.
- Al finalizar, reportar una matriz de T00–T17 con estado real. Una plataforma sin comprobar permanece pendiente; no presentarla como validada.

## 10. Fuentes técnicas y precisión del análisis

Las decisiones de alcance, plazos, PIN de cuatro dígitos y lista operativa son propuestas de este plan basadas en el pedido. Los hechos sobre GAM provienen de los archivos de los commits identificados.

La documentación consultada apoya los mecanismos, no certifica la seguridad de la implementación futura:

- Para separar cookie SPA y PAT nativo, expiración/revocación y la limitación de tokenCan en SPA: [Laravel Sanctum](https://laravel.com/framework/docs/13.x/sanctum).
- Para login/logout de sesión y regeneración: [autenticación de Laravel](https://laravel.com/framework/docs/13.x/authentication).
- Para protección de datos persistidos en dispositivos: [seguridad de Capacitor](https://capacitorjs.com/docs/guides/security).
- Para el hasher y separación del pepper: [OWASP Password Storage](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html).
- Para autorización por request: [OWASP Authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
- Para límites de intentos: [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).
- Para inactividad, expiración e invalidación: [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
- Para cookies XSRF: [seguridad de Angular](https://angular.dev/best-practices/security). Además se inspeccionó el código instalado de Angular 22.1.5: su interceptor compara el origen de la petición con el de la página. No confiar en ejemplos antiguos que afirman que todas las URLs absolutas se excluyen; las de otro origen sí requieren atención.
- Para propiedades y límites del almacenamiento candidato: [capacitor-secure-storage](https://github.com/aparajita/capacitor-secure-storage).

No se modificó código de los repositorios ni se publicaron commits/PRs al producir este plan.