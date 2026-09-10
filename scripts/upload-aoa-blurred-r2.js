#!/usr/bin/env node
/**
 * scripts/upload-aoa-blurred-r2.js — Envoie les variantes floutées AOA
 * (générées par scripts/generate-aoa-blurred.js) vers Cloudflare R2, sous des
 * clés opaques (HMAC-SHA256) plutôt que le nom du personnage.
 *
 * Pourquoi opaque : R2 est un bucket public en lecture (comme celui qui sert
 * déjà l'art original du mode). Un objet nommé "Aigis_0.webp" (flou niveau 0 =
 * quasi net) resterait accessible en le devinant, même sans passer par le
 * serveur — exactement la fuite qu'on referme. La clé opaque n'est calculable
 * qu'avec AOA_R2_BLUR_SALT, qui ne vit que côté serveur (api/config.php,
 * jamais commité) et dans le .env local utilisé ici.
 *
 * Ne committe jamais les fichiers sources : contrairement aux silhouettes
 * (déjà dans le dépôt), l'art AOA n'a jusqu'ici jamais été versionné — il vit
 * sur R2 pour cette raison précise.
 *
 * Variables d'environnement requises :
 *   R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET
 *   AOA_R2_BLUR_SALT — DOIT être la même valeur que api/config.php sur le
 *     serveur (sinon le serveur calcule une clé différente et ne retrouve
 *     jamais l'objet). Générer avec : node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
 *
 * Usage :
 *   node scripts/upload-aoa-blurred-r2.js
 */

import { createHmac } from "node:crypto";
import { readdirSync, readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { S3Client, PutObjectCommand } from "@aws-sdk/client-s3";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const SRC_DIR = join(ROOT, "api/data/aoa_blurred");
const R2_PREFIX = "aoa-blurred/";

const required = [
  "R2_ACCOUNT_ID",
  "R2_ACCESS_KEY_ID",
  "R2_SECRET_ACCESS_KEY",
  "R2_BUCKET",
  "AOA_R2_BLUR_SALT",
];
const missing = required.filter((name) => !process.env[name]);
if (missing.length) {
  console.error(`❌ Variables d'environnement manquantes : ${missing.join(", ")}`);
  process.exit(1);
}

const {
  R2_ACCOUNT_ID,
  R2_ACCESS_KEY_ID,
  R2_SECRET_ACCESS_KEY,
  R2_BUCKET,
  AOA_R2_BLUR_SALT,
} = process.env;

/**
 * Description : même calcul que personadle_aoa_r2_key() côté PHP
 *               (api/lib/game_state.php) — les deux DOIVENT rester identiques.
 * Usage       : const key = r2Key("Aigis", "0");
 * Inputs      : imageName (string), suffix (string) — niveau de flou ou "expert".
 * Outputs     : string — nom d'objet R2, sans extension.
 */
function r2Key(imageName, suffix) {
  return createHmac("sha256", AOA_R2_BLUR_SALT).update(`aoa:${imageName}:${suffix}`).digest("hex");
}

const client = new S3Client({
  region: "auto",
  endpoint: `https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com`,
  credentials: { accessKeyId: R2_ACCESS_KEY_ID, secretAccessKey: R2_SECRET_ACCESS_KEY },
});

async function main() {
  const files = readdirSync(SRC_DIR).filter((f) => f.endsWith(".webp"));
  let uploaded = 0;

  for (const file of files) {
    const match = file.match(/^(.+)_(\d+|expert)\.webp$/);
    if (!match) {
      console.warn(`⚠ Ignoré (nom inattendu) : ${file}`);
      continue;
    }
    const [, imageName, suffix] = match;
    const key = R2_PREFIX + r2Key(imageName, suffix) + ".webp";
    const body = readFileSync(join(SRC_DIR, file));

    await client.send(
      new PutObjectCommand({
        Bucket: R2_BUCKET,
        Key: key,
        Body: body,
        ContentType: "image/webp",
      })
    );
    uploaded++;
    if (uploaded % 50 === 0) console.log(`… ${uploaded}/${files.length}`);
  }

  console.log(`✅ ${uploaded} variantes envoyées vers r2://${R2_BUCKET}/${R2_PREFIX}`);
}

main().catch((err) => {
  console.error("[upload-aoa-blurred-r2] Erreur:", err);
  process.exitCode = 1;
});
