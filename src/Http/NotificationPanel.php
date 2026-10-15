<?php

declare(strict_types=1);

namespace PawLife\Http;

final class NotificationPanel
{
    public static function serve(string $path): bool
    {
        $files = ['/notificaciones' => ['index.html', 'text/html'],
            '/notificaciones/app.js' => ['app.js', 'text/javascript'],
            '/notificaciones/style.css' => ['style.css', 'text/css']];
        if (!isset($files[$path])) return false;
        [$file, $type] = $files[$path];
        header('Content-Type: ' . $type . '; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://www.gstatic.com https://apis.google.com; style-src 'self'; connect-src 'self' https://*.googleapis.com https://*.firebaseapp.com https://*.gstatic.com; frame-src https://*.firebaseapp.com https://accounts.google.com; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        readfile(dirname(__DIR__, 2) . '/panel/' . $file);
        return true;
    }
}
