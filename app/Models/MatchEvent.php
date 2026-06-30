<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class MatchEvent
{
    public static function forMatch(int $matchId): array
    {
        $st = DB::pdo()->prepare(
            'SELECT * FROM match_events WHERE match_id = :match_id ORDER BY minute DESC, extra_minute DESC, id DESC'
        );
        $st->execute(['match_id' => $matchId]);
        return $st->fetchAll() ?: [];
    }

    /** Reemplaza eventos API (elimina goles anulados que ya no vienen en el detalle). */
    public static function replaceFromFootballData(int $matchId, array $events): int
    {
        $pdo = DB::pdo();
        $pdo->prepare('DELETE FROM match_events WHERE match_id = :match_id')->execute(['match_id' => $matchId]);

        $count = 0;
        foreach ($events as $event) {
            self::upsertFromFootballData($matchId, $event);
            $count++;
        }

        return $count;
    }

    public static function upsertFromFootballData(int $matchId, array $event): void
    {
        $pdo = DB::pdo();

        $minute = (int)($event['minute'] ?? 0);
        $extra = $event['extra_minute'] ?? null;
        $teamApiId = $event['team_api_id'] ?? null;
        $playerName = $event['player_name'] ?? null;
        $assistName = $event['assist_name'] ?? null;
        $type = $event['type'] ?? null;
        $detail = $event['detail'] ?? null;
        $raw = json_encode($event['raw'] ?? $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $derivedKey = hash(
            'sha256',
            $matchId . '|' . $minute . '|' . ($extra ?? '') . '|' . ($teamApiId ?? '') . '|' . ($type ?? '') . '|' . ($detail ?? '') . '|' . ($playerName ?? '') . '|' . ($assistName ?? '')
        );

        $st = $pdo->prepare(
            'INSERT INTO match_events (match_id, api_event_id, derived_key, minute, extra_minute, team_api_id, player_name, assist_name, type, detail, raw_json, created_at)
             VALUES (:match_id, NULL, :derived_key, :minute, :extra_minute, :team_api_id, :player_name, :assist_name, :type, :detail, :raw_json, NOW())
             ON DUPLICATE KEY UPDATE raw_json = VALUES(raw_json)'
        );
        $st->execute([
            'match_id' => $matchId,
            'derived_key' => $derivedKey,
            'minute' => $minute,
            'extra_minute' => $extra,
            'team_api_id' => $teamApiId,
            'player_name' => $playerName,
            'assist_name' => $assistName,
            'type' => $type,
            'detail' => $detail,
            'raw_json' => $raw,
        ]);
    }

    public static function upsertFromApi(int $matchId, array $event): void
    {
        $pdo = DB::pdo();

        $apiEventId = $event['id'] ?? null; // algunos providers no lo traen
        $minute = (int)($event['time']['elapsed'] ?? 0);
        $extra = isset($event['time']['extra']) ? (int)$event['time']['extra'] : null;
        $teamApiId = isset($event['team']['id']) ? (int)$event['team']['id'] : null;
        $playerName = isset($event['player']['name']) ? (string)$event['player']['name'] : null;
        $assistName = isset($event['assist']['name']) ? (string)$event['assist']['name'] : null;
        $type = isset($event['type']) ? (string)$event['type'] : null;
        $detail = isset($event['detail']) ? (string)$event['detail'] : null;
        $raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Si no hay id del evento, hacemos una huella (hash) para idempotencia.
        $derivedKey = $apiEventId ? null : hash('sha256', $matchId . '|' . $minute . '|' . ($extra ?? '') . '|' . ($teamApiId ?? '') . '|' . ($type ?? '') . '|' . ($detail ?? '') . '|' . ($playerName ?? '') . '|' . ($assistName ?? ''));

        $st = $pdo->prepare(
            'INSERT INTO match_events (match_id, api_event_id, derived_key, minute, extra_minute, team_api_id, player_name, assist_name, type, detail, raw_json, created_at)
             VALUES (:match_id, :api_event_id, :derived_key, :minute, :extra_minute, :team_api_id, :player_name, :assist_name, :type, :detail, :raw_json, NOW())
             ON DUPLICATE KEY UPDATE raw_json = VALUES(raw_json)'
        );
        $st->execute([
            'match_id' => $matchId,
            'api_event_id' => $apiEventId ? (string)$apiEventId : null,
            'derived_key' => $derivedKey,
            'minute' => $minute,
            'extra_minute' => $extra,
            'team_api_id' => $teamApiId,
            'player_name' => $playerName,
            'assist_name' => $assistName,
            'type' => $type,
            'detail' => $detail,
            'raw_json' => $raw,
        ]);
    }
}

