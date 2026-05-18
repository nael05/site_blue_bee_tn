<?php
// Script LOCAL uniquement - génère les UPDATE SQL pour InfinityFree
$pdo = new PDO("mysql:host=localhost;dbname=db_restaurant;charset=utf8mb4", "root", "");
$plats = $pdo->query("SELECT id, nom, image_url FROM carte_restaurant ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

echo "-- Colle ces requêtes dans phpMyAdmin InfinityFree\n\n";
foreach ($plats as $p) {
    $img = addslashes($p['image_url']);
    $nom = addslashes($p['nom']);
    echo "UPDATE `carte_restaurant` SET `image_url` = '$img' WHERE `nom` = '$nom';\n";
}
?>
