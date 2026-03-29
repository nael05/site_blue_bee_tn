<?php
session_start();
require_once 'config.php'; 

$mot_de_passe_secret = "bluebeetn2026";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['password']) && $_POST['password'] === $mot_de_passe_secret) {
    $_SESSION['cuisine_logged_in'] = true;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: cuisine.php");
    exit;
}

if (!isset($_SESSION['cuisine_logged_in'])) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Cuisine - Connexion</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@400;700&display=swap" rel="stylesheet">';
    echo '<style>body { background:#fdfbf7; font-family:"Tajawal", sans-serif; text-align:center; padding-top:150px; color:#003a6c; } .box { background:white; padding:40px; border-radius:15px; box-shadow:0 10px 30px rgba(0,0,0,0.05); display:inline-block; border-top:5px solid #0066b2; } input { padding:12px; width:220px; border:2px solid #e0f2fe; border-radius:8px; font-size:1rem; outline:none; } button { margin-top:15px; padding:12px 25px; background:#c1272d; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:bold; font-size:1.1rem; } button:hover{ background:#9a1f24; }</style></head><body>';
    echo '<div class="box"><h2 style="font-family:\'Aref Ruqaa\', serif; font-size:2.5rem; margin-top:0;">Espace Cuisine</h2>';
    echo '<form method="post"><input type="password" name="password" placeholder="Code secret" required><br>';
    echo '<button type="submit">Ouvrir le service</button></form></div></body></html>';
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Le service est temporairement indisponible.");
}

if (isset($_POST['action']) && $_POST['action'] == 'terminer' && isset($_POST['id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        exit;
    }
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE commandes SET statut = 'terminé' WHERE id = ?");
    $stmt->execute([$id]);
    exit;
}

if (isset($_GET['ajax'])) {
    $statut = $_GET['ajax'] === 'historique' ? 'terminé' : 'en attente';
    $stmt = $pdo->prepare("SELECT * FROM commandes WHERE statut = ? ORDER BY id " . ($statut === 'terminé' ? 'DESC' : 'ASC'));
    $stmt->execute([$statut]);
    $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($commandes) === 0) {
        echo "<div style='grid-column: 1 / -1; text-align:center; padding:4rem; color:#888;'>Aucun ticket ici.</div>";
        exit;
    }

    foreach ($commandes as $cmd) {
        $panier = json_decode($cmd['details_panier'], true);
        echo '<div class="order-card">';
        echo '<div class="order-header"><div class="time">' . htmlspecialchars($cmd['heure_retrait']) . '</div>';
        if ($statut === 'en attente') {
            echo '<button class="btn-done" onclick="terminerCommande(' . $cmd['id'] . ')">✓ Prête</button>';
        } else {
            echo '<span style="color:var(--olive-green); font-weight:bold;">Terminée</span>';
        }
        echo '</div>';
        echo '<div class="client-info"><strong>' . htmlspecialchars($cmd['client_nom']) . '</strong><br>📞 ' . htmlspecialchars($cmd['client_tel']) . '</div>';
        echo '<ul class="item-list">';
        foreach ($panier as $item) {
            echo '<li><span class="qty">' . htmlspecialchars($item['qty']) . 'x</span> ' . htmlspecialchars($item['nom']) . '</li>';
        }
        echo '</ul></div>';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BlueBeeTN | Cuisine</title>
    <link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidi-blue: #0066b2;
            --sidi-dark: #003a6c;
            --medina-gold: #d4af37;
            --harissa-red: #c1272d;
            --olive-green: #5a6b31;
            --chaux-white: #fdfbf7;
        }

        body { font-family: 'Tajawal', sans-serif; background: var(--chaux-white); margin: 0; padding-top: 130px; }

        /* NAVIGATION STYLE INDEX */
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
        .nav-container { padding: 0 2rem; height: 100px; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 10px; }
        @media (min-width: 768px) { .nav-container { height: 80px; flex-direction: row; justify-content: space-between; } }
        .nav-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .nav-logo span { font-family: 'Aref Ruqaa', serif; font-size: 1.8rem; font-weight: 700; color: var(--sidi-dark); }
        .khamsa-icon { font-size: 1.8rem; color: var(--sidi-blue); }

        .tabs { display: flex; gap: 10px; }
        .tab-btn { padding: 8px 15px; border: 2px solid #eee; background: white; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .tab-btn.active { background: var(--sidi-blue); color: white; border-color: var(--sidi-blue); }

        .container { padding: 20px; max-width: 1400px; margin: 0 auto; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .order-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-top: 6px solid var(--harissa-red); }
        .order-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed #eee; padding-bottom: 10px; }
        .time { font-family: 'Aref Ruqaa', serif; font-size: 1.8rem; color: var(--sidi-dark); }
        .client-info { background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid var(--medina-gold); }
        .item-list { list-style: none; padding: 0; margin: 0; font-size: 1.1rem; }
        .qty { background: var(--sidi-blue); color: white; padding: 1px 6px; border-radius: 4px; font-weight: bold; margin-right: 8px; }
        .btn-done { background: var(--olive-green); color: white; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

<nav>
    <div class="ceramic-border"></div>
    <div class="nav-container">
        <a href="index.php" class="nav-logo">
            <span class="khamsa-icon">🪬</span>
            <span>BlueBeeTN Cuisine</span>
        </a>
        <div class="tabs">
            <button class="tab-btn active" id="btn-encours" onclick="changerOnglet('encours')">En cours</button>
            <button class="tab-btn" id="btn-historique" onclick="changerOnglet('historique')">Historique</button>
        </div>
        <a href="?logout=1" style="color: var(--harissa-red); text-decoration: none; font-weight: bold; font-size: 0.9rem;">Déconnexion</a>
    </div>
</nav>

<div class="container">
    <div class="grid" id="grille-tickets"></div>
</div>

<script>
    let ongletActuel = 'encours';
    function changerOnglet(onglet) {
        ongletActuel = onglet;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('btn-' + onglet).classList.add('active');
        chargerCommandes();
    }
    function chargerCommandes() {
        fetch(`cuisine.php?ajax=${ongletActuel}`).then(r => r.text()).then(html => {
            document.getElementById('grille-tickets').innerHTML = html;
        });
    }
    function terminerCommande(id) {
        fetch(`cuisine.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=terminer&id=${id}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
        }).then(() => chargerCommandes());
    }
    setInterval(() => { if(ongletActuel === 'encours') chargerCommandes(); }, 15000);
    chargerCommandes();
</script>

</body>
</html>