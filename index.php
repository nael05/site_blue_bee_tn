<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

header('X-Frame-Options: SAMEORIGIN'); // Permet l'affichage sur son propre domaine
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

session_start();
require_once 'config.php';

try {
  $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $stmt = $pdo->query("SELECT * FROM carte_restaurant ORDER BY categorie, nom");
  $plats_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $menu_array = [];
  foreach ($plats_db as $plat) {
    $img_url = !empty($plat['image_url']) ? 'images/' . $plat['image_url'] : 'https://images.unsplash.com/photo-1547592180-85f173990554?w=400';
    $menu_array[] = [
      'id' => (int) $plat['id'],
      'nom' => $plat['nom'],
      'desc' => $plat['description'] ? $plat['description'] : '',
      'prix' => (float) $plat['prix'],
      'img' => $img_url,
      'cat' => $plat['categorie'],
      'temps_prep' => (int) $plat['temps_prep_min'],
      'type_stock' => $plat['type_stock'],
      'stock' => (int) $plat['stock_actuel'],
      'dispo' => (int) $plat['est_disponible']
    ];
  }
  $json_menu = json_encode($menu_array);

  // Nouvelle logique Plat du Jour & Vote
  $num_jour = (int) date('N'); // 1=Mon, 4=Thu, 5=Fri, 6=Sat, 7=Sun
  $is_vote_mode = ($num_jour >= 1 && $num_jour <= 4);
  $plat_du_jour = null;

  if ($num_jour >= 1 && $num_jour <= 4) {
    // Lundi à Jeudi : Plat défini manuellement
    $noms_jours_fr = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi'];
    $jour_nom = $noms_jours_fr[$num_jour];
    $stmtPDJ = $pdo->prepare("SELECT c.* FROM plat_du_jour p JOIN carte_restaurant c ON p.id_plat = c.id WHERE p.jour = ?");
    $stmtPDJ->execute([$jour_nom]);
    $plat_du_jour = $stmtPDJ->fetch(PDO::FETCH_ASSOC);
  } else {
    // Vendredi à Dimanche : Gagnant du vote (clôturé Jeudi 23:59)
    $dateLundi = date('Y-m-d', strtotime('monday this week'));
    $dateJeudi = date('Y-m-d', strtotime('thursday this week'));
    
    // 1. Priorité à la décision manuelle de l'admin
    $stmtD = $pdo->prepare("SELECT plat_id FROM decisions_vote WHERE vote_date = ?");
    $stmtD->execute([$dateJeudi]);
    $winner_id = $stmtD->fetchColumn();

    if (!$winner_id) {
        // 2. Calcul automatique du gagnant du vote
        $stmtV = $pdo->prepare("SELECT plat_index, COUNT(*) as cnt FROM votes_menu WHERE vote_date BETWEEN ? AND ? AND plat_index IS NOT NULL GROUP BY plat_index ORDER BY cnt DESC LIMIT 1");
        $stmtV->execute([$dateLundi, $dateJeudi]);
        $winner_res = $stmtV->fetch(PDO::FETCH_ASSOC);
        $winner_id = $winner_res ? $winner_res['plat_index'] : null;
    }

    if ($winner_id) {
        $stmtW = $pdo->prepare("SELECT * FROM carte_restaurant WHERE id = ?");
        $stmtW->execute([$winner_id]);
        $plat_du_jour = $stmtW->fetch(PDO::FETCH_ASSOC);
    }
  }

  // Récupération des réglages de commande
  $settings_raw = $pdo->query("SELECT * FROM commandes_settings")->fetchAll(PDO::FETCH_ASSOC);
  $settings = [];
  foreach ($settings_raw as $s) {
      $settings[$s['s_key']] = $s['s_value'];
  }
  // Valeurs par défaut
  $settings['morning_start'] = $settings['morning_start'] ?? '11:00';
  $settings['morning_end'] = $settings['morning_end'] ?? '14:00';
  $settings['evening_start'] = $settings['evening_start'] ?? '18:00';
  $settings['evening_end'] = $settings['evening_end'] ?? '23:00';
  $settings['slot_duration'] = (int)($settings['slot_duration'] ?? '30');
  $settings['max_per_slot'] = (int)($settings['max_per_slot'] ?? '10');
  $settings['is_active'] = (bool)($settings['is_active'] ?? '1');
  $settings['closed_days'] = json_decode($settings['closed_days'] ?? '[]', true);

  // Vérification fermeture aujourd'hui
  $nom_jour_fr = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][date('w')];
  if (in_array($nom_jour_fr, $settings['closed_days'])) {
      $settings['is_active'] = false;
  }

  // ID du plat du jour actuel pour le filtre JS
  $current_pdj_id = ($plat_du_jour) ? (int)$plat_du_jour['id'] : 0;

  // Récupération des options de vote actives
  $vote_options_data = [];
  if ($is_vote_mode) {
    $stmtOptions = $pdo->query("SELECT c.* FROM options_vote o JOIN carte_restaurant c ON o.id_plat = c.id");
    $vote_options_data = $stmtOptions->fetchAll(PDO::FETCH_ASSOC);
  }

  // Comptage des commandes du jour pour la capacité
  $stmtUsage = $pdo->prepare("SELECT heure_retrait, COUNT(*) as cnt FROM commandes WHERE DATE(date_commande) = CURDATE() GROUP BY heure_retrait");
  $stmtUsage->execute();
  $usage_raw = $stmtUsage->fetchAll(PDO::FETCH_ASSOC);
  $slot_usage = [];
  foreach ($usage_raw as $u) {
      $slot_usage[$u['heure_retrait']] = (int)$u['cnt'];
  }
  $json_usage = json_encode($slot_usage);
  $json_settings = json_encode($settings);

} catch (PDOException $e) {
  die("Le service est temporairement indisponible.");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
  <script src="https://js.stripe.com/v3/"></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>BlueBeeTN - L'Âme de la Tunisie</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@300;400;500;700;800&display=swap');

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    :root {
      --sidi-blue: #005599;
      --sidi-dark: #003a6c;
      --medina-gold: #d4af37;
      --harissa-red: #d32f2f;
      --olive-green: #4caf50;
      --chaux-white: #faf9f6;
      --text-dark: #2c3e50;
      --text-muted: #607d8b;
    }

    html {
      scroll-behavior: smooth;
      overflow-x: hidden;
    }

    body {
      font-family: 'Tajawal', sans-serif;
      background: var(--chaux-white);
      color: var(--text-dark);
      overflow-x: hidden;
      width: 100%;
      max-width: 100%;
      min-height: 100vh;
      line-height: 1.6;
    }

    h1,
    h2,
    h3,
    .oriental-font {
      font-family: 'Aref Ruqaa', serif;
    }

    /* Style de base pour un fond uni */
    body {
      background-color: #ffffff; /* Fond blanc uni garanti */
    }

    .rub-el-hizb {
      display: inline-block;
      width: 24px;
      height: 24px;
      background: var(--medina-gold);
      position: relative;
      margin: 0 15px;
      transform: rotate(45deg);
    }

    .rub-el-hizb::before {
      content: "";
      position: absolute;
      inset: 0;
      background: var(--medina-gold);
      transform: rotate(45deg);
    }

    .rub-el-hizb::after {
      content: "";
      position: absolute;
      inset: 5px;
      background: var(--chaux-white);
      border-radius: 50%;
      z-index: 1;
    }

    .ceramic-border {
      height: 10px;
      width: 100%;
      background: repeating-linear-gradient(90deg,
          var(--sidi-blue),
          var(--sidi-blue) 20px,
          var(--medina-gold) 20px,
          var(--medina-gold) 25px,
          var(--chaux-white) 25px,
          var(--chaux-white) 45px,
          var(--medina-gold) 45px,
          var(--medina-gold) 50px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .item-rupture {
      filter: grayscale(100%);
      opacity: 0.6;
      pointer-events: none;
    }
    .btn-rupture {
      background: #ccc !important;
      border-color: #ccc !important;
      color: #666 !important;
      font-size: 0.7rem !important;
      width: auto !important;
      padding: 0 10px !important;
      cursor: not-allowed !important;
    }

    nav {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 1000;
      background: rgba(250, 249, 246, 0.95);
      backdrop-filter: blur(12px);
      transition: all 0.3s ease;
    }

    .nav-container {
      padding: 0 3rem;
      height: 85px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .nav-logo {
      display: flex;
      align-items: center;
      gap: 15px;
      text-decoration: none;
      transition: transform 0.3s ease;
    }

    .nav-logo:hover {
      transform: scale(1.02);
    }

    .khamsa-icon {
      font-size: 2.5rem;
      color: var(--sidi-blue);
      filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.15));
    }

    .nav-logo span {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2.4rem;
      font-weight: 700;
      color: var(--sidi-dark);
    }

    .nav-links {
      display: flex;
      gap: 2.5rem;
      list-style: none;
      align-items: center;
    }

    .nav-links a {
      text-decoration: none;
      color: var(--text-dark);
      font-weight: 700;
      font-size: 1.1rem;
      transition: color 0.3s ease;
      position: relative;
      padding: 8px 0;
    }

    .nav-links a:hover {
      color: var(--sidi-blue);
    }

    .nav-links a::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      width: 0;
      height: 3px;
      background: var(--sidi-blue);
      transition: width 0.3s ease, left 0.3s ease;
      border-radius: 4px;
    }

    .nav-links a:hover::after {
      width: 100%;
      left: 0;
    }

    .hero {
      position: relative;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding-top: 120px;
      background: radial-gradient(circle at center, var(--chaux-white) 0%, #f0ebd8 100%);
      overflow: hidden;
    }

    .lantern {
      position: absolute;
      font-size: 4.5rem;
      top: 60px;
      animation: swing 5s ease-in-out infinite alternate;
      transform-origin: top center;
      filter: drop-shadow(0 20px 20px rgba(0, 0, 0, 0.25));
    }

    .lantern.left {
      left: 12%;
    }

    .lantern.right {
      right: 12%;
      animation-delay: 1.5s;
    }

    @keyframes swing {
      0% {
        transform: rotate(-6deg);
      }

      100% {
        transform: rotate(6deg);
      }
    }

    .hero-content {
      position: relative;
      z-index: 2;
      text-align: center;
      padding: 2rem;
      max-width: 900px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .door-recess {
      position: relative;
      width: 100%;
      max-width: 700px;
      margin: 0 auto 2.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .door-svg-wrap {
      position: relative;
      width: 100%;
      max-width: 100%;
      filter: drop-shadow(0 30px 60px rgba(0, 0, 0, 0.35));
    }

    .door-image {
      width: 100%;
      height: auto;
      display: block;
      border-radius: 8px;
    }

    .deco-float {
      position: absolute;
      font-size: 3.2rem;
      z-index: 5;
      filter: drop-shadow(0 8px 12px rgba(0, 0, 0, 0.2));
      animation: floatDeco 5s ease-in-out infinite alternate;
    }

    .deco-float:nth-child(2) {
      animation-delay: 1s;
      animation-duration: 6s;
    }

    .deco-float:nth-child(3) {
      animation-delay: 0.5s;
      animation-duration: 7s;
    }

    .deco-float:nth-child(4) {
      animation-delay: 1.5s;
      animation-duration: 5.5s;
    }

    .deco-float:nth-child(5) {
      animation-delay: 2s;
      animation-duration: 6.5s;
    }

    .deco-float:nth-child(6) {
      animation-delay: 0.8s;
      animation-duration: 7.5s;
    }

    @keyframes floatDeco {
      0% {
        transform: translateY(0) rotate(-5deg);
      }

      100% {
        transform: translateY(-15px) rotate(8deg);
      }
    }

    .d-jasmin-tl {
      top: -40px;
      left: -70px;
      font-size: 3.5rem;
    }

    .d-jasmin-br {
      bottom: 30px;
      right: -75px;
      font-size: 2.8rem;
      transform: rotate(20deg);
    }

    .d-piment-tr {
      top: 20px;
      right: -65px;
      font-size: 3rem;
    }

    .d-couffin-bl {
      bottom: -10px;
      left: -65px;
      font-size: 3.8rem;
    }

    .d-olive-ml {
      top: 45%;
      left: -80px;
      font-size: 2.6rem;
    }

    .d-olive-mr {
      top: 55%;
      right: -80px;
      font-size: 2.6rem;
      animation-direction: alternate-reverse;
    }

    .hero-title {
      font-size: clamp(3.5rem, 8vw, 5.5rem);
      color: var(--sidi-dark);
      line-height: 1.1;
      margin-bottom: 0.8rem;
      text-shadow: 2px 2px 0px rgba(255, 255, 255, 1);
    }

    .hero-subtitle {
      font-size: clamp(1.3rem, 2.5vw, 1.8rem);
      color: var(--harissa-red);
      font-weight: 700;
      margin-bottom: 2.5rem;
      font-family: 'Aref Ruqaa', serif;
    }

    .btn {
      padding: 16px 36px;
      border-radius: 50px;
      font-family: 'Tajawal', sans-serif;
      font-size: 1.2rem;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 15px;
      border: none;
      text-transform: uppercase;
      letter-spacing: 1.5px;
    }

    .btn-primary {
      background: var(--harissa-red);
      color: white;
      box-shadow: 0 10px 25px rgba(211, 47, 47, 0.4);
    }

    .btn-primary:hover {
      background: #b71c1c;
      transform: translateY(-5px) scale(1.02);
      box-shadow: 0 15px 35px rgba(211, 47, 47, 0.5);
    }

    section {
      position: relative;
      z-index: 1;
      padding: 8rem 2rem;
    }

    .section-header {
      text-align: center;
      margin-bottom: 4rem;
    }

    .section-header h2 {
      font-size: clamp(2.2rem, 5vw, 3.2rem);
      color: var(--sidi-dark);
      margin-bottom: 0.5rem;
      position: relative;
      display: inline-block;
      font-family: 'Aref Ruqaa', serif;
      letter-spacing: 1px;
    }

    .section-header h2::before,
    .section-header h2::after {
      content: '❈';
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      font-size: 1.8rem;
      color: var(--medina-gold);
      opacity: 0.8;
    }

    .section-header h2::before {
      left: -45px;
    }

    .section-header h2::after {
      right: -45px;
    }

    .section-header p {
      color: var(--text-muted);
      max-width: 800px;
      margin: 0 auto;
      font-size: clamp(1rem, 2vw, 1.25rem);
      line-height: 1.6;
      font-weight: 500;
      opacity: 0.9;
    }

    .divider-tunisian {
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 1rem 0 1.5rem 0;
      gap: 15px;
    }

    .divider-line {
      width: clamp(50px, 15vw, 100px);
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--medina-gold), transparent);
    }

    .menu-section {
      background: #fafaf8;
    }

    .zellige-pattern {
      background-color: #ffffff;
    }

    .menu-categories {
      display: flex;
      justify-content: center;
      gap: 1rem;
      margin-bottom: 5rem;
      flex-wrap: wrap;
    }

    @media (max-width: 768px) {
      .menu-categories {
        justify-content: flex-start;
        flex-wrap: nowrap;
        overflow-x: auto;
        padding: 0 1.5rem 1rem 1.5rem;
        margin-bottom: 3rem;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
      }
      .menu-categories::-webkit-scrollbar { display: none; }
      .cat-btn { padding: 10px 24px; font-size: 1rem; white-space: nowrap; }
    }

    .cat-btn {
      padding: 14px 32px;
      border-radius: 40px;
      border: none;
      background: #f1f5f9;
      color: var(--sidi-dark);
      font-family: 'Tajawal', sans-serif;
      font-size: 1.1rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .cat-btn.active,
    .cat-btn:hover {
      background: var(--sidi-blue);
      color: white;
      transform: translateY(-3px);
      box-shadow: 0 10px 20px rgba(0, 85, 153, 0.25);
    }

    .menu-grid {
      max-width: 1300px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 3.5rem;
    }

    .alcove-card {
      background: #ffffff;
      border-radius: 140px 140px 20px 20px;
      padding: 0 0 2rem 0;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.06);
      position: relative;
      border: 1px solid rgba(0, 0, 0, 0.03);
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .alcove-card:hover {
      transform: translateY(-12px);
      box-shadow: 0 25px 45px rgba(0, 0, 0, 0.12);
    }

    .card-image-wrapper {
      width: 100%;
      height: 240px;
      border-radius: 140px 140px 0 0;
      overflow: hidden;
      margin-bottom: 1.5rem;
      position: relative;
      z-index: 2;
    }

    .card-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.4s ease;
    }

    .alcove-card:hover .card-image {
      transform: scale(1.08);
    }

    /* Badge Rupture Majestic - Positionné au centre de l'image */
    .rupture-badge-overlay {
      position: absolute !important;
      top: 50% !important;
      left: 50% !important;
      transform: translate(-50%, -50%) rotate(-15deg) !important;
      background: rgba(211, 47, 47, 0.95) !important;
      color: white !important;
      padding: 10px 20px !important;
      font-weight: 900 !important;
      font-size: 1.1rem !important;
      text-transform: uppercase !important;
      border: 2px solid white !important;
      box-shadow: 0 5px 15px rgba(0,0,0,0.3) !important;
      z-index: 20 !important;
      white-space: nowrap !important;
      pointer-events: none !important;
      border-radius: 5px !important;
      display: block !important;
    }

    .alcove-content {
      padding: 0 2rem;
      width: 100%;
      display: flex;
      flex-direction: column;
      flex: 1;
      text-align: center;
      justify-content: space-between;
    }

    .alcove-card h4 {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2rem;
      color: var(--sidi-dark);
      margin-bottom: 0.8rem;
      position: relative;
      z-index: 2;
    }

    .alcove-card p {
      font-size: 1.05rem;
      color: var(--text-muted);
      line-height: 1.6;
      margin-bottom: 2rem;
      font-weight: 500;
      flex: 1;
    }

    .alcove-card .price-row {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 1.5rem;
      border-top: 1px solid rgba(212, 175, 55, 0.2);
      position: relative;
      z-index: 2;
    }

    .alcove-card .price {
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--harissa-red);
      font-family: 'Aref Ruqaa', serif;
    }

    .controls-container {
      display: flex;
      align-items: center;
      justify-content: flex-end;
    }

    .add-btn {
      width: 45px;
      height: 45px;
      border-radius: 12px;
      background: var(--chaux-white);
      color: var(--sidi-blue);
      border: 2px solid var(--sidi-blue);
      font-size: 1.8rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    }

    .add-btn:hover {
      background: var(--sidi-blue);
      color: white;
      transform: translateY(-3px);
      box-shadow: 0 8px 15px rgba(0, 85, 153, 0.3);
    }

    .card-qty-controls {
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--sidi-blue);
      border-radius: 12px;
      padding: 5px 8px;
      box-shadow: 0 6px 15px rgba(0, 85, 153, 0.25);
      animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    @keyframes popIn {
      0% {
        transform: scale(0.8);
        opacity: 0;
      }

      100% {
        transform: scale(1);
        opacity: 1;
      }
    }

    .ctrl-btn-card {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      border: none;
      background: white;
      cursor: pointer;
      font-weight: bold;
      color: var(--sidi-dark);
      font-size: 1.4rem;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.2s ease;
    }

    .ctrl-btn-card:hover {
      transform: scale(1.1);
    }

    .qty-display {
      font-weight: 800;
      font-size: 1.2rem;
      color: white;
      min-width: 20px;
      text-align: center;
    }

    .specialties {
      background: #0a192f;
      color: var(--chaux-white);
      position: relative;
    }

    .specialties::before {
      display: none;
    }

    /* Plat du Jour Premium */
    /* Plat du Jour Premium - Overhaul */
    .pdj-section {
      background: var(--chaux-white);
      padding: 4rem 1rem;
      position: relative;
      overflow: hidden;
    }

    .pdj-container {
      max-width: 1000px;
      margin: 0 auto;
      background: white;
      border-radius: 30px;
      display: flex;
      flex-wrap: wrap;
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(0, 85, 153, 0.08);
      border: 1px solid rgba(212, 175, 55, 0.1);
    }

    .pdj-image-col {
      flex: 1 1 400px;
      height: 400px;
      position: relative;
    }

    .pdj-image-col img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .pdj-badge {
      position: absolute;
      top: 20px;
      left: 20px;
      background: var(--medina-gold);
      color: white;
      padding: 8px 20px;
      border-radius: 50px;
      font-weight: 800;
      font-size: 0.9rem;
      z-index: 5;
      box-shadow: 0 5px 15px rgba(212, 175, 55, 0.3);
    }

    .pdj-info-col {
      flex: 1 1 400px;
      padding: 3rem;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background: white;
    }

    .pdj-title {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2.8rem;
      color: var(--sidi-dark);
      margin-bottom: 1rem;
      line-height: 1.2;
    }

    .pdj-desc {
      font-size: 1.1rem;
      color: var(--text-muted);
      margin-bottom: 2rem;
      line-height: 1.6;
    }

    .pdj-meta {
      display: flex;
      align-items: center;
      gap: 20px;
      flex-wrap: wrap;
    }

    .pdj-price {
      font-size: 1.8rem;
      font-weight: 900;
      color: var(--sidi-blue);
    }

    @media (max-width: 768px) {
      .pdj-info-col { padding: 2rem; }
      .pdj-title { font-size: 2rem; }
      .pdj-image-col { height: 300px; }
    }

    /* Vote UI Update */
    .vote-section {
      background: white;
      padding: 4rem 1rem;
    }

    .vote-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 3rem;
      max-width: 1200px;
      margin: 3rem auto;
    }

    .vote-card-premium {
      border: 2px solid var(--medina-gold);
      background: white;
      border-radius: 140px 140px 30px 30px;
      overflow: hidden;
      box-shadow: 0 15px 40px rgba(0,0,0,0.08);
      transition: all 0.4s ease;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 520px; /* Assure un alignement visuel minimum */
    }

    .vote-card-premium:hover {
      transform: translateY(-10px);
      box-shadow: 0 25px 60px rgba(212, 175, 55, 0.2);
    }

    .vote-card-premium .alcove-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      text-align: center;
      padding: 1.5rem 2rem 2.5rem 2rem;
      background: white;
      justify-content: space-between;
    }

    .vote-card-premium .alcove-content p {
      flex: 1;
      margin-bottom: 2rem;
    }

    .btn-vote-card {
      background: var(--sidi-blue);
      color: white;
      border: none;
      padding: 15px 40px;
      border-radius: 50px;
      font-weight: 800;
      cursor: pointer;
      margin-top: 1.5rem;
      transition: all 0.3s;
      width: 100%;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .btn-vote-card:hover {
      background: var(--sidi-dark);
      transform: scale(1.05);
      box-shadow: 0 10px 20px rgba(0, 85, 153, 0.3);
    }

    .specialties .section-header h2 {
      color: var(--medina-gold);
    }

    .specialties .section-header p {
      color: #8892b0;
    }

    .spec-grid {
      max-width: 1200px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 4rem;
    }

    .spec-item {
      text-align: center;
      padding: 2.5rem;
      position: relative;
      background: rgba(255, 255, 255, 0.03);
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.05);
      transition: transform 0.3s ease, background 0.3s ease;
    }

    .spec-item:hover {
      transform: translateY(-10px);
      background: rgba(255, 255, 255, 0.06);
    }

    .spec-item .icon-wrapper {
      width: 130px;
      height: 130px;
      margin: 0 auto 2.5rem;
      background: rgba(0, 85, 153, 0.3);
      border: 3px solid var(--medina-gold);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 4.5rem;
      position: relative;
      box-shadow: 0 0 40px rgba(212, 175, 55, 0.15);
      transition: transform 0.4s ease;
    }

    .cart-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background: var(--harissa-red);
      color: white;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      font-weight: 800;
      border: 2px solid white;
    }

    /* Sélecteur ASAP / Planifié */
    .order-type-toggle {
      display: flex;
      background: #f1f5f9;
      padding: 4px;
      border-radius: 12px;
      margin-bottom: 15px;
      gap: 4px;
    }

    .order-type-btn {
      flex: 1;
      border: none;
      background: transparent;
      padding: 10px;
      border-radius: 8px;
      font-weight: 800;
      font-size: 0.85rem;
      color: #64748b;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
    }

    .order-type-btn.active {
      background: white;
      color: var(--sidi-blue);
      box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }
    
    .order-type-btn span.label {
      font-size: 0.9rem;
    }
    
    .order-type-btn span.sub {
      font-size: 0.7rem;
      opacity: 0.7;
    }

    #asapInfoBox {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      padding: 12px;
      border-radius: 12px;
      text-align: center;
      margin-bottom: 15px;
    }
    
    #asapInfoBox .ready-time {
      display: block;
      font-size: 1.2rem;
      font-weight: 800;
      color: var(--olive-green);
    }
    
    #scheduledBox {
      margin-bottom: 15px;
    }
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 4.5rem;
      position: relative;
      box-shadow: 0 0 40px rgba(212, 175, 55, 0.15);
      transition: transform 0.4s ease;
    }

    .spec-item:hover .icon-wrapper {
      transform: scale(1.1) rotate(5deg);
    }

    .spec-item .icon-wrapper::after {
      content: '۞';
      position: absolute;
      bottom: -20px;
      color: var(--medina-gold);
      font-size: 1.8rem;
      background: #0a192f;
      padding: 0 8px;
    }

    .spec-item h3 {
      font-size: 2.2rem;
      color: var(--chaux-white);
      margin-bottom: 1.2rem;
    }

    .spec-item p {
      color: #a8b2d1;
      font-size: 1.15rem;
      line-height: 1.7;
    }

    .reviews {
      background: #fafafa;
      position: relative;
      overflow: hidden;
    }

    .reviews-slider {
      width: 100%;
      overflow: hidden;
      position: relative;
      padding: 2rem 0;
    }

    .reviews-slider::before,
    .reviews-slider::after {
      content: '';
      position: absolute;
      top: 0;
      bottom: 0;
      width: 15%;
      z-index: 2;
      pointer-events: none;
    }

    .reviews-slider::before {
      left: 0;
      background: linear-gradient(to right, #f8f9fa, transparent);
    }

    .reviews-slider::after {
      right: 0;
      background: linear-gradient(to left, #f8f9fa, transparent);
    }

    .reviews-track {
      display: flex;
      gap: 3rem;
      width: max-content;
      animation: scrollReviews 30s linear infinite;
    }

    .reviews-track:hover {
      animation-play-state: paused;
    }

    @keyframes scrollReviews {
      0% {
        transform: translateX(0);
      }

      100% {
        transform: translateX(calc(-50% - 1.5rem));
      }
    }

    .review-box {
      width: 500px;
      max-width: 85vw;
      background: #ffffff;
      border-radius: 30px;
      padding: 4rem 3rem;
      text-align: center;
      position: relative;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
      flex-shrink: 0;
    }

    .review-box::before,
    .review-box::after {
      content: '۞';
      position: absolute;
      font-size: 2.5rem;
      color: rgba(211, 47, 47, 0.1);
    }

    .review-box::before {
      top: 20px;
      left: 25px;
    }

    .review-box::after {
      bottom: 20px;
      right: 25px;
    }

    .nazar-icon {
      position: absolute;
      top: -40px;
      left: 50%;
      transform: translateX(-50%);
      font-size: 4rem;
      filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.15));
    }

    .stars {
      font-size: 2rem;
      color: var(--medina-gold);
      margin-bottom: 1.5rem;
      letter-spacing: 5px;
    }

    .review-text {
      font-family: 'Aref Ruqaa', serif;
      font-size: 1.8rem;
      line-height: 1.6;
      color: var(--sidi-dark);
      margin-bottom: 2rem;
    }

    .author-name {
      font-weight: 800;
      color: var(--harissa-red);
      font-size: 1.2rem;
      text-transform: uppercase;
      letter-spacing: 2px;
    }

    .contact-section {
      background: #f8f9fa;
      position: relative;
      overflow: hidden;
    }

    .c-jasmin-tl {
      top: 5%;
      left: 5%;
      font-size: 2.8rem;
      opacity: 0.8;
    }

    .c-piment-tr {
      top: 12%;
      right: 8%;
      font-size: 3rem;
      opacity: 0.8;
      transform: rotate(15deg);
    }

    .c-olive-bl {
      bottom: 8%;
      left: 6%;
      font-size: 2.5rem;
      opacity: 0.8;
      transform: rotate(-10deg);
    }

    .contact-container {
      max-width: 1200px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4rem;
      align-items: stretch;
      position: relative;
      z-index: 2;
    }

    .contact-info {
      background: linear-gradient(135deg, #ffffff 0%, #f1f5f9 100%);
      padding: 3.5rem;
      border-radius: 20px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.06);
      border: 2px solid var(--medina-gold);
      position: relative;
    }

    .contact-info::before {
      content: '';
      position: absolute;
      inset: 8px;
      border: 1px dashed rgba(212, 175, 55, 0.5);
      border-radius: 12px;
      pointer-events: none;
    }

    .contact-info h3 {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2.6rem;
      color: var(--sidi-dark);
      margin-bottom: 2.5rem;
      text-align: center;
    }

    .contact-detail {
      display: flex;
      align-items: center;
      gap: 20px;
      margin-bottom: 2rem;
      font-size: 1.2rem;
      color: var(--text-dark);
      font-weight: 500;
    }

    .contact-icon-wrap {
      width: 55px;
      height: 55px;
      background: var(--sidi-blue);
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.6rem;
      flex-shrink: 0;
      box-shadow: 0 8px 15px rgba(0, 85, 153, 0.2);
    }

    .social-wrapper {
      display: flex;
      gap: 15px;
      margin-top: 2.5rem;
      justify-content: center;
      position: relative;
      z-index: 2;
    }

    .social-icon {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: var(--chaux-white);
      color: var(--sidi-blue);
      display: flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
      transition: all 0.3s ease;
      border: 1px solid rgba(0, 85, 153, 0.1);
    }

    .social-icon:hover {
      transform: translateY(-5px);
      background: var(--harissa-red);
      color: white;
      box-shadow: 0 8px 20px rgba(211, 47, 47, 0.3);
      border-color: var(--harissa-red);
    }

    .social-icon svg {
      width: 22px;
      height: 22px;
      fill: currentColor;
    }

    .map-container {
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.06);
      height: 100%;
      min-height: 450px;
      border: 2px solid white;
      position: relative;
      z-index: 2;
    }

    .map-container iframe {
      width: 100%;
      height: 100%;
      border: none;
    }

    .site-footer {
      background: #050d1a;
      color: #a8b2d1;
      padding: 5rem 2rem 2rem;
      position: relative;
      border-top: 5px solid var(--harissa-red);
    }

    .footer-grid {
      max-width: 1200px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 4rem;
      margin-bottom: 4rem;
    }

    .footer-brand .footer-logo {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2.8rem;
      color: var(--chaux-white);
      margin-bottom: 1rem;
    }

    .footer-brand .footer-logo span {
      color: var(--medina-gold);
    }

    .footer-brand .tunisian-flag-bar {
      margin: 0 0 1.5rem 0;
      width: 60px;
      height: 4px;
      background: var(--harissa-red);
      position: relative;
      border-radius: 2px;
    }

    .footer-brand p {
      font-size: 1.1rem;
      line-height: 1.6;
    }

    .footer-section h4 {
      color: var(--chaux-white);
      font-family: 'Aref Ruqaa', serif;
      font-size: 1.8rem;
      margin-bottom: 1.5rem;
      position: relative;
      display: inline-block;
    }

    .footer-section h4::after {
      content: '';
      position: absolute;
      bottom: -5px;
      left: 0;
      width: 40px;
      height: 2px;
      background: var(--medina-gold);
    }

    .footer-section ul {
      list-style: none;
      padding: 0;
    }

    .footer-section ul li {
      margin-bottom: 0.8rem;
    }

    .footer-section a {
      color: #a8b2d1;
      text-decoration: none;
      transition: color 0.3s ease;
      font-size: 1.1rem;
      display: inline-block;
    }

    .footer-section a:hover {
      color: var(--medina-gold);
      transform: translateX(5px);
    }

    .footer-bottom {
      text-align: center;
      padding-top: 2rem;
      border-top: 1px solid rgba(255, 255, 255, 0.05);
      font-size: 0.95rem;
    }

    .cart-fab {
      position: fixed;
      bottom: 40px;
      right: 40px;
      width: 75px;
      height: 75px;
      border-radius: 50%;
      background: var(--harissa-red);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2.2rem;
      cursor: pointer;
      z-index: 999;
      box-shadow: 0 10px 25px rgba(211, 47, 47, 0.4);
      transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .cart-fab:hover {
      transform: scale(1.15) rotate(-8deg);
    }

    .cart-badge {
      position: absolute;
      top: -2px;
      right: -2px;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--chaux-white);
      color: var(--harissa-red);
      font-size: 1rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    @keyframes staggered-slide {
      from { transform: translateX(30px); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    @keyframes shimmer-gold {
      0% { background-position: -200% 0; }
      100% { background-position: 200% 0; }
    }

    .cart-panel {
      position: fixed;
      top: 0;
      right: -550px;
      width: 480px;
      max-width: 100%;
      height: 100vh;
      height: 100dvh;
      background: rgba(253, 251, 247, 0.98);
      backdrop-filter: blur(25px);
      z-index: 1001;
      box-shadow: -15px 0 60px rgba(0, 0, 0, 0.15);
      transition: all 0.7s cubic-bezier(0.19, 1, 0.22, 1);
      display: flex;
      flex-direction: column;
      border-left: 2px solid rgba(212, 175, 55, 0.3);
      background-image: radial-gradient(circle at 2px 2px, rgba(212, 175, 55, 0.03) 1px, transparent 0);
      background-size: 24px 24px;
      overflow: hidden; 
    }

    .cart-panel.open {
      right: 0;
    }
    
    @media (max-width: 600px) {
        .cart-panel { width: 100%; right: -100%; border-left: none; }
        .cart-header { padding: 1rem; flex-shrink: 0; }
        .cart-header h3 { font-size: 1.6rem; }
        .cart-scroll-area { padding: 0.5rem; }
        .cart-total { font-size: 1.6rem; margin-bottom: 0.8rem; }
        .btn-order { padding: 14px; font-size: 1.05rem; }
    }
    
    .cart-scroll-area {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      -webkit-overflow-scrolling: touch; /* Défilement fluide iOS */
      padding-bottom: 30px;
    }

    .cart-header {
      padding: 2rem;
      background: transparent;
      color: var(--sidi-dark);
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 3px solid rgba(212, 175, 55, 0.15);
      position: relative;
    }

    .cart-header h3 {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2.35rem;
      margin: 0;
      background: linear-gradient(
        to right,
        #bf953f,
        #fcf6ba,
        #b38728,
        #fbf5b7,
        #aa771c
      );
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      color: transparent;
      text-shadow: 0 2px 4px rgba(0,0,0,0.05);
      animation: shimmer-gold 8s linear infinite;
      background-size: 200% auto;
    }

    .close-cart {
      background: #f1f5f9;
      border: none;
      border-radius: 50%;
      width: 45px;
      height: 45px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-dark);
      font-size: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .close-cart:hover {
      background: var(--harissa-red);
      color: white;
      transform: rotate(90deg);
    }

    .cart-body {
      padding: 1.5rem;
    }

    .results-container {
       background: #fdfbf7;
       border: 2px solid var(--medina-gold);
       border-radius: 20px;
       padding: 3rem;
       max-width: 900px;
       margin: 0 auto;
       box-shadow: 0 15px 35px rgba(212, 175, 55, 0.1);
       position: relative;
       overflow: hidden;
    }

    .results-container::before {
       content: '🧿';
       position: absolute;
       top: -10px;
       right: -10px;
       font-size: 5rem;
       opacity: 0.05;
    }

    .result-bar-item {
      margin-bottom: 2rem;
    }

    .result-label {
      display: flex;
      justify-content: space-between;
      margin-bottom: 10px;
      font-weight: 700;
      color: var(--sidi-dark);
      font-size: 1.1rem;
    }

    .bar-bg {
      height: 14px;
      background: #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
    }

    @media (max-width: 600px) {
       .results-container { padding: 1.5rem; }
       .result-label { font-size: 0.95rem; }
    }

    .cart-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      margin-bottom: 12px;
      background: white;
      border-radius: 18px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
      border: 1px solid rgba(212, 175, 55, 0.08);
      transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
      animation: staggered-slide 0.5s cubic-bezier(0.19, 1, 0.22, 1) both;
      animation-delay: calc(var(--d, 0) * 0.1s);
      flex-wrap: nowrap; /* Force horizontal */
      overflow: hidden;
    }
    .cart-item:hover { 
      transform: translateY(-5px) scale(1.02); 
      box-shadow: 0 12px 30px rgba(212, 175, 55, 0.12);
      border-color: rgba(212, 175, 55, 0.2);
    }

    .cart-item .image {
      width: 55px;
      height: 55px;
      border-radius: 10px;
      object-fit: cover;
      flex-shrink: 0; /* Ne pas rétrécir la photo */
      box-shadow: 0 4px 8px rgba(0,0,0,0.08);
    }

    .cart-item .info { flex: 1; }

    .cart-item .title {
      font-weight: 800;
      color: var(--sidi-dark);
      font-size: 1.05rem;
      margin-bottom: 2px;
    }

    .cart-item .price {
      color: var(--harissa-red);
      font-weight: 800;
      font-size: 1.1rem;
    }

    .cart-controls {
      display: flex;
      align-items: center;
      background: #f1f5f9;
      border-radius: 12px;
      padding: 3px;
      gap: 5px;
    }

    .ctrl-btn {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      border: none;
      background: white;
      cursor: pointer;
      font-weight: 900;
      color: var(--sidi-blue);
      font-size: 1.2rem;
      transition: 0.2s;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .ctrl-btn:hover { background: var(--sidi-blue); color: white; transform: scale(1.05); }

    .cart-footer {
      padding: 2rem;
      border-top: 2px solid rgba(212, 175, 55, 0.1);
      background: var(--glass-white);
      box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.03);
    }

    .cart-total {
      display: flex;
      justify-content: space-between;
      font-size: 2.2rem;
      font-weight: 800;
      color: var(--sidi-blue);
      margin-bottom: 1.5rem;
      font-family: 'Aref Ruqaa', serif;
    }

    .btn-order {
      width: 100%;
      padding: 22px;
      background: linear-gradient(135deg, var(--sidi-blue), var(--sidi-dark));
      color: white;
      border: none;
      border-radius: 18px;
      font-size: 1.25rem;
      font-weight: 900;
      font-family: 'Tajawal', sans-serif;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      text-transform: uppercase;
      letter-spacing: 3px;
      box-shadow: 0 12px 30px rgba(0, 85, 153, 0.35);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      position: relative;
      overflow: hidden;
    }

    .btn-order::after {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: linear-gradient(
        to right,
        rgba(255, 255, 255, 0) 0%,
        rgba(255, 255, 255, 0.3) 50%,
        rgba(255, 255, 255, 0) 100%
      );
      transform: rotate(30deg);
      transition: 0.5s;
      animation: silk-shimmer 4s infinite;
    }

    @keyframes silk-shimmer {
      0% { transform: translateX(-150%) rotate(30deg); }
      100% { transform: translateX(150%) rotate(30deg); }
    }

    .btn-order:hover {
      background: var(--sidi-dark);
      transform: translateY(-6px) scale(1.01);
      box-shadow: 0 20px 40px rgba(0, 85, 153, 0.45);
    }

    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 26, 51, 0.6);
      backdrop-filter: blur(8px);
      z-index: 1000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.4s ease;
    }

    .overlay.active {
      opacity: 1;
      pointer-events: all;
    }

    input,
    select,
    textarea {
      width: 100%;
      padding: 12px;
      margin-bottom: 10px;
      border-radius: 8px;
      border: 1px solid #e0e0e0;
      font-family: 'Tajawal', sans-serif;
      font-size: 1rem;
      outline: none;
      transition: border-color 0.3s;
    }

    textarea {
      resize: none;
    }

    input:focus,
    select:focus,
    textarea:focus {
      border-color: var(--sidi-blue);
    }

    .close-form-btn {
      position: absolute;
      top: 10px;
      right: 0;
      background: none;
      border: none;
      font-size: 1.5rem;
      color: #888;
      cursor: pointer;
      transition: color 0.3s;
    }

    .close-form-btn:hover {
      color: var(--harissa-red);
    }

    @media (min-width: 769px) {
      .burger-menu {
        display: none;
      }
    }

    @media (max-width: 992px) {
      .spec-grid {
        grid-template-columns: 1fr 1fr;
      }

      .contact-container {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .burger-menu {
        display: flex;
        flex-direction: column;
        justify-content: space-around;
        width: 30px;
        height: 25px;
        background: transparent;
        border: none;
        cursor: pointer;
        z-index: 1100;
      }

      .burger-menu span {
        width: 30px;
        height: 3px;
        background: var(--sidi-dark);
        border-radius: 10px;
        transition: all 0.3s ease;
      }

      .burger-menu.active span:nth-child(1) {
        transform: rotate(45deg) translate(5px, 5px);
      }

      .burger-menu.active span:nth-child(2) {
        opacity: 0;
      }

      .burger-menu.active span:nth-child(3) {
        transform: rotate(-45deg) translate(7px, -6px);
      }

      .nav-links {
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 85px;
        left: 0;
        right: 0;
        background: rgba(250, 249, 246, 0.98);
        padding: 2rem;
        gap: 2rem;
        text-align: center;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        transform: translateY(-150%);
        transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
        z-index: 1000;
      }

      .nav-links.active {
        transform: translateY(0);
      }

      .spec-grid {
        grid-template-columns: 1fr;
      }

      .hero {
        padding-top: 90px;
        min-height: auto;
        padding-bottom: 2rem;
      }

      .hero-title {
        font-size: 2.5rem;
        margin-top: 15px;
        margin-bottom: 10px;
        line-height: 1.2;
      }

      .door-recess {
        width: 100%;
        max-width: 450px;
        margin-top: 0;
        margin-bottom: 1.5rem;
      }

      .d-jasmin-tl {
        top: -15px;
        left: -15px;
        font-size: 2rem;
      }

      .d-jasmin-br {
        bottom: 10px;
        right: -15px;
        font-size: 2rem;
      }

      .d-piment-tr {
        top: 5px;
        right: -15px;
        font-size: 1.8rem;
      }

      .d-couffin-bl {
        bottom: -10px;
        left: -20px;
        font-size: 2.2rem;
      }

      .d-olive-ml {
        top: 40%;
        left: -20px;
        font-size: 1.5rem;
      }

      .d-olive-mr {
        top: 50%;
        right: -20px;
        font-size: 1.5rem;
      }

      .section-header h2::before {
        left: -25px;
        font-size: 1.5rem;
      }

      .section-header h2::after {
        right: -25px;
        font-size: 1.5rem;
      }

      .nav-container {
        padding: 0 1.5rem;
      }
      .cart-fab { bottom: 20px; right: 20px; width: 65px; height: 65px; font-size: 1.8rem; }
      .menu-grid { gap: 2rem; }
      .alcove-card { border-radius: 100px 100px 20px 20px; }
      .card-image-wrapper { border-radius: 100px 100px 0 0; height: 200px; }
      }

      section {
        padding: 5rem 1.5rem;
      }

      .menu-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 2.5rem;
      }

      .c-jasmin-tl {
        top: 2%;
        left: 2%;
        font-size: 2rem;
      }

      .c-piment-tr {
        top: 5%;
        right: 2%;
        font-size: 2rem;
      }

      .c-olive-bl {
        bottom: 2%;
        left: 2%;
        font-size: 2rem;
      }
    }

    @media (max-width: 1024px) {
      .nav-links {
        display: none;
      }
      
      .burger-menu {
        display: flex;
      }
      
      .nav-links.active {
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        background: white;
        padding: 100px 2rem;
        z-index: 1500;
        gap: 2rem;
        align-items: center;
        box-shadow: -10px 0 30px rgba(0,0,0,0.1);
      }
      
      .nav-links.active li a {
        font-size: 2rem;
        color: var(--sidi-dark);
      }
      
      .spec-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 2rem;
      }
      
      .pdj-container {
        flex-direction: column;
        gap: 2rem;
      }
      
      .pdj-image-col {
        min-width: 100%;
        height: 350px;
      }
    }

    @media (max-width: 768px) {
      section {
        padding: 4rem 1.5rem;
      }
      
      .hero-title {
        font-size: clamp(3rem, 12vw, 5rem);
      }
      
      .hero-subtitle {
        font-size: 1.2rem;
      }
      
      .spec-grid {
        grid-template-columns: 1fr;
      }
      
      .vote-grid {
        grid-template-columns: 1fr !important;
      }
      
      .review-box {
        width: 290px;
        padding: 3rem 1.5rem;
      }
    }

    @media (max-width: 600px) {
      .alcove-card {
        border-radius: 120px 120px 15px 15px;
      }

      .card-image-wrapper {
        height: 200px;
        border-radius: 112px 112px 0 0;
        margin-bottom: 1.2rem;
      }

      .alcove-content {
        padding: 0 1.2rem;
      }

      .cart-panel {
        width: 100%;
        right: -100%;
      }

      .review-box {
        width: 320px;
        padding: 3rem 2rem;
        border-radius: 20px;
      }

      .reviews-track {
        gap: 1.5rem;
      }

      .menu-categories {
        gap: 0.5rem;
      }

      .cat-btn {
        padding: 10px 20px;
        font-size: 1rem;
      }

      .cart-fab {
        bottom: 25px;
        right: 25px;
        width: 65px;
        height: 65px;
        font-size: 1.8rem;
      }

      .cart-header {
        padding: 1rem 1.2rem;
      }

      .cart-body {
        padding: 1rem 1.2rem;
      }

      .cart-footer {
        padding: 1rem 1.2rem;
        padding-bottom: max(1rem, env(safe-area-inset-bottom));
      }

      .cart-total {
        margin-bottom: 0.8rem;
        font-size: 1.4rem;
      }

      .btn-order {
        padding: 12px;
        font-size: 1.1rem;
      }

      input,
      select,
      textarea {
        padding: 10px;
        margin-bottom: 6px;
        font-size: 16px;
      }

      #formClientInfo {
        padding-top: 10px;
        margin-bottom: 10px;
      }

      .contact-info {
        padding: 2rem;
      }

      .map-container {
        height: 300px;
        min-height: 300px;
      }
    }

    .fade-in {
      opacity: 0;
      transform: translateY(50px);
      transition: all 0.8s cubic-bezier(0.25, 0.8, 0.25, 1);
    }

    .fade-in.visible {
      opacity: 1;
      transform: translateY(0);
    }

    /* Styles Système de Vote / Menu Dynamique */
    .vote-section {
      background: white;
      padding: 4rem 2rem;
      text-align: center;
      position: relative;
      z-index: 10;
    }

    .vote-card {
      max-width: 800px;
      margin: 0 auto;
      background: var(--chaux-white);
      padding: 3rem;
      border-radius: 30px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(212, 175, 55, 0.2);
    }

    .vote-title {
      font-family: 'Aref Ruqaa', serif;
      font-size: clamp(2.5rem, 6vw, 4rem);
      color: var(--sidi-blue);
      text-align: center;
      margin-bottom: 0.5rem;
      text-shadow: 2px 2px 4px rgba(0,0,0,0.05);
    }

    .vote-subtitle {
      font-size: 1.1rem;
      color: var(--text-muted);
      margin-bottom: 2.5rem;
      font-weight: 600;
    }

    .vote-options {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .vote-opt {
      background: white;
      padding: 1.5rem;
      border-radius: 20px;
      border: 2px solid #e2e8f0;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .vote-opt:hover {
      border-color: var(--sidi-blue);
      transform: translateY(-5px);
    }

    .vote-opt h4 {
      font-family: 'Tajawal', sans-serif;
      font-weight: 800;
      font-size: 1.2rem;
      color: var(--sidi-dark);
      margin-bottom: 0.5rem;
    }

    .vote-btn-mini {
      background: var(--sidi-blue);
      color: white;
      border: none;
      padding: 8px 15px;
      border-radius: 50px;
      font-size: 0.9rem;
      font-weight: 700;
      margin-top: 10px;
    }

    .results-container {
      text-align: left;
      display: none;
    }

    .result-bar-item {
      margin-bottom: 1.5rem;
    }

    .result-label {
      display: flex;
      justify-content: space-between;
      font-weight: 800;
      margin-bottom: 5px;
      color: var(--sidi-dark);
    }

    .bar-bg {
      background: #e2e8f0;
      height: 12px;
      border-radius: 10px;
      overflow: hidden;
    }

    .bar-fill {
      background: var(--harissa-red);
      height: 100%;
      width: 0%;
      transition: width 1s ease-out;
    }

    /* Modal Vote */
    .modal-vote {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(5px);
      z-index: 2000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .modal-vote-content {
      background: white;
      width: 100%;
      max-width: 400px;
      padding: 2.5rem;
      border-radius: 25px;
      position: relative;
      text-align: center;
    }

    .modal-vote-content h3 {
      font-family: 'Aref Ruqaa', serif;
      font-size: 2rem;
      color: var(--sidi-dark);
      margin-bottom: 1.5rem;
    }

    .modal-vote-content input {
      margin-bottom: 1rem;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      padding: 12px;
      width: 100%;
      outline: none;
    }

    .modal-vote-content input:focus {
      border-color: var(--sidi-blue);
    }

    .modal-vote-content .btn-confirm {
      background: var(--harissa-red);
      color: white;
      border: none;
      width: 100%;
      padding: 15px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 1.1rem;
      cursor: pointer;
      transition: 0.3s;
    }

    .btn-confirm:hover {
      background: #b71c1c;
    }

    .close-modal {
      position: absolute;
      top: 15px;
      right: 20px;
      font-size: 1.5rem;
      cursor: pointer;
      color: #888;
    }

    /* Winner Banner */
    .winner-banner {
      background: linear-gradient(135deg, var(--sidi-blue), var(--sidi-dark));
      color: white;
      padding: 1.5rem;
      border-radius: 20px;
      margin-bottom: 2.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 15px;
      box-shadow: 0 10px 25px rgba(0, 102, 178, 0.25);
      border: 2px solid var(--medina-gold);
      animation: slideInDown 0.8s cubic-bezier(0.25, 1, 0.5, 1);
    }

    .winner-icon {
      font-size: 2.5rem;
      filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.6));
    }

    .winner-text h5 {
      font-family: 'Aref Ruqaa', serif;
      font-size: 1.1rem;
      color: var(--medina-gold);
      margin-bottom: 2px;
    }

    .winner-text p {
      font-size: 1.3rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    @keyframes slideInDown {
      from {
        transform: translateY(-30px);
        opacity: 0;
      }

      to {
        transform: translateY(0);
        opacity: 1;
      }
    /* Système de Notifications Majestic (Toasts) */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 3000;
      display: flex;
      flex-direction: column;
      gap: 10px;
      pointer-events: none;
    }

    .toast {
      background: white;
      border-left: 5px solid var(--sidi-blue);
      border-radius: 12px;
      padding: 15px 25px;
      min-width: 280px;
      max-width: 400px;
      box-shadow: 0 10px 30px rgba(0, 85, 153, 0.15);
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--sidi-dark);
      font-weight: 700;
      pointer-events: auto;
      transform: translateX(150%);
      opacity: 0;
      transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      border-bottom: 2px solid rgba(212, 175, 55, 0.1);
    }

    .toast.show {
      transform: translateX(0);
      opacity: 1;
    }

    .toast i {
      font-size: 1.4rem;
    }

    .toast.error { border-left-color: var(--harissa-red); }
    .toast.error i { color: var(--harissa-red); }
    .toast.success { border-left-color: #22c55e; }
    .toast.success i { color: #22c55e; }
    .toast.info { border-left-color: var(--sidi-blue); }
    .toast.info i { color: var(--sidi-blue); }
    .toast.warning { border-left-color: var(--medina-gold); }
    .toast.warning i { color: var(--medina-gold); }


    @media (max-width: 600px) {
      .toast-container { top: 10px; right: 10px; left: 10px; }
      .toast { min-width: auto; width: 100%; box-sizing: border-box; }
      .rupture-badge-overlay { font-size: 0.85rem !important; padding: 6px 12px !important; }
    }
  </style>
</head>

<body>

  <!-- Fond uni sans motifs -->

  <nav>
    <div class="ceramic-border"></div>
    <div class="nav-container">
      <a href="#" class="nav-logo">
        <span class="khamsa-icon">🪬</span>
        <span>BlueBeeTN</span>
      </a>

      <button class="burger-menu" id="burgerBtn" aria-label="Menu">
        <span></span>
        <span></span>
        <span></span>
      </button>

      <ul class="nav-links" id="navLinks">
        <li><a href="#accueil">Accueil</a></li>
        <li><a href="#avis">Livre d'Or</a></li>
        <li><a href="#menu">Notre Carte</a></li>
        <li><a href="#specialites">Notre Héritage</a></li>
        <li><a href="#contact">Contact</a></li>
      </ul>
    </div>
  </nav>

  <section class="hero" id="accueil">
    <div class="hero-content fade-in visible">
      <div class="door-recess">
        <div class="door-svg-wrap">
          <img src="images/image-home.webp" alt="Accueil BlueBeeTN" class="door-image">
        </div>
      </div>
      <h1 class="hero-title">Bienvenue !</h1>
      <p class="hero-subtitle">Une immersion culinaire tunisienne à Meulan-en-Yvelines</p>
      <a href="#menu" class="btn btn-primary">Commandez à emporter !<span>🐪</span></a>
    </div>
  </section>

  <?php if ($plat_du_jour): ?>
    <section id="plat-du-jour" class="pdj-section">
      <div class="section-header fade-in">
        <h2 style="font-size: 3.2rem;">Le Trésor du Jour</h2>
        <div class="divider-tunisian">
          <div class="divider-line"></div>
          <div class="rub-el-hizb"></div>
          <div class="divider-line"></div>
        </div>
        <p>Chaque jour, une immersion unique dans la gastronomie tunisienne.</p>
      </div>

      <div class="pdj-container fade-in">
        <div class="pdj-image-col">
          <div class="pdj-badge">✨ Star du Jour</div>
          <img src="<?= !empty($plat_du_jour['image_url']) ? 'images/'.htmlspecialchars($plat_du_jour['image_url']) : 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=800&q=80' ?>" 
               alt="<?= htmlspecialchars($plat_du_jour['nom']) ?>"
               onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=800&q=80'">
        </div>
        <div class="pdj-info-col">
          <h3 class="pdj-title"><?= htmlspecialchars($plat_du_jour['nom']) ?></h3>
          <p class="pdj-desc"><?= htmlspecialchars($plat_du_jour['description']) ?></p>
          <div class="pdj-meta">
            <span class="pdj-price"><?= number_format($plat_du_jour['prix'], 2, ',', ' ') ?> €</span>
            <a href="#menu" class="btn btn-primary" onclick="filterMenu('pdj', event)" style="padding: 10px 25px; font-size: 1rem;">Commander<span>🛒</span></a>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

      <?php if ($is_vote_mode): ?>
        <?php if (!empty($vote_options_data)): ?>
          <div class="section-header fade-in">
            <h2 class="vote-title">Votez pour Demain !</h2>
            <div class="divider-tunisian">
              <div class="divider-line"></div>
              <div class="rub-el-hizb"></div>
              <div class="divider-line"></div>
            </div>
            <p class="vote-subtitle">Définissez la saveur de votre prochaine journée</p>
          </div>

          <div id="vote-options-ui">
            <div class="vote-grid">
              <?php foreach ($vote_options_data as $option): ?>
                <div class="vote-card-premium fade-in">
                  <div class="card-image-wrapper" style="height: 280px; border-bottom: 3px solid var(--medina-gold); position: relative;">
                    <img src="images/<?= htmlspecialchars((string)($option['image_url'] ?? '')) ?>" alt="<?= htmlspecialchars((string)($option['nom'] ?? '')) ?>" class="card-image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?w=400'">
                  </div>
                  <div class="alcove-content">
                    <h4 style="margin-bottom: 1rem; font-family: 'Aref Ruqaa', serif; font-size: 1.8rem; color: var(--sidi-dark);"><?= htmlspecialchars((string)($option['nom'] ?? '')) ?></h4>
                    <p style="font-size: 1rem; color: var(--text-muted); line-height: 1.5;"><?= htmlspecialchars((string)($option['description'] ?? '')) ?></p>
                    <button class="btn-vote-card" onclick="openVoteModal(<?= (int)$option['id'] ?>, '<?= addslashes((string)($option['nom'] ?? '')) ?>')" style="width: auto; padding: 12px 40px; margin-top: auto;">
                      Voter 
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div style="text-align: center; margin-top: 2rem;">
              <button class="btn-vote-card" onclick="showResults()" style="background: transparent; border: 2px solid var(--sidi-blue); color: var(--sidi-blue); width: auto; padding: 10px 30px;">Consulter les résultats en direct 📊</button>
            </div>
          </div>

          <div id="vote-results-ui" class="results-container">
            <h3 style="font-family: 'Aref Ruqaa', serif; margin-bottom:1.5rem; color:var(--sidi-blue); text-align: center; font-size: 2.2rem;">Résultats en Direct 📊</h3>
            <?php foreach ($vote_options_data as $option): ?>
              <div class="result-bar-item">
                <div class="result-label">
                  <span><?= htmlspecialchars($option['nom']) ?></span>
                  <span id="percentage-<?= $option['id'] ?>">0%</span>
                </div>
                <div class="bar-bg">
                  <div class="bar-fill" id="bar-<?= $option['id'] ?>"></div>
                </div>
              </div>
            <?php endforeach; ?>
            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 2rem; align-items: center;">
              <button class="btn-vote-card" onclick="backToVote()" style="background: transparent; border: 2px solid var(--sidi-blue); color: var(--sidi-blue); width: auto; padding: 10px 30px;">Retourner au vote ↩️</button>
              <p style="font-size: 0.9rem; color: var(--text-muted); text-align: center;">Merci pour votre participation !</p>
            </div>
          </div>
        <?php else: ?>
          <h2 class="vote-title">Système de Vote</h2>
          <p class="vote-subtitle">Aucun plat n'a encore été sélectionné pour le vote cette semaine.</p>
        <?php endif; ?>
      <?php else: ?>
        <h2 class="vote-title">Menu du Week-end 🕌</h2>
        <p class="vote-subtitle">Découvrez nos suggestions gourmandes de cette fin de semaine.</p>
        <div class="vote-options" style="pointer-events:none;">
          <?php 
          ?>
           <p class="vote-subtitle">Le système de vote est ouvert du Lundi au Jeudi. Profitez de notre sélection spéciale du week-end !</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Modal Vote -->
  <div id="modalVote" class="modal-vote">
    <div class="modal-vote-content">
      <span class="close-modal" onclick="closeVoteModal()">✕</span>
      <h3 id="modal-dish-name">Votre choix</h3>
      <p style="margin-bottom:1rem; font-size:0.9rem; color:#666;">Validez votre vote avec votre prénom et numéro de
        téléphone.</p>
      <input type="text" id="voteFirstName" placeholder="Prénom" required>
      <input type="tel" id="votePhone" placeholder="Numéro de téléphone" required>
      <button class="btn-confirm" onclick="submitVote()">Confirmer mon Vote</button>
    </div>
  </div>

  <section class="reviews zellige-pattern" id="avis">
    <div class="section-header fade-in">
      <h2>Paroles d'Invités</h2>
      <div class="divider-tunisian">
        <div class="divider-line"></div>
        <div class="rub-el-hizb"></div>
        <div class="divider-line"></div>
      </div>
    </div>

    <div class="reviews-slider fade-in">
      <div class="reviews-track">
        <div class="review-box">
          <div class="nazar-icon">🧿</div>
          <div class="stars">★★★★★</div>
          <p class="review-text">« Alors là c'est une vraie pépite ! Un restau tunisien à deux pas de chez moi ! J'ai
            foncé direct, on retrouve vraiment le goût du bled. »</p>
          <div class="author-name">Essia A.</div>
          <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google
          </p>
        </div>
        <div class="review-box">
          <div class="nazar-icon">🧿</div>
          <div class="stars">★★★★★</div>
          <p class="review-text">« Le couscous est incroyable, la viande fond dans la bouche ! L'accueil est chaleureux
            comme à Tunis. Je recommande à 1000%. »</p>
          <div class="author-name">Karim B.</div>
          <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google
          </p>
        </div>
        <div class="review-box">
          <div class="nazar-icon">🧿</div>
          <div class="stars">★★★★★</div>
          <p class="review-text">« Les meilleures bricks de la région ! Croustillantes et bien garnies. L'ambiance et la
            décoration nous font voyager. »</p>
          <div class="author-name">Sophie M.</div>
          <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google
          </p>
        </div>

        <div class="review-box">
          <div class="nazar-icon">🧿</div>
          <div class="stars">★★★★★</div>
          <p class="review-text">« Alors là c'est une vraie pépite ! Un restau tunisien à deux pas de chez moi ! J'ai
            foncé direct, on retrouve vraiment le goût du bled. »</p>
          <div class="author-name">Essia A.</div>
          <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google
          </p>
        </div>
        <div class="review-box">
          <div class="nazar-icon">🧿</div>
          <div class="stars">★★★★★</div>
          <p class="review-text">« Le couscous est incroyable, la viande fond dans la bouche ! L'accueil est chaleureux
            comme à Tunis. Je recommande à 1000%. »</p>
          <div class="author-name">Karim B.</div>
          <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google
          </p>
        </div>
        <div class="review-box">
          <div class="nazar-icon">🧿</div>
          <div class="stars">★★★★★</div>
          <p class="review-text">« Les meilleures bricks de la région ! Croustillantes et bien garnies. L'ambiance et la
            décoration nous font voyager. »</p>
          <div class="author-name">Sophie M.</div>
          <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google
          </p>
        </div>
      </div>
    </div>
  </section>

  <section class="menu-section" id="menu">
    <div class="section-header fade-in">
      <h2>Les Saveurs de la Tunisie</h2>
      <div class="divider-tunisian">
        <div class="divider-line"></div>
        <div class="rub-el-hizb"></div>
        <div class="divider-line"></div>
      </div>
      <p>Des recettes ancestrales, des épices de tunisien, de l'huile d'olive extra. Tout est fait maison avec l'amour
        des grands-mères tunisiennes.</p>
    </div>

    <div class="menu-categories fade-in">
      <button class="cat-btn active" data-cat="all" onclick="filterMenu('all', event)">La Carte Complète</button>
      <button class="cat-btn" data-cat="pdj" onclick="filterMenu('pdj', event)" style="border-color: var(--medina-gold); color: var(--sidi-blue); font-weight: 800;">✨ Plat du jour</button>
      <button class="cat-btn" data-cat="Entrées" onclick="filterMenu('Entrées', event)">Entrées</button>
      <button class="cat-btn" data-cat="Plats Tunisiens" onclick="filterMenu('Plats Tunisiens', event)">Plats Mijotés</button>
      <button class="cat-btn" data-cat="Sandwiches Tunisiens" onclick="filterMenu('Sandwiches Tunisiens', event)">Sandwiches</button>
      <button class="cat-btn" data-cat="Boissons" onclick="filterMenu('Boissons', event)">Boissons & Desserts</button>
    </div>

    <div class="menu-grid" id="menuGrid">
    </div>
  </section>

  <section class="specialties" id="specialites">
    <div class="section-header fade-in">
      <h2>Notre Héritage</h2>
      <div class="divider-tunisian">
        <div class="divider-line"></div>
        <div class="rub-el-hizb"></div>
        <div class="divider-line"></div>
      </div>
    </div>

    <div class="spec-grid fade-in">
      <div class="spec-item">
        <div class="icon-wrapper">🥘</div>
        <h3>Le Couscous aux 7 Épices</h3>
        <p>Grains roulés à la main, bouillon rougeoyant infusé au ras-el-hanout, légumes fondants et viande tendre.
          L'âme du dimanche tunisien.</p>
      </div>
      <div class="spec-item">
        <div class="icon-wrapper">🌶️</div>
        <h3>L'Art de la Harissa</h3>
        <p>Nous broyons nos propres piments séchés au soleil avec de l'ail, du carvi et de la coriandre. Un feu
          aromatique irrésistible.</p>
      </div>
      <div class="spec-item">
        <div class="icon-wrapper">🫓</div>
        <h3>Malsouka Artisanale</h3>
        <p>Nos feuilles de brik sont faites à la main sur une plaque de cuivre. Résultat : un croustillant parfait et
          léger à chaque bouchée.</p>
      </div>
    </div>
  </section>

  <section class="contact-section" id="contact">
    <div class="section-header fade-in" style="position:relative; z-index:2;">
      <h2>Nous Trouver</h2>
      <div class="divider-tunisian">
        <div class="divider-line"></div>
        <div class="rub-el-hizb"></div>
        <div class="divider-line"></div>
      </div>
    </div>

    <div class="contact-container fade-in">
      <div class="contact-info">
        <h3>Informations Pratiques</h3>
        <div class="contact-detail">
          <div class="contact-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-telephone"
              viewBox="0 0 16 16">
              <path
                d="M3.654 1.328a.678.678 0 0 0-1.015-.063L1.605 2.3c-.483.484-.661 1.169-.45 1.77a17.6 17.6 0 0 0 4.168 6.608 17.6 17.6 0 0 0 6.608 4.168c.601.211 1.286.033 1.77-.45l1.034-1.034a.678.678 0 0 0-.063-1.015l-2.307-1.794a.68.68 0 0 0-.58-.122l-2.19.547a1.75 1.75 0 0 1-1.657-.459L5.482 8.062a1.75 1.75 0 0 1-.46-1.657l.548-2.19a.68.68 0 0 0-.122-.58zM1.884.511a1.745 1.745 0 0 1 2.612.163L6.29 2.98c.329.423.445.974.315 1.494l-.547 2.19a.68.68 0 0 0 .178.643l2.457 2.457a.68.68 0 0 0 .644.178l2.189-.547a1.75 1.75 0 0 1 1.494.315l2.306 1.794c.829.645.905 1.87.163 2.611l-1.034 1.034c-.74.74-1.846 1.065-2.877.702a18.6 18.6 0 0 1-7.01-4.42 18.6 18.6 0 0 1-4.42-7.009c-.362-1.03-.037-2.137.703-2.877z" />
            </svg>
          </div>
          <span>01 23 45 67 89</span>
        </div>
        <div class="contact-detail">
          <div class="contact-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-geo-alt"
              viewBox="0 0 16 16">
              <path
                d="M12.166 8.94c-.524 1.062-1.234 2.12-1.96 3.07A32 32 0 0 1 8 14.58a32 32 0 0 1-2.206-2.57c-.726-.95-1.436-2.008-1.96-3.07C3.304 7.867 3 6.862 3 6a5 5 0 0 1 10 0c0 .862-.305 1.867-.834 2.94M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10" />
              <path d="M8 8a2 2 0 1 1 0-4 2 2 0 0 1 0 4m0 1a3 3 0 1 0 0-6 3 3 0 0 0 0 6" />
            </svg>
          </div>
          <span>18 Rue du Maréchal Foch, 78250 Meulan-en-Yvelines</span>
        </div>
        <div class="contact-detail" style="align-items: flex-start;">
          <div class="contact-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-clock"
              viewBox="0 0 16 16">
              <path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z" />
              <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0" />
            </svg>
          </div>
          <div>
            <strong style="display:block; margin-bottom:5px; color:var(--sidi-dark);">Horaires d'ouverture :</strong>
            Tous les jours<br>
            11h00 - 14h00<br>
            18h00 - 20h00
          </div>
        </div>

        <div class="social-wrapper">
          <a href="#" class="social-icon" title="Facebook">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
              <path
                d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951" />
            </svg>
          </a>
          <a href="#" class="social-icon" title="Instagram">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
              <path
                d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.9 3.9 0 0 0-1.417.923A3.9 3.9 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.851-.04 1.434-.174 1.943-.372a3.9 3.9 0 0 0 1.416-.923c.445-.445.718-.891.923-1.417.197-.509.332-1.09.372-1.942C15.99 10.445 16 10.173 16 8s-.01-2.445-.048-3.299c-.04-.851-.175-1.433-.372-1.941a3.9 3.9 0 0 0-.923-1.417A3.9 3.9 0 0 0 13.24.42c-.51-.198-1.092-.333-1.943-.372C10.443.01 10.172 0 7.998 0zm-.717 1.442h.718c2.136 0 2.389.007 3.232.046.78.035 1.204.166 1.486.275.373.145.64.319.92.599s.453.546.598.92c.11.281.24.705.275 1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.5 2.5 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-1.096-.046-3.233s.008-2.388.046-3.231c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92s.546-.453.92-.598c.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92m-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217m0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334" />
            </svg>
          </a>
          <a href="#" class="social-icon" title="Snapchat">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
              <path
                d="M15.943 11.526c-.111-.303-.323-.465-.564-.599a1 1 0 0 0-.123-.064l-.219-.111c-.752-.399-1.339-.902-1.746-1.498a3.4 3.4 0 0 1-.3-.531c-.034-.1-.032-.156-.008-.207a.3.3 0 0 1 .097-.1c.129-.086.262-.173.352-.231.162-.104.289-.187.371-.245.309-.216.525-.446.66-.702a1.4 1.4 0 0 0 .069-1.16c-.205-.538-.713-.872-1.329-.872a1.8 1.8 0 0 0-.487.065c.006-.368-.002-.757-.035-1.139-.116-1.344-.587-2.048-1.077-2.61a4.3 4.3 0 0 0-1.095-.881C9.764.216 8.92 0 7.999 0s-1.76.216-2.505.641c-.412.232-.782.53-1.097.883-.49.562-.96 1.267-1.077 2.61-.033.382-.04.772-.036 1.138a1.8 1.8 0 0 0-.487-.065c-.615 0-1.124.335-1.328.873a1.4 1.4 0 0 0 .067 1.161c.136.256.352.486.66.701.082.058.21.14.371.246l.339.221a.4.4 0 0 1 .109.11c.026.053.027.11-.012.217a3.4 3.4 0 0 1-.295.52c-.398.583-.968 1.077-1.696 1.472-.385.204-.786.34-.955.8-.128.348-.044.743.28 1.075q.18.189.409.31a4.4 4.4 0 0 0 1 .4.7.7 0 0 1 .202.09c.118.104.102.26.259.488q.12.178.296.3c.33.229.701.243 1.095.258.355.014.758.03 1.217.18.19.064.389.186.618.328.55.338 1.305.802 2.566.802 1.262 0 2.02-.466 2.576-.806.227-.14.424-.26.609-.321.46-.152.863-.168 1.218-.181.393-.015.764-.03 1.095-.258a1.14 1.14 0 0 0 .336-.368c.114-.192.11-.327.217-.42a.6.6 0 0 1 .19-.087 4.5 4.5 0 0 0 1.014-.404c.16-.087.306-.2.429-.336l.004-.005c.304-.325.38-.709.256-1.047m-1.121.602c-.684.378-1.139.337-1.493.565-.3.193-.122.61-.34.76-.269.186-1.061-.012-2.085.326-.845.279-1.384 1.082-2.903 1.082s-2.045-.801-2.904-1.084c-1.022-.338-1.816-.14-2.084-.325-.218-.15-.041-.568-.341-.761-.354-.228-.809-.187-1.492-.563-.436-.24-.189-.39-.044-.46 2.478-1.199 2.873-3.05 2.89-3.188.022-.166.045-.297-.138-.466-.177-.164-.962-.65-1.18-.802-.36-.252-.52-.503-.402-.812.082-.214.281-.295.49-.295a1 1 0 0 1 .197.022c.396.086.78.285 1.002.338q.04.01.082.011c.118 0 .16-.06.152-.195-.026-.433-.087-1.277-.019-2.066.094-1.084.444-1.622.859-2.097.2-.229 1.137-1.22 2.93-1.22 1.792 0 2.732.987 2.931 1.215.416.475.766 1.013.859 2.098.068.788.009 1.632-.019 2.065-.01.142.034.195.152.195a.4.4 0 0 0 .082-.01c.222-.054.607-.253 1.002-.338a1 1 0 0 1 .197-.023c.21 0 .409.082.49.295.117.309-.04.56-.401.812-.218.152-1.003.638-1.18.802-.184.169-.16.3-.139.466.018.14.413 1.991 2.89 3.189.147.073.394.222-.041.464" />
            </svg>
          </a>
          <a href="#" class="social-icon" title="TikTok">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
              <path
                d="M9 0h1.98c.144.715.54 1.617 1.235 2.512C12.895 3.389 13.797 4 15 4v2c-1.753 0-3.07-.814-4-1.829V11a5 5 0 1 1-5-5v2a3 3 0 1 0 3 3z" />
            </svg>
          </a>
        </div>

      </div>
      <div class="map-container">
        <iframe
          src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2617.962059345228!2d1.9054707767355153!3d49.00185967135805!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47e6ed1ab5c2eb7d%3A0xc6fb042f9ef03a!2s18%20Rue%20du%20Mar%C3%A9chal%20Foch%2C%2078250%20Meulan-en-Yvelines!5e0!3m2!1sfr!2sfr!4v1716301234567!5m2!1sfr!2sfr"
          allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>
    </div>
  </section>

  <footer class="site-footer">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="footer-logo">BlueBee<span>TN</span></div>
        <div class="tunisian-flag-bar"></div>
        <p>L'âme de la Tunisie, servie avec passion. Venez découvrir des saveurs authentiques et un savoir-faire
          culinaire hérité de nos grands-mères.</p>
      </div>

      <div class="footer-section">
        <h4>Navigation</h4>
        <ul>
          <li><a href="#accueil">Accueil</a></li>
          <li><a href="#menu">Notre Carte</a></li>
          <li><a href="#specialites">Nos Spécialités</a></li>
          <li><a href="#avis">Livre d'Or</a></li>
        </ul>
      </div>

      <div class="footer-section">
        <h4>Informations</h4>
        <ul>
          <li><a href="#contact">Contact & Horaires</a></li>
          <li><a href="mentions-legales.php">Mentions Légales</a></li>
          <li><a href="cgv.php">Conditions de Vente</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <p>© 2026 BlueBeeTN. Tous droits réservés.</p>
    </div>
  </footer>

  <div class="cart-fab" onclick="toggleCart()">
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bi bi-basket"
      viewBox="0 0 16 16">
      <path
        d="M5.757 1.071a.5.5 0 0 1 .172.686L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217L5.07 1.243a.5.5 0 0 1 .686-.172zM2 9v4.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V9zM1 7v1h14V7zm3 3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 4 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 6 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 8 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5" />
    </svg>
    <div class="cart-badge" id="cartCount">0</div>
  </div>

  <div class="overlay" id="overlay" onclick="toggleCart()"></div>

  <div class="cart-panel" id="cartPanel">
    <div class="cart-header">
      <h3>Votre Couffin</h3>
      <button class="close-cart" onclick="toggleCart()">✕</button>
    </div>
    <div class="cart-scroll-area">
      <div class="cart-body" id="cartBody">
        <div style="text-align:center; padding:6rem 1rem; color:var(--text-muted);">
          <div style="display:flex; justify-content:center; margin-bottom:1.5rem; opacity:0.5;">
            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor" viewBox="0 0 16 16">
              <path
                d="M5.757 1.071a.5.5 0 0 1 .172.686L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217L5.07 1.243a.5.5 0 0 1 .686-.172zM2 9v4.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V9zM1 7v1h14V7zm3 3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 4 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 6 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 8 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5" />
            </svg>
          </div>
          <p style="font-size:1.4rem; font-weight:700;">Votre couffin est vide.</p>
          <p style="margin-top:0.5rem;">Remplissez-le de délices !</p>
        </div>
      </div>
      <div class="cart-footer">
        <div class="cart-total">
          <span>Total</span>
          <span id="cartTotal">0,00 €</span>
        </div>

        <div id="formClientInfo"
          style="display:none; position:relative; margin-bottom:10px; padding-top:10px; border-top: 1px dashed #e0e0e0;">
          <button class="close-form-btn" onclick="retourPanier()" aria-label="Retour">✕</button>
          <p style="margin-bottom: 15px; font-weight: 700; color: var(--sidi-dark); font-size: 1.1rem; padding-right: 30px;">
            Options de retrait :</p>
          
          <div class="order-type-toggle">
            <button type="button" class="order-type-btn active" id="btnTypeAsap" onclick="switchOrderType('asap')">
              <span class="label">Maintenant</span>
              <span class="sub">Au plus vite</span>
            </button>
            <button type="button" class="order-type-btn" id="btnTypeScheduled" onclick="switchOrderType('scheduled')">
              <span class="label">Planifier</span>
              <span class="sub">Choisir l'heure</span>
            </button>
          </div>

          <div id="asapInfoBox">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
              <span style="font-size: 0.85rem; color: #166534;">Prêt estimé à :</span>
              <span id="syncPulse" style="width:8px; height:8px; background:var(--olive-green); border-radius:50%; opacity:0.3; transition:0.3s;" title="Synchronisé en temps réel"></span>
            </div>
            <span class="ready-time" id="asapReadyTime">--:--</span>
          </div>

          <div id="scheduledBox" style="display:none;">
            <select id="clientHeure" onchange="checkActualAvailability()"></select>
          </div>

          <div id="availabilityInfo" style="margin-top:5px; font-size:0.8rem; font-weight:800; color:var(--harissa-red); min-height:1.2rem; text-align:center;"></div>

          <p style="margin-top: 15px; margin-bottom: 10px; font-weight: 700; color: var(--sidi-dark); font-size: 1.1rem;">
            Vos coordonnées :</p>
          <input type="text" id="clientNom" placeholder="Votre Nom" required>
          <input type="tel" id="clientTel" placeholder="Votre Numéro" required>

          <textarea id="clientNote" placeholder="Une précision ? (ex: sans oignons, bien cuit...)" rows="2"></textarea>
        </div>

        <button id="btnOrderMain" class="btn-order" onclick="passerEtapeSuivante()"
          style="display:none;">Commandez</button>
      </div>
    </div>
  </div>

  <script>
    const menuData = <?php echo $json_menu; ?>;
    const currentPdjId = <?php echo $current_pdj_id; ?>;
    // --- SYSTÈME DE NOTIFICATIONS MAJESTIC (ROBUSTE) ---
    function showToast(message, type = 'info') {
      console.log(`[Toast] ${type}: ${message}`);
      const container = document.getElementById('toastContainer');
      
      // Sécurité : si le conteneur n'existe pas, on utilise l'alerte système
      if (!container) {
        alert(message);
        return;
      }
      
      try {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let icon = 'fa-circle-info';
        if (type === 'error') icon = 'fa-circle-exclamation';
        if (type === 'success') icon = 'fa-circle-check';
        if (type === 'warning') icon = 'fa-triangle-exclamation';

        toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${message}</span>`;
        container.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 100);

        setTimeout(() => {
          toast.classList.remove('show');
          setTimeout(() => toast.remove(), 500);
        }, 5000);
      } catch (e) {
        console.error("Erreur Toast:", e);
        alert(message);
      }
    }

    let panier = [];
    let etapeCommande = 1;

    function renderMenu(items) {
      const grid = document.getElementById('menuGrid');
      grid.innerHTML = items.map(item => {
        const isRupture = (item.dispo == 0) || (item.type_stock === 'reel' && item.stock <= 0);
        return `
        <div class="alcove-card fade-in visible" style="${isRupture ? 'filter: grayscale(100%);' : ''}">
          <div class="card-image-wrapper">${isRupture ? '<div class="rupture-badge-overlay">Rupture de Stock</div>' : ''}<img src="${item.img}" alt="${item.nom}" class="card-image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=400&q=80'"></div>
          <div class="alcove-content">
            <h4>${item.nom}</h4>
            <p>${item.desc}</p>
            <div class="price-row">
              <span class="price">${item.prix.toFixed(2).replace('.', ',')} €</span>
              <div id="controls-${item.id}" class="controls-container">
                ${isRupture ? '<button class="add-btn btn-rupture" style="font-size:0.65rem!important" disabled>RUPTURE DE STOCK</button>' : getControlsHTML(item.id)}
              </div>
            </div>
          </div>
        </div>
      `;}).join('');
    }

    function getControlsHTML(id) {
      const existant = panier.find(p => p.id === id);
      const qty = existant ? existant.qty : 0;

      if (qty > 0) {
        return `
        <div class="card-qty-controls">
          <button class="ctrl-btn-card" onclick="modifierQty(${id}, -1)">-</button>
          <span class="qty-display">${qty}</span>
          <button class="ctrl-btn-card" onclick="modifierQty(${id}, 1)">+</button>
        </div>
      `;
      } else {
        return `<button class="add-btn" onclick="ajouter(${id})">+</button>`;
      }
    }

    function updateAllCardControls() {
      menuData.forEach(item => {
        const container = document.getElementById(`controls-${item.id}`);
        if (container) {
          container.innerHTML = getControlsHTML(item.id);
        }
      });
    }

    function filterMenu(cat, event = null) {
      // Gestion de la classe active
      document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
      
      const targetBtn = document.querySelector(`.cat-btn[data-cat="${cat}"]`);
      if (targetBtn) {
        targetBtn.classList.add('active');
      } else if (event) {
        // Fallback si pas de data-cat mais event présent
        event.target.classList.add('active');
      }

      let items = [];
      if (cat === 'all') {
        items = menuData;
      } else if (cat === 'pdj') {
        // Recherche du plat du jour dans les données chargées
        items = menuData.filter(i => i.id == currentPdjId);
        
        // Si non trouvé dans menuData (ex: en rupture), on reste sur le message d'indisponibilité
        if (items.length === 0) {
          document.getElementById('menuGrid').innerHTML = `
            <div style="grid-column: 1 / -1; text-align:center; padding:5rem 2rem; background:white; border-radius:20px; box-shadow:0 10px 30px rgba(0,0,0,0.05); border: 1px dashed var(--medina-gold);">
              <div style="font-size:4rem; margin-bottom:1rem;">🥘</div>
              <h3 style="font-family:'Aref Ruqaa',serif; color:var(--sidi-dark); font-size:2rem; margin-bottom:1rem;">Plat du Jour Indisponible</h3>
              <p style="color:var(--text-muted); font-size:1.1rem;">Le plat du jour est actuellement victime de son succès ou indisponible à la commande.</p>
              <button onclick="filterMenu('all', null)" class="btn-vote-card" style="max-width:250px; margin-top:1.5rem;">Cliquer ici pour voir la suite</button>
            </div>`;
          return;
        }
      } else {
        items = menuData.filter(i => i.cat === cat);
      }
      renderMenu(items);
    }

    function ajouter(id) {
      const produit = menuData.find(i => i.id === id);
      const existant = panier.find(p => p.id === id);
      if (existant) existant.qty++;
      else panier.push({ ...produit, qty: 1 });
      majPanier();
    }

    function modifierQty(id, delta) {
      const item = panier.find(p => p.id === id);
      if (item) {
        item.qty += delta;
        if (item.qty <= 0) panier = panier.filter(p => p.id !== id);
      }
      majPanier();
    }

    function majPanier() {
      const totalQty = panier.reduce((sum, i) => sum + i.qty, 0);
      const totalPrice = panier.reduce((sum, i) => sum + (i.prix * i.qty), 0);

      document.getElementById('cartCount').innerText = totalQty;
      document.getElementById('cartTotal').innerText = totalPrice.toFixed(2).replace('.', ',') + ' €';

      updateAllCardControls();

      const body = document.getElementById('cartBody');
      const formClient = document.getElementById('formClientInfo');
      const btnOrder = document.getElementById('btnOrderMain');

      if (panier.length === 0) {
        body.innerHTML = `
        <div style="text-align:center; padding:4rem 1rem; color:var(--text-muted);">
          <div style="display:flex; justify-content:center; margin-bottom:1.5rem; opacity:0.5;">
            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor" viewBox="0 0 16 16">  <path d="M5.757 1.071a.5.5 0 0 1 .172.686L3.383 6h9.234L10.07 1.757a.5.5 0 1 1 .858-.514L13.783 6H15a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1v4.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 1 13.5V9a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h1.217L5.07 1.243a.5.5 0 0 1 .686-.172zM2 9v4.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V9zM1 7v1h14V7zm3 3a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 4 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 6 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3A.5.5 0 0 1 8 10m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5m2 0a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-1 0v-3a.5.5 0 0 1 .5-.5"/></svg>
          </div>
          <p style="font-size:1.4rem; font-weight:700;">Votre couffin est vide.</p>
          <p style="margin-top:0.5rem;">Remplissez-le de délices !</p>
        </div>`;
        formClient.style.display = 'none';
        btnOrder.style.display = 'none';
        etapeCommande = 1;
        return;
      }

      btnOrder.style.display = 'block';

      if (etapeCommande === 1) {
        formClient.style.display = 'none';
        btnOrder.innerText = 'Commandez';
      } else {
        formClient.style.display = 'block';
        btnOrder.innerText = 'Valider & Payer';
        preparerHoraires();
      }

      body.innerHTML = panier.map((item, index) => `
        <div class="cart-item" style="--d: ${index}">
          <img src="${item.img}" alt="${item.nom}" class="image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?w=400'">
          <div class="info">
            <div class="title">${item.nom}</div>
            <div class="price">${(item.prix * item.qty).toFixed(2).replace('.', ',')} €</div>
          </div>
          <div class="cart-controls">
            <button class="ctrl-btn" onclick="modifierQty(${item.id}, -1)">–</button>
            <span style="font-weight:800; min-width:20px; text-align:center;">${item.qty}</span>
            <button class="ctrl-btn" onclick="modifierQty(${item.id}, 1)">+</button>
          </div>
        </div>
      `).join('');
    }

    let currentOrderType = 'asap';

    function switchOrderType(type) {
      currentOrderType = type;
      document.getElementById('btnTypeAsap').classList.toggle('active', type === 'asap');
      document.getElementById('btnTypeScheduled').classList.toggle('active', type === 'scheduled');
      
      document.getElementById('asapInfoBox').style.display = (type === 'asap' ? 'block' : 'none');
      document.getElementById('scheduledBox').style.display = (type === 'scheduled' ? 'block' : 'none');
      
      checkActualAvailability();
    }

    async function preparerHoraires() {
      if (panier.length === 0) return;
      
      const select = document.getElementById('clientHeure');
      const info = document.getElementById('availabilityInfo');
      const asapTimeSpan = document.getElementById('asapReadyTime');
      const btn = document.getElementById('btnOrderMain');

      info.innerText = "Calcul des disponibilités...";
      
      try {
        const response = await fetch('api_commandes.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'get_full_availability',
            panier: panier.map(i => ({ id: i.id, qty: i.qty }))
          })
        });
        const data = await response.json();
        
        if (data.success) {
          // 1. Gérer ASAP
          if (data.asap) {
            asapTimeSpan.innerText = data.asap.display_time;
            if (currentOrderType === 'asap') {
              info.innerText = ""; 
              btn.disabled = false;
              btn.style.opacity = "1";
            }
          } else {
            asapTimeSpan.innerText = "--:--";
            if (currentOrderType === 'asap') {
              info.style.color = "var(--harissa-red)";
              info.innerText = "Cuisine complète pour le moment";
              btn.disabled = true;
              btn.style.opacity = "0.5";
            }
          }

          // 2. Gérer les créneaux
          const existingValue = select.value;
          select.innerHTML = '';
          data.slots.forEach(slot => {
            const opt = document.createElement('option');
            opt.value = slot.time;
            opt.text = slot.available ? slot.display : `${slot.display} (Complet 🛑)`;
            opt.disabled = !slot.available;
            if (slot.time === existingValue) opt.selected = true;
            select.appendChild(opt);
          });
          
          if (currentOrderType === 'scheduled') {
            checkActualAvailability();
          }
        } else {
          info.innerText = data.message || "Erreur de disponibilité";
        }
      } catch (e) {
        info.innerText = "Erreur de connexion";
      }
    }

    function checkActualAvailability() {
      const select = document.getElementById('clientHeure');
      const info = document.getElementById('availabilityInfo');
      const btn = document.getElementById('btnOrderMain');

      if (currentOrderType === 'asap') {
        const asapTime = document.getElementById('asapReadyTime').innerText;
        if (asapTime !== "--:--") {
          info.innerText = ""; // On vide pour ne pas faire doublon
          btn.disabled = false;
          btn.style.opacity = "1";
        } else {
          info.style.color = "var(--harissa-red)";
          info.innerText = "Cuisine complète pour le moment";
          btn.disabled = true;
          btn.style.opacity = "0.5";
        }
      } else {
        const selected = select.options[select.selectedIndex];
        if (selected && !selected.disabled) {
          info.style.color = "var(--olive-green)";
          info.innerText = `Prêt vers ${selected.value.replace(':', 'h')}`;
          btn.disabled = false;
          btn.style.opacity = "1";
        } else {
          info.style.color = "var(--harissa-red)";
          info.innerText = "Veuillez choisir un créneau disponible";
          btn.disabled = true;
          btn.style.opacity = "0.5";
        }
      }
    }

    function passerEtapeSuivante() {
      if (etapeCommande === 1) {
        etapeCommande = 2;
        majPanier();
      } else {
        commander();
      }
    }

    function retourPanier() {
      etapeCommande = 1;
      majPanier();
    }

    let availabilityInterval = null;
    
    function toggleCart() {
      const panel = document.getElementById('cartPanel');
      panel.classList.toggle('open');
      document.getElementById('overlay').classList.toggle('active');

      if (panel.classList.contains('open')) {
        preparerHoraires();
        // Lancement de la synchro temps réel toutes les 20s
        if (!availabilityInterval) {
          availabilityInterval = setInterval(() => {
            if (panel.classList.contains('open')) {
              const pulse = document.getElementById('syncPulse');
              if(pulse) pulse.style.opacity = "1";
              preparerHoraires().then(() => {
                 setTimeout(() => { if(pulse) pulse.style.opacity = "0.3"; }, 1000);
              });
            }
          }, 20000);
        }
      } else {
        if (availabilityInterval) {
          clearInterval(availabilityInterval);
          availabilityInterval = null;
        }
      }
    }
    
    // On surcharge preparerHoraires pour qu'elle retourne une promesse (facilite le pulse)
    const originalPrepare = preparerHoraires;
    preparerHoraires = async function() {
        await originalPrepare();
    };

    const jsonMenu = <?= $json_menu ?>;
    const orderSettings = <?= $json_settings ?>;
    const slotUsage = <?= $json_usage ?>;
    const stripe = Stripe('<?= STRIPE_PUBLIC_KEY ?>');

    function commander() {
      if (!panier || panier.length === 0) return;

      const elNom = document.getElementById('clientNom');
      const elTel = document.getElementById('clientTel');
      const elNote = document.getElementById('clientNote');
      
      if (!elNom || !elTel) {
          console.error("Champs client introuvables");
          return;
      }

      const nom = elNom.value.trim();
      const tel = elTel.value.trim();
      const note = elNote ? elNote.value.trim() : "";
      
      let heure = 'asap';
      if (currentOrderType === 'scheduled') {
        const elHeure = document.getElementById('clientHeure');
        heure = elHeure ? elHeure.value : 'asap';
      }

      if (nom === "" || tel === "") {
        showToast("Veuillez renseigner votre nom et numéro de téléphone.", 'warning');
        return;
      }

      const info = document.getElementById('availabilityInfo');
      const btn = document.getElementById('btnOrderMain');

      if (btn && btn.disabled) {
        showToast((info ? info.innerText : "") || "Ce créneau n'est pas disponible.", 'error');
        return;
      }

      if (btn) {
          btn.innerText = "Redirection...";
          btn.disabled = true;
      }
      
      if (typeof stripe === 'undefined') {
          showToast("Erreur: Le module de paiement n'est pas chargé. Veuillez rafraîchir.", 'error');
          if (btn) { btn.innerText = "Valider & Payer"; btn.disabled = false; }
          return;
      }

      fetch('checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          panier: panier.map(i => ({ id: i.id, qty: i.qty })),
          client: nom,
          tel: tel,
          heure: heure,
          note: note
        })
      })
        .then(response => response.json())
        .then(session => {
          if (session.error) {
            showToast("Détail de l'erreur : " + JSON.stringify(session.error), 'error');
            btn.innerText = "Valider & Payer";
            btn.disabled = false;
          } else {
            return stripe.redirectToCheckout({ sessionId: session.id });
          }
        })
        .catch(error => {
          showToast("Erreur de connexion avec le serveur : " + error.message, 'error');
          btn.innerText = "Valider & Payer";
          btn.disabled = false;
        });
    }

    function verifScroll() {
      document.querySelectorAll('.fade-in').forEach(el => {
        if (el.getBoundingClientRect().top < window.innerHeight - 50) {
          el.classList.add('visible');
        }
      });
    }

    window.addEventListener('scroll', verifScroll);
    verifScroll();

    // Sécurisation du Menu Mobile
    const burgerBtn = document.getElementById('burgerBtn');
    const navLinks = document.getElementById('navLinks');

    if (burgerBtn && navLinks) {
      burgerBtn.addEventListener('click', () => {
        burgerBtn.classList.toggle('active');
        navLinks.classList.toggle('active');
      });

      document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', () => {
          burgerBtn.classList.remove('active');
          navLinks.classList.remove('active');
        });
      });
    }

    // Logique Vote Interactif
    let currentVotedIndex = null;

    function openVoteModal(index, dishName) {
      currentVotedIndex = index;
      document.getElementById('modal-dish-name').innerText = dishName;
      document.getElementById('modalVote').style.display = 'flex';
    }

    function closeVoteModal() {
      document.getElementById('modalVote').style.display = 'none';
      currentVotedIndex = null;
    }

    async function submitVote() {
      const firstName = document.getElementById('voteFirstName').value.trim();
      const phone = document.getElementById('votePhone').value.trim();

      if (!firstName || !phone) {
        showToast('Veuillez remplir tous les champs.', 'warning');
        return;
      }

      try {
        const response = await fetch('api_vote.php?action=vote', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ firstName, phone, choice: currentVotedIndex })
        });
        const result = await response.json();

        if (result.success) {
          const d = new Date();
          const day = d.getDay() || 7;
          d.setDate(d.getDate() + 4 - day);
          const year = d.getFullYear();
          const week = Math.ceil((((d - new Date(year, 0, 1)) / 86400000) + 1) / 7);
          const weekId = year + '_W' + week;
          
          localStorage.setItem('hasVoted_' + weekId, 'true');
          closeVoteModal();
          showResults();
        } else {
          if (result.error && result.error.includes('déjà voté')) {
            // Synchronisation si le serveur dit qu'on a déjà voté
            const d = new Date();
            const day = d.getDay() || 7;
            d.setDate(d.getDate() + 4 - day);
            const year = d.getFullYear();
            const week = Math.ceil((((d - new Date(year, 0, 1)) / 86400000) + 1) / 7);
            const weekId = year + '_W' + week;
            localStorage.setItem('hasVoted_' + weekId, 'true');
            
            closeVoteModal();
            showResults();
          } else {
            showToast(result.error || 'Une erreur est survenue.', 'error');
          }
        }
      } catch (e) {
        showToast('Erreur de connexion au serveur.', 'error');
      }
    }

    async function showResults() {
      document.getElementById('vote-options-ui').style.display = 'none';
      const resUI = document.getElementById('vote-results-ui');
      resUI.style.display = 'block';

      try {
        const response = await fetch('api_vote.php?action=results');
        const data = await response.json();

        if (data.percentages) {
          Object.keys(data.percentages).forEach(idx => {
            const bar = document.getElementById(`bar-${idx}`);
            const span = document.getElementById(`percentage-${idx}`);
            if (bar && span) {
              const val = data.percentages[idx];
              // On laisse un petit délai pour l'animation
              setTimeout(() => {
                bar.style.width = val + '%';
              }, 100);
              span.innerText = val + '%';
            }
          });
        }
      } catch (e) {
        console.error(e);
      }
    }

    function backToVote() {
      document.getElementById('vote-results-ui').style.display = 'none';
      document.getElementById('vote-options-ui').style.display = 'block';
    }

    // Au chargement du site
    window.addEventListener('load', () => {
       // Retour en haut au refresh
       if ('scrollRestoration' in history) {
         history.scrollRestoration = 'manual';
       }
       window.scrollTo(0, 0);

       // Déjà voté cette semaine ?
       const d = new Date();
       const day = d.getDay() || 7;
       d.setDate(d.getDate() + 4 - day);
       const year = d.getFullYear();
       const week = Math.ceil((((d - new Date(year, 0, 1)) / 86400000) + 1) / 7);
       const weekId = year + '_W' + week;
       
       const hasVoted = localStorage.getItem('hasVoted_' + weekId);
       const isVoteMode = <?= $is_vote_mode ? 'true' : 'false' ?>;
       
       if (hasVoted && isVoteMode) {
          showResults();
       }
       
       // Initialisation de la carte
       filterMenu('all');
    });

  </script>
  <div id="toastContainer" class="toast-container"></div>
</body>

</html>