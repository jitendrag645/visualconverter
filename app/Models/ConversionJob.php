<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ConversionJob extends Model
{
    protected $fillable = [
        'uuid',
        'source_r2_path',
        'source_quality',
        'destination_folder',
        'target_quality',
        'callback_url',
        'callback_token',
        'external_video_id',
        'watermark_path',
        'watermark_folder',
        'output_r2_path',
        'output_width',
        'output_height',
        'output_codec',
        'status',
        'error_message',
        'progress',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($model) => $model->uuid = (string) Str::uuid());
    }

    public const QUALITY_8K = '8K';
    public const QUALITY_4K = '4K';
    public const QUALITY_HD = 'HD';
    public const QUALITY_WATERMARK = 'WATERMARK';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function isWatermark(): bool
    {
        return strtoupper($this->target_quality) === self::QUALITY_WATERMARK;
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING, 'started_at' => now()]);
    }

    public function markCompleted(string $outputPath, ?int $width = null, ?int $height = null, ?string $codec = null): void
    {
        $this->update([
            'status'         => self::STATUS_COMPLETED,
            'output_r2_path' => $outputPath,
            'output_width'   => $width,
            'output_height'  => $height,
            'output_codec'   => $codec,
            'progress'       => 100,
            'completed_at'   => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'completed_at' => now(),
        ]);
    }
}
