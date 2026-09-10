<?php
/**
 * api/config.example.php — Template de configuration
 * ────────────────────────────────────────────────────
 * Copier ce fichier en config.php et remplir les valeurs réelles.
 * Ne JAMAIS committer config.php dans git.
 *
 * Local  : DB user = personadle_usr, créé par setup.sh
 * Hostinger : remplacer par les credentials du panel hPanel
 */

// ── Base de données ─────────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'personadle_db');
define('DB_USER', 'personadle_usr');
define('DB_PASS', 'CHANGE_THIS_PASSWORD');

// ── Environnement ────────────────────────────────────────────────────────────
// 'local' → cookies non-secure, CORS permissif
// 'production' → cookies secure (HTTPS), CORS strict
define('APP_ENV', 'local');

// ── Cron secret ──────────────────────────────────────────────────────────────
// Clé secrète pour les endpoints cron — générer avec:
//   php -r "echo bin2hex(random_bytes(24));"
define('CRON_SECRET', 'CHANGE_ME_generate_with_php_random_bytes');

// ── Proxys de confiance (rate limiting) ──────────────────────────────────────
// Laisser VIDE tant que le site répond en direct (Apache/LiteSpeed) : l'IP
// utilisée comme clé de rate limiting est alors REMOTE_ADDR, non falsifiable.
//
// À renseigner UNIQUEMENT si un CDN / reverse-proxy est placé devant le site
// (Hostinger CDN, Cloudflare en mode proxy…). Sans ça, REMOTE_ADDR vaut l'IP du
// proxy pour tout le monde → un seul seau partagé → 5 connexions / 15 min pour
// le site ENTIER. Avec la liste renseignée, X-Forwarded-For n'est déroulé que
// s'il vient réellement d'un de ces proxys (cf. api/lib/client_ip.php).
//
// Symptôme à surveiller : des 429 « Too many attempts » sur /api/auth/login
// alors que le joueur n'a fait qu'un seul essai.
//
// Format : IPs exactes et/ou CIDR, IPv4 et IPv6.
//   define('TRUSTED_PROXIES', ['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']);
define('TRUSTED_PROXIES', []);

// ── Variantes AOA floutées sur R2 ─────────────────────────────────────────────
// L'art All-Out-Attack n'est pas versionné dans le dépôt (contrairement aux
// silhouettes) — il vit sur un bucket R2 public en lecture. Les variantes
// pré-floutées (scripts/generate-aoa-blurred.js) y sont envoyées sous des clés
// opaques (HMAC de ce secret) plutôt que le nom du personnage, pour qu'un accès
// direct au bucket ne révèle rien — voir scripts/upload-aoa-blurred-r2.js.
//
// AOA_R2_BLUR_SALT DOIT être IDENTIQUE à la valeur utilisée lors de l'upload
// (variable d'env du même nom), sinon le serveur calcule une clé différente et
// ne retrouve aucun objet. Générer avec :
//   php -r "echo bin2hex(random_bytes(32));"
define('AOA_R2_BLUR_SALT', 'CHANGE_ME_must_match_upload_script_env');

// URL publique de base du bucket (avant le nom d'objet), ex :
//   https://pub-xxxxxxxxxxxx.r2.dev/aoa-blurred/
define('AOA_R2_BLUR_BASE_URL', 'CHANGE_ME_r2_public_url/aoa-blurred/');
