<?php
require 'config.php';
try {
    $bdd = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    
    $sql = "INSERT INTO carte_restaurant (nom, description, prix, categorie, image_url)
    SELECT * FROM (
        SELECT 'Chorba' AS n, 'Soupe chaude et parfumée aux épices, tomates, pois chiches et viande' AS d, 4.00 AS p, 'Entrées' AS c, 'default.jpg' AS i UNION ALL
        SELECT 'Salade tunisienne', 'Tomates, concombres, oignons et thon, assaisonnés d''huile d''olive et citron', 3.00, 'Entrées', 'default.jpg' UNION ALL
        SELECT 'Salade Méchouia', 'Poivrons, oignons et tomates grillés, hachés finement, assaisonnés d''huile d''olive et ail', 3.00, 'Entrées', 'default.jpg' UNION ALL
        SELECT 'Tajine Tunisien', 'Gratin salé aux œufs, fromage, viande, pommes de terre, persil et épices, cuit au four', 2.50, 'Entrées', 'default.jpg' UNION ALL
        SELECT 'Fricassé Thon', 'Beignet frit, thon, pommes de terre, olives et oeuf, relevé de harissa', 2.50, 'Entrées', 'default.jpg' UNION ALL
        SELECT 'Brick Tunisien', 'Fine pâte croustillante garnie de thon, œuf et épices', 3.00, 'Entrées', 'default.jpg' UNION ALL
        SELECT 'Assiette Escalope', 'Salade tunisienne, salade méchouia, œuf, pommes de terre, tomates', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Assiette Merguez', 'Salade tunisienne, salade méchouia, œuf, pommes de terre, tomates', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Assiette Kefta', 'Salade tunisienne, salade méchouia, œuf, pommes de terre, tomates', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Assiette Tunisienne (shan tounsi)', 'Salade tunisienne, salade méchouia, œuf, pommes de terre, tomates', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Assiette Kafteji', 'Salade tunisienne, salade méchouia, œuf, pommes de terre, tomates', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Plat du jour', '', 12.00, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Ojja Merguez', 'Tomate, poivron, oignons, ail, œuf', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Ojja Escalope', 'Tomate, poivron, oignons, ail, œuf', 9.50, 'Plats Tunisiens', 'default.jpg' UNION ALL
        SELECT 'Assida Zgougou', '', 4.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Tiramisu', '', 4.00, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Pâtisseries orientales', '3 pcs au choix', 3.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Mille-feuilles', '', 3.00, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Fondant au chocolat', '', 2.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Citronnade', 'Jus Frais Maison', 3.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Jus de Fraise', 'Jus Frais Maison', 3.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Jus d''Orange', 'Jus Frais Maison', 3.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Eau 50 cl', '', 1.00, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Soda 33 cl', '', 1.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Soda 1.50 L', '', 2.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Café', '', 1.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Café gourmand', 'Café + pâtisseries orientales 2 pcs au choix', 3.50, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Thé', '', 1.00, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Thé aux amandes', '', 3.00, 'Boissons', 'default.jpg' UNION ALL
        SELECT 'Thé aux pignons', '', 3.50, 'Boissons', 'default.jpg'
    ) AS tmp
    WHERE NOT EXISTS (
        SELECT 1 FROM carte_restaurant WHERE carte_restaurant.nom = tmp.n
    );";

    $bdd->exec($sql);
    echo "✅ Tous les plats ont été ajoutés avec succès ! Allez voir votre site.";
} catch (PDOException $e) {
    echo "Erreur : " . $e->getMessage();
}
?>