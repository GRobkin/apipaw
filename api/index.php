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
use PawLife\Firestore\FirestoreClient;
use PawLife\Http\HttpException;
use PawLife\Http\Request;
use PawLife\Http\Response;
use PawLife\Http\Router;
use PawLife\Resources\Catalog;
use PawLife\Resources\ResourceController;
use PawLife\Resources\Schema;
use PawLife\Support\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$request = Request::fromGlobals();
$origin = $request->header('origin');

// El preflight del navegador no lleva token: se contesta antes de tocar nada.
if ($request->method === 'OPTIONS') {
    http_response_code(204);
    Response::sendCorsHeaders($origin);
    exit;
}

try {
    $router = new Router();

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

    // Datos del usuario que hay detras del token. Util para depurar desde la app
    // y, mas adelante, para pintar el perfil en las pantallas de cuenta.
    $router->get('/api/me', $auth(static function (Request $r, array $p, AuthenticatedUser $u): Response {
        return Response::ok($u->toArray());
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
            $subcollections
        ): ResourceController {
            return $controller ??= new ResourceController(
                $schema,
                $db(),
                $collectionPath,
                $parentPath,
                $subcollections,
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

    $mascotasPath = static fn (AuthenticatedUser $u, array $p): string => "users/{$u->uid}/mascotas";
    $mascotaDoc = static fn (AuthenticatedUser $u, array $p): string => "users/{$u->uid}/mascotas/{$p['mascotaId']}";

    $resource(
        '/api/mascotas',
        Catalog::mascotas(),
        $mascotasPath,
        null,
        // Al borrar la mascota se lleva por delante todo lo que cuelga de ella.
        ['vacunas', 'medicamentos', 'pesos', 'paseos'],
    );

    foreach ([
        'vacunas' => Catalog::vacunas(),
        'medicamentos' => Catalog::medicamentos(),
        'pesos' => Catalog::pesos(),
        'paseos' => Catalog::paseos(),
    ] as $nombre => $schema) {
        $resource(
            "/api/mascotas/{mascotaId}/$nombre",
            $schema,
            static fn (AuthenticatedUser $u, array $p): string => "users/{$u->uid}/mascotas/{$p['mascotaId']}/$nombre",
            $mascotaDoc,
        );
    }

    $resource(
        '/api/recordatorios',
        Catalog::recordatorios(),
        static fn (AuthenticatedUser $u, array $p): string => "users/{$u->uid}/recordatorios",
    );

    $router->dispatch($request)->send($origin);
} catch (HttpException $e) {
    Response::error($e->status(), $e->getMessage(), $e->details())->send($origin);
} catch (Throwable $e) {
    // El detalle va al log de Vercel, no al cliente: puede contener trozos de
    // configuracion o rutas internas.
    error_log('[pawlife-api] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    Response::error(500, 'Error interno del servidor.')->send($origin);
}
