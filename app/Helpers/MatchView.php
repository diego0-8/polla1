<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Services\MatchDataMapper;
use App\Services\WorldCupVenueResolver;

final class MatchView
{
    public static function season(): int
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        return (int)($cfg['football_data']['season'] ?? 2026);
    }

    public static function competitionLabel(): string
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        $code = (string)($cfg['football_data']['competition_code'] ?? 'WC');
        return "Mundial {$code} " . self::season();
    }

    public static function statusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'NS' => 'Programado',
            'FT' => 'Finalizado',
            'LIVE' => 'En juego',
            'HT' => 'Entretiempo',
            'PST' => 'Aplazado',
            'CANC' => 'Cancelado',
            'PEN' => 'Penales',
            'AET' => 'Tras prórroga',
            default => $status,
        };
    }

    public static function statusBadgeClass(string $status): string
    {
        return match (strtoupper($status)) {
            'LIVE', 'HT' => 'badge badge-live',
            'FT', 'PEN', 'AET' => 'badge badge-ft',
            'NS' => 'badge badge-ns',
            default => 'badge bg-secondary',
        };
    }

    public static function formatKickoff(string $kickoffAt): string
    {
        try {
            $dt = MatchDataMapper::kickoffFromStorage($kickoffAt);
            return self::formatKickoffDateTime($dt);
        } catch (\Throwable) {
            return $kickoffAt;
        }
    }

    public static function formatKickoffTime(string $kickoffAt): string
    {
        try {
            $dt = MatchDataMapper::kickoffFromStorage($kickoffAt);
            return self::formatTime12h($dt) . ' (Bogotá)';
        } catch (\Throwable) {
            return $kickoffAt;
        }
    }

    public static function formatKickoffDate(string $kickoffAt): string
    {
        try {
            $dt = MatchDataMapper::kickoffFromStorage($kickoffAt);
            return self::formatDateShort($dt);
        } catch (\Throwable) {
            return $kickoffAt;
        }
    }

    private static function formatKickoffDateTime(\DateTimeImmutable $dt): string
    {
        return self::formatDateShort($dt) . ' · ' . self::formatTime12h($dt) . ' (Bogotá)';
    }

    private static function formatDateShort(\DateTimeImmutable $dt): string
    {
        $days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        return $days[(int)$dt->format('w')] . ', ' . $dt->format('j/n/Y');
    }

    private static function formatTime12h(\DateTimeImmutable $dt): string
    {
        $hour = (int)$dt->format('G');
        $suffix = $hour < 12 ? 'a.m.' : 'p.m.';
        return $dt->format('g:i') . ' ' . $suffix;
    }

    public static function formatLastSynced(?string $lastSyncedAt): string
    {
        if ($lastSyncedAt === null || trim($lastSyncedAt) === '') {
            return 'Sin sincronizar';
        }
        try {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $tz = new \DateTimeZone($cfg['timezone'] ?? 'America/Bogota');
            $dt = new \DateTimeImmutable($lastSyncedAt);
            $dt = $dt->setTimezone($tz);
            return $dt->format('d/m/Y H:i') . ' (Bogotá)';
        } catch (\Throwable) {
            return $lastSyncedAt;
        }
    }

    public static function stageLabel(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '' || $stored === 'Calendario') {
            return 'Por definir';
        }

        $map = [
            'GROUP_STAGE' => 'Fase de grupos',
            'LAST_16' => 'Octavos de final',
            'LAST_32' => 'Dieciseisavos de final',
            'QUARTER_FINALS' => 'Cuartos de final',
            'SEMI_FINALS' => 'Semifinal',
            'THIRD_PLACE' => 'Tercer puesto',
            'FINAL' => 'Final',
            'PLAYOFFS' => 'Repesca',
            'REGULAR_SEASON' => 'Fase regular',
            'Group ' => 'Grupo ',
            'MD ' => 'Jornada ',
        ];

        $out = $stored;
        foreach ($map as $en => $es) {
            $out = str_replace($en, $es, $out);
        }
        $out = preg_replace('/Grupo GROUP_([A-Z])/i', 'Grupo $1', $out) ?? $out;

        return $out;
    }

    public static function groupLabel(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return null;
        }
        $label = self::stageLabel($stored);
        if (preg_match('/Grupo\s+([A-Za-z0-9]+)/u', $label, $m)) {
            return 'Grupo ' . strtoupper($m[1]);
        }
        return null;
    }

    public static function eventTitle(array $event): string
    {
        $type = strtolower((string)($event['type'] ?? ''));
        $detail = strtoupper((string)($event['detail'] ?? ''));

        if ($detail === 'SUMMARY') {
            return match ($type) {
                'corner' => 'Tiros de esquina',
                'card' => 'Tarjetas',
                default => 'Resumen',
            };
        }

        return match ($type) {
            'goal' => 'Gol',
            'card' => 'Tarjeta',
            'subst' => 'Cambio',
            'corner' => 'Tiro de esquina',
            default => (string)($event['type'] ?? 'Evento'),
        };
    }

    public static function eventDetail(array $event): string
    {
        $type = strtolower((string)($event['type'] ?? ''));
        $detail = (string)($event['detail'] ?? '');

        if (strtoupper($detail) === 'SUMMARY') {
            return (string)($event['summary_text'] ?? '');
        }

        if ($type === 'card') {
            return match (strtoupper($detail)) {
                'YELLOW' => 'Amarilla',
                'RED', 'YELLOW_RED' => 'Roja',
                default => $detail,
            };
        }
        if ($type === 'goal') {
            return match (strtoupper($detail)) {
                'PENALTY', 'PENALTY_SHOOTOUT', 'PEN' => 'Penal',
                'OWN', 'OWN_GOAL' => 'Autogol',
                'REGULAR' => 'Juego',
                default => $detail !== '' ? $detail : 'Gol',
            };
        }
        if ($type === 'subst') {
            return 'Sustitución';
        }
        return $detail;
    }

    public static function eventPlayersLine(array $event): string
    {
        if (strtoupper((string)($event['detail'] ?? '')) === 'SUMMARY') {
            return '';
        }

        $type = strtolower((string)($event['type'] ?? ''));
        $player = trim((string)($event['player_name'] ?? ''));
        $assist = trim((string)($event['assist_name'] ?? ''));

        if ($type === 'subst' && $player !== '' && $assist !== '') {
            return "Sale: {$player} · Entra: {$assist}";
        }
        $parts = [];
        if ($player !== '') {
            $parts[] = $player;
        }
        if ($assist !== '' && $type === 'goal') {
            $parts[] = 'Asistencia: ' . $assist;
        }
        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $match */
    public static function venueLabel(array $match): string
    {
        return WorldCupVenueResolver::resolveForDbRow($match);
    }

    /** @param array<string, mixed> $match */
    public static function kickoffHasStarted(array $match): bool
    {
        $kickoffRaw = (string)($match['kickoff_at'] ?? '');
        if ($kickoffRaw === '') {
            return false;
        }

        try {
            $kickoff = MatchDataMapper::kickoffFromStorage($kickoffRaw);
            return $kickoff <= new \DateTimeImmutable('now', MatchDataMapper::appTimezone());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $match */
    public static function shouldAutoRefreshMatch(array $match): bool
    {
        $status = strtoupper((string)($match['status'] ?? 'NS'));
        if (in_array($status, ['LIVE', 'HT'], true)) {
            return true;
        }
        if (in_array($status, ['FT', 'PEN', 'AET', 'CANC', 'PST'], true)) {
            return false;
        }

        $kickoffRaw = (string)($match['kickoff_at'] ?? '');
        if ($kickoffRaw === '') {
            return false;
        }

        try {
            $kickoff = MatchDataMapper::kickoffFromStorage($kickoffRaw);
            $now = new \DateTimeImmutable('now', MatchDataMapper::appTimezone());
            return $kickoff <= $now->modify('+15 minutes') && $kickoff >= $now->modify('-48 hours');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int, array<string, mixed>> $matches */
    public static function shouldAutoRefreshMatches(array $matches): bool
    {
        foreach ($matches as $match) {
            if (self::shouldAutoRefreshMatch($match)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $match
     * @param array<int, array<string, mixed>> $events
     */
    public static function emptyEventsMessage(array $match, array $events): string
    {
        if ($events !== []) {
            return '';
        }

        $status = strtoupper((string)($match['status'] ?? 'NS'));
        if (in_array($status, ['LIVE', 'HT'], true)) {
            return 'El partido está en juego. La cronología se actualiza automáticamente cuando Football-Data.org publica los eventos.';
        }
        if (in_array($status, ['FT', 'PEN', 'AET'], true)) {
            return 'Partido finalizado. Football-Data.org aún no publica goles/tarjetas para el Mundial 2026; '
                . 'carga la cronología y las stats (córners y tarjetas) en Admin → Manual. '
                . 'Al registrar las stats, los totales de tiros de esquina y tarjetas aparecen aquí al cierre.';
        }
        if (self::kickoffHasStarted($match)) {
            return 'El partido ya comenzó. Estamos sincronizando con Football-Data.org; los goles y tarjetas aparecerán aquí en cuanto la API los publique (puede haber unos minutos de retraso).';
        }

        return 'Todavía no hay registros. La cronología aparecerá cuando comience el partido.';
    }

    public static function isFinishedStatus(string $status): bool
    {
        return in_array(strtoupper($status), ['FT', 'PEN', 'AET'], true);
    }

    /**
     * Añade filas de resumen (córners y tarjetas) al inicio de la cronología en partidos finalizados.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<string, mixed> $match
     * @param array<string, mixed>|null $stats
     * @return array<int, array<string, mixed>>
     */
    public static function eventsWithStatsSummary(array $events, array $match, ?array $stats): array
    {
        $status = (string)($match['status'] ?? 'NS');
        if (!self::isFinishedStatus($status) || $stats === null) {
            return $events;
        }

        $homeName = (string)($match['home_name'] ?? 'Local');
        $awayName = (string)($match['away_name'] ?? 'Visitante');
        $summary = [];

        if (isset($stats['total_corners']) && $stats['total_corners'] !== null) {
            $homeCorners = $stats['home_corners'] ?? null;
            $awayCorners = $stats['away_corners'] ?? null;
            $totalCorners = (int)$stats['total_corners'];
            $summaryText = $homeCorners !== null && $awayCorners !== null
                ? "{$homeName} " . (int)$homeCorners . " · {$awayName} " . (int)$awayCorners . " · Total {$totalCorners}"
                : "Total {$totalCorners}";

            $summary[] = [
                'type' => 'corner',
                'detail' => 'SUMMARY',
                'summary_text' => $summaryText,
                'minute' => 90,
                'extra_minute' => null,
                'source' => 'stats',
            ];
        }

        if (isset($stats['total_cards']) && $stats['total_cards'] !== null) {
            $totalCards = (int)$stats['total_cards'];
            $parts = [];
            if (isset($stats['total_yellow_cards']) && $stats['total_yellow_cards'] !== null) {
                $parts[] = (int)$stats['total_yellow_cards'] . ' amarilla' . ((int)$stats['total_yellow_cards'] === 1 ? '' : 's');
            }
            if (isset($stats['total_red_cards']) && $stats['total_red_cards'] !== null) {
                $parts[] = (int)$stats['total_red_cards'] . ' roja' . ((int)$stats['total_red_cards'] === 1 ? '' : 's');
            }
            $summaryText = $parts !== []
                ? implode(' · ', $parts) . " · Total {$totalCards}"
                : "Total {$totalCards}";

            $summary[] = [
                'type' => 'card',
                'detail' => 'SUMMARY',
                'summary_text' => $summaryText,
                'minute' => 90,
                'extra_minute' => null,
                'source' => 'stats',
            ];
        }

        if ($summary === []) {
            return $events;
        }

        return array_merge($summary, $events);
    }

    /** @param array<string, mixed> $match */
    public static function statsSourceLabel(?array $stats): string
    {
        if ($stats === null) {
            return 'Stats pendientes';
        }

        return match ((string)($stats['stats_source'] ?? 'api')) {
            'manual' => 'Stats manual',
            'mixed' => 'Stats API + manual',
            default => 'Stats API',
        };
    }

    /** @param array<string, mixed>|null $stats */
    public static function statsSourceBadgeClass(?array $stats): string
    {
        if ($stats === null) {
            return 'badge bg-secondary';
        }

        return match ((string)($stats['stats_source'] ?? 'api')) {
            'manual' => 'badge bg-warning text-dark',
            'mixed' => 'badge bg-info text-dark',
            default => 'badge bg-success',
        };
    }

    /** @param array<string, mixed> $match */
    public static function eventsSourceLabel(array $match): string
    {
        return ($match['events_source'] ?? 'api') === 'manual'
            ? 'Cronología manual'
            : 'Cronología API';
    }

    /** @param array<string, mixed> $match */
    public static function eventsSourceBadgeClass(array $match): string
    {
        return ($match['events_source'] ?? 'api') === 'manual'
            ? 'badge bg-warning text-dark'
            : 'badge bg-success';
    }

    /** @param array<string, mixed> $match */
    public static function dataSourceLabel(array $match): string
    {
        return ($match['data_source'] ?? 'api') === 'manual'
            ? 'Datos manuales (fallback)'
            : 'Datos API';
    }

    /** @param array<string, mixed> $match */
    public static function dataSourceBadgeClass(array $match): string
    {
        return ($match['data_source'] ?? 'api') === 'manual'
            ? 'badge bg-warning text-dark'
            : 'badge bg-success';
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function groupByStage(array $matches): array
    {
        $groups = [];
        foreach ($matches as $m) {
            $stage = trim((string)($m['stage'] ?? ''));
            $key = $stage !== '' ? $stage : 'Calendario';
            $groups[$key][] = $m;
        }
        return $groups;
    }

    /**
     * Marcador para UI: 90 min / prórroga y tanda de penales por separado.
     *
     * @param array<string, mixed> $match
     * @return array{
     *   home:int,away:int,
     *   show_penalties:bool,
     *   pen_home:?int,pen_away:?int,
     *   pen_line:?string
     * }
     */
    public static function scorePresentation(array $match): array
    {
        $status = strtoupper((string)($match['status'] ?? 'NS'));
        $home = (int)($match['regular_home_score'] ?? $match['home_score'] ?? 0);
        $away = (int)($match['regular_away_score'] ?? $match['away_score'] ?? 0);

        if ($status === 'AET') {
            $home = (int)($match['home_score'] ?? $home);
            $away = (int)($match['away_score'] ?? $away);
        }

        $penHome = isset($match['penalty_home_score']) && $match['penalty_home_score'] !== null
            ? (int)$match['penalty_home_score'] : null;
        $penAway = isset($match['penalty_away_score']) && $match['penalty_away_score'] !== null
            ? (int)$match['penalty_away_score'] : null;
        $showPen = $status === 'PEN' && $penHome !== null && $penAway !== null;

        return [
            'home' => $home,
            'away' => $away,
            'show_penalties' => $showPen,
            'pen_home' => $penHome,
            'pen_away' => $penAway,
            'pen_line' => $showPen ? sprintf('Penales %d - %d', $penHome, $penAway) : null,
        ];
    }
}
