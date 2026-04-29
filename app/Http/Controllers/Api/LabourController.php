<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LabourApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

class LabourController extends Controller
{
    public function __construct(private LabourApiService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $allowed = [
            'search',
            'company_id',
            'labour_agency',
            'labour_status',
            'labour_status_job',
            'labour_nationality',
            'per_page',
            'page',
            'all',
        ];
        $query = collect($request->only($allowed))->filter(fn ($v) => $v !== null && $v !== '')->all();

        try {
            return response()->json($this->service->listLabours($query));
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $data = $this->service->getLabour($id);
            $status = $data['_http_status'] ?? 200;
            unset($data['_http_status']);
            return response()->json($data, $status);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function showByPassport(string $passport): JsonResponse
    {
        try {
            $data = $this->service->getLabourByPassport($passport);
            $status = $data['_http_status'] ?? 200;
            unset($data['_http_status']);
            return response()->json($data, $status);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    private function handleError(\Throwable $e): JsonResponse
    {
        if ($e instanceof RequestException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Labour API error',
                'detail'  => $e->response?->json() ?? $e->getMessage(),
            ], $e->response?->status() ?? 502);
        }
        if ($e instanceof ConnectionException) {
            return response()->json([
                'status'  => 'error',
                'message' => 'ไม่สามารถเชื่อมต่อ Labour API ได้',
            ], 504);
        }
        return response()->json([
            'status'  => 'error',
            'message' => $e->getMessage(),
        ], 500);
    }
}
