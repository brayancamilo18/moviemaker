<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Exceptions\FreesoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

final class FreesoundClient
{
    public const LICENSE_CC0 = 'Creative Commons 0';

    public const LICENSE_ATTRIBUTION = 'Attribution';

    /**
     * @var list<string>
     */
    public const ALLOWED_LICENSES = [
        self::LICENSE_CC0,
        self::LICENSE_ATTRIBUTION,
    ];

    private const SEARCH_FIELDS = 'id,name,tags,username,license,duration,avg_rating,num_downloads,previews,url';

    private readonly string $token;

    private readonly string $baseUrl;

    private readonly int $timeout;

    private readonly float $rateLimitSeconds;

    private readonly int $maxRetries;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private Factory $http,
        string $token,
        string $baseUrl,
        int $timeout,
        float $rateLimitSeconds,
        int $maxRetries,
    ) {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->rateLimitSeconds = $rateLimitSeconds;
        $this->maxRetries = $maxRetries;
    }

    /**
     * @return list<array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads: int, tags: list<string>, previewUrl: string, sourceUrl: string}>
     */
    public function search(string $query, string $type, int $limit): array
    {
        $this->assertReady();

        $query = trim($query);
        $limit = max(1, $limit);

        if ($query === '') {
            throw new InvalidArgumentException('La consulta de búsqueda no puede estar vacía.');
        }

        $pageSize = min(150, max($limit * 3, $limit));
        $response = $this->request('GET', $this->baseUrl.'/search/text/', [
            'query' => $query,
            'filter' => $this->filterFor($type),
            'fields' => self::SEARCH_FIELDS,
            'sort' => 'rating_desc',
            'page_size' => $pageSize,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $sounds = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sound = $this->hydrate($row);

            if ($sound === null) {
                continue;
            }

            $sounds[] = $sound;

            if (count($sounds) >= $limit) {
                break;
            }
        }

        return $sounds;
    }

    public function downloadPreview(string $url): string
    {
        $this->assertReady();

        $response = $this->request('GET', $url);

        $body = $response->body();

        if ($body === '') {
            throw new FreesoundException('Freesound devolvió un preview vacío.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, name: string, author: string, license: string, duration: float, rating: float, downloads: int, tags: list<string>, previewUrl: string, sourceUrl: string}|null
     */
    private function hydrate(array $row): ?array
    {
        $license = $this->normalizeLicense((string) ($row['license'] ?? ''));

        if ($license === null) {
            return null;
        }

        $author = trim((string) ($row['username'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $sourceUrl = trim((string) ($row['url'] ?? ''));
        $previewUrl = $this->previewUrl($row['previews'] ?? null);
        $id = (int) ($row['id'] ?? 0);

        if ($id < 1 || $name === '' || $author === '' || $sourceUrl === '' || $previewUrl === '') {
            return null;
        }

        $tags = [];

        foreach (is_array($row['tags'] ?? null) ? $row['tags'] : [] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = trim($tag);
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'author' => $author,
            'license' => $license,
            'duration' => (float) ($row['duration'] ?? 0),
            'rating' => (float) ($row['avg_rating'] ?? 0),
            'downloads' => (int) ($row['num_downloads'] ?? 0),
            'tags' => $tags,
            'previewUrl' => $previewUrl,
            'sourceUrl' => $sourceUrl,
        ];
    }

    public function licenseAllowed(string $license): bool
    {
        return $this->normalizeLicense($license) !== null;
    }

    /**
     * Freesound filtra por nombre (“Attribution”, “Creative Commons 0”)
     * pero el campo license de cada sonido suele ser la URL del deed de CC.
     */
    public function normalizeLicense(string $license): ?string
    {
        $trimmed = trim($license);

        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed);

        if ($lower === 'creative commons 0' || $lower === 'cc0') {
            return self::LICENSE_CC0;
        }

        if ($lower === 'attribution') {
            return self::LICENSE_ATTRIBUTION;
        }

        if (
            str_contains($lower, 'noncommercial')
            || str_contains($lower, 'non-commercial')
            || str_contains($lower, 'sampling')
        ) {
            return null;
        }

        if (! preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        $path = mb_strtolower((string) (parse_url($trimmed, PHP_URL_PATH) ?? ''));

        if (str_contains($path, '/publicdomain/zero/')) {
            return self::LICENSE_CC0;
        }

        if (preg_match('#/licenses/by/\d#', $path) === 1) {
            return self::LICENSE_ATTRIBUTION;
        }

        return null;
    }

    private function filterFor(string $type): string
    {
        return 'license:("Creative Commons 0" OR "Attribution") '.AudioDuration::filter($type);
    }

    private function previewUrl(mixed $previews): string
    {
        if (! is_array($previews)) {
            return '';
        }

        $hq = $previews['preview-hq-mp3'] ?? null;

        return is_string($hq) ? trim($hq) : '';
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function request(string $method, string $url, array $query = []): Response
    {
        $this->throttle();

        try {
            $pending = $this->http
                ->timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Token '.$this->token,
                ])
                ->retry(
                    $this->retryBackoff(),
                    when: $this->shouldRetry(...),
                )
                ->throw();

            $response = strtoupper($method) === 'GET'
                ? $pending->get($url, $query)
                : $pending->send($method, $url);

            $this->lastRequestAt = microtime(true);

            return $response;
        } catch (FreesoundException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw new FreesoundException('No se pudo conectar con Freesound.', previous: $exception);
        } catch (RequestException $exception) {
            throw new FreesoundException(
                $this->httpErrorMessage($exception->response),
                previous: $exception,
            );
        }
    }

    private function throttle(): void
    {
        if ($this->lastRequestAt <= 0.0 || $this->rateLimitSeconds <= 0) {
            return;
        }

        $wait = $this->rateLimitSeconds - (microtime(true) - $this->lastRequestAt);

        if ($wait > 0) {
            Sleep::usleep((int) round($wait * 1_000_000));
        }
    }

    /**
     * @return int|list<int>
     */
    private function retryBackoff(): int|array
    {
        if ($this->maxRetries < 1) {
            return 1;
        }

        return array_map(
            static fn (int $attempt): int => 1000 * (2 ** $attempt),
            range(0, $this->maxRetries - 1),
        );
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        return $exception->response->status() === 429;
    }

    private function httpErrorMessage(Response $response): string
    {
        $status = $response->status();
        $detail = $response->json('detail') ?? $response->json('error') ?? $response->body();
        $detail = is_string($detail) ? $detail : json_encode($detail);

        return match ($status) {
            401, 403 => 'Freesound rechazó el token (HTTP '.$status.'). Revisa FREESOUND_TOKEN.',
            429 => 'Freesound está saturado (HTTP 429) tras varios reintentos.',
            default => "Freesound respondió con HTTP {$status}: {$detail}",
        };
    }

    private function assertReady(): void
    {
        if ($this->token === '') {
            throw new FreesoundException(
                'Falta FREESOUND_TOKEN. Pídelo en https://freesound.org/apiv2/apply y añádelo a .env.',
            );
        }
    }
}
