<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

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
    $_SESSION['cuisine_logged_in'] = true;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: cuisine.php");
    exit;
}

if (!isset($_SESSION['cuisine_logged_in'])) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>BlueBeeTN | Login Cuisine</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">';
    echo '<style>
        body { background: #fdfbf7; font-family: "Tajawal", sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; color: #003a6c; }
        .login-box { background: white; padding: 50px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.1); border-top: 10px solid #d32f2f; width: 100%; max-width: 400px; text-align: center; }
        .khamsa-logo { font-size: 3rem; color: #005599; margin-bottom: 20px; }
        h2 { font-size: 2rem; margin-bottom: 30px; font-weight: 800; }
        input { padding: 15px; width: 100%; border: 2px solid #e0f2fe; border-radius: 10px; font-size: 1rem; outline: none; margin-bottom: 20px; box-sizing: border-box; }
        button { padding: 15px; background: #005599; color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 1.1rem; width: 100%; transition: 0.3s; }
        button:hover { background: #003a6c; transform: translateY(-3px); }
    </style></head><body>';
    echo '<div class="login-box"><div class="khamsa-logo"><i class="fa-solid fa-hamsa"></i></div><h2>Service Cuisine</h2><form method="post"><input type="password" name="password" placeholder="Code Secret" required><button type="submit">Ouvrir le Service</button></form></div></body></html>';
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $num_jour = (int) date('N');
    $pdj_nom = '';
    if ($num_jour >= 1 && $num_jour <= 4) {
        $noms_jours_fr = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi'];
        $jour_nom = $noms_jours_fr[$num_jour] ?? '';
        $stmtPDJ = $pdo->prepare("SELECT c.nom FROM plat_du_jour p JOIN carte_restaurant c ON p.id_plat = c.id WHERE p.jour = ?");
        $stmtPDJ->execute([$jour_nom]);
        $pdj_nom = $stmtPDJ->fetchColumn();
    } else {
        $dateJeudi = date('Y-m-d', strtotime('thursday this week'));
        $stmtD = $pdo->prepare("SELECT plat_index FROM decisions_vote WHERE vote_date = ?");
        $stmtD->execute([$dateJeudi]);
        $winner_id = $stmtD->fetchColumn();
        if ($winner_id) {
            $stmtW = $pdo->prepare("SELECT nom FROM carte_restaurant WHERE id = ?");
            $stmtW->execute([$winner_id]);
            $pdj_nom = $stmtW->fetchColumn();
        }
    }
} catch (PDOException $e) {
    die("Le service est temporairement indisponible.");
}

if (isset($_POST['action']) && $_POST['action'] == 'terminer' && isset($_POST['id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { exit; }
    $id = (int)$_POST['id'];
    // On met le statut à terminé ET on règle l'heure de fin estimée sur MAINTENANT pour libérer la piste
    $stmt = $pdo->prepare("UPDATE commandes SET statut = 'terminé', heure_fin_estimee = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    exit;
}

if (isset($_GET['ajax'])) {
    $statut = $_GET['ajax'] === 'historique' ? 'terminé' : 'en attente';
    $sort = ($statut === 'terminé' ? 'id DESC' : 'heure_debut_prep ASC');
    $stmt = $pdo->prepare("SELECT * FROM commandes WHERE statut = ? ORDER BY $sort");
    $stmt->execute([$statut]);
    $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['pdj']) && $_GET['pdj'] === 'true' && !empty($pdj_nom)) {
        $filtered = [];
        foreach ($commandes as $cmd) {
            $panier_data = json_decode($cmd['details_panier'], true);
            $items = is_array($panier_data) && isset($panier_data['items']) ? $panier_data['items'] : $panier_data;
            if (!is_array($items)) continue;
            foreach ($items as $item) {
                if (($item['nom'] ?? '') === $pdj_nom) { $filtered[] = $cmd; break; }
            }
        }
        $commandes = $filtered;
    }

    if (empty($commandes)) {
        echo "<div style='grid-column: 1 / -1; text-align:center; padding:4rem; color:#64748b; font-weight:700;'>Aucun ticket trouvé.</div>";
        exit;
    }

    foreach ($commandes as $cmd) {
        $panier_data = json_decode($cmd['details_panier'], true);
        $panier = (is_array($panier_data) && isset($panier_data['items'])) ? $panier_data['items'] : $panier_data;
        $note = (is_array($panier_data) && isset($panier_data['note'])) ? $panier_data['note'] : '';

        echo '<div class="order-card" id="card-' . $cmd['id'] . '">';
        echo '<div class="order-header">';
        $debut = $cmd['heure_debut_prep'] ? date('H:i', strtotime($cmd['heure_debut_prep'])) : '--:--';
        $fin = $cmd['heure_fin_estimee'] ? date('H:i', strtotime($cmd['heure_fin_estimee'])) : '--:--';
        echo '<div class="time" style="display:flex; flex-direction:column; gap:5px;">';
        echo '<span style="font-size:0.75rem; color:#64748b; font-weight:800; background:#f1f5f9; padding:2px 8px; border-radius:6px; display:inline-block; width:fit-content; margin-bottom:5px;">CUISINIER ' . $cmd['piste_id'] . '</span>';
        echo '<span style="font-size:0.8rem; color:var(--harissa-red); border-bottom:1px solid #fee2e2;">LANCER À : ' . $debut . '</span>';
        echo '<span>POUR : ' . $fin . '</span>';
        echo '</div>';
        if ($statut === 'en attente') {
            echo '<button class="btn-done" onclick="terminerCommande(' . $cmd['id'] . ')"><i class="fa-solid fa-circle-check"></i> Prête</button>';
        } else {
            echo '<span class="status-done-label"><i class="fa-solid fa-check-double"></i> Archivée</span>';
        }
        echo '</div>';
        
        echo '<div class="client-info">';
        echo '<strong><i class="fa-solid fa-user"></i> ' . htmlspecialchars($cmd['client_nom']) . '</strong>';
        echo '<div class="tel"><i class="fa-solid fa-phone"></i> ' . htmlspecialchars($cmd['client_tel']) . '</div>';
        echo '</div>';
        
        if (!empty($note)) {
            echo '<div class="note-box"><i class="fa-solid fa-comment-dots"></i> <strong>Note Client :</strong><br>' . nl2br(htmlspecialchars($note)) . '</div>';
        }

        echo '<ul class="item-list">';
        foreach ($panier as $item) {
            $is_pdj = (!empty($pdj_nom) && ($item['nom'] ?? '') === $pdj_nom);
            echo '<li' . ($is_pdj ? ' class="highlight-pdj"' : '') . '><span class="qty">' . htmlspecialchars($item['qty']) . 'x</span> ' . htmlspecialchars($item['nom']) . '</li>';
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
    <title>BlueBeeTN | Cuisine Majestic</title>
    <link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --sidi-blue: #005599;
            --sidi-dark: #003a6c;
            --medina-gold: #d4af37;
            --harissa-red: #d32f2f;
            --chaux-white: #fdfbf7;
            --glass-white: rgba(255, 255, 255, 0.95);
        }

        body { font-family: 'Tajawal', sans-serif; background: var(--chaux-white); margin: 0; padding-top: 110px; color: var(--sidi-dark); overflow-x: hidden; }

        nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            background: var(--glass-white); backdrop-filter: blur(12px);
            border-bottom: 3px solid var(--medina-gold);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .nav-container { padding: 0 2rem; height: 90px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .nav-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .nav-logo span { font-family: 'Aref Ruqaa', serif; font-size: 2rem; color: var(--sidi-dark); font-weight: 700; }
        .khamsa-icon { font-size: 1.8rem; color: var(--sidi-blue); width: 45px; height: 45px; background: white; display: flex; align-items: center; justify-content: center; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }

        .tabs { display: flex; gap: 10px; background: #eaeff5; padding: 5px; border-radius: 14px; }
        .tab-btn { 
            padding: 10px 20px; border: none; background: transparent; font-weight: 800; color: #64748b;
            cursor: pointer; border-radius: 10px; transition: 0.3s; display: flex; align-items: center; gap: 8px;
            font-size: 0.95rem;
        }
        .tab-btn.active { background: white; color: var(--sidi-blue); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .tab-btn-pdj { border: 2px solid var(--medina-gold); color: var(--medina-gold); font-weight: 800; border-radius: 10px; background: transparent; cursor: pointer; padding: 10px 15px; transition: 0.3s; }
        .tab-btn-pdj.active { background: var(--medina-gold); color: white; }

        .container { padding: 25px; max-width: 1400px; margin: 0 auto; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px; }

        .order-card { 
            background: white; border-radius: 20px; padding: 25px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.04); border-top: 8px solid var(--harissa-red);
            animation: slideUp 0.4s ease-out; position: relative;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .order-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px dashed #f1f5f9; }
        .time { font-family: 'Aref Ruqaa', serif; font-size: 2.22rem; color: var(--sidi-dark); font-weight: 700; line-height: 1; display: flex; align-items: center; gap: 8px; }
        .time i { font-size: 1.2rem; color: var(--medina-gold); }

        .btn-done { 
            background: var(--sidi-blue); color: white; border: none; padding: 12px 18px; border-radius: 12px; 
            font-weight: 800; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px;
            box-shadow: 0 6px 15px rgba(0, 85, 153, 0.2);
        }
        .btn-done:hover { background: var(--sidi-dark); transform: scale(1.05); }
        .btn-done:active { transform: scale(0.95); }
        .status-done-label { color: #166534; font-weight: 800; display: flex; align-items: center; gap: 6px; }

        .client-info { background: #f8fafc; padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; border-left: 5px solid var(--medina-gold); }
        .client-info strong { font-size: 1.1rem; display: block; margin-bottom: 4px; }
        .client-info .tel { font-size: 0.95rem; color: #64748b; font-weight: 500; }

        .note-box { background: #fffbeb; color: #92400e; padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; border-left: 5px solid #fbbf24; font-size: 0.95rem; line-height: 1.5; }

        .item-list { list-style: none; padding: 0; margin: 0; }
        .item-list li { padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 1.2rem; font-weight: 500; display: flex; align-items: baseline; }
        .item-list li:last-child { border: none; }
        .qty { color: var(--harissa-red); font-weight: 800; font-size: 1.3rem; margin-right: 12px; min-width: 35px; display: inline-block; }
        .highlight-pdj { background: #fef9c3; border-radius: 8px; margin: 0 -10px; padding: 8px 10px !important; border-bottom: none !important; }

        /* Animation de refresh */
        .refresh-loader { position: fixed; top: 90px; left: 0; height: 3px; background: var(--medina-gold); width: 0; z-index: 1001; transition: width 0.3s; }

        @media (max-width: 850px) {
            .nav-container { height: auto; padding: 15px 1rem; flex-direction: column; gap: 15px; }
            body { padding-top: 180px; }
            .refresh-loader { top: 180px; }
            .tabs { width: 100%; justify-content: space-around; }
            .tab-btn { padding: 10px 12px; font-size: 0.85rem; }
        }

        .sound-toggle {
            background: #f1f5f9; border: 2px solid #e2e8f0; padding: 10px 15px; border-radius: 12px;
            cursor: pointer; color: #64748b; font-weight: 800; display: flex; align-items: center; gap: 8px; transition: 0.3s;
        }
        .sound-toggle.active { background: #dcfce7; border-color: #22c55e; color: #166534; }
    </style>
</head>
<body>

<div class="refresh-loader" id="loader"></div>

<nav>
    <div class="nav-container">
        <a href="index.php" class="nav-logo">
            <div class="khamsa-icon"><i class="fa-solid fa-hamsa"></i></div>
            <span>BlueBeeTN Cuisine</span>
        </a>
        <div class="tabs">
            <button class="tab-btn active" id="btn-encours" onclick="changerOnglet('encours')"><i class="fa-solid fa-fire-burner"></i> En cours</button>
            <button class="tab-btn" id="btn-historique" onclick="changerOnglet('historique')"><i class="fa-solid fa-clock-rotate-left"></i> Historique</button>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <button class="sound-toggle" id="btn-sound" onclick="toggleSound()">
                <i class="fa-solid fa-volume-xmark"></i> <span>Désactivé</span>
            </button>
            <button class="tab-btn-pdj" id="btn-pdj" onclick="togglePDJ()">
                <i class="fa-solid fa-star"></i> PDJ: <?= !empty($pdj_nom) ? htmlspecialchars($pdj_nom) : 'Aucun' ?>
            </button>
            <a href="?logout=1" style="color: var(--harissa-red); text-decoration: none; font-weight: 800; font-size: 1.1rem;"><i class="fa-solid fa-power-off"></i></a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="grid" id="grille-tickets"></div>
</div>

<script>
    let ongletActuel = 'encours';
    let filtrePDJ = false;
    let soundEnabled = false;
    let lastOrderCount = -1;

    function changerOnglet(onglet) {
        ongletActuel = onglet;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('btn-' + onglet).classList.add('active');
        chargerCommandes();
    }

    function toggleSound() {
        soundEnabled = !soundEnabled;
        const btn = document.getElementById('btn-sound');
        if (soundEnabled) {
            btn.classList.add('active');
            btn.innerHTML = '<i class="fa-solid fa-volume-high"></i> <span>Audio Actif</span>';
            // Play a silent beep to unlock browser audio
            playDing(true);
        } else {
            btn.classList.remove('active');
            btn.innerHTML = '<i class="fa-solid fa-volume-xmark"></i> <span>Désactivé</span>';
        }
    }

    function playDing(silent = false) {
        if (!soundEnabled && !silent) return;
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            const osc = context.createOscillator();
            const gain = context.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, context.currentTime); // Note A5 (Ding)
            gain.gain.setValueAtTime(silent ? 0 : 0.1, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, context.currentTime + 0.5);
            osc.connect(gain);
            gain.connect(context.destination);
            osc.start();
            osc.stop(context.currentTime + 0.5);
        } catch(e) { console.error("Audio error", e); }
    }

    function togglePDJ() {
        filtrePDJ = !filtrePDJ;
        document.getElementById('btn-pdj').classList.toggle('active', filtrePDJ);
        chargerCommandes();
    }

    function chargerCommandes() {
        const loader = document.getElementById('loader');
        loader.style.width = '30%';
        
        fetch(`cuisine.php?ajax=${ongletActuel}&pdj=${filtrePDJ}`)
        .then(r => r.text())
        .then(html => {
            loader.style.width = '100%';
            setTimeout(() => loader.style.width = '0', 300);
            
            // On compte le nombre de commandes dans le HTML reçu
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const currentCount = tempDiv.querySelectorAll('.order-card').length;

            // Si c'est un refresh de l'onglet "en cours" et qu'il y a de nouvelles commandes
            if (ongletActuel === 'encours' && lastOrderCount !== -1 && currentCount > lastOrderCount) {
                playDing();
            }
            
            lastOrderCount = currentCount;
            document.getElementById('grille-tickets').innerHTML = html;
        });
    }

    function terminerCommande(id) {
        const card = document.getElementById('card-' + id);
        if(card) {
            card.style.opacity = '0.5';
            card.style.transform = 'scale(0.95)';
        }
        
        const formData = new FormData();
        formData.append('action', 'terminer');
        formData.append('id', id);
        formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

        fetch(`cuisine.php`, { method: 'POST', body: formData })
        .then(() => {
            // On décrémente pour ne pas redéclencher le ding au refresh après suppression
            lastOrderCount--;
            chargerCommandes();
        });
    }

    setInterval(() => {
        if(ongletActuel === 'encours') chargerCommandes();
    }, 20000);

    chargerCommandes();
</script>

</body>
</html>