<?php

namespace App\Services;

use App\Models\ConversionJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CallbackService
{
    public function notify(ConversionJob $job): void
    {
        $payload = [
            'conversion_job_uuid' => $job->uuid,
            'external_video_id'   => $job->external_video_id,
            'source_quality'      => $job->source_quality,
            'target_quality'      => $job->target_quality,
            'is_watermarked'      => $job->isWatermark(),
            // Path within the bucket (private bucket for normal jobs, public bucket for watermark)
            'output_r2_path'      => $job->output_r2_path,
            // For watermark jobs: full public CDN URL (pub-*.r2.dev/...)
            'output_public_url'   => $this->buildPublicUrl($job),
            'destination_folder'  => $job->destination_folder,
            'status'              => $job->status,
            'completed_at'        => $job->completed_at?->toISOString(),
        ];

        $request = Http::timeout(30)->retry(3, 2000);

        if ($job->callback_token) {
            $request = $request->withToken($job->callback_token);
        }

        $response = $request->post($job->callback_url, $payload);

        if (!$response->successful()) {
            Log::warning('Callback to main site failed', [
                'uuid'   => $job->uuid,
                'url'    => $job->callback_url,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }
    }

    private function buildPublicUrl(ConversionJob $job): ?string
    {
        if (!$job->isWatermark() || empty($job->output_r2_path)) {
            return null;
        }

        // Generates: https://pub-xxx.r2.dev/{output_r2_path}
        return Storage::disk('r2_public')->url($job->output_r2_path);
    }
}
