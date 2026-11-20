<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stage 8 Vertical Slice 5:
 * Gemini API NOC Copilot (/api/v1/ai/diagnose) analyzing radpostauth rejects
 * and OLT optical power drops using GEMINI_API_KEY from Secret Manager / .env.
 */
final class AiCopilotController extends Controller
{
    public function diagnose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'symptom'  => ['required', 'string', 'max:1000'],
        ]);

        $apiKey = (string) env('GEMINI_API_KEY', '');
        $model  = (string) env('GEMINI_MODEL', env('GEMINI_FLASH_MODEL', 'gemini-3-flash-preview'));

        if ($apiKey === '' || str_starts_with($apiKey, 'REPLACE_WITH_')) {
            return response()->json(['error' => 'GEMINI_API_KEY is not configured'], 503);
        }

        $prompt = "You are a senior ISP FreeRADIUS & MikroTik RouterOS v7 NOC engineer. "
            . "Diagnose subscriber '{$data['username']}' reporting: {$data['symptom']}. "
            . "Provide: (1) Likely root cause, (2) Exact radtest or RouterOS CLI check, (3) Safe fix.";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $resp = Http::timeout(15)->post($url, [
            'contents' => [['parts' => [['text' => $prompt]]]],
        ]);

        if (!$resp->successful()) {
            return response()->json(['error' => 'Gemini API request failed', 'status' => $resp->status()], 502);
        }

        $advice = data_get($resp->json(), 'candidates.0.content.parts.0.text', 'No diagnosis returned.');

        return response()->json([
            'username'  => $data['username'],
            'model'     => $model,
            'diagnosis' => $advice,
        ]);
    }
}
