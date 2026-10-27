<?php
declare(strict_types=1);
namespace PawLife\Resources;
use PawLife\Http\HttpException;

/** No shell interpolation, image files, tokens or private stderr in responses. */
final class NodePhotoDecoder
{
    public static function decode(string $bytes): string
    {
        if (!function_exists('proc_open')) self::unavailable();
        $script = dirname(__DIR__, 2) . '/tool/jpeg-codec.cjs';
        $pipes = [];
        $process = @proc_open(['node', '--max-old-space-size=160', $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), null, ['bypass_shell' => true, 'blocking_pipes' => false]);
        if (!is_resource($process)) self::unavailable();
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $offset = 0; $out = ''; $stderr = ''; $started = microtime(true); $exit = -1;
        try {
            while (true) {
                if (isset($pipes[0])) {
                    $written = @fwrite($pipes[0], substr($bytes, $offset, 8192));
                    if ($written === false) self::unavailable();
                    $offset += $written;
                    if ($offset === strlen($bytes)) { fclose($pipes[0]); unset($pipes[0]); }
                }
                $out .= (string) fread($pipes[1], 8192);
                $stderr .= (string) fread($pipes[2], 1024);
                if (strlen($out) > 307182 || strlen($stderr) > 4096 || microtime(true) - $started > 10) self::unavailable();
                $status = proc_get_status($process);
                if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) { $exit = $status['exitcode']; break; }
                usleep(1000);
            }
        } finally {
            foreach ($pipes as $pipe) fclose($pipe);
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process);
            proc_close($process);
        }
        if ($exit !== 0) {
            if ($exit === 2) throw new HttpException(400, 'La foto JPEG no se pudo decodificar.', errorCode: 'INVALID_PHOTO');
            self::unavailable();
        }
        if ($out === '' || !str_starts_with($out, "\xFF\xD8")) self::unavailable();
        return $out;
    }
    private static function unavailable(): never
    {
        throw new HttpException(503, 'No se pudo procesar la foto. Reintentá más tarde.', errorCode: 'PHOTO_PROCESSOR_UNAVAILABLE');
    }
}
