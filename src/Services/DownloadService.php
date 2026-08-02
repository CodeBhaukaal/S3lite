<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\FileRecord;
use App\Storage\StorageManager;

/**
 * Streams file bytes with HTTP range support, and records the transfer.
 */
final class DownloadService
{
    private const CHUNK = 262144; // 256 KB

    public static function serve(
        Request $request,
        array $file,
        bool $inline = false,
        ?int $shareId = null,
        string $source = 'web'
    ): Response {
        $disk = StorageManager::disk((string) $file['disk']);

        if (!$disk->exists((string) $file['storage_path'])) {
            throw new HttpException(404, 'The stored file is missing from disk.', 'file_missing');
        }

        $size = (int) $file['size'] ?: $disk->size((string) $file['storage_path']);
        $mime = (string) $file['mime'];

        $disposition = $inline && MimeGuard::canInline($mime) ? 'inline' : 'attachment';
        $servingMime = $disposition === 'inline' ? $mime : MimeGuard::safeServingMime($mime);

        $filename = (string) $file['name'];
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';

        $headers = [
            'Content-Type'           => $servingMime,
            'Content-Disposition'    => sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $disposition,
                str_replace('"', '', $fallback),
                rawurlencode($filename)
            ),
            'Accept-Ranges'          => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=0, must-revalidate',
            'ETag'                   => '"' . substr((string) $file['checksum'], 0, 32) . '"',
            'Last-Modified'          => gmdate('D, d M Y H:i:s', strtotime((string) $file['updated_at']) ?: time()) . ' GMT',
        ];

        // Conditional request: nothing to send.
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null && trim($ifNoneMatch, '"') === substr((string) $file['checksum'], 0, 32)) {
            return Response::make('', 304, $headers);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $request->header('Range');
        if ($range !== null && preg_match('/bytes=(\d*)-(\d*)/', $range, $m) === 1) {
            $rangeStart = $m[1] === '' ? null : (int) $m[1];
            $rangeEnd = $m[2] === '' ? null : (int) $m[2];

            if ($rangeStart === null && $rangeEnd !== null) {
                // Suffix range: last N bytes.
                $start = max(0, $size - $rangeEnd);
            } else {
                $start = $rangeStart ?? 0;
                $end = $rangeEnd ?? ($size - 1);
            }

            if ($start > $end || $start >= $size) {
                return Response::make('', 416, ['Content-Range' => 'bytes */' . $size]);
            }

            $end = min($end, $size - 1);
            $status = 206;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        $startedAt = microtime(true);
        $path = (string) $file['storage_path'];

        $response = Response::stream(static function () use ($disk, $path, $start, $length): void {
            // Remote backends resume server-side rather than shipping the
            // leading bytes just so we can skip them.
            $stream = $disk->readStream($path, $start);

            if ($stream === null) {
                return;
            }

            $remaining = $length;

            while ($remaining > 0 && !feof($stream)) {
                $chunk = fread($stream, (int) min(self::CHUNK, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);

                if (connection_aborted()) {
                    break;
                }

                flush();
            }

            fclose($stream);
        }, $status, $headers);

        if ($request->method !== 'HEAD') {
            self::record($request, $file, $length, $shareId, $source, (int) round((microtime(true) - $startedAt) * 1000));
        }

        return $response;
    }

    private static function record(Request $request, array $file, int $bytes, ?int $shareId, string $source, int $durationMs): void
    {
        try {
            Database::insert('downloads', [
                'file_id'     => (int) $file['id'],
                'share_id'    => $shareId,
                'user_id'     => Auth::id(),
                'bytes'       => $bytes,
                'duration_ms' => $durationMs,
                'source'      => $source,
                'ip'          => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'referer'     => mb_substr((string) $request->header('Referer', ''), 0, 255) ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            FileRecord::incrementDownloads((int) $file['id']);

            if ($shareId !== null) {
                \App\Models\Share::registerHit($shareId);
            }

            WebhookService::dispatch('file.downloaded', [
                'file_id'  => (int) $file['id'],
                'name'     => $file['name'],
                'bytes'    => $bytes,
                'share_id' => $shareId,
                'ip'       => $request->ip(),
            ], (int) $file['user_id']);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Download logging failed: ' . $e->getMessage());
        }
    }

    /** Inline preview payload for text-like files. */
    public static function previewText(array $file, int $maxBytes = 262144): string
    {
        $disk = StorageManager::disk((string) $file['disk']);
        $stream = $disk->readStream((string) $file['storage_path']);

        if ($stream === null) {
            return '';
        }

        $contents = (string) fread($stream, $maxBytes);
        fclose($stream);

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        return $contents;
    }
}
