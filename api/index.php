<?php

declare(strict_types=1);

/**
 * Front controller de la API de PawLife.
 *
 * En Vercel todo el trafico se reescribe a este archivo (ver vercel.json), asi
 * que aqui se monta el router entero. En local se consigue lo mismo con:
 *
 *   php -S localhost:8000 api/index.php
 */

use PawLife\Auth\AuthenticatedUser;
use PawLife\Auth\FirebaseTokenVerifier;
use PawLife\Auth\FirebaseAuthAdmin;
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Http\Response;
use PawLife\Http\Router;
use PawLife\Resources\Catalog;
use PawLife\Resources\CareValidator;
use PawLife\Resources\ResourceController;
use PawLife\Resources\Schema;
use PawLife\Notifications\ReminderRateLimiter;
use PawLife\Support\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null;
try {
    $request = Request::fromGlobals();
    if ($request->method === 'GET' && \PawLife\Http\NotificationPanel::serve($request->path)) exit;

    // El preflight del navegador no lleva token: se contesta antes de tocar nada.
    if ($request->method === 'OPTIONS') {
        http_response_code(204);
        Response::sendCorsHeaders($origin);
        exit;
    }

    $router = new Router();
    $router->get('/api/panel-config', static fn (): Response => Response::ok(
        \PawLife\Support\Json::decodeObject((string) file_get_contents(dirname(__DIR__) . '/panel/firebase-config.json')) ?? [],
    ));

    // Sin autenticacion: sirve para comprobar que el despliegue esta vivo y que
    // las variables de entorno llegaron, sin necesidad de un token.
    $router->get('/api/health', static function (): Response {
        return Response::ok([
            'ok' => true,
            'servicio' => 'pawlife-api',
            'hora' => gmdate('c'),
            'proyecto' => Env::get('FIREBASE_PROJECT_ID'),
            'credenciales' => Env::get('FIREBASE_SERVICE_ACCOUNT') !== null,
        ]);
    });

    // Cloudflare firma cada llamada. La clave privada solo vive en el Worker;
    // la API conserva la clave publica y las credenciales de Firebase.
    $router->post('/api/internal/send-reminders', static function (Request $r): Response {
        \PawLife\Notifications\CronSignature::verify($r);
        $result = (new \PawLife\Notifications\PushDispatcher(
            FirestoreClient::fromEnv(),
            new \PawLife\Notifications\FcmSender(),
        ))->dispatch(dryRun: $r->queryParam('dryRun') === '1');
        return Response::ok($result);
    });

    // Genera avisos de "sin paseos" (opt-in). El push lo envía send-reminders.
    $router->post('/api/internal/inactivity-reminders', static function (Request $r): Response {
        \PawLife\Notifications\CronSignature::verify($r, \PawLife\Notifications\CronSignature::INACTIVITY_PATH);
        $result = (new \PawLife\Notifications\InactivityReminderService(FirestoreClient::fromEnv()))
            ->run(dryRun: $r->queryParam('dryRun') === '1');
        return Response::ok($result);
    });

    // Avanza el envío a todos (si hay uno en curso). Presupuesto propio: no frena los recordatorios.
    $router->post('/api/internal/process-notification-jobs', static function (Request $r): Response {
        \PawLife\Notifications\CronSignature::verify($r, \PawLife\Notifications\CronSignature::NOTIFICATION_JOBS_PATH);
        $result = (new \PawLife\Notifications\GlobalNotification(
            FirestoreClient::fromEnv(),
            new \PawLife\Notifications\FcmSender(),
        ))->processNext();
        return Response::ok($result);
    });

    // Termina borrados de cuenta pendientes: no depende de que el token del usuario siga vivo.
    $router->post('/api/internal/delete-accounts', static function (Request $r): Response {
        \PawLife\Notifications\CronSignature::verify($r, \PawLife\Notifications\CronSignature::DELETE_ACCOUNTS_PATH);
        return Response::ok((new \PawLife\Privacy\AccountDeletionService(FirestoreClient::fromEnv()))->runPending(2));
    });

    // El usuario se verifica una sola vez y solo cuando la ruta lo necesita,
    // para que /api/health siga respondiendo aunque el token sea invalido.
    $user = null;
    $currentUser = static function () use (&$user, $request): AuthenticatedUser {
        if ($user === null) {
            $user = FirebaseTokenVerifier::fromEnv()->verify($request->bearerToken());
        }

        return $user;
    };

    $firestore = null;
    $db = static function () use (&$firestore): FirestoreClient {
        return $firestore ??= FirestoreClient::fromEnv();
    };

    /** Envuelve un handler para que reciba el usuario ya verificado. */
    $auth = static function (callable $handler) use ($currentUser): callable {
        return static function (Request $request, array $params) use ($handler, $currentUser): Response {
            return $handler($request, $params, $currentUser());
        };
    };

    $router->get('/api/mascotas/{mascotaId}/historial-paseos', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        $page = $db()->walkHistoryPage($u->uid, $p['mascotaId'], $r->queryParam('cursor'));
        return Response::ok([
            'items' => array_map(static fn (array $doc): array => Catalog::paseos()->toJson($doc['id'], $doc['data']), $page['documents']),
            'nextCursor' => $page['nextCursor'],
        ]);
    }));

    $admin = static function (AuthenticatedUser $u) use ($db): void {
        \PawLife\Auth\NotificationAdmin::assertAllowed($u, $db());
    };
    // El panel informa qué capacidades tiene la cuenta; cada endpoint vuelve a comprobarlas.
    $router->get('/api/admin/session', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        $avisos = false;
        $premium = false;
        try { \PawLife\Auth\NotificationAdmin::assertAllowed($u, $db()); $avisos = true; } catch (HttpException) {}
        try { \PawLife\Billing\ManualGrantService::assertAllowed($u, $db()); $premium = true; } catch (HttpException) {}
        if (!$avisos && !$premium) \PawLife\Auth\NotificationAdmin::assertAllowed($u, $db()); // lanza el 403 de siempre
        return Response::ok(['email' => $u->email, 'uid' => $u->uid, 'avisos' => $avisos, 'premium' => $premium]);
    }));
    $router->get('/api/admin/notification-users', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($admin, $db): Response {
        $admin($u);
        $email = trim($r->queryParam('email', '') ?? '');
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpException::badRequest('Escribí el correo completo del destinatario.');
        }
        $profile = FirebaseAuthAdmin::findByEmail($email);
        if ($profile === null || $db()->getDocument('users/' . $profile['uid']) === null) {
            return Response::ok(['items' => []]);
        }
        return Response::ok(['items' => [$profile]]);
    }));
    $router->post('/api/admin/notifications', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($admin, $db): Response {
        $admin($u);
        return Response::ok((new \PawLife\Notifications\ManualNotification($db(), new \PawLife\Notifications\FcmSender()))->send($u->uid, $r->body));
    }));

    // Premium manual: capacidad propia `premium.manage` (no la tiene todo administrador de avisos).
    $premiumAdmin = static function (AuthenticatedUser $u) use ($db): void {
        \PawLife\Billing\ManualGrantService::assertAllowed($u, $db());
    };
    $router->get('/api/admin/premium-users', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($premiumAdmin, $db): Response {
        $premiumAdmin($u);
        (new \PawLife\Notifications\ReminderRateLimiter($db()))->consume('premium-lookup:' . $u->uid);
        $email = trim($r->queryParam('email', '') ?? '');
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw HttpException::badRequest('Escribí el correo completo de la cuenta.');
        }
        $profile = FirebaseAuthAdmin::findByEmail($email);
        if ($profile === null || $db()->getDocument('users/' . $profile['uid']) === null) return Response::ok(['items' => []]);
        return Response::ok(['items' => [$profile]]);
    }));
    $router->get('/api/admin/users/{uid}/premium', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        return Response::ok((new \PawLife\Billing\ManualGrantService($db()))->state($u, $p['uid']));
    }));
    $router->post('/api/admin/users/{uid}/premium-grants', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        return new Response(201, (new \PawLife\Billing\ManualGrantService($db()))->grant($u, $p['uid'], $r->body));
    }));
    $router->delete('/api/admin/users/{uid}/premium-grants/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        return Response::ok((new \PawLife\Billing\ManualGrantService($db()))->revoke($u, $p['uid'], $p['id']));
    }));
    // Estado propio recalculado en el servidor (la app no lo decide).
    $router->get('/api/me/premium', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        $state = (new \PawLife\Billing\EntitlementService($db()))->effective($u->uid);
        return Response::ok(['activo' => $state['activo'], 'estado' => $state['estado'], 'venceEn' => $state['venceEn'],
            'fuentes' => ['manual' => $state['fuentes']['manual']['activa'], 'pago' => $state['fuentes']['pago']['activa']]]);
    }));

    // Envío a todos: el trabajo lo procesa el cron; aquí solo se crea y se consulta.
    $router->post('/api/admin/notifications/global', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        return new Response(202, (new \PawLife\Notifications\GlobalNotification($db(), new \PawLife\Notifications\FcmSender()))->create($u, $r->body));
    }));
    $router->get('/api/admin/notifications/global/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db): Response {
        return Response::ok((new \PawLife\Notifications\GlobalNotification($db(), new \PawLife\Notifications\FcmSender()))->get($u, $p['id']));
    }));

    $ensurePerfil = static function (AuthenticatedUser $u) use ($db): array {
        $path = "users/{$u->uid}";
        $data = $db()->getDocument($path);
        if ($data !== null) {
            return \PawLife\Auth\EmailSync::sync($db(), $u, $data);
        }
        $now = new DateTimeImmutable();
        $inicial = [
            'email' => $u->email,
            'nombre' => $u->name ?? '',
            'fotoUrl' => $u->pictureUrl,
            'premium' => false,
            'premiumHasta' => null,
            'zonaHoraria' => 'America/Montevideo',
            'creadoEn' => $now,
            'actualizadoEn' => $now,
        ];
        try {
            return $db()->createDocument('users', $inicial, $u->uid)['data'];
        } catch (HttpException $e) {
            if ($e->status() !== 409) {
                throw $e;
            }
            // Otro dispositivo pudo crear el perfil al mismo tiempo.
            return $db()->getDocument($path) ?? throw $e;
        }
    };

    $router->get('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($ensurePerfil): Response {
        return Response::ok($u->toArray() + [
            'perfil' => Catalog::perfil()->toJson($u->uid, $ensurePerfil($u)),
        ]);
    }));

    $router->patch('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db, $ensurePerfil): Response {
        $data = Catalog::perfilEditable()->forUpdate($r->body);
        $existing = $ensurePerfil($u);
        $data = \PawLife\Resources\PhotoInput::prepare($data, $existing,
            static fn () => (new \PawLife\Security\WriteQuota($db()))->reservePhotoAttempt($u->uid));
        $data['actualizadoEn'] = new DateTimeImmutable();
        \PawLife\Firestore\DocumentBudget::assertFits(array_merge($existing, $data));
        $actualizado = $db()->patchDocument("users/{$u->uid}", $data);
        return Response::ok(Catalog::perfil()->toJson($u->uid, $actualizado['data']));
    }));

    $privacy = static fn (): \PawLife\Privacy\PrivacyService => new \PawLife\Privacy\PrivacyService($db(), [
        'responsable' => Env::get('PRIVACY_RESPONSIBLE', 'Soporte PawLife'),
        'email' => Env::get('PRIVACY_CONTACT_EMAIL', 'pawlife.support@gmail.com')]);
    $exports = static fn (): \PawLife\Privacy\ExportService => new \PawLife\Privacy\ExportService($db());
    $router->get('/api/me/privacy', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($privacy, $ensurePerfil): Response {
        $ensurePerfil($u);
        return Response::ok($privacy()->info($u->uid));
    }));
    $router->patch('/api/me/preferences', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($privacy): Response {
        return Response::ok($privacy()->updatePreferences($u->uid, $r->body));
    }));
    $router->post('/api/me/privacy-requests', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($privacy): Response {
        return new Response(202, $privacy()->request($u->uid, $r->body));
    }));
    $router->get('/api/me/privacy-requests', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($privacy): Response {
        return Response::ok($privacy()->requests($u->uid, $r->queryParam('cursor')));
    }));
    $router->get('/api/me/privacy-requests/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($privacy): Response {
        return Response::ok($privacy()->requestStatus($u->uid, $p['id']));
    }));
    $router->post('/api/me/exports', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($exports): Response {
        return new Response(202, $exports()->start($u));
    }));
    $router->get('/api/me/exports/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($exports): Response {
        return Response::ok($exports()->page($u, $p['id'], $r->queryParam('cursor')));
    }));
    $router->delete('/api/me/exports/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($exports): Response {
        $exports()->cancel($u, $p['id']);
        return Response::noContent();
    }));

    /**
     * Registra las cinco rutas del CRUD de un recurso.
     *
     * @param string $base         ej. "/api/mascotas/{mascotaId}/vacunas"
     * @param callable(AuthenticatedUser, array<string, string>): string $collectionPath
     */
    $resource = static function (
        string $base,
        Schema $schema,
        callable $collectionPath,
        ?callable $parentPath = null,
        array $subcollections = [],
        ?callable $scope = null,
        ?callable $cascadePlan = null,
        ?callable $validateWrite = null,
    ) use ($router, $auth, $db): void {
        // El controlador se construye en la primera peticion que lo use, no al
        // registrar la ruta: asi /api/health sigue contestando aunque falten
        // las credenciales de Firestore, que es justo cuando hace falta.
        $controller = null;
        $resolve = static function () use (
            &$controller,
            $schema,
            $db,
            $collectionPath,
            $parentPath,
            $subcollections,
            $scope,
            $cascadePlan,
            $validateWrite,
        ): ResourceController {
            return $controller ??= new ResourceController(
                $schema,
                $db(),
                $collectionPath,
                $parentPath,
                $subcollections,
                $scope,
                $cascadePlan,
                $validateWrite,
            );
        };

        $action = static function (string $method) use ($auth, $resolve): callable {
            return $auth(
                static fn (Request $r, array $p, AuthenticatedUser $u): Response => $resolve()->$method($r, $p, $u),
            );
        };

        $router->get($base, $action('index'));
        $router->post($base, $action('store'));
        $router->get("$base/{id}", $action('show'));
        $router->patch("$base/{id}", $action('update'));
        // PUT se acepta como alias de PATCH: es un merge parcial igualmente,
        // pero ahorra sorpresas a cualquier cliente que solo hable PUT.
        $router->put("$base/{id}", $action('update'));
        $router->delete("$base/{id}", $action('destroy'));
    };

    $mascotasPath = static fn (AuthenticatedUser $u, array $p): string => 'mascotas';
    $mascotaDoc = static fn (AuthenticatedUser $u, array $p): string => "mascotas/{$p['mascotaId']}";
    $porUsuario = static fn (AuthenticatedUser $u, array $p): array => ['userId' => $u->uid];
    $porMascota = static fn (AuthenticatedUser $u, array $p): array => [
        'userId' => $u->uid,
        'mascotaId' => $p['mascotaId'],
    ];

    $resource(
        '/api/mascotas',
        Catalog::mascotas(),
        $mascotasPath,
        null,
        // Al borrar la mascota se lleva por delante todo lo que cuelga de ella.
        [],
        $porUsuario,
        static fn (AuthenticatedUser $u, array $p, string $id): array => array_fill_keys(
            ['vacunas', 'medicamentos', 'alimentaciones', 'pesos', 'paseos', 'recordatorios'],
            ['userId' => $u->uid, 'mascotaId' => $id],
        ),
    );

    foreach ([
        'vacunas' => Catalog::vacunas(),
        'medicamentos' => Catalog::medicamentos(),
        'pesos' => Catalog::pesos(),
        'paseos' => Catalog::paseos(),
        'alimentaciones' => Catalog::alimentaciones(),
    ] as $nombre => $schema) {
        $cascade = in_array($nombre, ['vacunas', 'medicamentos'], true)
            ? static fn (AuthenticatedUser $u, array $p, string $id): array => [
                'recordatorios' => [
                    'filters' => ['userId' => $u->uid],
                    'matches' => [
                        'mascotaId' => $p['mascotaId'],
                        'tipo' => $nombre === 'vacunas' ? 'vacuna' : 'medicamento',
                        'entidadId' => $id,
                    ],
                ],
            ]
            : null;
        $resource(
            "/api/mascotas/{mascotaId}/$nombre",
            $schema,
            static fn (AuthenticatedUser $u, array $p): string => $nombre,
            $mascotaDoc,
            [],
            $porMascota,
            $cascade,
            static function (array $data, AuthenticatedUser $u) use ($nombre): void {
                CareValidator::check($nombre, $data);
            },
        );
    }

    $validateRecordatorio = static function (array $data, AuthenticatedUser $u) use ($db): void {
        $mascotaId = $data['mascotaId'] ?? null;
        $mascota = is_string($mascotaId) && $mascotaId !== ''
            ? $db()->getDocument("mascotas/$mascotaId") : null;
        if ($mascota === null || ($mascota['userId'] ?? null) !== $u->uid) {
            throw HttpException::badRequest('La mascota del recordatorio no existe o no pertenece a la cuenta.');
        }
        $collection = match ($data['tipo'] ?? null) {
            'vacuna' => 'vacunas',
            'medicamento' => 'medicamentos',
            default => null,
        };
        $entityId = $data['entidadId'] ?? null;
        if ($entityId !== null && $entityId !== '') {
            if ($collection === null || !is_string($entityId)) {
                throw HttpException::badRequest('La entidad del recordatorio no corresponde al tipo.');
            }
            $entity = $db()->getDocument("$collection/$entityId");
            if ($entity === null || ($entity['userId'] ?? null) !== $u->uid
                || ($entity['mascotaId'] ?? null) !== $mascotaId) {
                throw HttpException::badRequest('La entidad del recordatorio no pertenece a esa mascota.');
            }
        }
        if (($data['completado'] ?? false) !== true) {
            (new ReminderRateLimiter($db()))->consume($u->uid);
        }
    };

    $resource(
        '/api/recordatorios',
        Catalog::recordatorios(),
        static fn (AuthenticatedUser $u, array $p): string => 'recordatorios',
        null,
        [],
        $porUsuario,
        null,
        $validateRecordatorio,
    );

    // Borrado reanudable: reserva la cuenta (barrera persistente) y avanza por pasos.
    // Si no termina dentro de esta peticion, 202 y el worker de Cloudflare lo completa.
    $deletion = static fn (): \PawLife\Privacy\AccountDeletionService => new \PawLife\Privacy\AccountDeletionService($db());
    $router->delete('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deletion): Response {
        $service = $deletion();
        $service->start($u);
        $status = $service->resume($u->uid);
        return $status['estado'] === 'completa' ? Response::noContent() : new Response(202, $status);
    }));
    $router->get('/api/me/deletion', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deletion): Response {
        return Response::ok($deletion()->status($u->uid));
    }));

    $deviceRegistry = static fn (): \PawLife\Notifications\DeviceRegistry => new \PawLife\Notifications\DeviceRegistry($db());
    $router->get('/api/dispositivos', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($db, $porUsuario): Response {
        return (new ResourceController(Catalog::dispositivos(), $db(),
            static fn (): string => 'dispositivos', scope: $porUsuario))->index($r, $p, $u);
    }));
    $registerDevice = $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deviceRegistry): Response {
        $record = $deviceRegistry()->register($u->uid, $r->body);
        $data = Catalog::dispositivos()->toJson($record['id'], $record['data']);
        return $record['created'] ? Response::created($data) : Response::ok($data);
    });
    $router->post('/api/dispositivos', $registerDevice);
    // Ruta nueva para móviles actualizados: no crear IDs aleatorios si todavía
    // están conectados a una API anterior a esta migración.
    $router->post('/api/me/devices', $registerDevice);
    $router->post('/api/me/devices/revoke', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deviceRegistry): Response {
        $deviceRegistry()->revoke($u->uid, $r->body);
        return Response::ok(['revocado' => true]);
    }));
    $router->get('/api/dispositivos/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deviceRegistry): Response {
        $record = $deviceRegistry()->find($u->uid, $p['id']);
        if ($record === null) throw HttpException::notFound('No existe el dispositivo.');
        return Response::ok(Catalog::dispositivos()->toJson($record['id'], $record['data']));
    }));
    $updateDevice = $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deviceRegistry): Response {
        $record = $deviceRegistry()->update($u->uid, $p['id'], $r->body);
        return Response::ok(Catalog::dispositivos()->toJson($record['id'], $record['data']));
    });
    $router->patch('/api/dispositivos/{id}', $updateDevice);
    $router->put('/api/dispositivos/{id}', $updateDevice);
    $router->delete('/api/dispositivos/{id}', $auth(static function (Request $r, array $p, AuthenticatedUser $u) use ($deviceRegistry): Response {
        $deviceRegistry()->remove($u->uid, $p['id']);
        return Response::noContent();
    }));

    $router->dispatch($request)->send($origin);
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(12));
    if (!$e instanceof HttpException || $e->status() >= 500) {
        error_log(\PawLife\Http\ApiError::logRecord($e, $requestId));
    }
    \PawLife\Http\ApiError::response($e, $requestId)->send($origin);
}
