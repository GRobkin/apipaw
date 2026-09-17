# PawLife API

Backend en PHP de PawLife, pensado para desplegarse en Vercel. Guarda los datos
en Firestore y autentica con los ID token que emite Firebase Auth en la app
Flutter.

El cliente Flutter vive en `../flutter_application_1` y ya no habla con
Firestore directamente: todo pasa por esta API.

## Por que esta escrito asi

Dos decisiones que condicionan todo el codigo y conviene no deshacer sin saber
lo que se rompe:

**Firestore por REST, no por SDK.** El paquete oficial `google/cloud-firestore`
habla gRPC y necesita la extension de PHP correspondiente. El runtime de PHP en
Vercel es comunitario (`vercel-php`) y no la trae, ni se puede instalar. Asi que
`src/Firestore/` habla con la API REST de Firestore por HTTP normal y traduce a
mano el formato de valores etiquetados que usa (`{"stringValue": "..."}`).

**Sin Composer.** Como no hace falta ninguna libreria externa — la firma y la
verificacion de JWT salen de `openssl`, que viene de serie — el proyecto no
tiene dependencias y usa un autoloader PSR-4 propio de diez lineas en
`src/bootstrap.php`. Un paso menos que pueda fallar en el build.

## Seguridad: donde esta ahora el aislamiento entre usuarios

Antes lo garantizaban las reglas de `firestore.rules`: el cliente escribia
directo y Firestore comprobaba `request.auth.uid == uid`.

Ahora quien escribe es este backend con una service account, **que se salta las
reglas**. El aislamiento pasa a depender de dos cosas:

1. `Auth\FirebaseTokenVerifier` verifica la firma del ID token contra los
   certificados publicos de Google y comprueba emisor, audiencia y caducidad.
   De ahi sale el `uid`.
2. Todas las rutas de Firestore se construyen como `users/{uid}/...` con ese
   uid, nunca con algo que venga del cuerpo o de la URL.

Por eso `firestore.rules` pasa a denegar todo acceso directo desde clientes: si
alguien sigue teniendo la config de Firebase de la app, no debe poder saltarse
la API.

## Configuracion

Variables de entorno (ver `.env.example`):

| Variable | Para que |
| --- | --- |
| `FIREBASE_PROJECT_ID` | Proyecto de Firebase. Se usa para verificar el `aud` del token y para construir las URLs de Firestore. |
| `FIREBASE_SERVICE_ACCOUNT` | JSON de la service account, o ese mismo JSON en base64. |
| `ALLOWED_ORIGINS` | Origenes permitidos por CORS, separados por coma. `*` por defecto. Solo importa en Flutter Web. |

La service account se saca en la consola de Firebase, en **Configuracion del
proyecto > Cuentas de servicio > Generar nueva clave privada**.

En Vercel conviene meterla en base64, porque el JSON lleva saltos de linea
dentro de `private_key` y segun como se pegue en la UI llegan destrozados:

```bash
base64 -w0 service-account.json     # en Windows: certutil -encode, y quita las cabeceras
```

Tambien hay que habilitar la **Cloud Firestore API** en el proyecto de Google
Cloud asociado, si no lo estaba ya.

## Desplegar en Vercel

`vercel.json` ya declara el runtime y la reescritura de todas las rutas a
`api/index.php`.

1. Sube esta carpeta a su propio repositorio, o usa el monorepo y pon
   `apipaw` como **Root Directory** del proyecto en Vercel.
2. Framework Preset: **Other**.
3. Anade las variables de entorno de arriba (Production y Preview).
4. Despliega y comprueba: `curl https://<tu-proyecto>.vercel.app/api/health`.
   Debe responder `{"ok":true,...,"credenciales":true}`.

## Desarrollo local

```bash
cp .env.example .env     # y rellena los valores
php -S localhost:8000 api/index.php
curl http://localhost:8000/api/health
```

Para probar una ruta con autenticacion hace falta un ID token real. El mas
comodo es sacarlo de la propia app, con la app corriendo:

```dart
debugPrint(await FirebaseAuth.instance.currentUser!.getIdToken());
```

```bash
curl http://localhost:8000/api/mascotas -H "Authorization: Bearer $TOKEN"
```

Los ID token caducan a la hora.

### Pruebas

`tests/selftest.php` cubre lo que se puede probar sin tocar Firestore: el mapeo
de valores de Firestore en las dos direcciones, la validacion de los seis
recursos, el formato de salida, la lectura de paseos antiguos con GeoPoint y la
firma/verificacion de JWT. No necesita credenciales ni red.

```bash
php tests/selftest.php
```

Si `openssl_pkey_new` falla en Windows con un PHP portable, es que falta
apuntar `OPENSSL_CONF` al `openssl.cnf` que viene en `extras/ssl/`.

## Endpoints

Todas las rutas menos `/api/health` exigen
`Authorization: Bearer <ID token de Firebase>`.

| Metodo | Ruta | Que hace |
| --- | --- | --- |
| GET | `/api/health` | Estado del despliegue. Sin autenticacion. |
| GET | `/api/me` | Datos del usuario del token. |
| GET/POST | `/api/mascotas` | Listar / crear mascotas. |
| GET/PATCH/DELETE | `/api/mascotas/{id}` | Ver / editar / borrar una mascota. |
| GET/POST | `/api/mascotas/{mascotaId}/vacunas` | Vacunas de la mascota. |
| GET/PATCH/DELETE | `/api/mascotas/{mascotaId}/vacunas/{id}` | Una vacuna. |
| GET/POST | `/api/mascotas/{mascotaId}/medicamentos` | Medicamentos. |
| GET/PATCH/DELETE | `/api/mascotas/{mascotaId}/medicamentos/{id}` | Un medicamento. |
| GET/POST | `/api/mascotas/{mascotaId}/pesos` | Registros de peso. |
| GET/PATCH/DELETE | `/api/mascotas/{mascotaId}/pesos/{id}` | Un registro de peso. |
| GET/POST | `/api/mascotas/{mascotaId}/paseos` | Paseos. |
| GET/PATCH/DELETE | `/api/mascotas/{mascotaId}/paseos/{id}` | Un paseo. |
| GET/POST | `/api/recordatorios` | Recordatorios del usuario. |
| GET/PATCH/DELETE | `/api/recordatorios/{id}` | Un recordatorio. |

`PUT` funciona como alias de `PATCH`. Los listados aceptan `?orderBy=campo desc`
y `?limit=n`.

### Formato

Las fechas viajan como cadenas ISO 8601 en UTC, no como el `Timestamp` de
Firestore. Un listado responde `{"items": [...], "total": n}` y un elemento
suelto responde el objeto directamente, siempre con su `id`.

```jsonc
// POST /api/mascotas/buddy_123/paseos
{
  "fechaInicio": "2026-09-17T09:00:00.000Z",
  "fechaFin": "2026-09-17T09:32:10.000Z",
  "duracionSegundos": 1930,
  "distanciaMetros": 2413.5,
  "velocidadMaximaKmh": 7.8,
  "ruta": [
    { "lat": 41.3874, "lng": 2.1686, "timestamp": "2026-09-17T09:00:00.000Z" }
  ]
}
```

Los errores salen siempre con la misma forma, y los de validacion detallan que
campo falla:

```jsonc
{
  "error": {
    "status": 400,
    "message": "Datos invalidos para paseo.",
    "details": { "distanciaMetros": "Debe ser un numero." }
  }
}
```

## Estructura en Firestore

```
users/{uid}/mascotas/{mascotaId}
users/{uid}/mascotas/{mascotaId}/vacunas/{id}
users/{uid}/mascotas/{mascotaId}/medicamentos/{id}
users/{uid}/mascotas/{mascotaId}/pesos/{id}
users/{uid}/mascotas/{mascotaId}/paseos/{id}
users/{uid}/recordatorios/{id}
```

Es la misma que usaba la app antes, con un cambio en los paseos: donde habia
`ruta` (lista de GeoPoint, sin tiempos) y `rutaDetallada` (con tiempos) ahora
hay un unico campo `ruta` con `{lat, lng, timestamp}` por punto. Los paseos
viejos se siguen leyendo — `Firestore\Value` decodifica los GeoPoint — pero
llegan sin timestamps por punto.

## Mapa del codigo

```
api/index.php              Front controller: monta el router y despacha.
src/bootstrap.php          Autoloader PSR-4 propio y carga del .env.
src/Http/                  Request, Response, Router y HttpException.
src/Auth/                  Service account y verificacion de ID token.
src/Firestore/             Access token de Google, cliente REST y mapeo de valores.
src/Resources/             Campos, esquemas de los seis recursos y CRUD generico.
src/Support/               cURL, JSON, JWT, cache en /tmp y variables de entorno.
```
