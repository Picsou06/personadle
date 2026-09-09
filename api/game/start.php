<?php
/**
 * POST /api/game/start — Démarre une partie et choisit sa cible côté serveur.
 * ────────────────────────────────────────────────────────────────────────────
 * Le client ne connaît jamais la cible : cette route ne renvoie qu'un
 * `game_id`, à passer ensuite à /api/game/guess pour chaque tentative.
 *
 * Ouvert aux joueurs connectés ET aux invités (api/lib/game_state.php::
 * personadle_game_state_identity()) — seuls les joueurs connectés voient
 * leurs stats/streak mises à jour à la fin de la partie.
 *
 * Body JSON :
 *   mode          : 'silhouette' (seul mode branché en phase 1)
 *   is_expert     : bool (défaut: false)
 *   origin        : 'replay' (seule origine branchée en phase 1)
 *   active_filters: string[] — codes opus actifs, vide = pas de filtre
 *
 * Réponse : { game_id: int }
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/game_state.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method Not Allowed', 405);
}

rateLimit('game-start:' . getClientIp(), 300, 15 * 60, 'Too many requests. Please wait a few minutes.');

$data = getJsonBody();

$mode = strtolower(trim((string) ($data['mode'] ?? '')));
$isExpert = filter_var($data['is_expert'] ?? false, FILTER_VALIDATE_BOOLEAN);
$origin = strtolower(trim((string) ($data['origin'] ?? '')));
$activeFilters = is_array($data['active_filters'] ?? null) ? $data['active_filters'] : [];

if ($mode === '') {
    jsonError('mode is required', 400);
}
if (!in_array($origin, ['daily', 'replay', 'challenge'], true)) {
    jsonError("Invalid origin. Expected 'daily', 'replay' or 'challenge'", 400);
}

$result = personadle_game_state_start(pdo(), $mode, $isExpert, $origin, $activeFilters);

jsonSuccess($result, 201);
