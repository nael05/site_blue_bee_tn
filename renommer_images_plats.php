<?php
require 'config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function nom_image_plat_migration(string $nom, string $extension, string $suffix = ''): string
{
    $base = strtr($nom, [
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'Ç' => 'C', 'ç' => 'c', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'Î' => 'I', 'Ï' => 'I',
        'î' => 'i', 'ï' => 'i', 'Ô' => 'O', 'Ö' => 'O', 'ô' => 'o', 'ö' => 'o',
        'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ÿ' => 'Y', 'ÿ' => 'y',
    ]);
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $base), '_'));
    $base = $base !== '' ? $base : 'plat';
    return $base . $suffix . '.' . strtolower($extension);
}

$dossierImages = __DIR__ . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;
$plats = $pdo->query('SELECT id, nom, image_url FROM carte_restaurant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare('UPDATE carte_restaurant SET image_url = ? WHERE id = ?');

foreach ($plats as $plat) {
    $ancienneImage = (string) $plat['image_url'];
    if ($ancienneImage === '' || $ancienneImage === 'default.jpg' || filter_var($ancienneImage, FILTER_VALIDATE_URL)) {
        continue;
    }

    $ancienneChemin = $dossierImages . basename($ancienneImage);
    if (!is_file($ancienneChemin)) {
        echo "Introuvable: {$ancienneImage} ({$plat['nom']})\n";
        continue;
    }

    $extension = strtolower(pathinfo($ancienneImage, PATHINFO_EXTENSION));
    $nouvelleImage = nom_image_plat_migration($plat['nom'], $extension);
    $suffixe = 2;
    while ($nouvelleImage !== $ancienneImage && file_exists($dossierImages . $nouvelleImage)) {
        $nouvelleImage = nom_image_plat_migration($plat['nom'], $extension, '-' . $suffixe++);
    }

    if ($nouvelleImage !== $ancienneImage) {
        if (!rename($ancienneChemin, $dossierImages . $nouvelleImage)) {
            echo "Echec: {$ancienneImage} ({$plat['nom']})\n";
            continue;
        }
        $update->execute([$nouvelleImage, $plat['id']]);
        echo "OK: {$ancienneImage} -> {$nouvelleImage}\n";
    }
}

echo "Migration terminee.\n";