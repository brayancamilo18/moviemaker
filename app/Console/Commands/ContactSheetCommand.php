<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\FfmpegException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class ContactSheetCommand extends Command
{
    protected $signature = 'story:contactsheet
        {file : Ruta al JSON del guion generado en la Fase 1}';

    protected $description = 'Genera hojas de contacto 6×5 con todos los planos para revisar de un vistazo';

    private const COLUMNS = 6;

    private const ROWS = 5;

    private const CELL_WIDTH = 320;

    private const CELL_HEIGHT = 180;

    private const PADDING = 4;

    private const MARGIN = 8;

    private readonly string $outputDirectory;

    private readonly string $ffmpeg;

    private readonly int $nice;

    private readonly float $timeout;

    public function __construct(
        private Filesystem $files,
        Repository $config,
    ) {
        parent::__construct();

        $this->outputDirectory = storage_path('app/'.$config->get('stories.output_path'));
        $this->ffmpeg = (string) $config->get('stories.ffmpeg.binary');
        $this->nice = (int) $config->get('stories.ffmpeg.nice');
        $this->timeout = (float) $config->get('stories.ffmpeg.timeout');
    }

    public function handle(): int
    {
        $storyFile = $this->resolveStoryFile((string) $this->argument('file'));

        if ($storyFile === null) {
            return self::FAILURE;
        }

        $slug = pathinfo($storyFile, PATHINFO_FILENAME);
        $storyDirectory = $this->outputDirectory.DIRECTORY_SEPARATOR.$slug;
        $shotsPath = $storyDirectory.DIRECTORY_SEPARATOR.'shots.json';

        $shots = $this->readShots($shotsPath);

        if ($shots === null) {
            return self::FAILURE;
        }

        $this->clearPreviousSheets($storyDirectory);

        $pageSize = self::COLUMNS * self::ROWS;
        $pages = array_chunk($shots, $pageSize);
        $written = [];

        try {
            foreach ($pages as $index => $page) {
                $pageNumber = $index + 1;
                $output = $storyDirectory.DIRECTORY_SEPARATOR.'contact-sheet-'.$pageNumber.'.jpg';
                $this->renderPage($page, $output);
                $written[] = $output;
                $this->line("Hoja {$pageNumber}: {$output}");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            '%d hoja%s, %d planos. Regenera los raros con: php artisan story:images %s --only=',
            count($written),
            count($written) === 1 ? '' : 's',
            count($shots),
            basename($storyFile),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{order: int, duration: float, path: ?string, placeholder: bool}>  $page
     */
    private function renderPage(array $page, string $output): void
    {
        $workDir = storage_path('app/tmp/contact-'.bin2hex(random_bytes(8)));
        $this->files->ensureDirectoryExists($workDir);

        try {
            $cells = $this->stampCells($page, $workDir);
            $this->tile($cells, $output);
        } finally {
            $this->files->deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<array{order: int, duration: float, path: ?string, placeholder: bool}>  $page
     * @return list<string>
     */
    private function stampCells(array $page, string $workDir): array
    {
        $cells = [];
        $pageSize = self::COLUMNS * self::ROWS;

        for ($slot = 0; $slot < $pageSize; $slot++) {
            $path = $workDir.DIRECTORY_SEPARATOR.sprintf('cell-%02d.jpg', $slot);
            $shot = $page[$slot] ?? null;

            if ($shot === null) {
                $this->writeBlankCell($path);
            } else {
                $this->writeStampedCell($path, $shot);
            }

            $cells[] = $path;
        }

        return $cells;
    }

    /**
     * @param  array{order: int, duration: float, path: ?string, placeholder: bool}  $shot
     */
    private function writeStampedCell(string $destination, array $shot): void
    {
        $canvas = $this->blankCanvas();
        $sourcePath = $shot['path'];

        if (is_string($sourcePath) && $this->files->isFile($sourcePath)) {
            $this->pasteSource($canvas, $sourcePath);
        }

        $label = sprintf('#%d  %.1fs', $shot['order'], $shot['duration']);
        $this->stampLabel($canvas, $label, $shot['placeholder']);
        $this->saveJpeg($canvas, $destination);
    }

    private function writeBlankCell(string $destination): void
    {
        $canvas = $this->blankCanvas();
        $this->saveJpeg($canvas, $destination);
    }

    /**
     * @return \GdImage
     */
    private function blankCanvas(): object
    {
        $canvas = imagecreatetruecolor(self::CELL_WIDTH, self::CELL_HEIGHT);

        if ($canvas === false) {
            throw new RuntimeException('GD no pudo crear una miniatura.');
        }

        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 17, 17, 17));

        return $canvas;
    }

    /**
     * @param  \GdImage  $canvas
     */
    private function pasteSource(object $canvas, string $sourcePath): void
    {
        $source = @imagecreatefromstring($this->files->get($sourcePath));

        if ($source === false) {
            return;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $scale = min(self::CELL_WIDTH / max(1, $srcW), self::CELL_HEIGHT / max(1, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));
        $x = (int) round((self::CELL_WIDTH - $dstW) / 2);
        $y = (int) round((self::CELL_HEIGHT - $dstH) / 2);

        imagecopyresampled($canvas, $source, $x, $y, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);
    }

    /**
     * @param  \GdImage  $canvas
     */
    private function stampLabel(object $canvas, string $label, bool $placeholder): void
    {
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($label);
        $textHeight = imagefontheight($font);
        $tile = imagecreatetruecolor($textWidth, $textHeight);

        $ink = $placeholder
            ? [255, 210, 70]
            : [255, 255, 255];

        if ($tile === false) {
            imagestring(
                $canvas,
                $font,
                8,
                self::CELL_HEIGHT - 16,
                $label,
                imagecolorallocate($canvas, $ink[0], $ink[1], $ink[2]),
            );

            return;
        }

        imagefill($tile, 0, 0, imagecolorallocate($tile, 0, 0, 0));
        imagestring($tile, $font, 0, 0, $label, imagecolorallocate($tile, $ink[0], $ink[1], $ink[2]));

        $scale = 2;
        $dstW = $textWidth * $scale;
        $dstH = $textHeight * $scale;
        $x = 8;
        $y = self::CELL_HEIGHT - $dstH - 8;
        $box = imagecolorallocate($canvas, 0, 0, 0);
        imagefilledrectangle($canvas, $x - 4, $y - 3, $x + $dstW + 4, $y + $dstH + 3, $box);
        imagecopyresampled($canvas, $tile, $x, $y, 0, 0, $dstW, $dstH, $textWidth, $textHeight);
        imagedestroy($tile);
    }

    /**
     * @param  \GdImage  $canvas
     */
    private function saveJpeg(object $canvas, string $path): void
    {
        $written = imagejpeg($canvas, $path, 85);
        imagedestroy($canvas);

        if ($written === false) {
            throw new RuntimeException('No se pudo guardar una miniatura del contact sheet.');
        }
    }

    /**
     * Homebrew ffmpeg no trae drawtext (falta libfreetype). El sello va en GD.
     * tile consume fotogramas sucesivos: primero concat, luego la rejilla.
     *
     * @param  list<string>  $cells
     */
    private function tile(array $cells, string $output): void
    {
        $this->files->ensureDirectoryExists(dirname($output));

        $arguments = [$this->ffmpeg, '-nostdin', '-y', '-hide_banner'];
        $labels = [];

        foreach ($cells as $index => $cell) {
            $arguments[] = '-i';
            $arguments[] = $cell;
            $labels[] = "[{$index}:v]";
        }

        $filter = implode('', $labels)
            .'concat=n='.count($cells).':v=1:a=0,'
            .'tile='.self::COLUMNS.'x'.self::ROWS
            .':padding='.self::PADDING
            .':margin='.self::MARGIN
            .':color=0x111111,'
            .'format=yuv420p[out]';

        $arguments[] = '-filter_complex';
        $arguments[] = $filter;
        $arguments[] = '-map';
        $arguments[] = '[out]';
        $arguments[] = '-frames:v';
        $arguments[] = '1';
        $arguments[] = '-q:v';
        $arguments[] = '3';
        $arguments[] = $output;

        $this->runFfmpeg($arguments);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runFfmpeg(array $arguments): void
    {
        $process = new Process(['nice', '-n', (string) $this->nice, ...$arguments]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw FfmpegException::fromProcess($process);
        }
    }

    /**
     * @return list<array{order: int, duration: float, path: ?string, placeholder: bool}>|null
     */
    private function readShots(string $path): ?array
    {
        if (! $this->files->isFile($path)) {
            $this->error('No hay shots.json. Ejecuta story:images primero.');

            return null;
        }

        $decoded = $this->readJson($path, 'shots.json no es un JSON válido.');

        if ($decoded === null || ! isset($decoded['shots']) || ! is_array($decoded['shots'])) {
            $this->error('shots.json no tiene el esquema esperado.');

            return null;
        }

        $shots = [];

        foreach ($decoded['shots'] as $row) {
            if (! is_array($row) || ! isset($row['order'])) {
                continue;
            }

            $start = (float) ($row['start'] ?? 0);
            $end = (float) ($row['end'] ?? $start);
            $imagePath = isset($row['imagePath']) && is_string($row['imagePath']) ? $row['imagePath'] : null;
            $placeholder = (bool) ($row['placeholder'] ?? false);

            if (is_string($imagePath) && str_starts_with(basename($imagePath), 'placeholder-')) {
                $placeholder = true;
            }

            $shots[] = [
                'order' => (int) $row['order'],
                'duration' => max(0.0, $end - $start),
                'path' => $imagePath,
                'placeholder' => $placeholder,
            ];
        }

        if ($shots === []) {
            $this->error('shots.json no contiene planos.');

            return null;
        }

        return $shots;
    }

    private function clearPreviousSheets(string $directory): void
    {
        foreach ($this->files->glob($directory.DIRECTORY_SEPARATOR.'contact-sheet-*.jpg') as $path) {
            $this->files->delete($path);
        }
    }

    private function resolveStoryFile(string $file): ?string
    {
        $candidates = [
            $file,
            $this->outputDirectory.DIRECTORY_SEPARATOR.basename($file),
        ];

        foreach ($candidates as $candidate) {
            if ($this->files->isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $this->error("No se encontró el guion '{$file}'.");

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path, string $invalidMessage): ?array
    {
        try {
            $decoded = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error($invalidMessage);

            return null;
        }

        if (! is_array($decoded)) {
            $this->error($invalidMessage);

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
