#!/usr/bin/env php
<?php
/**
 * Script d'extraction des évaluations Steam de LeVioc
 * Version PHP simplifiée et robuste
 */

define('BASE_URL', 'https://steamcommunity.com/id/LeVioc/recommended/');
define('OUTPUT_DIR', 'pages/reviews');
define('IMAGE_DIR', 'assets/images/apps');
define('STEAM_IMAGE_URL', 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/%s/capsule_616x353.jpg');
define('STEAM_IMAGE_URL_FALLBACK', 'https://cdn.akamai.steamstatic.com/steam/apps/%s/header.jpg');
define('DELAY', 2);

const MOIS_FR = [
    'janvier' => '01', 'février' => '02', 'fevrier' => '02', 'mars' => '03',
    'avril' => '04', 'mai' => '05', 'juin' => '06', 'juillet' => '07',
    'août' => '08', 'aout' => '08', 'septembre' => '09', 'octobre' => '10',
    'novembre' => '11', 'décembre' => '12', 'decembre' => '12'
];

function cleanText($text) {
    if (empty($text)) return '';
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = strip_tags($text);
    $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return trim($text);
}

function getGameTitle($appId) {
    static $cache = [];

    if (isset($cache[$appId])) {
        return $cache[$appId];
    }

    // Méthode 1 : Appel à l'API Steam Store
    $url = "https://store.steampowered.com/api/appdetails?appids={$appId}&l=french";

    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 5
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response) {
        $data = json_decode($response, true);
        if (isset($data[$appId]['success']) && $data[$appId]['success'] && isset($data[$appId]['data']['name'])) {
            $title = $data[$appId]['data']['name'];
            $cache[$appId] = $title;
            return $title;
        }
    }

    // Méthode 2 (fallback) : Récupération depuis la page de review Steam Community
    $reviewUrl = "https://steamcommunity.com/id/LeVioc/recommended/{$appId}";
    $reviewHtml = @file_get_contents($reviewUrl, false, $context);

    if ($reviewHtml && preg_match('/<meta property="og:title" content="[^:]*::[^:]*:: Review for ([^"]+)"/i', $reviewHtml, $m)) {
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cache[$appId] = $title;
        return $title;
    }

    // Dernier recours
    return "Game {$appId}";
}

function extractReviews($pageNum) {
    $url = BASE_URL . '?p=' . $pageNum;
    echo "\n📄 Page {$pageNum}...\n";

    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\nAccept-Language: fr-FR\r\n",
            'timeout' => 15
        ]
    ]);

    $html = @file_get_contents($url, false, $context);
    if (!$html) {
        echo "  ✗ Erreur de récupération\n";
        return [];
    }

    $reviews = [];
    $seen = [];

    // Extraction simple : trouve tous les app IDs
    preg_match_all('/\/recommended\/(\d+)\//', $html, $matches);

    foreach (array_unique($matches[1]) as $appId) {
        if (isset($seen[$appId])) continue;
        $seen[$appId] = true;

        // Pattern amélioré pour capturer le bloc complet de review (inclut hours et posted)
        // On capture depuis le lien app jusqu'à la div bottom_controls ou le prochain review_box
        $pattern = '/\/app\/' . $appId . '[^\w].*?(?=(<div class="review_box"|<div class="review_paging"|$))/s';

        if (!preg_match($pattern, $html, $match)) {
            continue;
        }

        $block = $match[0];

        // Titre du jeu via API Steam Store
        echo "    🔍 Récupération titre {$appId}...";
        $title = getGameTitle($appId);
        echo " OK\n";

        // Recommandé ?
        $recommended = strpos($block, 'icon_thumbsUp') !== false ||
                      strpos($block, 'Recommended</a>') !== false ||
                      strpos($block, 'Recommandé</a>') !== false;

        // Temps de jeu - Pattern amélioré pour "X hrs on record" ou "X h en tout"
        $playtime = 0.0;
        if (preg_match('/(\d+[,.]?\d*)\s*hrs?\s+on\s+record/i', $block, $m)) {
            $playtime = floatval(str_replace(',', '.', $m[1]));
        } elseif (preg_match('/(\d+[,.]?\d*)\s*h[a-z]*\s+en\s+tout/ui', $block, $m)) {
            $playtime = floatval(str_replace(',', '.', $m[1]));
        }

        // Date - Pattern amélioré pour extraire spécifiquement de la balise "posted"
        $date = '2016-01-01';
        $monthsEN = [
            'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04',
            'may' => '05', 'june' => '06', 'july' => '07', 'august' => '08',
            'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12'
        ];

        // Cherche d'abord la balise posted complète
        if (preg_match('/<div\s+class="posted"[^>]*>(.*?)<\/div>/si', $block, $postedMatch)) {
            $postedText = $postedMatch[1];

            // Extrait la date au format "Posted X Month, Year"
            if (preg_match('/Posted\s+(\d{1,2})\s+([a-z]+),?\s+(\d{4})/i', $postedText, $m)) {
                $jour = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $moisText = strtolower($m[2]);
                $mois = $monthsEN[$moisText] ?? '01';
                $annee = $m[3];

                // Validation basique de la date
                if ($jour >= 1 && $jour <= 31 && isset($monthsEN[$moisText])) {
                    $date = "{$annee}-{$mois}-{$jour}";
                }
            }
            // Format français si présent
            elseif (preg_match('/(\d{1,2})\s+([a-zûàéè]+)\s+(\d{4})/ui', $postedText, $m)) {
                $jour = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $moisText = mb_strtolower($m[2]);
                $mois = MOIS_FR[$moisText] ?? '01';
                $annee = $m[3];

                if ($jour >= 1 && $jour <= 31 && isset(MOIS_FR[$moisText])) {
                    $date = "{$annee}-{$mois}-{$jour}";
                }
            }
        }

        // Contenu - Pattern amélioré pour capturer le texte de la review
        $content = '';

        // Le pattern doit gérer "content " avec un espace après
        if (preg_match('/<div\s+class="content\s*"[^>]*>(.*?)<\/div>/si', $block, $m)) {
            $content = cleanText($m[1]);
        }

        // Fallback : cherche juste après "content" sans être trop strict
        if (empty($content) || strlen($content) < 20) {
            if (preg_match('/class="content[^"]*">(.+?)<\/div>/si', $block, $m)) {
                $content = cleanText($m[1]);
            }
        }

        // Ignore les reviews sans contenu (au lieu de créer un fichier vide)
        if (strlen($content) < 20) {
            echo "  ⚠️  {$title} - Pas de contenu, ignoré\n";
            continue;
        }

        $reviews[] = [
            'app_id' => $appId,
            'title' => $title,
            'date' => $date,
            'recommended' => $recommended,
            'playtime' => $playtime,
            'content' => $content
        ];

        $emoji = $recommended ? '👍' : '👎';
        echo "  ✓ {$title} - {$playtime}h - {$date} {$emoji}\n";

        // Petit délai pour ne pas surcharger l'API Steam
        usleep(200000); // 0.2 secondes
    }

    echo "  → " . count($reviews) . " évaluation(s)\n";
    return $reviews;
}

function downloadImage($appId) {
    $path = IMAGE_DIR . "/{$appId}.jpg";

    if (file_exists($path)) {
        echo "    Image OK: {$appId}.jpg\n";
        return true;
    }

    $url = sprintf(STEAM_IMAGE_URL, $appId);
    $img = @file_get_contents($url);

    if (!$img) {
        $fallbackUrl = sprintf(STEAM_IMAGE_URL_FALLBACK, $appId);
        $img = @file_get_contents($fallbackUrl);
    }

    if ($img) {
        @mkdir(IMAGE_DIR, 0755, true);
        file_put_contents($path, $img);
        echo "    ✓ Image: {$appId}.jpg (" . strlen($img) . " bytes)\n";
        return true;
    }

    echo "    ✗ Image manquante: {$appId}\n";
    return false;
}

function createFile($review) {
    $file = OUTPUT_DIR . "/{$review['app_id']}.md";

    if (file_exists($file)) {
        echo "  Existe déjà: {$review['app_id']}.md\n";
        return false;
    }

    @mkdir(OUTPUT_DIR, 0755, true);

    $title = $review['title'];
    $rec = $review['recommended'] ? 'true' : 'false';

    $md = "---\n";
    $md .= "title: \"{$title}\"\n";
    $md .= "date: {$review['date']}\n";
    $md .= "recommended: {$rec}\n";
    $md .= "playtime: {$review['playtime']}\n";
    $md .= "image: images/apps/{$review['app_id']}.jpg\n";
    $md .= "---\n";
    $md .= "{$review['content']}\n";

    file_put_contents($file, $md);
    echo "  ✓ Créé: {$review['app_id']}.md\n";

    downloadImage($review['app_id']);
    return true;
}

function main($argc, $argv) {
    echo str_repeat('=', 70) . "\n";
    echo " 🎮  Extraction Steam - LeVioc\n";
    echo str_repeat('=', 70) . "\n";

    $mode = $argc > 1 ? strtolower($argv[1]) : '';

    if (empty($mode)) {
        echo "\nUsage: php extract_reviews.php [test|all|1-14]\n";
        echo "  test  = 1 page (test)\n";
        echo "  all   = 14 pages (complet)\n";
        echo "  1-14  = nombre de pages\n\n";
        $mode = 'test';
    }

    $pages = match($mode) {
        'test' => 1,
        'all', '' => 14,
        default => max(1, min(14, intval($mode)))
    };

    echo "→ Extraction de {$pages} page(s)\n";

    @mkdir(OUTPUT_DIR, 0755, true);
    @mkdir(IMAGE_DIR, 0755, true);

    $start = microtime(true);
    $all = [];

    echo "\n" . str_repeat('─', 70) . "\n";
    echo "📥 Phase 1: Extraction\n";
    echo str_repeat('─', 70);

    for ($p = 1; $p <= $pages; $p++) {
        $all = array_merge($all, extractReviews($p));
        if ($p < $pages) {
            echo "⏳ Pause " . DELAY . "s...\n";
            sleep(DELAY);
        }
    }

    // Dédoublonnage
    $unique = [];
    $seen = [];
    foreach ($all as $r) {
        if (!isset($seen[$r['app_id']])) {
            $seen[$r['app_id']] = true;
            $unique[] = $r;
        }
    }

    echo "\n" . str_repeat('─', 70) . "\n";
    echo "✓ " . count($unique) . " évaluation(s) unique(s)\n";
    echo str_repeat('─', 70) . "\n";

    echo "\n" . str_repeat('─', 70) . "\n";
    echo "📝 Phase 2: Création des fichiers\n";
    echo str_repeat('─', 70) . "\n\n";

    $created = 0;
    $skipped = 0;

    foreach ($unique as $i => $r) {
        echo "[" . ($i+1) . "/" . count($unique) . "] {$r['title']}\n";
        if (createFile($r)) {
            $created++;
        } else {
            $skipped++;
        }
        echo "\n";
    }

    $time = microtime(true) - $start;

    echo str_repeat('=', 70) . "\n";
    echo "✅ TERMINÉ\n";
    echo str_repeat('=', 70) . "\n";
    echo "  • Nouveaux:  {$created}\n";
    echo "  • Existants: {$skipped}\n";
    echo "  • Total:     " . count($unique) . "\n";
    echo "  • Durée:     " . number_format($time, 1) . "s\n";
    echo str_repeat('=', 70) . "\n";

    if ($created > 0) {
        echo "\n💾 Fichiers: " . OUTPUT_DIR . "/\n";
        echo "🖼️  Images: " . IMAGE_DIR . "/\n";
    }
}

main($argc, $argv);
