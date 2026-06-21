<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchModel;

final class SyncService
{
    public function __construct(
        private readonly FootballApiClient $api,
        private readonly int $softLimitPerDay,
        private readonly ?int $worldCupLeagueId,
        private readonly int $season,
    ) {}

    /**
     * Descubre ligas “World Cup” para encontrar league_id y seasons disponibles.
     */
    public function discoverWorldCupLeagues(?int $season = null): array
    {
        $this->guardRequestBudget();
        $resp = $this->api->get('/leagues', array_filter([
            'search' => 'World Cup',
            'season' => $season,
        ], fn ($v) => $v !== null));
        RateLimitService::recordRequest();
        return $resp['response'] ?? [];
    }

    /**
     * Carga fixtures del Mundial por rango de fechas (cuando el provider lo permita).
     * Requiere $worldCupLeagueId configurado.
     */
    public function syncSchedule(string $from, string $to): int
    {
        if (!$this->worldCupLeagueId) {
            throw new \RuntimeException('WORLD_CUP_LEAGUE_ID no configurado.');
        }
        $this->guardRequestBudget();
        $resp = $this->api->get('/fixtures', [
            'league' => $this->worldCupLeagueId,
            'season' => $this->season,
            'from' => $from,
            'to' => $to,
        ]);
        RateLimitService::recordRequest();

        $count = 0;
        foreach (($resp['response'] ?? []) as $fixture) {
            MatchModel::upsertFromApi($fixture);
            $count++;
        }
        return $count;
    }

    /**
     * Sincroniza marcadores en vivo y eventos (si aplica).
     * Filtra solo partidos del Mundial si $worldCupLeagueId está configurado.
     */
    public function syncLive(bool $fetchEventsOnChangeOnly = true): array
    {
        $this->guardRequestBudget();
        $resp = $this->api->get('/fixtures', ['live' => 'all']);
        RateLimitService::recordRequest();

        $fixtures = $resp['response'] ?? [];
        $result = [
            'live_total' => count($fixtures),
            'worldcup_live' => 0,
            'events_calls' => 0,
            'updated_matches' => 0,
        ];

        foreach ($fixtures as $fixture) {
            if ($this->worldCupLeagueId) {
                $leagueId = (int)($fixture['league']['id'] ?? 0);
                if ($leagueId !== $this->worldCupLeagueId) {
                    continue;
                }
            }

            $result['worldcup_live']++;
            $matchId = MatchModel::upsertFromApi($fixture);
            $result['updated_matches']++;

            // En esta primera versión: si se pide eventos, se consulta siempre que haya presupuesto.
            // Optimización real (cambio de marcador/status) se implementa con una tabla de “last_live_snapshot”.
            if (!$fetchEventsOnChangeOnly) {
                $this->syncEventsForFixture((int)($fixture['fixture']['id'] ?? 0), $matchId);
                $result['events_calls']++;
                continue;
            }

            // Heurística: solo consulta eventos si el marcador NO es 0-0 o si el status cambió a HT/FT, etc.
            // Esto reduce llamadas y suele capturar goles/tarjetas cuando hay “acción”.
            $status = (string)($fixture['fixture']['status']['short'] ?? '');
            $goals = $fixture['goals'] ?? [];
            $hs = (int)($goals['home'] ?? 0);
            $as = (int)($goals['away'] ?? 0);
            $interesting = ($hs + $as) > 0 || in_array($status, ['HT', 'FT', 'AET', 'PEN'], true);

            if ($interesting && RateLimitService::canRequest($this->softLimitPerDay)) {
                $this->syncEventsForFixture((int)($fixture['fixture']['id'] ?? 0), $matchId);
                $result['events_calls']++;
            }
        }

        return $result;
    }

    private function syncEventsForFixture(int $apiFixtureId, int $matchId): void
    {
        $this->guardRequestBudget();
        $resp = $this->api->get('/fixtures/events', ['fixture' => $apiFixtureId]);
        RateLimitService::recordRequest();

        foreach (($resp['response'] ?? []) as $event) {
            MatchEvent::upsertFromApi($matchId, $event);
        }
    }

    private function guardRequestBudget(): void
    {
        if (!RateLimitService::canRequest($this->softLimitPerDay)) {
            throw new \RuntimeException('Límite diario de API alcanzado (modo ahorro).');
        }
    }
}

