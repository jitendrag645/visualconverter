<?php

namespace App\Jobs;

use App\Models\ConversionJob;
use App\Services\CallbackService;
use App\Services\R2StorageService;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\FFMpeg;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\X264;
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

    // Watermark is always HD, positioned bottom-right, 25% of video width, 50% opacity
    private const WATERMARK_IMAGE    = 'watermark-image.png';
    private const WATERMARK_WIDTH_PC = 0.25;
    private const WATERMARK_OPACITY  = 0.5;
    private const WATERMARK_PADDING  = 20;

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

    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------

    private function convert(string $input, string $output, string $targetQuality): void
    {
        [$width, $height, $kilobitrate] = $this->resolutionSettings($targetQuality);

        $video = $this->makeFfmpeg()->open($input);

        $video->filters()
            ->resize(new Dimension($width, $height), ResizeFilter::RESIZEMODE_FIT)
            ->synchronize();

        $video->save($this->makeFormat($kilobitrate), $output);
    }

    private function convertWithWatermark(string $input, string $output, string $watermarkFile): void
    {
        [$width, $height, $kilobitrate] = $this->resolutionSettings('HD');

        // Watermark width = 25% of video width; must be even for x264
        $wmWidth = (int) round($width * self::WATERMARK_WIDTH_PC);
        if ($wmWidth % 2 !== 0) {
            $wmWidth++;
        }

        $filterComplex = implode(';', [
            // Scale + letterbox the source to HD
            "[0:v]scale={$width}:{$height}:force_original_aspect_ratio=decrease," .
            "pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2[base]",

            // Scale watermark to target width, convert to RGBA, apply 50% opacity
            "[1:v]scale={$wmWidth}:-2,format=rgba,colorchannelmixer=aa=" . self::WATERMARK_OPACITY . "[wm]",

            // Overlay watermark bottom-right with padding
            "[base][wm]overlay=W-w-" . self::WATERMARK_PADDING . ":H-h-" . self::WATERMARK_PADDING,
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

        $process = new Process($command);
        $process->setTimeout(config('laravel-ffmpeg.timeout', 3600));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'FFmpeg watermark failed: ' . $process->getErrorOutput()
            );
        }
    }

    // -------------------------------------------------------------------------

    private function makeFfmpeg(): FFMpeg
    {
        return FFMpeg::create([
            'ffmpeg.binaries'  => config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            'ffprobe.binaries' => config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
            'timeout'          => config('laravel-ffmpeg.timeout', 3600),
            'ffmpeg.threads'   => config('laravel-ffmpeg.ffmpeg.threads', 4),
        ]);
    }

    private function makeFormat(int $kilobitrate): X264
    {
        return (new X264())->setKiloBitrate($kilobitrate)->setAudioKiloBitrate(192);
    }

    private function resolutionSettings(string $quality): array
    {
        return match (strtoupper($quality)) {
            '4K'    => [3840, 2160, 20000],
            'HD'    => [1920, 1080, 8000],
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
