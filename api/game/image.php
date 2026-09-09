<?php
/**
 * GET /api/game/image — Sert l'image (déjà noircie) associée à une partie
 * démarrée via POST /api/game/start.
 * ────────────────────────────────────────────────────────────────────────────
 * Comme api/game/silhouette-image.php (cible du jour), mais adressé par
 * `game_id` plutôt que par (date, seed) — sert n'importe quelle partie de la
 * table game_states (aujourd'hui : origin='replay' uniquement, mode
 * Silhouette). Le nom du personnage ne quitte jamais ce endpoint.
 *
 * Query params :
 *   game_id : int — renvoyé par /api/game/start
 *
 * Réponse : image/webp en clair, ou 400/404/500 JSON en cas d'erreur.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/game_state.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method Not Allowed', 405);
}

rateLimit('game-image:' . getClientIp(), 300, 15 * 60, 'Too many requests. Please wait a few minutes.');

$gameId = (int) ($_GET['game_id'] ?? 0);
if ($gameId <= 0) {
    jsonError('game_id is required', 400);
}

$identity = personadle_game_state_identity();
$row = personadle_game_state_load(pdo(), $gameId, $identity);

if ($row['mode'] !== 'silhouette') {
    jsonError('This mode has no server-side image yet', 501);
}

$pools = personadle_load_daily_pools();
$imageBasename = $pools['silhouette']['images'][$row['target_name']] ?? null;
if ($imageBasename === null) {
    jsonError('Image unavailable', 500);
}

$path = __DIR__ . '/../data/silhouette_blackened/' . $imageBasename . '.webp';
if (!is_file($path)) {
    personadle_log_error(pdo(), 'error', 'Missing pre-blackened silhouette', [
        'source' => 'game-image',
        'image'  => $imageBasename,
    ]);
    jsonError('Image unavailable', 500);
}

header('Content-Type: image/webp');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
