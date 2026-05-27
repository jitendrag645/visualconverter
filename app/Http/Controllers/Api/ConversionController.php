<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVideoConversion;
use App\Models\ConversionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConversionController extends Controller
{
    private const VALID_QUALITIES = ['8K', '4K', 'HD', 'WATERMARK'];

    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'r2_path'             => 'required|string',
            'source_quality'      => 'required|in:8K,4K,HD',
            'destination_quality' => 'required|string',
            'destination_folder'  => 'required|string',
            'watermark_folder'    => 'nullable|string',
            'external_video_id'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data    = $validator->validated();
        $targets = $this->parseTargetQualities($data['destination_quality']);

        if (empty($targets)) {
            return response()->json([
                'message' => 'No valid target qualities found. Accepted: 8K, 4K, HD, WATERMARK.',
            ], 422);
        }

        $callbackUrl   = config('app.callback_url');
        $callbackToken = config('app.callback_token');

        if (empty($callbackUrl)) {
            return response()->json([
                'message' => 'CALLBACK_URL is not configured on the converter server.',
            ], 500);
        }

        $created = [];
        foreach ($targets as $target) {
            $job = ConversionJob::create([
                'source_r2_path'     => $data['r2_path'],
                'source_quality'     => strtoupper($data['source_quality']),
                'destination_folder' => $data['destination_folder'],
                'target_quality'     => $target,
                'callback_url'       => $callbackUrl,
                'callback_token'     => $callbackToken ?: null,
                'external_video_id'  => $data['external_video_id'] ?? null,
                'watermark_folder'   => $target === 'WATERMARK' ? ($data['watermark_folder'] ?? null) : null,
            ]);

            ProcessVideoConversion::dispatch($job->id)->onQueue('conversions');

            $created[] = [
                'uuid'           => $job->uuid,
                'target_quality' => $job->target_quality,
                'status'         => $job->status,
            ];
        }

        return response()->json([
            'message' => 'Conversion jobs queued.',
            'jobs'    => $created,
        ], 202);
    }

    public function status(string $uuid): JsonResponse
    {
        $job = ConversionJob::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'uuid'             => $job->uuid,
            'source_quality'   => $job->source_quality,
            'target_quality'   => $job->target_quality,
            'status'           => $job->status,
            'progress'         => $job->progress,
            'output_r2_path'   => $job->output_r2_path,
            'error_message'    => $job->error_message,
            'started_at'       => $job->started_at?->toISOString(),
            'completed_at'     => $job->completed_at?->toISOString(),
        ]);
    }

    private function parseTargetQualities(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn ($q) => strtoupper(trim($q)))
            ->filter(fn ($q) => in_array($q, self::VALID_QUALITIES, true))
            ->unique()
            ->values()
            ->all();
    }
}
