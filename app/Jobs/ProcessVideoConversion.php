<?php

namespace App\Jobs;

use App\Models\ConversionJob;
use App\Services\CallbackService;
use App\Services\R2StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Process;

class ProcessVideoConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 2;
    public int $backoff = 60;

    private const WATERMARK_IMAGE    = 'watermark-image.png';
    private const WATERMARK_WIDTH_PC = 0.35;  // 35% of the video width
    private const WATERMARK_OPACITY  = 0.5;   // 50%
    private const WATERMARK_PADDING  = 20;    // px from right edge

    public function __construct(private readonly int $conversionJobId) {}

    public function handle(R2StorageService $r2, CallbackService $callback): void
    {
        $job = ConversionJob::findOrFail($this->conversionJobId);

        if ($job->status === ConversionJob::STATUS_COMPLETED) {
            return;
        }

        $job->markProcessing();
        $tempDir = (new TemporaryDirectory())->create();

        try {
            $sourceFile = $tempDir->path('source_' . basename($job->source_r2_path));

            Log::info('Downloading source from R2', ['uuid' => $job->uuid, 'path' => $job->source_r2_path]);
            $r2->download($job->source_r2_path, $sourceFile);

            $outputR2Path = $job->isWatermark()
                ? $this->handleWatermark($job, $r2, $tempDir, $sourceFile)
                : $this->handleConversion($job, $r2, $tempDir, $sourceFile);

            $job->markCompleted($outputR2Path);
            Log::info('Job completed', ['uuid' => $job->uuid, 'output' => $outputR2Path]);
        } catch (\Throwable $e) {
            Log::error('Job failed', ['uuid' => $job->uuid, 'error' => $e->getMessage()]);
            $job->markFailed($e->getMessage());
        } finally {
            $tempDir->delete();
        }

        $callback->notify($job);
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    private function handleConversion(ConversionJob $job, R2StorageService $r2, $tempDir, string $sourceFile): string
    {
        $outputFilename = $this->buildOutputFilename($job);
        $outputFile     = $tempDir->path($outputFilename);

        Log::info('Starting FFmpeg conversion', ['uuid' => $job->uuid, 'target' => $job->target_quality]);
        $this->convert($sourceFile, $outputFile, $job->target_quality);

        $outputR2Path = rtrim($job->destination_folder, '/') . '/' . $outputFilename;

        Log::info('Uploading to private R2', ['uuid' => $job->uuid, 'path' => $outputR2Path]);
        $r2->upload($outputFile, $outputR2Path, R2StorageService::DISK_PRIVATE);

        return $outputR2Path;
    }

    private function handleWatermark(ConversionJob $job, R2StorageService $r2, $tempDir, string $sourceFile): string
    {
        $watermarkFile = public_path(self::WATERMARK_IMAGE);

        if (!file_exists($watermarkFile)) {
            throw new \RuntimeException('Watermark image not found: ' . $watermarkFile);
        }

        $outputFilename = $this->buildOutputFilename($job);
        $outputFile     = $tempDir->path($outputFilename);

        Log::info('Starting FFmpeg watermark conversion', ['uuid' => $job->uuid]);
        $this->convertWithWatermark($sourceFile, $outputFile, $watermarkFile);

        $folder       = rtrim($job->watermark_folder ?: $job->destination_folder, '/');
        $outputR2Path = $folder . '/' . $outputFilename;

        Log::info('Uploading watermarked video to public R2', ['uuid' => $job->uuid, 'path' => $outputR2Path]);
        $r2->upload($outputFile, $outputR2Path, R2StorageService::DISK_PUBLIC);

        return $outputR2Path;
    }

    // ── FFmpeg ────────────────────────────────────────────────────────────────

    private function convert(string $input, string $output, string $targetQuality): void
    {
        [$shortSide, $kilobitrate] = $this->resolutionSettings($targetQuality);

        // Orientation-aware scale:
        //   Portrait  (iw < ih) → width = shortSide, height = auto (-2)
        //   Landscape (iw >= ih)→ width = auto (-2),  height = shortSide
        // -2 keeps the dimension divisible by 2 (required by x264)
        $scale = "scale='if(lt(iw,ih),{$shortSide},-2)':'if(lt(iw,ih),-2,{$shortSide})'";

        $command = [
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            '-y',
            '-i', $input,
            '-vf', $scale,
            '-vcodec', 'libx264',
            '-b:v', $kilobitrate . 'k',
            '-acodec', 'aac',
            '-b:a', '192k',
            '-threads', (string) config('laravel-ffmpeg.ffmpeg.threads', 4),
            $output,
        ];

        $this->runProcess($command, 'Conversion');
    }

    private function convertWithWatermark(string $input, string $output, string $watermarkFile): void
    {
        [$shortSide, $kilobitrate] = $this->resolutionSettings('HD');

        // Same orientation-aware scale for the main video
        $baseScale = "scale='if(lt(iw,ih),{$shortSide},-2)':'if(lt(iw,ih),-2,{$shortSide})',setsar=1";

        $filterComplex = implode(';', [
            // Scale main video preserving orientation + aspect ratio
            "[0:v]{$baseScale}[base]",

            // Scale watermark to 35% of the actual video width using scale2ref
            // main_w = width of [base] after scaling (correct for both portrait & landscape)
            // -2 keeps height even (required by x264) while preserving aspect ratio
            "[1:v][base]scale2ref='trunc(main_w*" . self::WATERMARK_WIDTH_PC . "/2)*2':-2[wm_scaled][base_ref]",

            // Normalize SAR to 1:1 to prevent vertical/horizontal stretching, then apply opacity
            "[wm_scaled]setsar=1,format=rgba,colorchannelmixer=aa=" . self::WATERMARK_OPACITY . "[wm]",

            // Overlay right-aligned, vertically centered
            "[base_ref][wm]overlay=W-w-" . self::WATERMARK_PADDING . ":(H-h)/2",
        ]);

        $command = [
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            '-y',
            '-i', $input,
            '-i', $watermarkFile,
            '-filter_complex', $filterComplex,
            '-vcodec', 'libx264',
            '-b:v', $kilobitrate . 'k',
            '-acodec', 'aac',
            '-b:a', '192k',
            '-threads', (string) config('laravel-ffmpeg.ffmpeg.threads', 4),
            $output,
        ];

        $this->runProcess($command, 'Watermark conversion');
    }

    private function runProcess(array $command, string $label): void
    {
        $process = new Process($command);
        $process->setTimeout(config('laravel-ffmpeg.timeout', 3600));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "{$label} failed: " . $process->getErrorOutput()
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns [shortSide, kilobitrate].
     * shortSide is the pixel count for the SHORTER dimension of the video,
     * which makes it orientation-independent:
     *   HD   → short side 1080 → landscape 1920×1080 / portrait 1080×1920
     *   4K   → short side 2160 → landscape 3840×2160 / portrait 2160×3840
     */
    private function resolutionSettings(string $quality): array
    {
        return match (strtoupper($quality)) {
            '4K'    => [2160, 20000],
            'HD'    => [1080, 8000],
            default => throw new \InvalidArgumentException("Unsupported target quality: {$quality}"),
        };
    }

    private function buildOutputFilename(ConversionJob $job): string
    {
        $ext    = pathinfo($job->source_r2_path, PATHINFO_EXTENSION) ?: 'mp4';
        $base   = pathinfo($job->source_r2_path, PATHINFO_FILENAME);
        $suffix = $job->isWatermark() ? 'WATERMARK' : strtoupper($job->target_quality);
        return "{$base}_{$suffix}.{$ext}";
    }

    public function failed(\Throwable $exception): void
    {
        $job = ConversionJob::find($this->conversionJobId);
        if ($job && $job->status !== ConversionJob::STATUS_FAILED) {
            $job->markFailed($exception->getMessage());
            app(CallbackService::class)->notify($job);
        }
    }
}
