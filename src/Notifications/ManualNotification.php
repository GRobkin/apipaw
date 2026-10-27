<?php

declare(strict_types=1);

namespace PawLife\Notifications;

use DateTimeImmutable;
use PawLife\Http\HttpException;

/** Envío dirigido y acotado; la misma solicitud nunca vuelve a enviar. */
final class ManualNotification
{
    public function __construct(private readonly ReminderStore $db, private readonly PushSender $fcm) {}

    public static function validate(array $body): array
    {
        $uid = $body['userId'] ?? null;
        $message = $body['mensaje'] ?? null;
        $id = $body['solicitudId'] ?? null;
        if (!is_string($uid) || preg_match('/^[^\x00-\x20\/]{1,128}$/u', $uid) !== 1 || in_array($uid, ['.', '..'], true)) {
            throw HttpException::badRequest('Seleccioná un usuario destinatario valido.');
        }
        if (!is_string($message) || trim($message) === '' || preg_match('/^.{1,240}$/us', trim($message)) !== 1) {
            throw HttpException::badRequest('Escribí un mensaje de hasta 240 caracteres.');
        }
        if (!is_string($id) || preg_match('/^[a-zA-Z0-9-]{16,80}$/', $id) !== 1) {
            throw HttpException::badRequest('La solicitud necesita un identificador valido.');
        }
        return ['userId' => $uid, 'mensaje' => trim($message), 'solicitudId' => $id];
    }

    public function send(string $adminUid, array $body): array
    {
        $data = self::validate($body);
        $id = 'manual-' . hash('sha256', $adminUid . ':' . $data['solicitudId']);
        $path = 'notificaciones/' . $id;
        $existing = $this->db->getDocument($path);
        if ($existing !== null) {
            if (($existing['userId'] ?? null) !== $data['userId'] || ($existing['mensaje'] ?? null) !== $data['mensaje']) {
                throw new HttpException(409, 'La solicitud ya se usó para otro mensaje.');
            }
            if (!isset($existing['resultado'])) throw new HttpException(409, 'Este envío ya está en proceso.');
            return $existing['resultado'];
        }
        $profile = $this->db->getDocument('users/' . $data['userId']);
        if ($profile === null) {
            throw HttpException::notFound('El destinatario ya no tiene perfil en PawLife.');
        }
        if (($profile['estadoCuenta'] ?? 'activa') !== 'activa'
            || !\PawLife\Privacy\PrivacyService::preferences($profile)['avisosEquipo']) {
            throw new HttpException(422, 'La cuenta no admite avisos del equipo.', errorCode: 'RECIPIENT_NOTIFICATIONS_DISABLED');
        }
        $devices = $this->db->queryDocuments('dispositivos', ['userId' => $data['userId']], 'actualizadoEn desc', 30);
        $tokens = [];
        foreach ($devices as $device) {
            $info = $device['data'];
            $token = $info['token'] ?? null;
            if (!is_string($token) || $token === '' || ($info['demo'] ?? false) === true || ($info['activo'] ?? true) === false) continue;
            $tokens[$token] = true;
        }
        if ($tokens === []) throw new HttpException(422, 'El usuario debe abrir PawLife en un teléfono y permitir las notificaciones antes de recibir avisos.');
        (new ReminderRateLimiter($this->db))->consume('admin-notification:' . $adminUid);
        // createDocument reserva el envío antes de FCM y evita carreras entre
        // dos pestañas o reintentos tras perder la respuesta HTTP.
        $this->db->createDocument('notificaciones', [
            'userId' => $data['userId'], 'administradorId' => $adminUid,
            'mensaje' => $data['mensaje'], 'tipo' => 'manual', 'estado' => 'procesando',
            'creadoEn' => new DateTimeImmutable(),
        ], $id);
        $selected = array_slice(array_keys($tokens), 0, 3);
        $result = ['id' => $id, 'aceptadas' => 0, 'fallidas' => 0, 'dispositivos' => count($selected), 'limitados' => max(0, count($tokens) - 3)];
        foreach ($selected as $token) {
            $current = $this->db->getDocument('users/' . $data['userId']);
            if ($current === null || ($current['estadoCuenta'] ?? 'activa') !== 'activa'
                || !\PawLife\Privacy\PrivacyService::preferences($current)['avisosEquipo']) {
                $result['fallidas'] += count($selected) - $result['aceptadas'] - $result['fallidas'];
                break;
            }
            try {
                $this->fcm->send($token, $id, '', 'manual', $data['mensaje']);
                $result['aceptadas']++;
            } catch (\Throwable) { $result['fallidas']++; }
        }
        $this->db->patchDocument($path, [
            'estado' => $result['fallidas'] === 0 ? 'aceptada' : 'parcial_o_fallida',
            'resultado' => $result, 'actualizadoEn' => new DateTimeImmutable(),
        ]);
        return $result;
    }
}
