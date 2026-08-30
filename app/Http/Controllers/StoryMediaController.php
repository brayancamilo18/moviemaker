<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StoryMediaController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const ALLOWED = [
        'video.mp4' => 'video/mp4',
        'video-nograde.mp4' => 'video/mp4',
        'narration.mp3' => 'audio/mpeg',
        'subtitles.srt' => 'application/x-subrip',
        'contact-sheet-1.jpg' => 'image/jpeg',
        'contact-sheet-2.jpg' => 'image/jpeg',
    ];

    private const CHUNK_BYTES = 8192;

    private const CACHE_CONTROL = 'private, max-age=0, no-store';

    public function show(Request $request, Story $story, string $artifact): Response|StreamedResponse
    {
        if (! isset(self::ALLOWED[$artifact])) {
            return $this->empty(404);
        }

        $path = $story->directory().DIRECTORY_SEPARATOR.$artifact;

        if (! is_file($path)) {
            return $this->empty(404);
        }

        $size = filesize($path);

        if ($size === false) {
            return $this->empty(404);
        }

        $type = self::ALLOWED[$artifact];
        $range = $this->range($request->headers->get('Range'), $size);

        if ($range === false) {
            return $this->empty(416, [
                'Content-Range' => 'bytes */'.$size,
                'Content-Type' => $type,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        if ($range === null) {
            return $this->stream($path, 0, $size, 200, [
                'Content-Type' => $type,
                'Content-Length' => (string) $size,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        $length = $range['end'] - $range['start'] + 1;

        return $this->stream($path, $range['start'], $length, 206, [
            'Content-Type' => $type,
            'Content-Length' => (string) $length,
            'Content-Range' => sprintf('bytes %d-%d/%d', $range['start'], $range['end'], $size),
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * @return array{start: int, end: int}|false|null
     */
    private function range(?string $header, int $size): array|false|null
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if (preg_match('/^bytes=(\d+)-(\d+)?$/', trim($header), $matches) !== 1) {
            return false;
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== ''
            ? (int) $matches[2]
            : $size - 1;

        if ($size < 1 || $start >= $size || $end < $start) {
            return false;
        }

        return [
            'start' => $start,
            'end' => min($end, $size - 1),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function stream(string $path, int $start, int $length, int $status, array $headers): StreamedResponse
    {
        $headers['Cache-Control'] = self::CACHE_CONTROL;

        return new StreamedResponse(function () use ($path, $start, $length): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                if (fseek($handle, $start) !== 0) {
                    return;
                }

                $remaining = $length;

                while ($remaining > 0 && ! feof($handle)) {
                    $chunk = fread($handle, min(self::CHUNK_BYTES, $remaining));

                    if (! is_string($chunk) || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($handle);
            }
        }, $status, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function empty(int $status, array $headers = []): Response
    {
        $headers['Cache-Control'] = self::CACHE_CONTROL;

        return new Response('', $status, $headers);
    }
}
