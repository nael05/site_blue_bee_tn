<?php
require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Vérification et mise à jour de la base de données...\n";

    // 1. Table carte_restaurant
    $cols = $pdo->query("DESCRIBE carte_restaurant")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('temps_prep_min', $cols)) {
        $pdo->exec("ALTER TABLE carte_restaurant ADD COLUMN temps_prep_min INT DEFAULT 5");
        echo "Colonne temps_prep_min ajoutée.\n";
    }
    if (!in_array('type_stock', $cols)) {
        $pdo->exec("ALTER TABLE carte_restaurant ADD COLUMN type_stock ENUM('reel', 'infini') DEFAULT 'infini'");
        echo "Colonne type_stock ajoutée.\n";
    }
    if (!in_array('stock_actuel', $cols)) {
        $pdo->exec("ALTER TABLE carte_restaurant ADD COLUMN stock_actuel INT DEFAULT 0");
        echo "Colonne stock_actuel ajoutée.\n";
    }

    // 2. Table commandes
    $cols = $pdo->query("DESCRIBE commandes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('temps_total_prep', $cols)) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN temps_total_prep INT");
        echo "Colonne temps_total_prep ajoutée.\n";
    }
    if (!in_array('heure_debut_prep', $cols)) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN heure_debut_prep DATETIME");
        echo "Colonne heure_debut_prep ajoutée.\n";
    }
    if (!in_array('heure_fin_estimee', $cols)) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN heure_fin_estimee DATETIME");
        echo "Colonne heure_fin_estimee ajoutée.\n";
    }

    // 3. Table commandes_settings (Default values)
    $stmt = $pdo->prepare("INSERT IGNORE INTO commandes_settings (s_key, s_value) VALUES (?, ?)");
    $stmt->execute(['reduction_temps_doublon', '0']);
    $stmt->execute(['nombre_pistes_simultanees', '1']);
    echo "Paramètres par défaut ajoutés dans commandes_settings.\n";

    echo "Migration terminée avec succès !";

} catch (Exception $e) {
    echo "Erreur lors de la migration : " . $e->getMessage();
}
