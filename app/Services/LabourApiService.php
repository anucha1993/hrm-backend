<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LabourApiService
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.labour.base_url'), '/');
        $this->apiKey  = config('services.labour.key');
        $this->timeout = (int) config('services.labour.timeout', 15);
    }

    public function listLabours(array $query = []): array
    {
        return $this->request()->get('/api/v1/labours', $query)->throw()->json();
    }

    public function getLabour(int|string $id): array
    {
        return $this->handleNotFound(
            $this->request()->get('/api/v1/labours/' . urlencode((string) $id))
        );
    }

    public function getLabourByPassport(string $passport): array
    {
        return $this->handleNotFound(
            $this->request()->get('/api/v1/labours/passport/' . urlencode($passport))
        );
    }

    private function request(): PendingRequest
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('LABOUR_API_KEY is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Accept'    => 'application/json',
            ])
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function handleNotFound(Response $res): array
    {
        if ($res->status() === 404) {
            return ['status' => 'error', 'message' => 'Labour not found.', '_http_status' => 404];
        }
        return $res->throw()->json();
    }
}
