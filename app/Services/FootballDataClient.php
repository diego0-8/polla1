<?php
declare(strict_types=1);

namespace App\Services;

final class FootballDataClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $softLimitPerMinute,
    ) {}

    public function get(string $path, array $query = []): array
    {
        if ($this->token === '') {
            throw new \RuntimeException('Token Football-Data no configurado. Copia app/Config/local.php.example a local.php.');
        }

        RateLimitService::acquireOrWait($this->softLimitPerMinute);

        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Auth-Token: ' . $this->token,
                'accept: application/json',
            ],
            CURLOPT_TIMEOUT => 25,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Error CURL: ' . $err);
        }

        RateLimitService::recordRequestPerMinute();

        if ($status === 429) {
            throw new \RuntimeException('Rate limit Football-Data (429). Espera al siguiente minuto.');
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
