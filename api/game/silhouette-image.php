<?php
/**
 * GET /api/game/silhouette-image — Sert la silhouette du jour, déjà noircie.
 * ────────────────────────────────────────────────────────────────────────────
 * Le nom du personnage ne quitte jamais ce endpoint : l'URL ne dépend que du
 * mode/de la date/du joueur, jamais de la cible. Le fichier streamé vient de
 * api/data/silhouette_blackened/ (pré-généré par
 * scripts/generate-silhouette-blackened.js, bloqué à l'accès HTTP direct par
 * son propre .htaccess) — jamais du dossier des portraits en clair.
 *
 * Query params :
 *   is_expert  : '1'/'true' pour la cible du jour Silhouette Expert (défaut: false)
 *   played_date: YYYY-MM-DD, aujourd'hui ou hier en heure de Paris (défaut: aujourd'hui)
 *   anon_id    : requis si non connecté — voir personadle_silhouette_resolve_seed()
 *
 * Réponse : image/webp en clair, ou 400/404/500 JSON en cas d'erreur.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/silhouette_game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method Not Allowed', 405);
}

rateLimit('silhouette-image:' . getClientIp(), 300, 15 * 60, 'Too many requests. Please wait a few minutes.');

$seedId = personadle_silhouette_resolve_seed();
[$isExpert, $playedDate] = personadle_silhouette_resolve_params($_GET);

rateLimit('silhouette-image:seed:' . $seedId, 120, 15 * 60, 'Too many requests. Please wait a few minutes.');

$target = personadle_silhouette_target($isExpert, $playedDate, $seedId);
if ($target === null) {
    jsonError('No target available for this mode/date', 404);
}
[, $imageBasename] = $target;

$path = __DIR__ . '/../data/silhouette_blackened/' . $imageBasename . '.webp';
if (!is_file($path)) {
    personadle_log_error(pdo(), 'error', 'Missing pre-blackened silhouette', [
        'source' => 'silhouette-image',
        'image'  => $imageBasename,
    ]);
    jsonError('Image unavailable', 500);
}

header('Content-Type: image/webp');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
