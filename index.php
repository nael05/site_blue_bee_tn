<?php
$host = 'localhost';
$dbname = 'db_restaurant';
$username = 'root';
$password = '';

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT * FROM carte_restaurant");
    // ... le reste du code reste identique ...
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT * FROM carte_restaurant");
    $plats_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $menu_array = [];
    foreach ($plats_db as $plat) {
        $menu_array[] = [
            'id' => (int)$plat['id'],
            'nom' => $plat['nom'],
            'desc' => $plat['description'] ? $plat['description'] : '',
            'prix' => (float)$plat['prix'],
            'img' => 'images/' . $plat['image_url'],
            'cat' => $plat['categorie']
        ];
    }
    $json_menu = json_encode($menu_array);
} catch (PDOException $e) {
    die("Le service est temporairement indisponible.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<script src="https://js.stripe.com/v3/"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BlueBeeTN - L'Âme de la Tunisie</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@300;400;500;700;800&display=swap');

  * { margin: 0; padding: 0; box-sizing: border-box; }

  :root {
    --sidi-blue: #0066b2;
    --sidi-dark: #003a6c;
    --medina-gold: #d4af37;
    --harissa-red: #c1272d;
    --olive-green: #5a6b31;
    --chaux-white: #fdfbf7;
    --terracotta: #cc7755;
    --zellige-light: #e0f2fe;
    --text-dark: #1a1a1a;
    --text-muted: #555555;
  }

  html { scroll-behavior: smooth; }

  body {
    font-family: 'Tajawal', sans-serif;
    background: var(--chaux-white);
    color: var(--text-dark);
    overflow-x: hidden;
    width: 100%;
    min-height: 100vh;
  }

  h1, h2, h3, .oriental-font {
    font-family: 'Aref Ruqaa', serif;
  }

  .zellige-bg {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    opacity: 0.04;
    pointer-events: none;
    z-index: 0;
    background-image: 
      linear-gradient(45deg, var(--sidi-blue) 25%, transparent 25%, transparent 75%, var(--sidi-blue) 75%, var(--sidi-blue)),
      linear-gradient(-45deg, var(--sidi-blue) 25%, transparent 25%, transparent 75%, var(--sidi-blue) 75%, var(--sidi-blue));
    background-size: 60px 60px;
    background-position: 0 0, 30px 30px;
  }

  .rub-el-hizb {
    display: inline-block;
    width: 20px; height: 20px;
    background: var(--medina-gold);
    position: relative;
    margin: 0 10px;
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
    inset: 4px;
    background: var(--chaux-white);
    border-radius: 50%;
    z-index: 1;
  }

  .ceramic-border {
    height: 12px;
    width: 100%;
    background: repeating-linear-gradient(
      90deg,
      var(--sidi-blue),
      var(--sidi-blue) 20px,
      var(--medina-gold) 20px,
      var(--medina-gold) 25px,
      var(--chaux-white) 25px,
      var(--chaux-white) 45px,
      var(--medina-gold) 45px,
      var(--medina-gold) 50px
    );
    border-bottom: 2px solid var(--sidi-dark);
    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
  }

  nav {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 1000;
    background: rgba(253, 251, 247, 0.96);
    backdrop-filter: blur(10px);
  }

  .nav-container {
    padding: 0 2rem;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .nav-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
  }

  .khamsa-icon { font-size: 2.2rem; color: var(--sidi-blue); filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2)); }
  
  .nav-logo span {
    font-family: 'Aref Ruqaa', serif;
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--sidi-dark);
    text-shadow: 1px 1px 0px rgba(212, 175, 55, 0.5);
  }

  .nav-links {
    display: flex;
    gap: 2rem;
    list-style: none;
    align-items: center;
  }

  .nav-links a {
    text-decoration: none;
    color: var(--sidi-dark);
    font-weight: 700;
    font-size: 1.05rem;
    transition: all 0.3s;
    position: relative;
    padding: 5px 0;
  }

  .nav-links a:hover { color: var(--harissa-red); }
  .nav-links a::after {
    content: '';
    position: absolute;
    bottom: 0; left: 50%;
    width: 0; height: 3px;
    background: var(--harissa-red);
    transition: all 0.3s ease;
    transform: translateX(-50%);
    border-radius: 2px;
  }
  .nav-links a:hover::after { width: 100%; }

  .hero {
    position: relative;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding-top: 100px;
    background: radial-gradient(circle at center, var(--chaux-white) 0%, #ebe5d9 100%);
    overflow: hidden;
  }

  .lantern {
    position: absolute;
    font-size: 4rem;
    top: 50px;
    animation: swing 4s ease-in-out infinite alternate;
    transform-origin: top center;
    filter: drop-shadow(0 15px 15px rgba(0,0,0,0.3));
  }
  .lantern.left { left: 15%; }
  .lantern.right { right: 15%; animation-delay: 1s; }

  @keyframes swing {
    0% { transform: rotate(-5deg); }
    100% { transform: rotate(5deg); }
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
    width: 340px;
    margin: 0 auto 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .door-svg-wrap {
    position: relative;
    width: 340px;
    filter: drop-shadow(0 30px 60px rgba(0,0,0,0.35));
  }

  /* Nouvelle classe pour l'image de la porte */
  .door-image {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 8px; /* Adoucit légèrement les coins si besoin */
  }

  /* Floating tunisian decorative elements */
  .deco-float {
    position: absolute;
    font-size: 3.2rem;
    z-index: 5;
    filter: drop-shadow(0 8px 12px rgba(0,0,0,0.2));
    animation: floatDeco 5s ease-in-out infinite alternate;
  }
  .deco-float:nth-child(2) { animation-delay: 1s; animation-duration: 6s; }
  .deco-float:nth-child(3) { animation-delay: 0.5s; animation-duration: 7s; }
  .deco-float:nth-child(4) { animation-delay: 1.5s; animation-duration: 5.5s; }
  .deco-float:nth-child(5) { animation-delay: 2s; animation-duration: 6.5s; }
  .deco-float:nth-child(6) { animation-delay: 0.8s; animation-duration: 7.5s; }

  @keyframes floatDeco {
    0%   { transform: translateY(0) rotate(-5deg); }
    100% { transform: translateY(-15px) rotate(8deg); }
  }

  .d-jasmin-tl  { top: -40px;  left: -70px;  font-size: 3.5rem; }
  .d-jasmin-br  { bottom: 30px; right: -75px; font-size: 2.8rem; transform: rotate(20deg); }
  .d-piment-tr  { top: 20px;   right: -65px; font-size: 3rem; }
  .d-couffin-bl { bottom: -10px; left: -65px; font-size: 3.8rem; }
  .d-olive-ml   { top: 45%;    left: -80px;  font-size: 2.6rem; }
  .d-olive-mr   { top: 55%;    right: -80px; font-size: 2.6rem; animation-direction: alternate-reverse; }

  .hero-title {
    font-size: clamp(3.5rem, 7vw, 5rem);
    color: var(--sidi-dark);
    line-height: 1;
    margin-bottom: 0.5rem;
    text-shadow: 2px 2px 0px rgba(255,255,255,0.8);
  }

  .hero-subtitle {
    font-size: clamp(1.2rem, 2.5vw, 1.6rem);
    color: var(--harissa-red);
    font-weight: 700;
    margin-bottom: 2rem;
    font-family: 'Aref Ruqaa', serif;
  }

  .btn {
    padding: 18px 40px;
    border-radius: 8px;
    font-family: 'Tajawal', sans-serif;
    font-size: 1.1rem;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 12px;
    border: none;
    text-transform: uppercase;
    letter-spacing: 2px;
    position: relative;
    overflow: hidden;
  }

  .btn-primary {
    background: var(--harissa-red);
    color: white;
    box-shadow: 0 10px 20px rgba(193, 39, 45, 0.3);
    border: 2px solid #9a1f24;
  }
  .btn-primary:hover {
    background: #a12025;
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(193, 39, 45, 0.4);
  }

  section {
    position: relative;
    z-index: 1;
    padding: 7rem 2rem;
  }

  .section-header {
    text-align: center;
    margin-bottom: 5rem;
  }

  .section-header h2 {
    font-size: clamp(3rem, 6vw, 4rem);
    color: var(--sidi-dark);
    margin-bottom: 1rem;
    position: relative;
    display: inline-block;
  }

  .section-header h2::before, .section-header h2::after {
    content: '🌿';
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 2rem;
    opacity: 0.8;
  }
  .section-header h2::before { left: -50px; }
  .section-header h2::after { right: -50px; transform: translateY(-50%) scaleX(-1); }

  .divider-tunisian {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 1.5rem 0;
  }
  .divider-line {
    width: 100px;
    height: 3px;
    background: var(--medina-gold);
  }

  .section-header p {
    color: var(--text-muted);
    max-width: 700px;
    margin: 0 auto;
    font-size: 1.2rem;
    line-height: 1.7;
    font-weight: 500;
  }

  .menu-section {
    background: #fff;
    border-top: 2px solid var(--medina-gold);
    border-bottom: 2px solid var(--medina-gold);
    background-image: radial-gradient(circle at 10px 10px, rgba(0, 102, 178, 0.05) 2px, transparent 0);
    background-size: 40px 40px;
  }

  .menu-categories {
    display: flex;
    justify-content: center;
    gap: 1.5rem;
    margin-bottom: 4rem;
    flex-wrap: wrap;
  }

  .cat-btn {
    padding: 12px 30px;
    border-radius: 5px;
    border: 2px solid var(--sidi-blue);
    background: var(--chaux-white);
    color: var(--sidi-dark);
    font-family: 'Tajawal', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 4px 4px 0px rgba(0, 102, 178, 0.2);
  }

  .cat-btn.active, .cat-btn:hover {
    background: var(--sidi-blue);
    color: white;
    transform: translate(-2px, -2px);
    box-shadow: 6px 6px 0px rgba(212, 175, 55, 0.6);
  }

  .menu-grid {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 3rem;
  }

  .alcove-card {
    background: var(--chaux-white);
    border-radius: 120px 120px 10px 10px;
    padding: 3rem 1.5rem 2rem;
    text-align: center;
    box-shadow: 
      inset 0 10px 20px rgba(0,0,0,0.03),
      0 15px 35px rgba(0,0,0,0.08);
    position: relative;
    border: 1px solid #ebe5d9;
    border-top: 10px solid var(--sidi-blue);
    transition: all 0.4s;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .alcove-card::before {
    content: '';
    position: absolute;
    top: 15px; left: 15px; right: 15px; bottom: 15px;
    border-radius: 105px 105px 5px 5px;
    border: 2px dashed var(--medina-gold);
    pointer-events: none;
    opacity: 0.6;
  }

  .alcove-card:hover {
    transform: translateY(-10px);
    border-top-color: var(--harissa-red);
    box-shadow: 0 25px 50px rgba(0,0,0,0.12);
  }

  .card-image {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    object-fit: cover;
    margin-bottom: 1.5rem;
    border: 4px solid var(--medina-gold);
    box-shadow: 0 10px 20px rgba(0,0,0,0.15);
    position: relative;
    z-index: 2;
  }

  .alcove-card h4 {
    font-family: 'Aref Ruqaa', serif;
    font-size: 1.8rem;
    color: var(--sidi-dark);
    margin-bottom: 0.8rem;
    position: relative;
    z-index: 2;
  }

  .alcove-card p {
    font-size: 1rem;
    color: var(--text-muted);
    line-height: 1.6;
    margin-bottom: 1.5rem;
    min-height: 50px;
    font-weight: 500;
  }

  .alcove-card .price-row {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(212, 175, 55, 0.3);
    position: relative;
    z-index: 2;
  }

  .alcove-card .price {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--harissa-red);
    font-family: 'Aref Ruqaa', serif;
  }

  .add-btn {
    width: 45px;
    height: 45px;
    border-radius: 8px;
    background: var(--sidi-blue);
    color: white;
    border: 2px solid var(--sidi-dark);
    font-size: 1.5rem;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 3px 3px 0px var(--medina-gold);
  }

  .add-btn:hover {
    background: var(--harissa-red);
    border-color: #9a1f24;
    transform: translate(-2px, -2px);
    box-shadow: 5px 5px 0px var(--medina-gold);
  }

  .specialties {
    background: #001a33;
    color: var(--chaux-white);
    position: relative;
  }

  .specialties::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle at 50% 50%, rgba(212, 175, 55, 0.05) 0%, transparent 60%);
    pointer-events: none;
  }

  .specialties .section-header h2 { color: var(--medina-gold); }
  .specialties .section-header p { color: #aab8c2; }

  .spec-grid {
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3rem;
  }

  .spec-item {
    text-align: center;
    padding: 2rem;
    position: relative;
  }

  .spec-item .icon-wrapper {
    width: 120px;
    height: 120px;
    margin: 0 auto 2rem;
    background: rgba(0, 102, 178, 0.2);
    border: 3px solid var(--medina-gold);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    position: relative;
    box-shadow: 0 0 30px rgba(212, 175, 55, 0.2);
  }

  .spec-item .icon-wrapper::after {
    content: '۞';
    position: absolute;
    bottom: -15px;
    color: var(--medina-gold);
    font-size: 1.5rem;
    background: #001a33;
    padding: 0 5px;
  }

  .spec-item h3 { 
    font-size: 2rem; 
    color: var(--chaux-white); 
    margin-bottom: 1rem; 
  }
  
  .spec-item p { 
    color: #8c9ca8; 
    font-size: 1.1rem; 
    line-height: 1.6; 
  }

  .reviews {
    background: var(--chaux-white);
    border-top: 10px solid var(--harissa-red);
    position: relative;
  }

  .review-box {
    max-width: 850px;
    margin: 0 auto;
    background: #fff;
    border: 2px solid var(--sidi-blue);
    padding: 4rem;
    text-align: center;
    position: relative;
    box-shadow: 15px 15px 0px rgba(0, 102, 178, 0.1);
  }

  .review-box::before, .review-box::after {
    content: '۞';
    position: absolute;
    font-size: 2rem;
    color: var(--harissa-red);
  }
  .review-box::before { top: 10px; left: 15px; }
  .review-box::after { bottom: 10px; right: 15px; }

  .nazar-icon {
    position: absolute;
    top: -35px; left: 50%;
    transform: translateX(-50%);
    font-size: 4rem;
    filter: drop-shadow(0 5px 10px rgba(0,0,0,0.2));
  }

  .stars {
    font-size: 2rem;
    color: var(--medina-gold);
    margin-bottom: 1.5rem;
    letter-spacing: 2px;
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

  footer {
    background: var(--sidi-dark);
    color: var(--chaux-white);
    text-align: center;
    position: relative;
  }

  .footer-content {
    padding: 5rem 2rem 3rem;
    position: relative;
    z-index: 2;
  }

  .footer-logo {
    font-family: 'Aref Ruqaa', serif;
    font-size: 3rem;
    color: var(--chaux-white);
    margin-bottom: 1.5rem;
    text-shadow: 0 2px 10px rgba(0,0,0,0.5);
  }
  .footer-logo span { color: var(--medina-gold); }

  .tunisian-flag-bar {
    width: 60px; height: 4px;
    background: var(--harissa-red);
    margin: 0 auto 2rem;
    position: relative;
  }
  .tunisian-flag-bar::after {
    content: '☪';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    color: var(--harissa-red);
    background: var(--sidi-dark);
    padding: 0 10px;
    font-size: 1.5rem;
  }

  .cart-fab {
    position: fixed;
    bottom: 30px; right: 30px;
    width: 70px; height: 70px;
    border-radius: 50%;
    background: var(--harissa-red);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    cursor: pointer;
    z-index: 999;
    box-shadow: 0 10px 20px rgba(193, 39, 45, 0.4), inset 0 0 0 2px var(--medina-gold);
    transition: all 0.3s;
  }

  .cart-fab:hover { transform: scale(1.1) rotate(-5deg); }

  .cart-badge {
    position: absolute;
    top: -5px; right: -5px;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--medina-gold);
    color: var(--sidi-dark);
    font-size: 0.9rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--sidi-dark);
  }

  .cart-panel {
    position: fixed;
    top: 0; right: -450px;
    width: 400px; max-width: 100vw;
    height: 100vh;
    background: var(--chaux-white);
    z-index: 1001;
    box-shadow: -15px 0 50px rgba(0,0,0,0.5);
    transition: right 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
    display: flex; flex-direction: column;
    border-left: 8px solid var(--sidi-blue);
  }

  .cart-panel.open { right: 0; }

  .cart-header {
    padding: 2rem;
    background: var(--chaux-white);
    color: var(--sidi-dark);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px dashed var(--medina-gold);
  }
  
  .cart-header h3 { font-family: 'Aref Ruqaa', serif; font-size: 2rem; }

  .close-cart {
    background: none; border: none;
    color: var(--harissa-red); font-size: 2rem;
    cursor: pointer;
  }

  .cart-body {
    flex: 1; overflow-y: auto; padding: 1.5rem;
  }

  .cart-item {
    display: flex; align-items: center; gap: 1rem;
    padding: 1.5rem; margin-bottom: 1rem;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 8px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.03);
  }

  .cart-item .image {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--sidi-blue);
  }

  .cart-item .info { flex: 1; }
  .cart-item .title { font-weight: 800; color: var(--sidi-dark); font-size: 1.1rem; }
  .cart-item .price { color: var(--harissa-red); font-weight: 700; font-size: 1rem; margin-top: 5px;}

  .cart-controls { display: flex; align-items: center; gap: 12px; }
  .ctrl-btn {
    width: 32px; height: 32px;
    border-radius: 5px; border: 2px solid var(--sidi-blue);
    background: var(--chaux-white); cursor: pointer;
    font-weight: bold; color: var(--sidi-blue); font-size: 1.2rem;
  }
  .ctrl-btn:hover { background: var(--sidi-blue); color: white; }

  .cart-footer {
    padding: 2rem;
    border-top: 2px solid var(--medina-gold);
    background: #fff;
  }

  .cart-total {
    display: flex; justify-content: space-between;
    font-size: 1.6rem; font-weight: 800; color: var(--sidi-dark);
    margin-bottom: 1.5rem; font-family: 'Aref Ruqaa', serif;
  }

  .btn-order {
    width: 100%; padding: 18px;
    background: var(--sidi-blue);
    color: white; border: none; border-radius: 8px;
    font-size: 1.2rem; font-weight: 800; font-family: 'Tajawal', sans-serif;
    cursor: pointer; transition: all 0.3s;
    text-transform: uppercase; letter-spacing: 2px;
    box-shadow: 4px 4px 0px var(--medina-gold);
  }
  .btn-order:hover { background: var(--sidi-dark); transform: translate(-2px, -2px); box-shadow: 6px 6px 0px var(--medina-gold); }

  .overlay {
    position: fixed; inset: 0;
    background: rgba(0, 58, 108, 0.8);
    backdrop-filter: blur(5px);
    z-index: 1000; opacity: 0; pointer-events: none;
    transition: opacity 0.3s;
  }
  .overlay.active { opacity: 1; pointer-events: all; }

  @media (max-width: 900px) {
    .nav-links { display: none; }
    .spec-grid { grid-template-columns: 1fr; }
    .door-recess { width: 280px; height: 420px; }
    .hero-title { font-size: 3.5rem; }
  }

  @media (max-width: 600px) {
    .alcove-card { border-radius: 90px 90px 10px 10px; }
    .cart-panel { width: 100vw; }
    .review-box { padding: 3rem 1.5rem; }
  }
  
  .fade-in {
    opacity: 0; transform: translateY(40px);
    transition: all 0.8s cubic-bezier(0.25, 0.8, 0.25, 1);
  }
  .fade-in.visible { opacity: 1; transform: translateY(0); }

</style>
</head>
<body>

<div class="zellige-bg"></div>

<nav>
  <div class="ceramic-border"></div>
  <div class="nav-container">
    <a href="#" class="nav-logo">
      <span class="khamsa-icon">🪬</span>
      <span>BlueBeeTN</span>
    </a>
    <ul class="nav-links">
      <li><a href="#accueil">La Médina</a></li>
      <li><a href="#menu">Notre Carte</a></li>
      <li><a href="#specialites">Héritage</a></li>
      <li><a href="#avis">Livre d'Or</a></li>
    </ul>
  </div>
</nav>

<section class="hero" id="accueil">
  <div class="lantern left">🪔</div>
  <div class="lantern right">🪔</div>
  
  <div class="hero-content fade-in visible">
    
    <div class="door-recess">
      <!-- Tunisian floating decorations -->
      <div class="deco-float d-jasmin-tl">🌸</div>
      <div class="deco-float d-piment-tr">🌶️</div>
      <div class="deco-float d-olive-ml">🫒</div>
      <div class="deco-float d-olive-mr">🫒</div>
      <div class="deco-float d-couffin-bl">🧺</div>
      <div class="deco-float d-jasmin-br">🌸</div>

      <!-- IMAGE DE LA PORTE TUNISIENNE - Intégrée ici -->
      <div class="door-svg-wrap">
        <img src="images/image_porte_tunisienne.png" alt="Porte traditionnelle tunisienne" class="door-image">
      </div>
    </div>

    <h1 class="hero-title">Bienvenue au Pays<br>du Jasmin</h1>
    <p class="hero-subtitle">Une immersion culinaire tunisienne à Meulan-en-Yvelines</p>
    <a href="#menu" class="btn btn-primary">Dégustez la Tradition <span>🐪</span></a>
  </div>
</section>

<section class="menu-section" id="menu">
  <div class="section-header fade-in">
    <h2>Les Saveurs du Souk</h2>
    <div class="divider-tunisian">
      <div class="divider-line"></div>
      <div class="rub-el-hizb"></div>
      <div class="divider-line"></div>
    </div>
    <p>Des recettes ancestrales, des épices de Nabeul, de l'huile d'olive extra de Sfax. Tout est fait maison avec l'amour des grands-mères tunisiennes.</p>
  </div>

  <div class="menu-categories fade-in">
    <button class="cat-btn active" onclick="filterMenu('all')">La Carte Complète</button>
    <button class="cat-btn" onclick="filterMenu('Entrées')">Kemia & Entrées</button>
    <button class="cat-btn" onclick="filterMenu('Plats Tunisiens')">Plats Mijotés</button>
    <button class="cat-btn" onclick="filterMenu('Sandwiches Tunisiens')">Sandwiches</button>
    <button class="cat-btn" onclick="filterMenu('Boissons')">Boissons & Desserts</button>
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
      <p>Grains roulés à la main, bouillon rougeoyant infusé au ras-el-hanout, légumes fondants et viande tendre. L'âme du dimanche tunisien.</p>
    </div>
    <div class="spec-item">
      <div class="icon-wrapper">🌶️</div>
      <h3>L'Art de la Harissa</h3>
      <p>Nous broyons nos propres piments séchés au soleil avec de l'ail, du carvi et de la coriandre. Un feu aromatique irrésistible.</p>
    </div>
    <div class="spec-item">
      <div class="icon-wrapper">🫓</div>
      <h3>Malsouka Artisanale</h3>
      <p>Nos feuilles de brik sont faites à la main sur une plaque de cuivre. Résultat : un croustillant parfait et léger à chaque bouchée.</p>
    </div>
  </div>
</section>

<section class="reviews" id="avis">
  <div class="section-header fade-in">
    <h2>Paroles d'Invités</h2>
    <div class="divider-tunisian">
      <div class="divider-line"></div>
      <div class="rub-el-hizb"></div>
      <div class="divider-line"></div>
    </div>
  </div>

  <div class="review-box fade-in">
    <div class="nazar-icon">🧿</div>
    <div class="stars">★★★★★</div>
    <p class="review-text">
      « Alors là c'est une vraie pépite ! Un restau tunisien à deux pas de chez moi ! Waooo ! J'étais nostalgique et j'ai foncé direct dès l'ouverture aujourd'hui ! On retrouve vraiment le goût du bled. »
    </p>
    <div class="author-name">Essia Ait Belkacem</div>
    <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:5px; font-weight:700;">Avis Vérifié Google</p>
  </div>
</section>

<footer>
  <div class="ceramic-border" style="border-bottom:none; border-top:2px solid var(--sidi-dark);"></div>
  <div class="footer-content">
    <div class="footer-logo">BlueBee<span>TN</span></div>
    <div class="tunisian-flag-bar"></div>
    <p style="font-size:1.2rem; margin-bottom:1rem;">18 Rue du Maréchal Foch, 78250 Meulan-en-Yvelines</p>
    <p style="color:#8c9ca8;">Ouvert tous les jours • Restauration authentique tunisienne</p>
    <p style="font-size:0.9rem; margin-top:3rem; opacity:0.5;">© 2026 BlueBeeTN - L'Âme de la Tunisie.</p>
  </div>
</footer>

<div class="cart-fab" onclick="toggleCart()">
  🏺
  <div class="cart-badge" id="cartCount">0</div>
</div>

<div class="overlay" id="overlay" onclick="toggleCart()"></div>

<div class="cart-panel" id="cartPanel">
  <div class="cart-header">
    <h3>Votre Couffin</h3>
    <button class="close-cart" onclick="toggleCart()">✕</button>
  </div>
  <div class="cart-body" id="cartBody">
    <div style="text-align:center; padding:4rem 1rem; color:var(--text-muted);">
      <div style="font-size:4rem; margin-bottom:1rem; opacity:0.5;">🧺</div>
      <p style="font-size:1.2rem; font-weight:700;">Votre couffin est vide.</p>
      <p>Remplissez-le de délices !</p>
    </div>
  </div>
  <div class="cart-footer">
    <div id="formClientInfo" style="display:none; margin-bottom:15px;">
      <input type="text" id="clientNom" placeholder="Votre Nom" required style="width:100%; padding:8px; margin-bottom:10px; border-radius:4px; border:1px solid #ccc;">
      <input type="tel" id="clientTel" placeholder="Votre Numéro" required style="width:100%; padding:8px; margin-bottom:10px; border-radius:4px; border:1px solid #ccc;">
      <select id="clientHeure" style="width:100%; padding:8px; border-radius:4px; border:1px solid #ccc;"></select>
    </div>
    <div class="cart-total">
      <span>Total</span>
      <span id="cartTotal">0,00 €</span>
    </div>
    <button class="btn-order" onclick="commander()">Préparer ma commande</button>
  </div>
</div>

<script>
  const menuData = <?php echo $json_menu; ?>;
  let panier = [];

  function renderMenu(filtre = 'all') {
    const grid = document.getElementById('menuGrid');
    const items = filtre === 'all' ? menuData : menuData.filter(i => i.cat === filtre);
    
    grid.innerHTML = items.map(item => `
      <div class="alcove-card fade-in visible">
        <img src="${item.img}" alt="${item.nom}" class="card-image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=300&q=80'">
        <h4>${item.nom}</h4>
        <p>${item.desc}</p>
        <div class="price-row">
          <span class="price">${item.prix.toFixed(2).replace('.', ',')} €</span>
          <button class="add-btn" onclick="ajouter(${item.id})">+</button>
        </div>
      </div>
    `).join('');
  }

  function filterMenu(cat) {
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    renderMenu(cat);
  }

  function ajouter(id) {
    const produit = menuData.find(i => i.id === id);
    const existant = panier.find(p => p.id === id);
    if (existant) existant.qty++;
    else panier.push({ ...produit, qty: 1 });
    majPanier();
    
    const btn = event.target;
    const oldText = btn.innerHTML;
    btn.innerHTML = "✓";
    btn.style.background = "var(--olive-green)";
    btn.style.borderColor = "var(--olive-green)";
    setTimeout(() => {
      btn.innerHTML = oldText;
      btn.style.background = "";
      btn.style.borderColor = "";
    }, 500);
  }

  function modifierQty(id, delta) {
    const item = panier.find(p => p.id === id);
    if(item) {
      item.qty += delta;
      if(item.qty <= 0) panier = panier.filter(p => p.id !== id);
    }
    majPanier();
  }

  function majPanier() {
    const totalQty = panier.reduce((sum, i) => sum + i.qty, 0);
    const totalPrice = panier.reduce((sum, i) => sum + (i.prix * i.qty), 0);
    
    document.getElementById('cartCount').innerText = totalQty;
    document.getElementById('cartTotal').innerText = totalPrice.toFixed(2).replace('.', ',') + ' €';

    const body = document.getElementById('cartBody');
    const formClient = document.getElementById('formClientInfo');

    if (panier.length === 0) {
      body.innerHTML = `
        <div style="text-align:center; padding:4rem 1rem; color:var(--text-muted);">
          <div style="font-size:4rem; margin-bottom:1rem; opacity:0.5;">🧺</div>
          <p style="font-size:1.2rem; font-weight:700;">Votre couffin est vide.</p>
          <p>Remplissez-le de délices !</p>
        </div>`;
      formClient.style.display = 'none';
      return;
    }

    formClient.style.display = 'block';
    body.innerHTML = panier.map(item => `
      <div class="cart-item">
        <img src="${item.img}" alt="${item.nom}" class="image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=300&q=80'">
        <div class="info">
          <div class="title">${item.nom}</div>
          <div class="price">${(item.prix * item.qty).toFixed(2).replace('.', ',')} €</div>
        </div>
        <div class="cart-controls">
          <button class="ctrl-btn" onclick="modifierQty(${item.id}, -1)">-</button>
          <span style="font-weight:800; font-size:1.1rem; width:20px; text-align:center;">${item.qty}</span>
          <button class="ctrl-btn" onclick="modifierQty(${item.id}, 1)">+</button>
        </div>
      </div>
    `).join('');
  }

  function preparerHoraires() {
    const select = document.getElementById('clientHeure');
    select.innerHTML = '';
    const now = new Date();

    const minTime = new Date(now.getTime() + 30 * 60000);
    const creneaux = [];
    let isOuvertMaintenant = false;

    const periodes = [
      { hDebut: 11, mDebut: 0, hFin: 14, mFin: 0 },
      { hDebut: 18, mDebut: 0, hFin: 20, mFin: 0 }
    ];

    periodes.forEach(p => {
      let debut = new Date(now);
      debut.setHours(p.hDebut, p.mDebut, 0, 0);
      
      let fin = new Date(now);
      fin.setHours(p.hFin, p.mFin, 0, 0);

      if (now >= debut && now <= fin) {
        isOuvertMaintenant = true;
      }

      let current = new Date(debut);
      while (current <= fin) {
        if (current >= minTime) {
          const h = current.getHours().toString().padStart(2, '0');
          const m = current.getMinutes().toString().padStart(2, '0');
          creneaux.push(`${h}:${m}`);
        }
        current.setMinutes(current.getMinutes() + 15);
      }
    });

    if (creneaux.length === 0 && !isOuvertMaintenant) {
      select.innerHTML = '<option value="">Fermé pour aujourd\'hui</option>';
    } else {
      if (isOuvertMaintenant) {
        select.innerHTML = '<option value="Au plus vite">Au plus vite</option>';
      }
      creneaux.forEach(c => {
        select.innerHTML += `<option value="${c}">${c}</option>`;
      });
    }
  }

  function toggleCart() {
    const panel = document.getElementById('cartPanel');
    panel.classList.toggle('open');
    document.getElementById('overlay').classList.toggle('active');
    
    if(panel.classList.contains('open')) {
      preparerHoraires();
    }
  }

  const stripe = Stripe('<?php echo STRIPE_PUBLIC_KEY; ?>');

  function commander() {
    if(panier.length === 0) return;

    const nom = document.getElementById('clientNom').value.trim();
    const tel = document.getElementById('clientTel').value.trim();
    const heure = document.getElementById('clientHeure').value;

    if(nom === "" || tel === "") {
      alert("Veuillez renseigner votre nom et numéro de téléphone.");
      return;
    }

    if(heure === "") {
      alert("Le restaurant est actuellement fermé.");
      return;
    }
    
    const btn = document.querySelector('.btn-order');
    btn.innerText = "Redirection vers le paiement...";
    btn.disabled = true;

    fetch('checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ 
        panier: panier,
        client: nom,
        tel: tel,
        heure: heure
      })
    })
    .then(response => response.json())
    .then(session => {
      if (session.error) {
        alert("Détail de l'erreur : " + JSON.stringify(session.error));
        btn.innerText = "Préparer ma commande";
        btn.disabled = false;
      } else {
        return stripe.redirectToCheckout({ sessionId: session.id });
      }
    })
    .catch(error => {
      alert("Erreur de connexion avec le serveur : " + error.message);
      btn.innerText = "Préparer ma commande";
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
  renderMenu();
  verifScroll();
</script>
</body>
</html>