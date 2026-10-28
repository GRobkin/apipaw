<?php

declare(strict_types=1);

namespace PawLife\Http;

final class NotificationPanel
{
    public static function serve(string $path): bool
    {
        $files = ['/notificaciones' => ['index.html', 'text/html'],
            '/notificaciones/app.js' => ['app.js', 'text/javascript'],
            '/notificaciones/notification-batch.mjs' => ['notification-batch.mjs', 'text/javascript'],
            '/notificaciones/global-job.mjs' => ['global-job.mjs', 'text/javascript'],
            '/notificaciones/style.css' => ['style.css', 'text/css'],
            '/notificaciones/icons.svg' => ['icons.svg', 'image/svg+xml'],
            '/notificaciones/assets/NunitoSans.ttf' => ['assets/NunitoSans.ttf', 'font/ttf'],
            '/notificaciones/assets/welcome.png' => ['assets/welcome.png', 'image/png'],
            '/notificaciones/assets/google.png' => ['assets/google.png', 'image/png']];
        if (!isset($files[$path])) return false;
        [$file, $type] = $files[$path];
        header('Content-Type: ' . $type . '; charset=utf-8');
        header(str_starts_with($file, 'assets/')
            ? 'Cache-Control: public, max-age=86400, stale-while-revalidate=604800'
            : 'Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://www.gstatic.com https://apis.google.com; style-src 'self'; connect-src 'self' https://*.googleapis.com https://*.firebaseapp.com https://*.gstatic.com; frame-src https://*.firebaseapp.com https://accounts.google.com; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        readfile(dirname(__DIR__, 2) . '/panel/' . $file);
        return true;
    }
}
