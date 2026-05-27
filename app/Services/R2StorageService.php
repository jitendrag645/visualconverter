<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class R2StorageService
{
    public const DISK_PRIVATE = 'r2';
    public const DISK_PUBLIC  = 'r2_public';

    public function download(string $r2Path, string $localPath, string $disk = self::DISK_PRIVATE): void
    {
        $r2Path = $this->stripBucketPrefix($r2Path, $disk);

        $stream = Storage::disk($disk)->readStream($r2Path);

        if ($stream === null) {
            throw new \RuntimeException("Cannot read from R2 ({$disk}): {$r2Path}");
        }

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dest = fopen($localPath, 'wb');
        stream_copy_to_stream($stream, $dest);
        fclose($dest);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function upload(string $localPath, string $r2Path, string $disk = self::DISK_PRIVATE): void
    {
        $r2Path = $this->stripBucketPrefix($r2Path, $disk);

        $stream = fopen($localPath, 'rb');
        Storage::disk($disk)->writeStream($r2Path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /**
     * Strip the bucket name prefix from a path if the caller accidentally
     * includes it — e.g. "visualsdepot-private/assets/video.mp4" → "assets/video.mp4"
     */
    private function stripBucketPrefix(string $path, string $disk): string
    {
        $bucket = config("filesystems.disks.{$disk}.bucket");

        if ($bucket && str_starts_with($path, $bucket . '/')) {
            return substr($path, strlen($bucket) + 1);
        }

        return $path;
    }
}
