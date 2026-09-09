<?php
/**
 * POST /api/game/guess — Vérifie une tentative sur une partie démarrée via
 * POST /api/game/start.
 * ────────────────────────────────────────────────────────────────────────────
 * Le serveur compte les tentatives, décide seul si la partie est gagnée, et
 * — pour un joueur connecté — écrit lui-même la session + les stats/streak
 * (personadle_record_game_session(), api/lib/game_session.php) dès qu'une
 * partie se termine. Le client n'envoie jamais ni le résultat, ni le nombre
 * de tentatives, ni la durée.
 *
 * `finished_at` est terminal côté serveur : rejouer la même requête sur une
 * partie déjà finie renvoie le résultat acquis, sans jamais réécrire ni
 * recompter — un retry réseau après coupure est donc sans risque.
 *
 * Body JSON :
 *   game_id : int — renvoyé par /api/game/start
 *   guess   : nom proposé (ignoré si reveal=true)
 *   reveal  : bool — abandon, termine la partie sans comparer de guess
 *
 * Réponse : { correct, finished, target_name?, attempts, stats?, global_streak? }
 *   target_name absent si la partie n'est pas terminée ; stats/global_streak
 *   absents pour un invité (pas de compte à mettre à jour).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/game_state.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method Not Allowed', 405);
}

rateLimit('game-guess:' . getClientIp(), 600, 15 * 60, 'Too many requests. Please wait a few minutes.');

$data = getJsonBody();

$gameId = (int) ($data['game_id'] ?? 0);
$reveal = filter_var($data['reveal'] ?? false, FILTER_VALIDATE_BOOLEAN);
$guess = trim((string) ($data['guess'] ?? ''));

if ($gameId <= 0) {
    jsonError('game_id is required', 400);
}
if (!$reveal && $guess === '') {
    jsonError('guess is required unless reveal=true', 400);
}

$pdo = pdo();
$pdo->beginTransaction();
try {
    $result = personadle_game_state_guess($pdo, $gameId, $reveal ? null : $guess, $reveal);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    personadle_log_error($pdo, 'error', $e->getMessage(), [
        'source'  => 'game-guess',
        'game_id' => $gameId,
    ]);
    jsonError('Failed to process guess', 500);
}

jsonSuccess($result);
