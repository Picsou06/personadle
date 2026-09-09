<?php
/**
 * POST /api/game/silhouette-guess — Vérifie une tentative côté serveur.
 * ────────────────────────────────────────────────────────────────────────────
 * Le client n'a jamais accès au nom de la cible du jour : il envoie un nom
 * proposé, le serveur recalcule la vraie cible et répond juste bon/pas bon.
 * Le nom réel n'est révélé dans la réponse que si la tentative est correcte,
 * ou si `reveal: true` (abandon — même logique que le "Give Up" existant).
 *
 * Body JSON :
 *   is_expert   : bool (défaut: false)
 *   played_date : YYYY-MM-DD, aujourd'hui ou hier en heure de Paris (défaut: aujourd'hui)
 *   anon_id     : requis si non connecté
 *   guess       : nom proposé (ignoré si reveal=true)
 *   reveal      : bool — abandon, révèle la cible sans comparer de guess
 *
 * Réponse : { correct: bool, target_name?: string }
 *   target_name absent si correct=false et reveal=false — c'est le seul cas
 *   où le nom ne doit PAS transiter.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/silhouette_game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method Not Allowed', 405);
}

rateLimit('silhouette-guess:' . getClientIp(), 300, 15 * 60, 'Too many requests. Please wait a few minutes.');

$data = getJsonBody();

$seedId = personadle_silhouette_resolve_seed();
[$isExpert, $playedDate] = personadle_silhouette_resolve_params($data);

$maxGuessesPerWindow = $isExpert ? 60 : 30;
rateLimit(
    'silhouette-guess:seed:' . $seedId,
    $maxGuessesPerWindow,
    15 * 60,
    'Too many guesses. Please wait a few minutes.'
);

$reveal = filter_var($data['reveal'] ?? false, FILTER_VALIDATE_BOOLEAN);
$guess  = trim((string) ($data['guess'] ?? ''));

if (!$reveal && $guess === '') {
    jsonError('guess is required unless reveal=true', 400);
}

$target = personadle_silhouette_target($isExpert, $playedDate, $seedId);
if ($target === null) {
    jsonError('No target available for this mode/date', 404);
}
[$targetName] = $target;

if ($reveal) {
    jsonSuccess(['correct' => false, 'revealed' => true, 'target_name' => $targetName]);
}

$correct = strcasecmp($targetName, $guess) === 0;

if ($correct) {
    jsonSuccess(['correct' => true, 'target_name' => $targetName]);
}

jsonSuccess(['correct' => false]);
