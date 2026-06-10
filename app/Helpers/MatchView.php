<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Services\MatchDataMapper;

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
            default => $status,
        };
    }

    public static function statusBadgeClass(string $status): string
    {
        return match (strtoupper($status)) {
            'LIVE', 'HT' => 'badge badge-live',
            'FT' => 'badge badge-ft',
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
        return match ($type) {
            'goal' => 'Gol',
            'card' => 'Tarjeta',
            'subst' => 'Cambio',
            default => (string)($event['type'] ?? 'Evento'),
        };
    }

    public static function eventDetail(array $event): string
    {
        $type = strtolower((string)($event['type'] ?? ''));
        $detail = (string)($event['detail'] ?? '');

        if ($type === 'card') {
            return match (strtoupper($detail)) {
                'YELLOW' => 'Amarilla',
                'RED', 'YELLOW_RED' => 'Roja',
                default => $detail,
            };
        }
        if ($type === 'goal') {
            return match (strtoupper($detail)) {
                'PENALTY' => 'Penal',
                'OWN' => 'Autogol',
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
}
