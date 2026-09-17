<?php

declare(strict_types=1);

namespace PawLife\Support;

use RuntimeException;

/**
 * Cliente HTTP minimo sobre cURL, para hablar con Google (OAuth y Firestore).
 * No usamos Guzzle para no arrastrar Composer al build de Vercel.
 */
final class HttpClient
{
    /**
     * @param array<string, string>            $headers
     * @param array<string, mixed>|string|null $body  array => se manda como JSON
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public static function send(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        int $timeoutSeconds = 10,
    ): array {
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('No se pudo inicializar cURL.');
        }

        if (is_array($body)) {
            $body = Json::encode($body);
            $headers['Content-Type'] = 'application/json';
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // No se llama a curl_close(): desde PHP 8.0 el handle es un objeto que
        // se libera solo al salir del ámbito, la función no hace nada, y desde
        // PHP 8.5 está deprecada. Como Vercel corre 8.5, llamarla imprimía un
        // aviso en mitad de la respuesta y rompía todas las cabeceras.

        if ($raw === false) {
            throw new RuntimeException("Fallo la peticion a $url: $error");
        }

        return ['status' => $status, 'body' => (string) $raw, 'headers' => $responseHeaders];
    }
}
