<?php
session_start();
require_once 'config.php'; 

$mot_de_passe_secret = "bluebeetn2026";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['password']) && $_POST['password'] === $mot_de_passe_secret) {
    $_SESSION['admin_logged_in'] = true;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (!isset($_SESSION['admin_logged_in'])) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>BlueBeeTN | Accès Pro</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@700&family=Tajawal:wght@400;700&display=swap" rel="stylesheet">';
    echo '<style>
        body { background: #fdfbf7; font-family: "Tajawal", sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; color: #003a6c; }
        .login-box { background: white; padding: 50px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.1); border-top: 10px solid #c1272d; width: 100%; max-width: 400px; text-align: center; }
        h2 { font-family: "Aref Ruqaa", serif; font-size: 2.5rem; margin-bottom: 30px; }
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
        $nomFichierOriginal = basename($_FILES['image_upload']['name']);
        $nomFichierSecurise = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $nomFichierOriginal);
        $nomFichierFinal = time() . '_' . $nomFichierSecurise;
        $cheminDestination = 'images/' . $nomFichierFinal;
        
        if (move_uploaded_file($_FILES['image_upload']['tmp_name'], $cheminDestination)) {
            $img = $nomFichierFinal;
        }
    }

    if (!empty($_POST['id_edit'])) {
        $stmt = $pdo->prepare("UPDATE carte_restaurant SET nom=?, description=?, prix=?, categorie=?, image_url=? WHERE id=?");
        $stmt->execute([$nom, $desc, $prix, $cat, $img, $_POST['id_edit']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO carte_restaurant (nom, description, prix, categorie, image_url) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nom, $desc, $prix, $cat, $img]);
    }
    header("Location: admin.php");
    exit;
}

if (isset($_POST['supprimer']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $id = $_POST['plat_id'];
    $stmt = $pdo->prepare("DELETE FROM carte_restaurant WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: admin.php");
    exit;
}

if (isset($_POST['sauvegarder_menu']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Weekend'];
    foreach ($jours as $j) {
        $p1 = $_POST["plat1_$j"] ?? '';
        $p2 = $_POST["plat2_$j"] ?? '';
        $p3 = $_POST["plat3_$j"] ?? '';
        $stmt = $pdo->prepare("INSERT INTO menu_du_jour (jour, plat1, plat2, plat3) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE plat1=?, plat2=?, plat3=?");
        $stmt->execute([$j, $p1, $p2, $p3, $p1, $p2, $p3]);
    }
    header("Location: admin.php?success=menu");
    exit;
}

if (isset($_POST['trancher_vote']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $v_date = $_POST['vote_date'];
    $p_idx = (int)$_POST['plat_index'];
    $stmt = $pdo->prepare("INSERT INTO decisions_vote (vote_date, plat_index) VALUES (?, ?) ON DUPLICATE KEY UPDATE plat_index=?");
    $stmt->execute([$v_date, $p_idx, $p_idx]);
    header("Location: admin.php?success=tranchage");
    exit;
}

// Détection des égalités (Dates des 7 derniers jours)
$conflits = [];
$stmtC = $pdo->query("SELECT vote_date, plat_index, COUNT(*) as vc FROM votes_menu WHERE vote_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY vote_date, plat_index");
$all_votes = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$grouped_votes = [];
foreach ($all_votes as $v) {
    if (!isset($grouped_votes[$v['vote_date']])) $grouped_votes[$v['vote_date']] = [];
    $grouped_votes[$v['vote_date']][$v['plat_index']] = (int)$v['vc'];
}

foreach ($grouped_votes as $date => $counts) {
    if (empty($counts)) continue;
    $max = max($counts);
    $winners = array_keys($counts, $max);
    
    if (count($winners) > 1) {
        // Vérifier si déjà tranché
        $stmtD = $pdo->prepare("SELECT plat_index FROM decisions_vote WHERE vote_date = ?");
        $stmtD->execute([$date]);
        $decision = $stmtD->fetchColumn();
        
        // Si une décision a déjà été prise, on ne considère plus cela comme un conflit actif
        if ($decision) continue;
        
        $day_num = (int)date('N', strtotime($date));
        $day_name = $noms_jours[$day_num] ?? 'Weekend';

        $conflits[] = [
            'date' => $date,
            'winners' => $winners,
            'decision' => $decision,
            'day_name' => $day_name,
            'counts' => $counts
        ];
    }
}

$stmtMenu = $pdo->query("SELECT * FROM menu_du_jour");
$menus_brut = $stmtMenu->fetchAll(PDO::FETCH_ASSOC);
$menus = [];
foreach ($menus_brut as $m) {
    $menus[$m['jour']] = $m;
}

$stmt = $pdo->query("SELECT * FROM carte_restaurant ORDER BY categorie, nom");
$plats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BlueBeeTN | Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidi-blue: #0066b2;
            --sidi-dark: #003a6c;
            --medina-gold: #d4af37;
            --harissa-red: #c1272d;
            --chaux-white: #fdfbf7;
        }

        body { font-family: 'Tajawal', sans-serif; background: var(--chaux-white); margin: 0; padding-top: 100px; }

        .ceramic-border {
            height: 12px; width: 100%;
            background: repeating-linear-gradient(90deg, var(--sidi-blue), var(--sidi-blue) 20px, var(--medina-gold) 20px, var(--medina-gold) 25px, var(--chaux-white) 25px, var(--chaux-white) 45px, var(--medina-gold) 45px, var(--medina-gold) 50px);
            border-bottom: 2px solid var(--sidi-dark);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            background: rgba(253, 251, 247, 0.96); backdrop-filter: blur(10px);
        }
        .nav-container { padding: 0 2rem; height: 80px; display: flex; align-items: center; justify-content: space-between; }
        .nav-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .nav-logo span { font-family: 'Aref Ruqaa', serif; font-size: 2.2rem; font-weight: 700; color: var(--sidi-dark); text-shadow: 1px 1px 0px rgba(212, 175, 55, 0.5); }
        .khamsa-icon { font-size: 2.2rem; color: var(--sidi-blue); }

        .container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-top: 6px solid var(--sidi-blue); margin-bottom: 30px; }
        h3 { font-family: 'Aref Ruqaa', serif; font-size: 2rem; color: var(--sidi-dark); margin: 0 0 20px 0; }
        .form-grid { display: grid; grid-template-columns: 1fr; gap: 15px; }
        @media (min-width: 768px) { .form-grid { grid-template-columns: 1fr 1fr; } }
        input[type="text"], input[type="number"], select { padding: 12px; border: 2px solid #e0f2fe; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; outline: none; }
        input[type="file"] { padding: 10px; border: 2px dashed #e0f2fe; border-radius: 8px; width: 100%; box-sizing: border-box; background: #f8fafc; }
        .btn-add { background: var(--sidi-blue); color: white; border: none; padding: 15px; border-radius: 8px; font-weight: bold; font-size: 1.1rem; cursor: pointer; width: 100%; margin-top: 10px; transition: 0.3s; }
        .btn-add:hover { background: var(--sidi-dark); }
        .product-item { background: white; border-radius: 12px; padding: 15px; display: flex; align-items: center; gap: 15px; margin-bottom: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); border: 1px solid #eee; }
        .thumb { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--medina-gold); }
        .info { flex: 1; }
        .actions { display: flex; gap: 8px; }
        .btn-edit { background: var(--medina-gold); color: white; padding: 8px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: bold; }
        .btn-del { background: var(--harissa-red); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.8rem; }
        
        .menu-config-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        @media (min-width: 992px) { .menu_config_grid { grid-template-columns: repeat(2, 1fr); } }
        .day-block { background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; }
        .day-block h4 { margin: 0 0 10px 0; color: var(--sidi-blue); border-bottom: 2px solid var(--medina-gold); display: inline-block; padding-bottom: 2px; }
        .day-block .inputs { display: flex; flex-direction: column; gap: 8px; }
        .day-block input { background: white; }
        .weekend-block { border-color: var(--harissa-red); background: #fff5f5; }
        .weekend-block h4 { color: var(--harissa-red); border-bottom-color: var(--harissa-red); }

        .conflit-card { border-top-color: var(--harissa-red); animation: pulse-border 2s infinite; }
        @keyframes pulse-border { 0% { border-top-color: var(--harissa-red); } 50% { border-top-color: #ff8e91; } 100% { border-top-color: var(--harissa-red); } }
        .conflit-item { background: #fff5f5; padding: 15px; border-radius: 12px; margin-top: 15px; border: 1px solid #fee2e2; }
        .conflit-options { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .btn-tranch { background: white; border: 2px solid var(--harissa-red); color: var(--harissa-red); padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.3s; }
        .btn-tranch.active { background: var(--harissa-red); color: white; }
        .btn-tranch:hover:not(.active) { background: #fee2e2; }
    </style>
</head>
<body>

<nav>
    <div class="ceramic-border"></div>
    <div class="nav-container">
        <a href="index.php" class="nav-logo">
            <span class="khamsa-icon">🪬</span>
            <span>BlueBeeTN Admin</span>
        </a>
        <div style="display: flex; gap: 20px; align-items: center;">
            <a href="admin.php" style="text-decoration: none; color: var(--sidi-dark); font-weight: bold;">Plats</a>
            <a href="?logout=1" style="color: var(--harissa-red); text-decoration: none; font-weight: bold;">Quitter ✖</a>
        </div>
    </div>
</nav>

<div class="container">
    <?php if (!empty($conflits)): ?>
        <div class="card conflit-card">
            <h3 style="color: var(--harissa-red);">⚠️ Égalités à Trancher</h3>
            <p style="color: #666; font-size: 0.9rem;">Plusieurs plats ont obtenu le même nombre de votes. Choisissez le gagnant pour que le bon plat s'affiche sur le site.</p>
            
            <?php foreach ($conflits as $c): ?>
                <div class="conflit-item">
                    <strong>Vote du <?= date('d/m', strtotime($c['date'])) ?> (Menu <?= $c['day_name'] ?>)</strong>
                    <div class="conflit-options">
                        <?php foreach ($c['winners'] as $idx): 
                            $name = $menus[$c['day_name']]["plat$idx"] ?? "Plat $idx";
                        ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="vote_date" value="<?= $c['date'] ?>">
                                <input type="hidden" name="plat_index" value="<?= $idx ?>">
                                <button type="submit" name="trancher_vote" class="btn-tranch <?= ($c['decision'] == $idx) ? 'active' : '' ?>">
                                    <?= htmlspecialchars($name) ?> (<?= $c['counts'][$idx] ?> voix)
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="card" id="form-container">
        <h3><?= $plat_a_modifier ? "Modifier le Trésor" : "Nouveau Plat" ?></h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="id_edit" value="<?= $plat_a_modifier['id'] ?? '' ?>">
            <div class="form-grid">
                <input type="text" name="nom" placeholder="Nom du plat" value="<?= htmlspecialchars($plat_a_modifier['nom'] ?? '') ?>" required>
                <input type="text" name="description" placeholder="Description" value="<?= htmlspecialchars($plat_a_modifier['description'] ?? '') ?>">
                <input type="number" step="0.01" name="prix" placeholder="Prix (€)" value="<?= $plat_a_modifier['prix'] ?? '' ?>" required>
                <select name="categorie">
                    <?php foreach(["Entrées", "Plats Tunisiens", "Sandwiches Tunisiens", "Boissons"] as $c): ?>
                        <option value="<?= $c ?>" <?= ($plat_a_modifier['categorie'] ?? '') === $c ? "selected" : "" ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="grid-column: 1 / -1;">
                    <?php if($plat_a_modifier && $plat_a_modifier['image_url']): ?>
                        <p style="margin: 0 0 10px 0; font-size: 0.9rem; color: var(--sidi-dark);">Image actuelle : <strong><?= htmlspecialchars($plat_a_modifier['image_url']) ?></strong></p>
                    <?php endif; ?>
                    <input type="file" name="image_upload" accept="image/*">
                </div>
            </div>
            <button type="submit" name="enregistrer" class="btn-add"><?= $plat_a_modifier ? "Sauvegarder" : "Mettre en ligne" ?></button>
            <?php if($plat_a_modifier): ?><div style="text-align:center; margin-top:10px;"><a href="admin.php" style="color:#888; text-decoration:none;">Annuler</a></div><?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Menu Interactif & Vote</h3>
        <p style="margin-bottom: 20px; color: #666; font-size: 0.95rem;">Configurez les plats du jour pour le système de vote (Lun-Jeu) et l'affichage du week-end (Ven-Dim).</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="menu-config-grid">
                <?php 
                $jours_affichage = [
                    'Lundi' => 'Lundi', 
                    'Mardi' => 'Mardi', 
                    'Mercredi' => 'Mercredi', 
                    'Jeudi' => 'Jeudi'
                ];
                foreach ($jours_affichage as $code => $label): ?>
                    <div class="day-block">
                        <h4><?= $label ?></h4>
                        <div class="inputs">
                            <input type="text" name="plat1_<?= $code ?>" placeholder="Plat Option 1" value="<?= htmlspecialchars($menus[$code]['plat1'] ?? '') ?>">
                            <input type="text" name="plat2_<?= $code ?>" placeholder="Plat Option 2" value="<?= htmlspecialchars($menus[$code]['plat1'] ?? '') ?>">
                            <input type="text" name="plat3_<?= $code ?>" placeholder="Plat Option 3" value="<?= htmlspecialchars($menus[$code]['plat1'] ?? '') ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="day-block weekend-block">
                    <h4>Week-end (Ven-Dim)</h4>
                    <div class="inputs">
                        <input type="text" name="plat1_Weekend" placeholder="Plat Week-end 1" value="<?= htmlspecialchars($menus['Weekend']['plat1'] ?? '') ?>">
                        <input type="text" name="plat2_Weekend" placeholder="Plat Week-end 2" value="<?= htmlspecialchars($menus['Weekend']['plat1'] ?? '') ?>">
                        <input type="text" name="plat3_Weekend" placeholder="Plat Week-end 3" value="<?= htmlspecialchars($menus['Weekend']['plat1'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <button type="submit" name="sauvegarder_menu" class="btn-add" style="background: var(--sidi-dark);">Enregistrer le Menu Hebdomadaire</button>
        </form>
    </div>

    <h3>La Carte</h3>
    <?php foreach ($plats as $plat): ?>
        <div class="product-item">
            <img src="images/<?= htmlspecialchars($plat['image_url']) ?>" class="thumb" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?w=100'">
            <div class="info">
                <strong><?= htmlspecialchars($plat['nom']) ?></strong><br>
                <span style="color:var(--harissa-red); font-weight:bold;"><?= number_format($plat['prix'], 2, ',', ' ') ?> €</span>
            </div>
            <div class="actions">
                <a href="?modifier=<?= $plat['id'] ?>#form-container" class="btn-edit">Modifier</a>
                <form method="post" onsubmit="return confirm('Supprimer ce plat ?');">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="plat_id" value="<?= $plat['id'] ?>">
                    <button type="submit" name="supprimer" class="btn-del">Supprimer</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>