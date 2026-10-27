<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/MemoryFirestoreTransport.php';

use PawLife\Auth\AuthenticatedUser;
use PawLife\Auth\EmailSync;
use PawLife\Firestore\FirestoreClient;

function mailCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function mailFixture(): array {
    $memory = new MemoryFirestoreTransport();
    $memory->put('users/a', ['email' => 'viejo@example.test', 'nombre' => 'Persona A', 'premium' => false]);
    $memory->put('users/b', ['email' => 'b@example.test', 'nombre' => 'Persona B']);
    return [$memory, new FirestoreClient('test-project', null, $memory)];
}
function mailCommits(MemoryFirestoreTransport $memory): int {
    return count(array_filter($memory->requests, static fn (array $r): bool => str_ends_with($r[1], ':commit')));
}
$user = static fn (?string $email, bool $verified, string $provider = 'password'): AuthenticatedUser =>
    new AuthenticatedUser('a', $email, signInProvider: $provider, emailVerified: $verified);

// 1. Correo verificado distinto: se actualiza solo ese campo y la fecha; nada ajeno.
[$memory, $db] = mailFixture();
$profile = $db->getDocument('users/a');
$result = EmailSync::sync($db, $user('nuevo@example.test', true), $profile);
mailCheck($result['email'] === 'nuevo@example.test', 'No devolvio el perfil actualizado');
mailCheck($memory->records['users/a']['data']['email'] === 'nuevo@example.test', 'No se guardo el correo verificado');
mailCheck($memory->records['users/a']['data']['nombre'] === 'Persona A' && $memory->records['users/a']['data']['premium'] === false, 'Se tocaron otros campos');
mailCheck($memory->records['users/b']['data']['email'] === 'b@example.test', 'Se tocó otra cuenta');

// 2. Correo sin verificar o sin cambios: no hay escrituras.
foreach ([[$user('nuevo@example.test', false), 'sin verificar'], [$user('VIEJO@example.test', true), 'mismo correo en otra capitalizacion'],
    [$user(null, true), 'sin correo'], [$user('x@example.test', true, 'anonymous'), 'anonimo']] as [$who, $label]) {
    [$memory, $db] = mailFixture(); $before = mailCommits($memory);
    $result = EmailSync::sync($db, $who, $db->getDocument('users/a'));
    mailCheck($result['email'] === 'viejo@example.test' && mailCommits($memory) === $before, "Escribio en caso: $label");
}

// 3. Cuenta en borrado: no se escribe aunque el correo difiera.
[$memory, $db] = mailFixture();
$memory->put('users/a', ['email' => 'viejo@example.test', 'estadoCuenta' => 'eliminando']);
$before = mailCommits($memory);
$result = EmailSync::sync($db, $user('nuevo@example.test', true), $db->getDocument('users/a'));
mailCheck($result['email'] === 'viejo@example.test' && mailCommits($memory) === $before, 'Escribio en una cuenta que se esta eliminando');

// 4. Carreras: borrado o cambio concurrente no rompen la peticion; se reintenta despues.
[$memory, $db] = mailFixture();
$memory->beforeCommit = static function ($m): void { $m->put('users/a', ['email' => 'viejo@example.test', 'estadoCuenta' => 'eliminando']); };
$result = EmailSync::sync($db, $user('nuevo@example.test', true), ['email' => 'viejo@example.test', 'nombre' => 'Persona A']);
mailCheck($result['email'] === 'viejo@example.test' && $memory->records['users/a']['data']['email'] === 'viejo@example.test', 'La carrera con el borrado cambio el correo');
[$memory, $db] = mailFixture();
$memory->beforeCommit = static function ($m): void { $m->put('users/a', ['email' => 'otro@example.test', 'nombre' => 'Persona A']); };
$result = EmailSync::sync($db, $user('nuevo@example.test', true), $db->getDocument('users/a'));
mailCheck($result['email'] === 'viejo@example.test', 'Debia conservar el perfil leido ante conflicto');
mailCheck($memory->records['users/a']['data']['email'] === 'otro@example.test', 'Piso una escritura concurrente');

echo "TODO OK: correo verificado sincroniza solo el perfil propio y no escribe sin verificar, sin cambios, ni durante el borrado\n";
