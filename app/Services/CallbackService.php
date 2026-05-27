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
            'output_r2_path'      => $job->output_r2_path,
            'output_public_url'   => $this->buildPublicUrl($job),
            'destination_folder'  => $job->destination_folder,
            'status'              => $job->status,
            'completed_at'        => $job->completed_at?->toISOString(),
        ];

        try {
            $request = Http::timeout(30)
                ->retry(3, 2000, null, false); // false = don't throw after retries exhausted

            if ($job->callback_token) {
                $request = $request->withToken($job->callback_token);
            }

            $response = $request->post($job->callback_url, $payload);

            if ($response->successful()) {
                Log::info('Callback sent successfully', [
                    'uuid' => $job->uuid,
                    'url'  => $job->callback_url,
                ]);
            } else {
                Log::warning('Callback returned non-2xx', [
                    'uuid'   => $job->uuid,
                    'url'    => $job->callback_url,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            // Callback failure must NEVER fail the job — just log and move on
            Log::error('Callback threw an exception', [
                'uuid'  => $job->uuid,
                'url'   => $job->callback_url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPublicUrl(ConversionJob $job): ?string
    {
        if (!$job->isWatermark() || empty($job->output_r2_path)) {
            return null;
        }

        return Storage::disk('r2_public')->url($job->output_r2_path);
    }
}
