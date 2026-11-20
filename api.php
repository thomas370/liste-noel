<?php
// Liste de Noël : lecture de la liste (GET) et enregistrement protégé par mot de passe (POST).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/config.php';

const FICHIER = __DIR__ . '/liste.json';

function repondre(int $code, array $corps): void
{
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function couper(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

function texte($v, int $max): string
{
    return is_string($v) ? couper(trim($v), $max) : '';
}

function lien($v): string
{
    if (!is_string($v)) {
        return '';
    }
    $v = trim($v);
    if ($v === '' || strlen($v) > 2000 || preg_match('/[\s<>"]/', $v)) {
        return '';
    }
    $p = parse_url($v);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) {
        return '';
    }
    $schema = strtolower($p['scheme']);
    return ($schema === 'http' || $schema === 'https') ? $v : '';
}

function nettoyer($d): array
{
    $d = is_array($d) ? $d : [];
    $source = (isset($d['items']) && is_array($d['items'])) ? $d['items'] : [];
    $items = [];
    foreach (array_slice($source, 0, 200) as $i) {
        if (!is_array($i)) {
            continue;
        }
        $titre = texte($i['title'] ?? '', 120);
        if ($titre === '') {
            continue;
        }
        $id = $i['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) {
            $id = bin2hex(random_bytes(6));
        }
        $prix = $i['price'] ?? null;
        $prix = ((is_int($prix) || is_float($prix)) && $prix >= 0 && $prix <= 100000) ? round((float) $prix, 2) : null;
        $prio = is_numeric($i['priority'] ?? null) ? (int) $i['priority'] : 2;
        $items[] = [
            'id' => $id,
            'title' => $titre,
            'link' => lien($i['link'] ?? ''),
            'alt' => lien($i['alt'] ?? ''),
            'price' => $prix,
            'priority' => in_array($prio, [1, 2, 3], true) ? $prio : 2,
            'note' => texte($i['note'] ?? '', 300),
            'createdAt' => is_numeric($i['createdAt'] ?? null) ? (int) $i['createdAt'] : 0,
        ];
    }
    return [
        'owner' => texte($d['owner'] ?? '', 40),
        'message' => texte($d['message'] ?? '', 280),
        'updatedAt' => is_numeric($d['updatedAt'] ?? null) ? (int) $d['updatedAt'] : null,
        'items' => $items,
    ];
}

function lire(): array
{
    if (!is_file(FICHIER)) {
        return nettoyer([]);
    }
    $brut = file_get_contents(FICHIER);
    return nettoyer(json_decode($brut === false ? '' : $brut, true));
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($methode === 'GET') {
    repondre(200, lire());
}
if ($methode !== 'POST') {
    repondre(405, ['error' => 'methode']);
}

$brut = file_get_contents('php://input');
if ($brut === false || strlen($brut) > 300000) {
    repondre(413, ['error' => 'trop_gros']);
}
$corps = json_decode($brut, true);
if (!is_array($corps)) {
    repondre(400, ['error' => 'requete']);
}

$mdp = (isset($MOT_DE_PASSE) && is_string($MOT_DE_PASSE)) ? $MOT_DE_PASSE : '';
if ($mdp === '' || $mdp === 'change-moi') {
    repondre(403, ['error' => 'mot_de_passe_par_defaut']);
}
$essai = is_string($corps['password'] ?? null) ? $corps['password'] : '';
if (!hash_equals($mdp, $essai)) {
    usleep(700000); // freine les essais de mot de passe en série
    repondre(401, ['error' => 'mot_de_passe']);
}

if (!empty($corps['check'])) {
    repondre(200, ['ok' => true]);
}

// Refuse d'écraser une version plus récente enregistrée depuis un autre appareil.
$actuelle = lire();
$base = is_numeric($corps['base'] ?? null) ? (int) $corps['base'] : null;
if ($actuelle['updatedAt'] !== null && $base !== $actuelle['updatedAt']) {
    repondre(409, ['error' => 'conflit', 'data' => $actuelle]);
}

$nouvelle = nettoyer($corps['data'] ?? null);
$nouvelle['updatedAt'] = (int) round(microtime(true) * 1000);

$json = json_encode($nouvelle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$tmp = FICHIER . '.tmp';
if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, FICHIER)) {
    repondre(500, ['error' => 'ecriture']);
}

repondre(200, ['ok' => true, 'data' => $nouvelle]);
