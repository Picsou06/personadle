<?php
/**
 * api/lib/game_state.php — Machine à états serveur pour une partie en cours
 * (table game_states, migration 040). Le serveur choisit la cible, compte les
 * tentatives, calcule la durée et décide seul du résultat — le client envoie
 * des intentions (démarrer une partie, proposer une réponse), jamais des faits.
 *
 * Phase 1 (2026-09-09) : origin='replay' uniquement, mode Silhouette
 * uniquement. 'daily' et 'challenge' rejoignent la même table dans une phase
 * ultérieure, sans changer son schéma.
 */

require_once __DIR__ . '/daily_target.php';
require_once __DIR__ . '/game_session.php';

/**
 * Identité du joueur pour cette table : user_id si connecté, sinon un jeton
 * invité opaque émis par le serveur et gardé en session — jamais fourni par
 * le client (un identifiant choisi par le client se change à volonté d'une
 * requête à l'autre, ce qui viderait `finished_at`/`attempts` de leur sens).
 *
 * @return array{user_id: ?int, guest_id: ?string}
 */
function personadle_game_state_identity(): array
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        return ['user_id' => (int) $userId, 'guest_id' => null];
    }

    if (empty($_SESSION['guest_id'])) {
        $_SESSION['guest_id'] = bin2hex(random_bytes(16));
    }
    return ['user_id' => null, 'guest_id' => $_SESSION['guest_id']];
}

/**
 * Restreint un pool de noms aux personnages dont au moins un opus est dans
 * $activeFilters. $activeFilters vide = aucun filtre, pool complet.
 *
 * @param string[] $pool
 * @param array<string, string[]> $opusByName
 * @param string[] $activeFilters
 * @return string[]
 */
function personadle_game_state_filter_pool(array $pool, array $opusByName, array $activeFilters): array
{
    if (empty($activeFilters)) {
        return $pool;
    }
    return array_values(array_filter($pool, function (string $name) use ($opusByName, $activeFilters) {
        $opus = $opusByName[$name] ?? [];
        return count(array_intersect($opus, $activeFilters)) > 0;
    }));
}

/**
 * Modes couverts par le tirage server-authoritative de personadle_game_state_start()
 * (image proxifiée + comparaison de nom, pas de grille d'attributs) — chacun a
 * son propre pool/carte d'images dans daily_pools.json, générés à partir de la
 * même clé que celle utilisée ici.
 */
const PERSONADLE_GAME_STATE_MODES = ['silhouette', 'alloutattack'];

/**
 * Démarre une partie et choisit sa cible côté serveur — jamais renvoyée à
 * l'appelant, qui n'a que le game_id.
 *
 * @param string[] $activeFilters
 * @return array{game_id: int}
 */
function personadle_game_state_start(
    PDO $pdo,
    string $mode,
    bool $isExpert,
    string $origin,
    array $activeFilters
): array {
    if (!in_array($mode, PERSONADLE_GAME_STATE_MODES, true)) {
        jsonError("Mode '$mode' is not yet server-authoritative for game_states", 501);
    }
    if ($origin !== 'replay') {
        jsonError("Origin '$origin' is not yet server-authoritative for game_states", 501);
    }

    $pools = personadle_load_daily_pools();
    $pool = $pools[$mode]['pool'] ?? [];
    $images = $pools[$mode]['images'] ?? [];
    $opusByName = $pools[$mode]['opusByName'] ?? [];

    $filteredPool = personadle_game_state_filter_pool($pool, $opusByName, $activeFilters);
    if (empty($filteredPool)) {
        jsonError('No character available for the active filters', 400);
    }

    $targetName = $filteredPool[random_int(0, count($filteredPool) - 1)];
    if (!isset($images[$targetName])) {
        jsonError('Target has no associated image', 500);
    }

    $identity = personadle_game_state_identity();
    $playedDate = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');

    $stmt = $pdo->prepare('
        INSERT INTO game_states
            (user_id, guest_id, mode, is_expert, played_date, target_name, origin, active_filters)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $identity['user_id'], $identity['guest_id'], $mode, $isExpert ? 1 : 0,
        $playedDate, $targetName, $origin, json_encode($activeFilters),
    ]);

    return ['game_id' => (int) $pdo->lastInsertId()];
}

/** Même formule que blurLevelForAttempts() (scripts/generate-aoa-blurred.js). */
function personadle_aoa_blur_level(int $attempts): int
{
    return max(20 - 3 * min($attempts, 7), 0);
}

/**
 * Clé d'objet R2 opaque pour une variante AOA floutée — même calcul que
 * r2Key() (scripts/upload-aoa-blurred-r2.js). AOA_R2_BLUR_SALT ne doit exister
 * QUE côté serveur (api/config.php, jamais commité) : c'est lui qui empêche de
 * retrouver la clé d'un personnage sans avoir aussi ce secret.
 */
function personadle_aoa_r2_key(string $imageName, string $suffix): string
{
    return hash_hmac('sha256', "aoa:$imageName:$suffix", AOA_R2_BLUR_SALT);
}

/**
 * Résout la source de l'image à streamer pour une ligne game_states : un
 * fichier local (Silhouette, déjà dans le dépôt) ou une URL R2 à récupérer
 * côté serveur (AOA, jamais versionné — voir scripts/upload-aoa-blurred-r2.js).
 * Cache-Control diffère aussi : une silhouette ne change jamais pendant la
 * partie, un flou AOA change à chaque tentative — le cacher longtemps
 * servirait un flou périmé après un guess.
 *
 * @return array{type: 'file'|'remote', location: string, cacheControl: string}|null
 *         null si le mode n'a pas encore d'image server-authoritative, ou si
 *         AOA_R2_BLUR_BASE_URL n'est pas configuré.
 */
function personadle_game_state_image_source(array $row): ?array
{
    $pools = personadle_load_daily_pools();
    $basename = $pools[$row['mode']]['images'][$row['target_name']] ?? null;
    if ($basename === null) {
        return null;
    }

    if ($row['mode'] === 'silhouette') {
        return [
            'type'         => 'file',
            'location'     => __DIR__ . '/../data/silhouette_blackened/' . $basename . '.webp',
            'cacheControl' => 'private, max-age=3600',
        ];
    }

    if ($row['mode'] === 'alloutattack') {
        if (!defined('AOA_R2_BLUR_BASE_URL') || !defined('AOA_R2_BLUR_SALT')) {
            return null;
        }
        $suffix = $row['is_expert'] ? 'expert' : (string) personadle_aoa_blur_level((int) $row['attempts']);
        $key = personadle_aoa_r2_key($basename, $suffix);
        return [
            'type'         => 'remote',
            'location'     => AOA_R2_BLUR_BASE_URL . $key . '.webp',
            'cacheControl' => 'private, no-cache',
        ];
    }

    return null;
}

/**
 * Charge une ligne game_states appartenant à l'identité courante. 404 si
 * absente ou si elle appartient à quelqu'un d'autre — même réponse dans les
 * deux cas, pour ne pas laisser deviner l'existence d'un game_id d'autrui.
 *
 * $forUpdate verrouille la ligne (SELECT ... FOR UPDATE) : nécessaire dans
 * une transaction avant de décider si la partie est terminale, sinon deux
 * requêtes de guess concurrentes peuvent toutes les deux lire finished_at
 * NULL avant qu'aucune n'écrive, et doubler l'appel à
 * personadle_record_game_session() (stats/streak comptées deux fois).
 */
function personadle_game_state_load(PDO $pdo, int $gameId, array $identity, bool $forUpdate = false): array
{
    $sql = 'SELECT * FROM game_states WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();

    $owns = $row && (
        ($identity['user_id'] !== null && (int) $row['user_id'] === $identity['user_id']) ||
        ($identity['guest_id'] !== null && $row['guest_id'] === $identity['guest_id'])
    );
    if (!$owns) {
        jsonError('Game not found', 404);
    }

    return $row;
}

/**
 * Traite une tentative (ou un abandon si $reveal) sur une partie en cours.
 *
 * `finished_at IS NOT NULL` est terminal : une requête sur une partie déjà
 * finie renvoie le résultat acquis, sans jamais réécrire ni ré-attribuer de
 * stats — c'est ce qui rend un retry réseau inoffensif (plus besoin de
 * client_session_id pour ce flux).
 *
 * L'appelant doit tenir la transaction (begin/commit/rollback) — cette
 * fonction verrouille la ligne (SELECT ... FOR UPDATE) mais ne commit rien.
 *
 * @return array{correct: bool, finished: bool, target_name?: string, attempts: int, stats?: array}
 */
function personadle_game_state_guess(PDO $pdo, int $gameId, ?string $guess, bool $reveal): array
{
    $identity = personadle_game_state_identity();
    $row = personadle_game_state_load($pdo, $gameId, $identity, forUpdate: true);

    if ($row['finished_at'] !== null) {
        return [
            'correct'     => $row['result'] === 'win',
            'finished'    => true,
            'target_name' => $row['target_name'],
            'attempts'    => (int) $row['attempts'],
        ];
    }

    $isCorrect = !$reveal && $guess !== null && strcasecmp($row['target_name'], $guess) === 0;
    $isFinal = $reveal || $isCorrect;

    $newAttempts = $isFinal ? (int) $row['attempts'] : (int) $row['attempts'] + 1;

    if (!$isFinal) {
        $pdo->prepare('UPDATE game_states SET attempts = ? WHERE id = ?')
            ->execute([$newAttempts, $gameId]);
        return ['correct' => false, 'finished' => false, 'attempts' => $newAttempts];
    }

    $result = $isCorrect ? 'win' : 'giveup';
    $pdo->prepare('
        UPDATE game_states
        SET attempts = attempts + ?, finished_at = UTC_TIMESTAMP(), result = ?
        WHERE id = ?
    ')->execute([$reveal ? 0 : 1, $result, $gameId]);

    $stmt = $pdo->prepare('SELECT attempts, started_at, finished_at FROM game_states WHERE id = ?');
    $stmt->execute([$gameId]);
    $finished = $stmt->fetch();
    $timeMs = (strtotime($finished['finished_at']) - strtotime($finished['started_at'])) * 1000;

    $response = [
        'correct'     => $isCorrect,
        'finished'    => true,
        'target_name' => $row['target_name'],
        'attempts'    => (int) $finished['attempts'],
    ];

    if ($identity['user_id'] !== null) {
        $sessionResult = personadle_record_game_session(
            $pdo,
            $identity['user_id'],
            $row['mode'],
            $row['played_date'],
            $row['target_name'],
            $result,
            (int) $finished['attempts'],
            max(0, $timeMs),
            json_decode($row['active_filters'] ?? '[]', true) ?? [],
            (bool) $row['is_expert']
        );
        $response['stats'] = $sessionResult['stats'];
        $response['global_streak'] = $sessionResult['global_streak'];
    }

    return $response;
}
