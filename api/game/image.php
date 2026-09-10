<?php
/**
 * GET /api/game/image — Sert l'image (déjà masquée) associée à une partie
 * démarrée via POST /api/game/start.
 * ────────────────────────────────────────────────────────────────────────────
 * Adressé par `game_id`, jamais par nom de personnage. La source dépend du
 * mode (personadle_game_state_image_source(), api/lib/game_state.php) :
 *   - silhouette : fichier local pré-noirci (déjà dans le dépôt)
 *   - alloutattack : variante floutée sur R2, sous clé opaque — le serveur la
 *     récupère lui-même et la restreame, l'URL R2 n'est jamais renvoyée au
 *     client. Le flou dépend des tentatives déjà faites sur CETTE partie
 *     (recalculé ici, jamais fourni par le client).
 *
 * Query params :
 *   game_id : int — renvoyé par /api/game/start
 *
 * Réponse : image/webp en clair, ou 400/404/500/501 JSON en cas d'erreur.
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

$source = personadle_game_state_image_source($row);
if ($source === null) {
    jsonError("Mode '{$row['mode']}' has no server-side image yet", 501);
}

if ($source['type'] === 'file') {
    if (!is_file($source['location'])) {
        personadle_log_error(pdo(), 'error', 'Missing pre-generated game image', [
            'source' => 'game-image',
            'mode'   => $row['mode'],
            'path'   => $source['location'],
        ]);
        jsonError('Image unavailable', 500);
    }

    header('Content-Type: image/webp');
    header("Cache-Control: {$source['cacheControl']}");
    header('Content-Length: ' . filesize($source['location']));
    readfile($source['location']);
    exit;
}

// type === 'remote' : le serveur va chercher l'image sur R2 lui-même — le
// client ne voit jamais cette URL.
$ch = curl_init($source['location']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_FOLLOWLOCATION => false,
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($body === false || $status !== 200) {
    personadle_log_error(pdo(), 'error', 'Failed to fetch remote game image', [
        'source' => 'game-image',
        'mode'   => $row['mode'],
        'status' => $status,
        'error'  => $curlError,
    ]);
    jsonError('Image unavailable', 500);
}

header('Content-Type: image/webp');
header("Cache-Control: {$source['cacheControl']}");
header('Content-Length: ' . strlen($body));
echo $body;
exit;
