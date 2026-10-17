# Auditoría PawLife — 17/10/2026

## Dictamen

Código preparado para revisión en GitHub, con correcciones locales verificadas.
**No equivale a una versión íntegramente validada para producción.** No se hizo
push, merge, despliegue, modificación de permisos ni escritura en producción.
El usuario confirmó que no dispone de teléfono/cuenta de prueba: la recepción
push y los flujos nativos quedan pendientes explícitos.

## Alcance y arquitectura comprobada

Dos repositorios independientes en rama `master`: API PHP sin Composer y cliente
Flutter. El panel es HTML/CSS/JavaScript servido por PHP; no tiene un build npm.
Firebase Auth identifica, PHP verifica los ID tokens y usa Firestore REST con
cuenta de servicio. Los clientes no acceden directamente a Firestore. Las reglas
locales deniegan lectura/escritura; esas reglas no limitan a la cuenta de servicio.
Las imágenes reducidas viven como data URI; no se utiliza Firebase Storage.
FCM sirve los push móviles. Un Worker Cloudflare firma las llamadas del cron.
No se identificaron Cloud Functions como parte de este flujo.

El panel solo administra envíos: **no es un CRUD de mascotas o cuidados**.
La app consulta HTTP: no existe sincronización de datos en tiempo real. Los
avisos locales se actualizan al sincronizar/reabrir y ante cambios propios.
Las sesiones del panel están en memoria y no persisten al recargar por diseño.
En esta revisión también se limpian borrador, destinatario y diálogo cuando
cambia la sesión, y se maneja un rechazo al cerrar sesión. Verificado por revisión
de fuente y sintaxis JS; el cambio real entre cuentas sigue pendiente de prueba.

## Correcciones de esta auditoría

| Estado | Defecto y corrección | Evidencia y límite |
|---|---|---|
| Corregido y verificado | Flutter Web quedaba en blanco al inicializar ReceivePort del servicio móvil. Arranque limitado a Android/iOS. | Se reprodujo en navegador release y después abrió bienvenida, login y validación vacía. |
| Corregido y verificado | El timeout HTTP solo cubría cabeceras. Ahora incluye la lectura del cuerpo. | Nueva prueba con cabeceras inmediatas y stream bloqueado; termina con ApiException. |
| Corregido y verificado | DELETE /api/me aceptaba sesiones antiguas. Exige auth_time firmado de los últimos 300 segundos antes de cualquier borrado. | Prueba de ausencia, antigüedad, futuro y límite; mutación que omite la comprobación hace fallar la prueba; se restauró y pasó. No se borraron cuentas reales. |
| Corregido y verificado | Enteros fraccionarios se truncaban y números infinitos podían alcanzar persistencia. | Validación y pruebas negativas; suite PHP pasa. |
| Corregido y verificado | Texto/listas sin límites por campo. | Límites antes de persistir: texto 4096 bytes, notas 8192, fotos 409600; listas 100 entradas de 4096 bytes. Pruebas de exceso y límite. |
| Pendiente de prueba autenticada | El botón Google en Flutter Web rechazaba la plataforma. Usa ahora FirebaseAuth.signInWithPopup. | API confirmada en SDK instalado, análisis y build pasan; no se completó OAuth real. |
| Corregido, revisión de fuente | Paseo presentaba distancia × 60 como calorías de la mascota. | Se sustituyó por “Calorías: no disponible”; no se inventó un modelo veterinario. Esa métrica sigue pendiente. |
| Corregido y verificado | Exclusiones Git incompletas para futuros secretos. | Se agregaron .env.*, service-account JSON y, en Flutter, claves/propiedades de firma; se conserva .env.example. |

Los límites de texto no sustituyen un límite total del documento ni de la ruta
GPS. Revisar trayectos largos y tamaño acumulado antes de dar por cerrado ese
punto. No se truncaron recorridos ni se migraron datos existentes.

## Pruebas ejecutadas en esta revisión

| Estado | Verificación | Resultado |
|---|---|---|
| Verificado | php tests/selftest.php | 29 pruebas pasan, con OPENSSL_CONF local de Windows. |
| Verificado | php tests/security_regression.php | Paginación, cola de recordatorios y tamaño de solicitudes pasan. |
| Verificado | php tests/walk_notification_features.php | Acceso administrativo, selección de dispositivos, idempotencia, historial y foto pasan con dobles de prueba. |
| Verificado | PHP lint sobre api/src/tests | Todos los archivos revisados pasan. |
| Verificado | node --check panel/app.js | Pasa. |
| Verificado | flutter pub get | Resuelve sin cambios al lockfile; 41 versiones más recientes fuera de restricciones, lo cual no demuestra vulnerabilidad. |
| Verificado | flutter analyze --no-pub | Sin incidencias tras los cambios. |
| Verificado | flutter test --no-pub --coverage | 7 pruebas pasan: historial/paginación, error/vacío, transporte/cursor/foto, reintento sin duplicado, PNG, límites GPS y timeout del cuerpo. |
| Verificado | flutter build web --release --no-pub | Compila; aviso de fuente CupertinoIcons permanece. No hubo referencias directas a CupertinoIcons en lib. |
| Verificado | OSV querybatch, 131 dependencias hosted del lockfile | Sin avisos devueltos para esas versiones el 17/10. No evalúa el SDK, runtime Vercel, Gradle/CocoaPods ni código propio. |
| Verificado | git diff --check en ambos repositorios | Pasa; advertencias de conversión CRLF/LF. |

LCOV: **321/859 líneas, 37,37 %**, solamente en los 12 archivos incluidos por la
ejecución de los tests. **No es cobertura de toda la app.** No se midió cobertura
PHP ni porcentaje E2E; no se afirma cobertura completa.

## Panel: navegador real, datos simulados

Servidor local de trabajo en 8766, explícitamente marcado con datos ficticios.
Se comprobó búsqueda de destinatario, contenido asociado a la cuenta, escritura,
contador, confirmación, envío simulado, resultado, bloqueo del envío repetido,
nuevo aviso y error 503 simulado conservando el contenido editable.
El login carga Firebase sin errores de consola y habilita el botón. No se ingresó
con cuentas del equipo. No se verificaron expiración ni revocación mediante UI.

Se inspeccionó la composición renderizada; a 390 px el ancho del documento fue
390 px, a 768 fue 753 px útiles y en escritorio 1265 px útiles para viewport1280:
sin desbordamiento horizontal del documento en esos casos. Esto no cubre todos
los tamaños, escalado de texto, lectores de pantalla ni todas las animaciones.
El error simulado no produjo errores JavaScript de consola.
Playwright MCP no inició porque buscaba un Chrome inexistente en su ruta estándar;
se utilizó el navegador integrado disponible. No se instaló otro navegador.

## Producción: comprobaciones de solo lectura

En https://apipaw.vercel.app se verificaron:

- GET /api/health: 200 JSON.
- GET /api/me, /api/mascotas y /api/admin/session sin token: 401 JSON.
- GET /notificaciones: 200, CSP y X-Content-Type-Options: nosniff.

No se confundió este smoke test con autenticación, persistencia o entrega push.
El código corregido localmente **no está desplegado**. No se volvieron a consultar
IAM, administradores, reglas desplegadas, índices, secretos del Worker ni logs.

## Flujos e integraciones que quedan abiertos

| Estado | Flujo | Condición para cerrarlo |
|---|---|---|
| No verificable en esta revisión | Panel → PHP → FCM → Flutter abierto/en fondo/cerrado | Cuenta administradora de prueba, destinatario y Android/iPhone con permisos/token reales. |
| No verificable en esta revisión | Registro/login/reset/logout/Google y persistencia autenticada | Entorno de prueba con cuentas; no pedir ni registrar contraseñas en el informe. |
| No verificable en esta revisión | CRUD de mascota, vacunas, medicamentos, alimentación, pesos, tareas y notas desde Flutter hasta Firestore | Ejecutar cada ciclo en entorno de prueba, releer desde una segunda sesión y limpiar solo datos desechables. La existencia del código no cierra el caso. |
| No verificable en esta revisión | GPS con pantalla apagada, permisos, cámara, paseo completo y compartir PNG a otra app | Android/iPhone real. PNG automatizado no demuestra el menú nativo de compartir. |
| No verificable en esta revisión | Android debug/release, iOS y Windows | Falta Android SDK/ADB, macOS/Xcode y Visual Studio C++ respectivamente. No se generaron APK/AAB/IPA. |
| Pendiente | Push al abrir desde estado terminado | No se identificó getInitialMessage; comprobar y cerrar la actualización/navegación de arranque frío. |
| Pendiente | OAuth Google Web | Completar con dominio autorizado y cuenta; popup/cancelación/bloqueo de ventanas. |

Para el E2E futuro: usar dos usuarios normales y un administrador; crear mascota
y cada cuidado con A; releer/cambiar/completar; confirmar que B no puede acceder
por ID ni cursor; enviar a A desde el panel y observar el teléfono en tres
estados; cancelar/restaurar permisos; guardar paseo con desconexión/reintento;
exportar/compartir; borrar los datos de prueba al terminar con autorización.

## Pendientes y gravedad

- **Alta, distribución:** Android release sigue usando debug signing y applicationId de ejemplo. Necesita clave custodiada fuera de Git y coordinación con Firebase; no se cambió identidad ni se generó una clave sin acuerdo.
- **Alta, aceptación:** faltan los E2E autenticados y nativos anteriores. No cerrar la entrega como “100 % funcional”.
- **Media:** rutas/listados generales y búsqueda administrativa sin cuota global; no hay límite de tamaño total del documento/ruta. El límite de solicitud de 2 MiB no garantiza caber en un documento Firestore.
- **Media:** recuperación/restauración sin procedimiento probado. No se activó facturación.
- **Media:** confirmar soporte de paseo web: RouteViewModel sigue dependiendo del servicio móvil; corregir el arranque no valida GPS web.
- **Media:** términos/privacidad de bienvenida parecen enlaces por subrayado pero son TextSpan sin acción; requieren documentos reales y navegación, no texto legal inventado.
- **Baja:** metadatos web conservan flutter_application_1 y descripción de plantilla; aviso de fuente de build.
- **Producto:** US15, US18, US21, US27, US28 y US29 no se consideran completos. Premium requiere decisión de producto/proveedor/precio; idiomas y modo oscuro pendientes. US20 excluido expresamente. US19 tiene el arreglo web, con aceptación autenticada pendiente. Ver PLAN_US15_US29.md en Flutter.
- La tarjeta de visita veterinaria no tiene entidad/flujo y muestra “—”: no representa una funcionalidad terminada.

## Seguridad de Git y preparación

Se inspeccionaron 10 commits locales accesibles de apipaw y 21 de PawLife buscando
cabeceras de claves privadas, tokens GitHub y access keys AWS: sin coincidencias.
La búsqueda es por patrones, no una certificación de ausencia de secretos.
Las configuraciones Firebase cliente son públicas; no equivalen a credenciales
de servicio. La clave pública del cron también es pública. No se copiaron claves
privadas ni datos de usuarios al informe.

Se conservaron los cambios previos del panel y el plan Flutter. flutter pub get
actualizó los registradores generados de plugins desktop para dependencias ya
declaradas; no se añadieron paquetes. Builds/cobertura quedan ignorados.
No había workflows CI versionados en los repositorios inspeccionados; las
verificaciones descritas son locales, no checks verdes remotos.

Cambios de auditoría: api/index.php, src/Auth/AuthenticatedUser.php,
src/Auth/FirebaseTokenVerifier.php, src/Resources/Schema.php, tests/selftest.php,
.gitignore, README y este informe. Flutter: main, ApiClient, AuthService,
RouteViewModel, prueba ApiClient, .gitignore y registradores generados.
Los archivos del panel preexistentes siguen separados para revisión visual.

Antes de publicar: revisar diffs/commits de cada repositorio, confirmar rama y
destino, y decidir si se acepta subir código con pendientes documentados. Subir
a GitHub puede activar Vercel: **no se hizo push ni despliegue**.
