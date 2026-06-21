<?php
declare(strict_types=1);

namespace App\Services;

final class FootballApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-apisports-key: ' . $this->apiKey,
                'accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Error CURL: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP $status: $raw");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('JSON inválido');
        }
        return $data;
    }
}

