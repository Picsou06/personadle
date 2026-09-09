-- =============================================================================
-- 040 — game_states : machine à états serveur pour une partie en cours
-- =============================================================================
-- Jusqu'ici le client choisissait la cible (localement ou via les endpoints
-- silhouette-image/silhouette-guess pour la cible du jour), comptait ses
-- propres tentatives, et déclarait le résultat final à POST /api/sessions —
-- que le serveur n'a jamais vérifié autrement qu'en loguant un écart (phase 1
-- de l'anti-triche, jamais passée en rejet). Cette table fait du serveur la
-- seule source de vérité : il choisit la cible, compte les tentatives,
-- calcule la durée, et décide seul si la partie est gagnée.
--
-- Séparée de `game_sessions` (qui reste l'historique des parties TERMINÉES,
-- lu par classement/streak/stats) plutôt que d'y ajouter un état "en cours" :
-- `game_sessions` a une contrainte UNIQUE sur client_session_id et un schéma
-- déjà lu par 15+ fichiers API, aucune raison d'y mêler le cycle de vie d'une
-- partie non terminée.
--
-- Joueurs connectés ET invités : `user_id` OU `guest_id` (jamais les deux, cf.
-- CHECK plus bas) — l'identité invité est un jeton opaque émis par le serveur
-- (api/lib/game_state.php::personadle_game_state_identity()), jamais fourni
-- par le client, pour ne pas rouvrir la faille qu'on referme (un identifiant
-- choisi par le client se change à volonté d'une requête à l'autre).
--
-- `attempts`/`started_at`/`finished_at` ne sont JAMAIS écrits depuis une
-- valeur envoyée par le client — c'est le point entier de cette table.
-- `finished_at IS NOT NULL` est terminal : toute requête ultérieure sur cette
-- ligne renvoie le résultat déjà acquis, jamais une réécriture (idempotence
-- anti-rejeu, remplace client_session_id pour ce flux).
--
-- ⚠️ Syntaxe MariaDB (`ADD COLUMN IF NOT EXISTS` etc.), comme 031/032/037.
-- MySQL 8.0 la refuse. La prod tourne en MariaDB 10.6.
--
-- Idempotente : rejouable sans effet de bord.
-- =============================================================================

CREATE TABLE IF NOT EXISTS game_states (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          BIGINT UNSIGNED NULL,
    guest_id         CHAR(32) NULL,
    mode             VARCHAR(30) NOT NULL,
    is_expert        TINYINT(1) NOT NULL DEFAULT 0,
    played_date      DATE NOT NULL,
    target_name      VARCHAR(200) NOT NULL,
    origin           ENUM('daily', 'replay', 'challenge') NOT NULL,
    challenge_msg_id BIGINT UNSIGNED NULL,
    active_filters   TEXT NULL,
    attempts         INT UNSIGNED NOT NULL DEFAULT 0,
    started_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at      TIMESTAMP NULL,
    result           ENUM('win', 'giveup') NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_game_states_owner CHECK (user_id IS NOT NULL OR guest_id IS NOT NULL),
    KEY idx_game_states_user  (user_id, mode, is_expert, finished_at),
    KEY idx_game_states_guest (guest_id, mode, is_expert, finished_at),
    CONSTRAINT game_states_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT game_states_msg_fk FOREIGN KEY (challenge_msg_id) REFERENCES messages (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
