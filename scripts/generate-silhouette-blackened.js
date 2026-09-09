#!/usr/bin/env node
/**
 * scripts/generate-silhouette-blackened.js — Pré-génère une version noircie de
 * chaque portrait du mode Silhouette (RVB=0, alpha inchangé — même rendu que
 * js/silhouette_mask.js côté client), servie ensuite par
 * api/game/silhouette-image.php sans jamais exposer le nom du fichier source
 * au client.
 *
 * Pourquoi côté serveur ET pas seulement côté client (js/silhouette_mask.js) :
 * le masquage client ne fait que peindre le résultat à l'écran — l'image
 * d'origine (non masquée) transite quand même sur le réseau, visible dans
 * l'onglet Réseau des DevTools. Ces fichiers pré-noircis permettent au serveur
 * de ne JAMAIS envoyer l'original.
 *
 * Usage :
 *   node scripts/generate-silhouette-blackened.js         # régénère tout
 *   node scripts/generate-silhouette-blackened.js --check # vérifie sans écrire
 *
 * npm run silhouette:build / npm run silhouette:check — voir package.json.
 */

import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import sharp from "sharp";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const CHECK = process.argv.includes("--check");
const SRC_DIR = join(ROOT, "silhouetteMode/database/img");
const OUT_DIR = join(ROOT, "api/data/silhouette_blackened");

/**
 * Description : peint RVB=0 sur chaque pixel opaque d'une image, alpha inchangé
 *               — équivalent serveur de blackenToDataURL() (js/silhouette_mask.js).
 * Usage       : const blackened = await blacken(inputBuffer);
 * Inputs      : inputBuffer (Buffer) — image source (webp/png avec canal alpha).
 * Outputs     : Promise<Buffer> — image webp noircie.
 */
async function blacken(inputBuffer) {
  const { data, info } = await sharp(inputBuffer)
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true });

  for (let i = 0; i < data.length; i += 4) {
    data[i] = 0;
    data[i + 1] = 0;
    data[i + 2] = 0;
  }

  return sharp(data, { raw: { width: info.width, height: info.height, channels: 4 } })
    .webp()
    .toBuffer();
}

async function main() {
  const { silhouetteCharacters } = await import(
    pathToFileURL(join(ROOT, "silhouetteMode/database/silhouetteCharacters.js")).href
  );

  const images = [...new Set(silhouetteCharacters.map((c) => c.image))];
  let changed = 0;
  let missing = 0;

  if (!CHECK) mkdirSync(OUT_DIR, { recursive: true });

  for (const image of images) {
    const srcPath = join(SRC_DIR, `${image}.webp`);
    const outPath = join(OUT_DIR, `${image}.webp`);

    if (!existsSync(srcPath)) {
      console.warn(`⚠ Source manquante pour "${image}" (${srcPath})`);
      missing++;
      continue;
    }

    const blackened = await blacken(readFileSync(srcPath));

    if (CHECK) {
      const current = existsSync(outPath) ? readFileSync(outPath) : null;
      if (!current || !current.equals(blackened)) {
        console.error(`❌ ${image}.webp désynchronisé — lancer \`npm run silhouette:build\`.`);
        changed++;
      }
      continue;
    }

    writeFileSync(outPath, blackened);
  }

  if (CHECK) {
    if (missing > 0) {
      console.error(`❌ ${missing} image(s) source manquante(s).`);
      process.exit(1);
    }
    if (changed > 0) process.exit(1);
    console.log(`✅ ${images.length} silhouettes pré-noircies à jour.`);
    return;
  }

  console.log(
    `✅ ${images.length - missing} silhouettes noircies générées dans ${OUT_DIR.replace(ROOT + "/", "")}` +
      (missing > 0 ? ` (${missing} source(s) manquante(s), voir avertissements ci-dessus)` : "")
  );
}

main().catch((err) => {
  console.error("[generate-silhouette-blackened] Erreur:", err);
  process.exitCode = 1;
});
