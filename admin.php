<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax'); // Permet le retour depuis Stripe

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

session_start();
require_once 'config.php'; 

$mot_de_passe_secret = "bluebeetn2026";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['password']) && $_POST['password'] === $mot_de_passe_secret) {
    session_regenerate_id(true); // Protection contre la fixation de session
    $_SESSION['admin_logged_in'] = true;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (!isset($_SESSION['admin_logged_in'])) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>BlueBeeTN | Accès Pro</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">';
    echo '<style>
        body { background: #fdfbf7; font-family: "Tajawal", sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; color: #003a6c; }
        .login-box { background: white; padding: 50px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.1); border-top: 10px solid #c1272d; width: 100%; max-width: 400px; text-align: center; }
        h2 { font-family: "Tajawal", sans-serif; font-size: 2rem; margin-bottom: 30px; font-weight: 800; }
        input { padding: 15px; width: 100%; border: 2px solid #e0f2fe; border-radius: 10px; font-size: 1rem; outline: none; margin-bottom: 20px; box-sizing: border-box; }
        button { padding: 15px; background: #0066b2; color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 1.1rem; width: 100%; transition: 0.3s; }
        button:hover { background: #003a6c; transform: translateY(-3px); }
    </style></head><body>';
    echo '<div class="login-box"><h2>Zone Pro</h2><form method="post"><input type="password" name="password" placeholder="Mot de passe" required><button type="submit">Ouvrir le Panneau</button></form></div></body></html>';
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $noms_jours = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi'];
} catch (PDOException $e) {
    die("Le service est temporairement indisponible.");
}

// Logique de Statistiques pour le Dashboard
$total_plats_actifs = $pdo->query("SELECT COUNT(*) FROM carte_restaurant WHERE est_disponible = 1")->fetchColumn();
$total_plats_rupture = $pdo->query("SELECT COUNT(*) FROM carte_restaurant WHERE est_disponible = 0")->fetchColumn();
$votes_aujourdhui = $pdo->query("SELECT COUNT(*) FROM votes_menu WHERE vote_date = CURDATE()")->fetchColumn();

// Calcul du prochain créneau/status
$stmtSet = $pdo->query("SELECT s_key, s_value FROM commandes_settings");
$settings_db = [];
foreach ($stmtSet->fetchAll(PDO::FETCH_ASSOC) as $s) { $settings_db[$s['s_key']] = $s['s_value']; }

$shop_active = (bool)($settings_db['is_active'] ?? true);
$closed_days = json_decode($settings_db['closed_days'] ?? '[]', true);
$nom_jour_fr = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][date('w')];
if (in_array($nom_jour_fr, $closed_days)) { $shop_active = false; }


$plat_a_modifier = null;
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM carte_restaurant WHERE id = ?");
    $stmt->execute([$_GET['modifier']]);
    $plat_a_modifier = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_POST['enregistrer']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $nom = $_POST['nom'];
    $desc = $_POST['description'];
    $prix = $_POST['prix'];
    $cat = $_POST['categorie'];
    
    $img = 'default.jpg';
    
    if (!empty($_POST['id_edit'])) {
        $stmtImg = $pdo->prepare("SELECT image_url FROM carte_restaurant WHERE id = ?");
        $stmtImg->execute([$_POST['id_edit']]);
        $currentImg = $stmtImg->fetchColumn();
        if ($currentImg) {
            $img = $currentImg;
        }
    }

    if (isset($_FILES['image_upload']) && $_FILES['image_upload']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['image_upload']['tmp_name'];
        $check = getimagesize($file_tmp);
        
        if ($check !== false) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            if (in_array($check['mime'], $allowed_types)) {
                $nomFichierOriginal = basename($_FILES['image_upload']['name']);
                $nomFichierSecurise = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $nomFichierOriginal);
                $nomFichierFinal = time() . '_' . $nomFichierSecurise;
                $cheminDestination = 'images/' . $nomFichierFinal;
                
                if (move_uploaded_file($file_tmp, $cheminDestination)) {
                    $img = $nomFichierFinal;
                }
            }
        }
    }

    $temps_prep = $_POST['temps_prep_min'] ?? 5;
    $type_stock = $_POST['type_stock'] ?? 'infini';
    $stock_actuel = $_POST['stock_actuel'] ?? 0;

    if (!empty($_POST['id_edit'])) {
        $stmt = $pdo->prepare("UPDATE carte_restaurant SET nom=?, description=?, prix=?, categorie=?, image_url=?, temps_prep_min=?, type_stock=?, stock_actuel=? WHERE id=?");
        $stmt->execute([$nom, $desc, $prix, $cat, $img, $temps_prep, $type_stock, $stock_actuel, $_POST['id_edit']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO carte_restaurant (nom, description, prix, categorie, image_url, temps_prep_min, type_stock, stock_actuel) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nom, $desc, $prix, $cat, $img, $temps_prep, $type_stock, $stock_actuel]);
    }
    header("Location: admin.php");
    exit;
}

if (isset($_POST['supprimer']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $id = $_POST['plat_id'];
    
    // Nettoyage de l'image sur le serveur
    $stmtImg = $pdo->prepare("SELECT image_url FROM carte_restaurant WHERE id = ?");
    $stmtImg->execute([$id]);
    $oldImg = $stmtImg->fetchColumn();
    if ($oldImg && $oldImg !== 'default.jpg' && file_exists('images/' . $oldImg)) {
        unlink('images/' . $oldImg);
    }

    $stmt = $pdo->prepare("DELETE FROM carte_restaurant WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: admin.php");
    exit;
}
if (isset($_POST['reset_votes']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $pdo->query("DELETE FROM votes_menu");
    header("Location: admin.php?success=reset#tab-menu");
    exit;
}

if (isset($_POST['sauvegarder_menu']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $pdo->beginTransaction();
    try {
        $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi'];
        foreach ($jours as $j) {
            $id_plat = $_POST["pdj_$j"] ?? 0;
            if ($id_plat) {
                $stmt = $pdo->prepare("INSERT INTO plat_du_jour (jour, id_plat) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_plat=?");
                $stmt->execute([$j, $id_plat, $id_plat]);
            }
        }
        $pdo->exec("DELETE FROM options_vote");
        if (isset($_POST['vote_options']) && is_array($_POST['vote_options'])) {
            $stmt = $pdo->prepare("INSERT INTO options_vote (id_plat) VALUES (?)");
            foreach ($_POST['vote_options'] as $id_p) {
                $stmt->execute([(int)$id_p]);
            }
        }
        $pdo->commit();
        header("Location: admin.php?success=menu#tab-menu");
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Erreur lors de la sauvegarde : " . $e->getMessage());
    }
    exit;
}

if (isset($_POST['trancher_vote']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $v_date = $_POST['vote_date'];
    $p_idx = (int)$_POST['plat_index'];
    $stmt = $pdo->prepare("INSERT INTO decisions_vote (vote_date, plat_index) VALUES (?, ?) ON DUPLICATE KEY UPDATE plat_index=?");
    $stmt->execute([$v_date, $p_idx, $p_idx]);
    header("Location: admin.php?success=tranchage#tab-menu");
    exit;
}

if (isset($_POST['toggle_dispo']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $id = (int)$_POST['id'];
    $val = (int)$_POST['statut'];
    $stmt = $pdo->prepare("UPDATE carte_restaurant SET est_disponible = ? WHERE id = ?");
    $stmt->execute([$val, $id]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_POST['sauvegarder_settings']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $keys = ['morning_start', 'morning_end', 'evening_start', 'evening_end', 'slot_duration', 'is_active', 'reduction_temps_doublon', 'nombre_pistes_simultanees'];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $stmt = $pdo->prepare("INSERT INTO commandes_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value=?");
            $stmt->execute([$k, $_POST[$k], $_POST[$k]]);
        }
    }
    $closed = isset($_POST['closed_days']) ? json_encode($_POST['closed_days']) : '[]';
    $stmt = $pdo->prepare("INSERT INTO commandes_settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value=?");
    $stmt->execute(['closed_days', $closed, $closed]);
    
    header("Location: admin.php?success=settings#tab-commandes");
    exit;
}

$settings_raw = $pdo->query("SELECT * FROM commandes_settings")->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['s_key']] = $s['s_value'];
}

$stmtPDJ = $pdo->query("SELECT * FROM plat_du_jour");
$pdj_raw = $stmtPDJ->fetchAll(PDO::FETCH_ASSOC);
$pdj_assignments = [];
foreach ($pdj_raw as $row) {
    $pdj_assignments[$row['jour']] = $row['id_plat'];
}

$stmtVote = $pdo->query("SELECT id_plat FROM options_vote");
$vote_options = $stmtVote->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->query("SELECT * FROM carte_restaurant ORDER BY categorie, nom");
$plats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Détection des conflits de vote
$conflits = [];
$dateJeudi = date('Y-m-d', strtotime('thursday this week'));
$stmtC = $pdo->prepare("SELECT plat_index, COUNT(*) as cnt FROM votes_menu WHERE vote_date = ? GROUP BY plat_index ORDER BY cnt DESC");
$stmtC->execute([$dateJeudi]);
$all_votes = $stmtC->fetchAll(PDO::FETCH_ASSOC);
if (!empty($all_votes)) {
    $max = $all_votes[0]['cnt'];
    $winners = array_filter($all_votes, fn($v) => $v['cnt'] == $max);
    if (count($winners) > 1) {
        $stmtD = $pdo->prepare("SELECT plat_index FROM decisions_vote WHERE vote_date = ?");
        $stmtD->execute([$dateJeudi]);
        if (!$stmtD->fetch()) {
             $conflits = ['date' => $dateJeudi, 'winners' => array_column($winners, 'plat_index'), 'counts' => array_combine(array_column($winners, 'plat_index'), array_column($winners, 'cnt'))];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BlueBeeTN | Dashboard Majestic</title>
    <link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --sidi-blue: #005599;
            --sidi-dark: #003a6c;
            --medina-gold: #d4af37;
            --harissa-red: #d32f2f;
            --chaux-white: #fdfbf7;
            --glass-white: rgba(255, 255, 255, 0.9);
        }

        body { 
            font-family: 'Tajawal', sans-serif; 
            background: var(--chaux-white); 
            margin: 0; 
            padding-top: 90px; 
            color: var(--sidi-dark);
            min-height: 100vh;
            overflow-x: hidden; /* Prévention jitter transitions */
        }

        nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            background: var(--glass-white); backdrop-filter: blur(12px);
            border-bottom: 2px solid var(--medina-gold);
        }
        .nav-container { padding: 0 1.5rem; height: 80px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .nav-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .nav-logo span { font-family: 'Aref Ruqaa', serif; font-size: 2rem; color: var(--sidi-dark); }
        .khamsa-icon { font-size: 1.8rem; color: var(--sidi-blue); width: 42px; height: 42px; background: white; display: flex; align-items: center; justify-content: center; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }

        @media (max-width: 380px) {
            .nav-container { padding: 0 0.8rem; height: 75px; }
            .nav-logo span { font-size: 1.4rem; }
            .khamsa-icon { width: 34px; height: 34px; font-size: 1.3rem; }
            .shop-status-badge { font-size: 0.7rem; padding: 4px 10px; }
        }

        .container { max-width: 1100px; margin: 30px auto; padding: 0 15px; }

        /* Dashboard Stats Widget */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white; border-radius: 20px; padding: 25px;
            display: flex; align-items: center; gap: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
            border-left: 6px solid var(--sidi-blue);
            transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @media (max-width: 480px) {
            .stats-overview { gap: 12px; margin-bottom: 30px; }
            .stat-card { padding: 15px; border-left-width: 4px; border-radius: 16px; }
            .stat-icon { width: 45px; height: 45px; font-size: 1.3rem; border-radius: 10px; }
            .stat-info .stat-val { font-size: 1.5rem; }
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.08); }
        .stat-icon { width: 55px; height: 55px; background: #eef2f7; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: var(--sidi-blue); }
        .stat-info h4 { margin: 0; font-size: 0.9rem; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .stat-info .stat-val { font-size: 1.8rem; font-weight: 800; font-family: 'Tajawal', sans-serif; color: var(--sidi-dark); }

        /* Sections & Transitions */
        .admin-tabs {
            display: flex; gap: 10px; margin-bottom: 30px; background: #eaeff5; padding: 6px; border-radius: 18px;
            position: sticky; top: 100px; z-index: 900; overflow-x: auto; scrollbar-width: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        .admin-tabs::after { content: ""; position: absolute; right: 0; top: 0; bottom: 0; width: 40px; background: linear-gradient(to left, #eaeff5, transparent); pointer-events: none; border-radius: 0 18px 18px 0; }
        .admin-tabs::-webkit-scrollbar { display: none; }
        
        .tab-btn {
            flex: 1; padding: 14px 28px; border: none; background: transparent; font-weight: 700; color: #64748b;
            cursor: pointer; border-radius: 12px; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; white-space: nowrap;
        }
        .tab-btn.active { background: white; color: var(--sidi-blue); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .tab-content { display: none; animation: slideFade 0.5s ease; width: 100%; }
        .tab-content.active { display: block; }
        @keyframes slideFade {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .card { 
            background: white; border-radius: 25px; padding: 30px; 
            box-shadow: 0 15px 45px rgba(0,0,0,0.04); border: 1px solid #f1f5f9;
            margin-bottom: 30px; border-top: 8px solid var(--sidi-blue);
        }
        h3 { 
            font-family: 'Aref Ruqaa', serif; font-size: 2.2rem; color: var(--sidi-dark); 
            margin: 0 0 25px 0; display: flex; align-items: center; gap: 15px;
            flex-wrap: wrap; line-height: 1.2;
        }
        h3 i { color: var(--medina-gold); }
        
        @media (max-width: 600px) {
            .card { padding: 15px; border-radius: 18px; }
            h3 { font-size: 1.5rem; margin-bottom: 15px; gap: 10px; }
            h3 i { font-size: 1.2rem; }
        }
        @media (max-width: 360px) {
            h3 { font-size: 1.3rem; }
        }

        /* Form Controls */
        input[type="text"], input[type="number"], select, input[type="time"], textarea { 
            padding: 15px; border: 2px solid #e2e8f0; border-radius: 14px; font-size: 1rem; width: 100%; box-sizing: border-box; outline: none; transition: 0.3s; 
            font-family: 'Tajawal', sans-serif;
        }
        input:focus { border-color: var(--medina-gold); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); }
        
        .time-row { display: flex; align-items: center; gap: 10px; }
        @media (max-width: 500px) {
            .time-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .time-row span { display: none; }
        }
        
        button.btn-majestic {
            background: var(--sidi-blue); color: white; border: none; padding: 16px 25px; border-radius: 14px; 
            font-weight: 800; font-size: 1.1rem; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 12px;
            box-shadow: 0 8px 20px rgba(0, 85, 153, 0.2);
        }
        button.btn-majestic:active { transform: scale(0.96); box-shadow: 0 4px 10px rgba(0, 85, 153, 0.1); }
        button.btn-majestic:hover { background: var(--sidi-dark); }

        .product-item { 
            background: white; border-radius: 20px; padding: 18px; display: grid; grid-template-columns: 80px 1fr auto; gap: 20px; align-items: center;
            margin-bottom: 15px; border: 1px solid #f1f5f9; transition: 0.3s;
        }
        .product-item:hover { transform: translateX(8px); border-color: var(--medina-gold); box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .thumb { width: 80px; height: 80px; border-radius: 16px; object-fit: cover; border: 2px solid var(--medina-gold); }
        
        .status-badge { font-size: 0.75rem; font-weight: 800; padding: 4px 12px; border-radius: 50px; text-transform: uppercase; display: inline-block; margin-bottom: 6px; }
        .status-on { background: #dcfce7; color: #166534; }
        .status-off { background: #fee2e2; color: #991b1b; }

        @media (max-width: 600px) {
            .stats-overview { grid-template-columns: 1fr; }
            .nav-container { height: 75px; }
            .admin-tabs { top: 75px; margin: 0 -15px 25px -15px; border-radius: 0; padding: 6px 15px; }
            .product-item { grid-template-columns: 75px 1fr; padding: 15px; border-radius: 18px; row-gap: 15px; column-gap: 15px; }
            .thumb { width: 75px; height: 75px; }
            .product-item .actions { grid-column: 1 / -1; border-top: 1px solid #f1f5f9; padding-top: 15px; justify-content: space-between; gap: 12px; }
            .btn-majestic { flex: 1; justify-content: center; height: 50px; font-size: 0.95rem; }
            .switch { transform: scale(1.15); margin-right: 15px; }
        }
        
        .success-toast {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: white; color: #166534; padding: 18px 30px;
            border-radius: 18px; border-left: 8px solid #22c55e; box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            z-index: 2000; display: flex; align-items: center; gap: 15px; font-weight: 800; animation: bounceInUp 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        @keyframes bounceInUp { from { transform: translate(-50%, 100px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; inset: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #22c55e; }
        input:checked + .slider:before { transform: translateX(20px); }
    </style>
</head>
<body>

<nav>
    <div class="nav-container">
        <a href="index.php" class="nav-logo">
            <div class="khamsa-icon"><i class="fa-solid fa-hamsa"></i></div>
            <span>BlueBeeTN Admin</span>
        </a>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="shop-status-badge" style="display: flex; align-items: center; gap: 8px; background: <?= $shop_active ? '#dcfce7' : '#fee2e2' ?>; padding: 6px 15px; border-radius: 50px; font-weight: 800; font-size: 0.8rem; color: <?= $shop_active ? '#166534' : '#991b1b' ?>; white-space: nowrap;">
                <i class="fa-solid <?= $shop_active ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i> <?= $shop_active ? 'EN LIGNE' : 'FERMÉE' ?>
            </div>
            <a href="?logout=1" style="color: var(--harissa-red); text-decoration: none; font-weight: 800; font-size: 1.1rem; padding: 10px;"><i class="fa-solid fa-power-off"></i></a>
        </div>
    </div>
</nav>

<div class="container">
    
    <!-- DASHBOARD OVERVIEW -->
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0f2fe; color: #0369a1;"><i class="fa-solid fa-utensils"></i></div>
            <div class="stat-info">
                <h4>Carte active</h4>
                <div class="stat-val"><?= $total_plats_actifs ?> <span style="font-size: 0.9rem; color: #94a3b8; font-weight: 400;">/ <?= $total_plats_actifs + $total_plats_rupture ?></span></div>
            </div>
        </div>
        <div class="stat-card" style="border-left-color: var(--medina-gold);">
            <div class="stat-icon" style="background: #fef3c7; color: #b45309;"><i class="fa-solid fa-heart"></i></div>
            <div class="stat-info">
                <h4>Votes du jour</h4>
                <div class="stat-val"><?= $votes_aujourdhui ?></div>
            </div>
        </div>
        <div class="stat-card" style="border-left-color: <?= $shop_active ? '#10b981' : '#f43f5e' ?>;">
            <div class="stat-icon" style="background: <?= $shop_active ? '#ecfdf5' : '#fff1f2' ?>; color: <?= $shop_active ? '#059669' : '#e11d48' ?>;"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-info">
                <h4>Statut Commandes</h4>
                <div class="stat-val" style="font-size: 1.2rem;"><?= $shop_active ? 'OUVERT' : 'FERMÉ' ?></div>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="success-toast" id="toast">
            <i class="fa-solid fa-circle-check" style="font-size: 1.8rem;"></i>
            <span>Action effectuée avec succès !</span>
        </div>
        <script>setTimeout(() => document.getElementById('toast').style.opacity='0', 3000);</script>
    <?php endif; ?>

    <div class="admin-tabs">
        <button class="tab-btn active" onclick="switchTab('tab-carte')"><i class="fa-solid fa-tags"></i> La Carte</button>
        <button class="tab-btn" onclick="switchTab('tab-menu')"><i class="fa-solid fa-calendar-star"></i> Menu & Votes</button>
        <button class="tab-btn" onclick="switchTab('tab-commandes')"><i class="fa-solid fa-gears"></i> Réglages</button>
    </div>

    <!-- ONGLET 1 : LA CARTE -->
    <section id="tab-carte" class="tab-content active">
        <div class="card">
            <h3><i class="fa-solid fa-circle-plus"></i> <?= $plat_a_modifier ? 'Modifier ce plat' : 'Nouveau Chef-d\'œuvre' ?></h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id_edit" value="<?= $plat_a_modifier['id'] ?? '' ?>">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                    <input type="text" name="nom" placeholder="Nom du délice" value="<?= htmlspecialchars($plat_a_modifier['nom'] ?? '') ?>" required>
                    <input type="number" step="0.01" name="prix" placeholder="Prix (€)" value="<?= $plat_a_modifier['prix'] ?? '' ?>" required>
                    <select name="categorie">
                        <?php foreach(["Entrées", "Plats Tunisiens", "Sandwiches Tunisiens", "Boissons"] as $c): ?>
                            <option value="<?= $c ?>" <?= ($plat_a_modifier['categorie'] ?? '') === $c ? "selected" : "" ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="temps_prep_min" placeholder="Temps prép (min)" value="<?= $plat_a_modifier['temps_prep_min'] ?? '5' ?>" required title="Temps de préparation en minutes">
                    <select name="type_stock" onchange="toggleStockInput(this.value)">
                        <option value="infini" <?= ($plat_a_modifier['type_stock'] ?? 'infini') === 'infini' ? 'selected' : '' ?>>Stock Infini</option>
                        <option value="reel" <?= ($plat_a_modifier['type_stock'] ?? '') === 'reel' ? 'selected' : '' ?>>Stock Réel (Épuisable)</option>
                    </select>
                    <div id="stock_input_container" style="<?= ($plat_a_modifier['type_stock'] ?? 'infini') === 'reel' ? '' : 'display:none;' ?>">
                        <input type="number" name="stock_actuel" placeholder="Quantité dispo" value="<?= $plat_a_modifier['stock_actuel'] ?? '0' ?>">
                    </div>
                    <input type="file" name="image_upload" accept="image/*">
                </div>
                <textarea name="description" placeholder="Une description qui donne l'eau à la bouche..." style="margin-top: 20px; height: 100px;"><?= htmlspecialchars($plat_a_modifier['description'] ?? '') ?></textarea>
                <button type="submit" name="enregistrer" class="btn-majestic" style="width: 100%; margin-top: 25px;"><i class="fa-solid fa-cloud-arrow-up"></i> Sauvegarder dans la Gazette</button>
            </form>
        </div>

        <h3 style="margin: 40px 0 20px;"><i class="fa-solid fa-list-check"></i> Votre Collection Culinaire</h3>
        <?php foreach ($plats as $plat): ?>
            <div class="product-item" id="product-<?= $plat['id'] ?>" style="<?= $plat['est_disponible'] ? '' : 'opacity: 0.7;' ?>">
                <img src="images/<?= htmlspecialchars($plat['image_url']) ?>" class="thumb" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?w=100'">
                <div class="info">
                    <span class="status-badge <?= $plat['est_disponible'] ? 'status-on' : 'status-off' ?>" id="badge-<?= $plat['id'] ?>">
                        <?= $plat['est_disponible'] ? 'Prêt à servir' : 'En rupture' ?>
                    </span><br>
                    <strong style="font-size: 1.15rem;"><?= htmlspecialchars($plat['nom']) ?></strong><br>
                    <span style="color: var(--sidi-blue); font-weight: 800;"><?= number_format($plat['prix'], 2, ',', ' ') ?> €</span>
                </div>
                <div class="actions" style="display: flex; gap: 12px;">
                    <label class="switch">
                        <input type="checkbox" <?= $plat['est_disponible'] ? 'checked' : '' ?> onchange="toggleDispo(<?= $plat['id'] ?>, this.checked)">
                        <span class="slider"></span>
                    </label>
                    <a href="?modifier=<?= $plat['id'] ?>#tab-carte" class="btn-edit btn-majestic" style="padding: 10px 15px; background: var(--medina-gold); box-shadow: none;"><i class="fa-solid fa-pen-to-square"></i></a>
                    <form method="post" onsubmit="return confirm('Supprimer définitivement ce plat ?');" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="plat_id" value="<?= $plat['id'] ?>">
                        <button type="submit" name="supprimer" class="btn-del btn-majestic" style="padding: 10px 15px; background: #fff1f2; color: var(--harissa-red); border: 1px solid var(--harissa-red); box-shadow: none;"><i class="fa-solid fa-trash-can"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- ONGLET 2 : MENU & VOTES -->
    <section id="tab-menu" class="tab-content">
        <?php if (!empty($conflits)): ?>
            <div class="card" style="border-top-color: var(--harissa-red); background: #fff1f2;">
                <h3 style="color: var(--harissa-red);"><i class="fa-solid fa-scale-balanced"></i> Arbitrage nécessaire</h3>
                <p>Égalité parfaite pour le vote du <strong><?= date('d/m', strtotime($conflits['date'])) ?></strong>.</p>
                <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                    <?php foreach ($conflits['winners'] as $idx): 
                        $stmtN = $pdo->prepare("SELECT nom FROM carte_restaurant WHERE id = ?");
                        $stmtN->execute([$idx]); $name = $stmtN->fetchColumn();
                    ?>
                        <form method="post" style="flex:1;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="vote_date" value="<?= $conflits['date'] ?>">
                            <input type="hidden" name="plat_index" value="<?= $idx ?>">
                            <button type="submit" name="trancher_vote" class="btn-majestic" style="width:100%; font-size: 0.9rem; background: var(--harissa-red);">Choisir <?= htmlspecialchars($name) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3><i class="fa-solid fa-calendar-check"></i> Planning Hebdomadaire</h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    <?php foreach (['Lundi', 'Mardi', 'Mercredi', 'Jeudi'] as $j): ?>
                        <div class="day-block" style="padding: 20px;">
                            <h4 style="color: var(--sidi-blue); margin: 0 0 10px 0;"><?= $j ?></h4>
                            <select name="pdj_<?= $j ?>">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($plats as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($pdj_assignments[$j] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="day-block" style="background: #f8fafc; border: 2px dashed var(--medina-gold); padding: 25px;">
                    <h4 style="margin-bottom: 15px;"><i class="fa-solid fa-box-archive"></i> Options pour le Vote du Week-end</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; max-height: 300px; overflow-y: auto; background: white; padding: 15px; border-radius: 12px;">
                        <?php foreach ($plats as $p): ?>
                            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 8px; border-bottom: 1px solid #f1f5f9;">
                                <input type="checkbox" name="vote_options[]" value="<?= $p['id'] ?>" <?= in_array($p['id'], $vote_options) ? 'checked' : '' ?>>
                                <span style="font-size: 0.95rem; font-weight: 500;"><?= htmlspecialchars($p['nom']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" name="sauvegarder_menu" class="btn-majestic" style="width: 100%; margin-top: 30px;"><i class="fa-solid fa-floppy-disk"></i> Publier le Planning</button>
            </form>
        </div>
    </section>

    <!-- ONGLET 3 : REGLAGES -->
    <section id="tab-commandes" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-sliders"></i> Configuration des Commandes</h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-bottom: 30px;">
                    <div class="day-block">
                        <h4 style="margin-bottom: 15px; border-bottom: 2px solid var(--sidi-blue); display: inline-block;">Cycles Horaires</h4>
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; font-size: 0.8rem; font-weight: 800; margin-bottom: 5px;">SERVICE MATIN</label>
                            <div class="time-row">
                                <input type="time" name="morning_start" value="<?= $settings['morning_start'] ?? '11:00' ?>"> <span>à</span> <input type="time" name="morning_end" value="<?= $settings['morning_end'] ?? '14:00' ?>">
                            </div>
                        </div>
                        <div>
                            <label style="display:block; font-size: 0.8rem; font-weight: 800; margin-bottom: 5px;">SERVICE SOIR</label>
                            <div class="time-row">
                                <input type="time" name="evening_start" value="<?= $settings['evening_start'] ?? '18:00' ?>"> <span>à</span> <input type="time" name="evening_end" value="<?= $settings['evening_end'] ?? '23:00' ?>">
                            </div>
                        </div>
                    </div>

                    <div class="day-block">
                        <h4 style="margin-bottom: 15px; border-bottom: 2px solid var(--sidi-blue); display: inline-block;">Capacité & Production</h4>
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; font-size: 0.8rem; font-weight: 800; margin-bottom: 5px;">INTERVALLE DES CRÉNEAUX (min)</label>
                            <input type="number" name="slot_duration" value="<?= $settings['slot_duration'] ?? 30 ?>" title="Temps entre chaque créneau (ex: 15, 30, 45...)">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; font-size: 0.8rem; font-weight: 800; margin-bottom: 5px;">RÉDUCTION DOUBLONS (min)</label>
                            <input type="number" name="reduction_temps_doublon" value="<?= $settings['reduction_temps_doublon'] ?? 0 ?>" title="Gain de temps pour chaque plat identique supplémentaire">
                        </div>
                        <div>
                            <label style="display:block; font-size: 0.8rem; font-weight: 800; margin-bottom: 5px;">NOMBRE DE PISTES (Cuisiniers)</label>
                            <input type="number" name="nombre_pistes_simultanees" value="<?= $settings['nombre_pistes_simultanees'] ?? 1 ?>" title="Nombre de commandes pouvant être lancées en même temps">
                        </div>
                    </div>

                    <div class="day-block">
                        <h4 style="margin-bottom: 15px; border-bottom: 2px solid var(--harissa-red); display: inline-block;">Jours de fermeture</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <?php 
                            $cl = json_decode($settings['closed_days'] ?? '[]', true);
                            foreach(['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'] as $j): ?>
                                <label style="display:flex; align-items:center; gap:8px; font-size: 0.9rem; cursor: pointer;">
                                    <input type="checkbox" name="closed_days[]" value="<?= $j ?>" <?= in_array($j, $cl) ? 'checked' : '' ?>> <?= $j ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div style="background: white; border: 2px solid #e2e8f0; padding: 25px; border-radius: 20px; display: flex; align-items: center; justify-content: space-between; margin-top: 40px; box-shadow: 0 5px 15px rgba(0,0,0,0.03);">
                    <div>
                        <strong style="font-size: 1.2rem; display: block;">Statut de la Boutique</strong>
                        <span style="color: #64748b; font-size: 0.9rem;">Désactivez pour bloquer toutes les nouvelles commandes instantanément.</span>
                    </div>
                    <select name="is_active" style="width: auto; padding: 12px 30px; border-radius: 50px; font-weight: 800; background: <?= $shop_active ? '#dcfce7' : '#fee2e2' ?>; border: none;">
                        <option value="1" <?= ($settings['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>BOUTIQUE OUVERTE</option>
                        <option value="0" <?= ($settings['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>BOUTIQUE FERMÉE</option>
                    </select>
                </div>
                
                <button type="submit" name="sauvegarder_settings" class="btn-majestic" style="width: 100%; margin-top: 30px; height: 70px; font-size: 1.3rem;"><i class="fa-solid fa-check-double"></i> Appliquer les Réglages Généraux</button>
            </form>
        </div>
    </section>
</div>

<script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        
        const target = document.getElementById(tabId);
        target.classList.add('active');
        
        const activeBtn = document.querySelector(`[onclick="switchTab('${tabId}')"]`);
        if(activeBtn) activeBtn.classList.add('active');
        
        window.location.hash = tabId;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    window.addEventListener('load', () => {
        const hash = window.location.hash.substring(1);
        if (hash && document.getElementById(hash)) switchTab(hash);
    });

    function toggleDispo(id, isChecked) {
        const formData = new FormData();
        formData.append('toggle_dispo', '1');
        formData.append('id', id);
        formData.append('statut', isChecked ? '1' : '0');
        formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

        const badge = document.getElementById('badge-' + id);
        const card = document.getElementById('product-' + id);

        fetch('admin.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                badge.innerText = isChecked ? 'Prêt à servir' : 'En rupture';
                badge.className = isChecked ? 'status-badge status-on' : 'status-badge status-off';
                card.style.opacity = isChecked ? '1' : '0.7';
            }
        });
    }

    function toggleStockInput(val) {
        document.getElementById('stock_input_container').style.display = (val === 'reel' ? 'block' : 'none');
    }
</script>

</body>
</html>