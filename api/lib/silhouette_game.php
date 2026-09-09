<?php
/**
 * api/lib/silhouette_game.php — Logique partagée entre
 * api/game/silhouette-image.php et api/game/silhouette-guess.php : les deux
 * doivent recalculer EXACTEMENT la même cible du jour pour le même joueur, donc
 * partagent la résolution du seed et la validation des paramètres plutôt que
 * de risquer une dérive entre deux copies.
 */

require_once __DIR__ . '/daily_target.php';

/**
 * Seed du joueur pour ce mode : (string) user_id si connecté (miroir exact de
 * getPlayerSeedId() côté client pour un compte), sinon l'anon_id fourni par le
 * client (miroir de l'UUID généré et stocké dans localStorage.anonPlayerId
 * pour un joueur non connecté — voir js/gameCore.js::getPlayerSeedId()).
 *
 * Le serveur ne peut pas connaître le seed anonyme autrement : il n'existe
 * que côté client, avant toute connexion. Le laisser passer ne permet PAS de
 * choisir la cible "à la carte" — un joueur non connecté choisit déjà
 * effectivement son tirage du jour via cet anon_id aujourd'hui (recalculé
 * localement) ; ça ne change rien de le refaire recalculer côté serveur avec
 * le même identifiant, seulement QUI voit le nom en clair.
 *
 * Termine en 400 si ni session ni anon_id valide.
 */
function personadle_silhouette_resolve_seed(): string
{
    $sessionUserId = $_SESSION['user_id'] ?? null;
    if ($sessionUserId) {
        return (string) $sessionUserId;
    }

    $anonId = trim((string) ($_GET['anon_id'] ?? $_POST['anon_id'] ?? ''));
    if ($anonId === '' || !preg_match('/^[0-9a-f]{4,}(-[0-9a-f]{4,})*$/i', $anonId) || strlen($anonId) > 64) {
        jsonError('Missing or invalid player identifier (anon_id)', 400);
    }
    return $anonId;
}

/**
 * Valide et normalise `is_expert` + `played_date` depuis la requête courante
 * (GET ou POST selon la méthode), avec les mêmes règles que api/sessions.php :
 * date au format YYYY-MM-DD, aujourd'hui ou hier en heure de Paris.
 *
 * @return array{0: bool, 1: string} [$isExpert, $playedDate]
 */
function personadle_silhouette_resolve_params(array $source): array
{
    $isExpert   = filter_var($source['is_expert'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $playedDate = trim((string) ($source['played_date'] ?? ''));

    $parisNow = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    if ($playedDate === '') {
        $playedDate = $parisNow;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $playedDate)) {
        jsonError('Invalid played_date format. Expected YYYY-MM-DD', 400);
    }
    $parisYesterday = (new DateTime('yesterday', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    if ($playedDate !== $parisNow && $playedDate !== $parisYesterday) {
        jsonError('played_date must be today or yesterday (Europe/Paris timezone)', 400);
    }

    return [$isExpert, $playedDate];
}

/**
 * Recalcule la cible Silhouette (ou Silhouette Expert) du jour pour ce seed,
 * et le nom de fichier de sa silhouette pré-noircie.
 *
 * @return array{0: string, 1: string}|null [$targetName, $imageBasename], ou
 *         null si la cible ou son image sont introuvables (pool/JSON désynchronisés).
 */
function personadle_silhouette_target(bool $isExpert, string $playedDate, string $seedId): ?array
{
    $mode   = $isExpert ? 'silhouette_expert' : 'silhouette';
    $target = personadle_compute_daily_target($mode, $playedDate, $seedId, []);
    if ($target === null) return null;

    $pools = personadle_load_daily_pools();
    $image = $pools['silhouette']['images'][$target] ?? null;
    if ($image === null) return null;

    return [$target, $image];
}
