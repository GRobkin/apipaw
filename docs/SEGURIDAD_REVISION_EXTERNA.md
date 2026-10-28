# Paquete para la revisión externa de seguridad (28/10/2026)

Esto **no es** la revisión: prepara el trabajo de quien la haga (una persona o equipo ajeno). La revisión externa no se puede hacer desde el código ni por quien lo escribió.

## Qué revisar (código nuevo desde la auditoría del 15/10)
Ramas locales de `apipaw` (encadenadas): `respaldo/etapas-1-3-borrado-y-correo` → `feature/t04-inactividad` → `feature/t09-enviar-a-todos` → `feature/t11-premium-manual`. App: `PawLife` `feature/us28-us29-idiomas-tema`.

| Área | Archivos | Pregunta clave |
|---|---|---|
| Cron firmado | `src/Notifications/CronSignature.php`, `cloudflare/src/index.js`, `api/index.php` (`/api/internal/*`) | ¿La firma ata método, ruta y hora (ventana 180 s)? ¿Se puede reutilizar dentro de la ventana? ¿Rutas nuevas: `inactivity-reminders`, `process-notification-jobs`? |
| Envío a todos | `src/Notifications/GlobalNotification.php`, `FcmSender.php`, `panel/global-job.mjs` | Permiso al crear y al procesar; idempotencia; audiencia congelada; un registro por dispositivo; qué pasa si el proceso muere a mitad; tope de 35 s por tramo |
| Premium manual | `src/Billing/*`, `src/Auth/PanelCapabilities.php`, `api/index.php` (`/api/admin/users/{uid}/premium*`, `/api/admin/premium-users`) | Capacidad `premium.manage` separada; autenticación reciente; IDOR (concesión de otra cuenta); enumeración por correo; motivo sin datos personales |
| Preferencias | `src/Privacy/PrivacyService.php` | Validación de `inactividad` e `idioma`; no se pueden tocar campos de privilegio |
| Inactividad | `src/Notifications/InactivityReminderService.php` | Solo cuentas que lo activaron; un aviso por episodio; consultas acotadas |
| Panel | `panel/*`, `src/Http/NotificationPanel.php` | XSS (se usa `textContent`), CSP, lista fija de archivos servidos, sesión en memoria |
| Fotos | `src/Resources/PhotoInput.php`, `NodePhotoDecoder.php`, `PhotoDiagnostics.php`, `GET /api/admin/diagnostics/photos` | Qué decodificador hay en el despliegue real (el runtime no publica GD) |
| Borrado y barrera | `src/Privacy/AccountLifecycle.php` | `premiumGrants`/`premiumAudit` bajo la barrera y borradas con la cuenta |
| App | `PawLife/lib/services/{api_client,auth_service}.dart`, `lib/l10n/error_messages.dart` | Reautenticación, errores sin datos privados, nada sensible en logs |

## Autoevaluación (no sustituye la externa)
- Probado con dobles en memoria (sin Firestore/FCM reales): 16 scripts PHP + 13 tests Node + 103 tests Flutter. Ver cada `docs/T0*.md` para límites.
- Hallazgo propio corregido: el envío a todos podía superar los 60 s de la función de Vercel; ahora cada tramo se limita a 35 s.
- Conocido y aceptado por diseño: un envío con resultado incierto no se reintenta (puede no llegar, no se duplica).
- Conocido y pendiente: la firma del cron se puede reutilizar dentro de su ventana de 3 minutos (no hay nonce); `configuracion/*` depende de que las reglas de Firestore sigan negando acceso de cliente.
- No cubierto: reglas de Firestore e IAM reales, secretos y variables de Vercel/Cloudflare, dependencias (`npm`/`composer`), infraestructura.

## Cómo reproducir
```
cd apipaw
for t in tests/*.php; do php $t | tail -1; done   # photo_decoder_unavailable_test.php: php -n -d disable_functions=proc_open
node --test tests/*.mjs
cd ../PawLife && flutter analyze && flutter test
```

## Qué necesita el revisor de vos
Acceso de solo lectura al repositorio (ramas arriba), al proyecto Firebase `pawlife-84d14` (reglas, IAM, índices) y a la configuración de Vercel/Cloudflare, sin secretos en claro.
