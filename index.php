<?php
require_once 'config.php'; 

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>BlueBeeTN - L'Âme de la Tunisie</title>
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
  }

  body {
    font-family: 'Tajawal', sans-serif;
    background: var(--chaux-white);
    color: var(--text-dark);
    overflow-x: hidden;
    width: 100%;
    min-height: 100vh;
    line-height: 1.6;
  }

  h1, h2, h3, .oriental-font {
    font-family: 'Aref Ruqaa', serif;
  }

  .zellige-bg {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    opacity: 0.03;
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
    width: 24px; height: 24px;
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
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  }

  nav {
    position: fixed;
    top: 0; left: 0; right: 0;
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
      filter: drop-shadow(0 4px 6px rgba(0,0,0,0.15)); 
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

  .nav-links a:hover { color: var(--sidi-blue); }
  
  .nav-links a::after {
    content: '';
    position: absolute;
    bottom: 0; left: 50%;
    width: 0; height: 3px;
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
    filter: drop-shadow(0 20px 20px rgba(0,0,0,0.25));
  }
  .lantern.left { left: 12%; }
  .lantern.right { right: 12%; animation-delay: 1.5s; }

  @keyframes swing {
    0% { transform: rotate(-6deg); }
    100% { transform: rotate(6deg); }
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
    font-size: clamp(3.5rem, 8vw, 5.5rem);
    color: var(--sidi-dark);
    line-height: 1.1;
    margin-bottom: 0.8rem;
    text-shadow: 2px 2px 0px rgba(255,255,255,1);
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
    font-size: clamp(2.8rem, 6vw, 4.2rem);
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
    font-size: 2.2rem;
    opacity: 0.6;
  }
  .section-header h2::before { left: -60px; }
  .section-header h2::after { right: -60px; transform: translateY(-50%) scaleX(-1); }

  .divider-tunisian {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 1.5rem 0 2rem 0;
  }
  .divider-line {
    width: 120px;
    height: 2px;
    background: var(--medina-gold);
  }

  .section-header p {
    color: var(--text-muted);
    max-width: 750px;
    margin: 0 auto;
    font-size: 1.25rem;
    line-height: 1.8;
    font-weight: 500;
  }

  .menu-section {
    background: #ffffff;
    background-image: radial-gradient(circle at 10px 10px, rgba(0, 85, 153, 0.04) 2px, transparent 0);
    background-size: 40px 40px;
  }

  .menu-categories {
    display: flex;
    justify-content: center;
    gap: 1rem;
    margin-bottom: 5rem;
    flex-wrap: wrap;
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

  .cat-btn.active, .cat-btn:hover {
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
    box-shadow: 0 15px 35px rgba(0,0,0,0.06);
    position: relative;
    border: 1px solid rgba(0,0,0,0.03);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .alcove-card:hover {
    transform: translateY(-12px);
    box-shadow: 0 25px 45px rgba(0,0,0,0.12);
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

  .alcove-content {
      padding: 0 2rem;
      width: 100%;
      display: flex;
      flex-direction: column;
      flex: 1;
      text-align: center;
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
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
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
      0% { transform: scale(0.8); opacity: 0; }
      100% { transform: scale(1); opacity: 1; }
  }

  .ctrl-btn-card {
      width: 34px; height: 34px;
      border-radius: 8px; border: none;
      background: white; cursor: pointer;
      font-weight: bold; color: var(--sidi-dark); font-size: 1.4rem;
      display: flex; align-items: center; justify-content: center;
      transition: transform 0.2s ease;
  }

  .ctrl-btn-card:hover { 
      transform: scale(1.1); 
  }

  .qty-display {
      font-weight: 800; font-size: 1.2rem;
      color: white; min-width: 20px; text-align: center;
  }

  .specialties {
    background: #0a192f;
    color: var(--chaux-white);
    position: relative;
  }

  .specialties::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle at 50% 50%, rgba(212, 175, 55, 0.08) 0%, transparent 60%);
    pointer-events: none;
  }

  .specialties .section-header h2 { color: var(--medina-gold); }
  .specialties .section-header p { color: #8892b0; }

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
    background: rgba(255,255,255,0.03);
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.05);
    transition: transform 0.3s ease, background 0.3s ease;
  }
  
  .spec-item:hover {
      transform: translateY(-10px);
      background: rgba(255,255,255,0.06);
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
    background: #f8f9fa;
    position: relative;
  }

  .review-box {
    max-width: 900px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 30px;
    padding: 5rem 4rem;
    text-align: center;
    position: relative;
    box-shadow: 0 20px 50px rgba(0,0,0,0.08);
  }

  .review-box::before, .review-box::after {
    content: '۞';
    position: absolute;
    font-size: 2.5rem;
    color: rgba(211, 47, 47, 0.1);
  }
  .review-box::before { top: 20px; left: 25px; }
  .review-box::after { bottom: 20px; right: 25px; }

  .nazar-icon {
    position: absolute;
    top: -45px; left: 50%;
    transform: translateX(-50%);
    font-size: 4.5rem;
    filter: drop-shadow(0 10px 15px rgba(0,0,0,0.15));
  }

  .stars {
    font-size: 2.5rem;
    color: var(--medina-gold);
    margin-bottom: 2rem;
    letter-spacing: 5px;
  }

  .review-text {
    font-family: 'Aref Ruqaa', serif;
    font-size: 2.2rem;
    line-height: 1.7;
    color: var(--sidi-dark);
    margin-bottom: 2.5rem;
  }

  .author-name {
    font-weight: 800;
    color: var(--harissa-red);
    font-size: 1.3rem;
    text-transform: uppercase;
    letter-spacing: 3px;
  }

  footer {
    background: #050d1a;
    color: var(--chaux-white);
    text-align: center;
    position: relative;
  }

  .footer-content {
    padding: 6rem 2rem 4rem;
    position: relative;
    z-index: 2;
  }

  .footer-logo {
    font-family: 'Aref Ruqaa', serif;
    font-size: 3.5rem;
    color: var(--chaux-white);
    margin-bottom: 2rem;
  }
  .footer-logo span { color: var(--medina-gold); }

  .tunisian-flag-bar {
    width: 80px; height: 5px;
    background: var(--harissa-red);
    margin: 0 auto 2.5rem;
    position: relative;
    border-radius: 5px;
  }
  .tunisian-flag-bar::after {
    content: '☪';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    color: var(--harissa-red);
    background: #050d1a;
    padding: 0 12px;
    font-size: 1.8rem;
  }

  .cart-fab {
    position: fixed;
    bottom: 40px; right: 40px;
    width: 75px; height: 75px;
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
    top: -2px; right: -2px;
    width: 30px; height: 30px;
    border-radius: 50%;
    background: var(--chaux-white);
    color: var(--harissa-red);
    font-size: 1rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
  }

  /* OPTIMISATION POUR LES ECRANS DE TELEPHONE */
  .cart-panel {
    position: fixed;
    top: 0; right: -480px;
    width: 450px; max-width: 100vw;
    height: 100vh;
    height: 100dvh; /* Résout le bug de la barre de Safari / Android */
    background: var(--chaux-white);
    z-index: 1001;
    box-shadow: -20px 0 60px rgba(0,0,0,0.2);
    transition: right 0.5s cubic-bezier(0.25, 1, 0.5, 1);
    display: flex; flex-direction: column;
  }

  .cart-panel.open { right: 0; }

  .cart-header {
    padding: 2rem;
    background: var(--chaux-white);
    color: var(--sidi-dark);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(0,0,0,0.05);
  }
  
  .cart-header h3 { font-family: 'Aref Ruqaa', serif; font-size: 2.4rem; margin:0; }

  .close-cart {
    background: #f1f5f9; 
    border: none;
    border-radius: 50%;
    width: 45px; height: 45px;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-dark); font-size: 1.5rem;
    cursor: pointer;
    transition: all 0.3s ease;
  }
  
  .close-cart:hover {
      background: var(--harissa-red);
      color: white;
      transform: rotate(90deg);
  }

  .cart-body {
    flex: 1; overflow-y: auto; padding: 2rem;
  }

  .cart-item {
    display: flex; align-items: center; gap: 1.2rem;
    padding: 1.2rem; margin-bottom: 1rem;
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.03);
    border-radius: 16px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.04);
  }

  .cart-item .image {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
  }

  .cart-item .info { flex: 1; }
  .cart-item .title { font-weight: 800; color: var(--sidi-dark); font-size: 1.1rem; }
  .cart-item .price { color: var(--harissa-red); font-weight: 700; font-size: 1.1rem; margin-top: 4px;}

  .cart-controls { display: flex; align-items: center; gap: 10px; }
  .ctrl-btn {
    width: 32px; height: 32px;
    border-radius: 8px; border: none;
    background: #f1f5f9; cursor: pointer;
    font-weight: bold; color: var(--sidi-dark); font-size: 1.2rem;
    transition: all 0.3s ease;
  }
  .ctrl-btn:hover { background: var(--sidi-blue); color: white; }

  .cart-footer {
    padding: 2rem;
    border-top: 1px solid rgba(0,0,0,0.05);
    background: #ffffff;
    box-shadow: 0 -10px 20px rgba(0,0,0,0.02);
  }

  .cart-total {
    display: flex; justify-content: space-between;
    font-size: 1.8rem; font-weight: 800; color: var(--sidi-dark);
    margin-bottom: 1.5rem; font-family: 'Aref Ruqaa', serif;
  }

  .btn-order {
    width: 100%; padding: 18px;
    background: var(--sidi-blue);
    color: white; border: none; border-radius: 12px;
    font-size: 1.2rem; font-weight: 800; font-family: 'Tajawal', sans-serif;
    cursor: pointer; transition: all 0.3s ease;
    text-transform: uppercase; letter-spacing: 2px;
    box-shadow: 0 10px 20px rgba(0, 85, 153, 0.2);
  }
  .btn-order:hover { 
      background: var(--sidi-dark); 
      transform: translateY(-3px); 
      box-shadow: 0 15px 30px rgba(0, 85, 153, 0.3); 
  }

  .overlay {
    position: fixed; inset: 0;
    background: rgba(0, 26, 51, 0.6);
    backdrop-filter: blur(8px);
    z-index: 1000; opacity: 0; pointer-events: none;
    transition: opacity 0.4s ease;
  }
  .overlay.active { opacity: 1; pointer-events: all; }

  /* Style des champs du formulaire */
  input, select, textarea {
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

  input:focus, select:focus, textarea:focus {
      border-color: var(--sidi-blue);
  }

  /* Bouton croix pour le formulaire */
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

  @media (max-width: 992px) {
    .spec-grid { grid-template-columns: 1fr 1fr; }
  }

  @media (max-width: 768px) {
    .hero {
        padding-top: 80px; 
        min-height: auto;  
    }
    .hero-title {
        font-size: 2.8rem;
        margin-top: 10px;
        margin-bottom: -10px;
    }
    .door-recess {
        width: 250px; 
        margin-top: 0;
    }
    .d-jasmin-tl { top: -10px; left: -20px; font-size: 2rem; }
    .d-piment-tr { top: 0px; right: -20px; font-size: 1.8rem; }
}

  @media (max-width: 600px) {
    .alcove-card { border-radius: 120px 120px 15px 15px; }
    .card-image-wrapper { height: 200px; border-radius: 112px 112px 0 0; margin-bottom: 1.2rem; }
    .alcove-content { padding: 0 1.2rem; }
    .cart-panel { width: 100vw; right: -100vw; }
    .review-box { padding: 4rem 2rem; border-radius: 20px; }
    .menu-categories { gap: 0.5rem; }
    .cat-btn { padding: 10px 20px; font-size: 1rem; }
    .cart-fab { bottom: 25px; right: 25px; width: 65px; height: 65px; font-size: 1.8rem; }
    
    /* MODIFICATIONS RESPONSIVE POUR LE PANIER */
    .cart-header { padding: 1rem 1.2rem; }
    .cart-body { padding: 1rem 1.2rem; }
    .cart-footer { padding: 1rem 1.2rem; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
    .cart-total { margin-bottom: 0.8rem; font-size: 1.4rem; }
    .btn-order { padding: 12px; font-size: 1.1rem; }
    /* Formulaire plus compact */
    input, select, textarea { padding: 10px; margin-bottom: 6px; font-size: 16px; } 
    #formClientInfo { padding-top: 10px; margin-bottom: 10px; }
  }
  
  .fade-in {
    opacity: 0; transform: translateY(50px);
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
  <div class="lantern left">🌶️</div>
  <div class="lantern right">🧺</div>
  
  <div class="hero-content fade-in visible">
    
    <div class="door-recess">
      <div class="deco-float d-jasmin-tl">🌸</div>
      <div class="deco-float d-piment-tr">🌶️</div>
      <div class="deco-float d-olive-ml">🫒</div>
      <div class="deco-float d-olive-mr">🫒</div>
      <div class="deco-float d-couffin-bl">🧺</div>
      <div class="deco-float d-jasmin-br">🌸</div>

      <div class="door-svg-wrap">
        <img src="images/image_porte_tunisienne.png" alt="Porte traditionnelle tunisienne" class="door-image">
      </div>
    </div>

    <h1 class="hero-title">Bienvenue Chez<br>BlueBeeTN</h1>
    <p class="hero-subtitle">Une immersion culinaire tunisienne à Meulan-en-Yvelines</p>
    <a href="#menu" class="btn btn-primary">Menu et commande à emporter <span>🐪</span></a>
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
    <p>Des recettes ancestrales, des épices de tunisien, de l'huile d'olive extra. Tout est fait maison avec l'amour des grands-mères tunisiennes.</p>
  </div>

  <div class="menu-categories fade-in">
    <button class="cat-btn active" onclick="filterMenu('all')">La Carte Complète</button>
    <button class="cat-btn" onclick="filterMenu('Entrées')">Entrées</button>
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
    <p style="color:var(--text-muted); font-size: 0.9rem; margin-top:10px; font-weight:700;">Avis Vérifié Google</p>
  </div>
</section>

<footer>
  <div class="footer-content">
    <div class="footer-logo">BlueBee<span>TN</span></div>
    <div class="tunisian-flag-bar"></div>
    <p style="font-size:1.2rem; margin-bottom:1rem;">18 Rue du Maréchal Foch, 78250 Meulan-en-Yvelines</p>
    <p style="color:#8c9ca8;">Ouvert tous les jours • Restauration authentique tunisienne</p>
    <p style="font-size:0.9rem; margin-top:4rem; opacity:0.4;">© 2026 BlueBeeTN - L'Âme de la Tunisie.</p>
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
    <div style="text-align:center; padding:6rem 1rem; color:var(--text-muted);">
      <div style="font-size:5rem; margin-bottom:1.5rem; opacity:0.5;">🧺</div>
      <p style="font-size:1.4rem; font-weight:700;">Votre couffin est vide.</p>
      <p style="margin-top:0.5rem;">Remplissez-le de délices !</p>
    </div>
  </div>
  <div class="cart-footer">
    <div class="cart-total">
      <span>Total</span>
      <span id="cartTotal">0,00 €</span>
    </div>
    
    <div id="formClientInfo" style="display:none; position:relative; margin-bottom:10px; padding-top:10px; border-top: 1px dashed #e0e0e0;">
      <button class="close-form-btn" onclick="retourPanier()" aria-label="Retour">✕</button>
      <p style="margin-bottom: 10px; font-weight: 700; color: var(--sidi-dark); font-size: 1.1rem; padding-right: 30px;">Vos coordonnées :</p>
      <input type="text" id="clientNom" placeholder="Votre Nom" required>
      <input type="tel" id="clientTel" placeholder="Votre Numéro" required>
      <select id="clientHeure"></select>
      
      <textarea id="clientNote" placeholder="Une précision ? (ex: sans oignons, bien cuit...)" rows="2"></textarea>
    </div>
    
    <button id="btnOrderMain" class="btn-order" onclick="passerEtapeSuivante()" style="display:none;">Commandez</button>
  </div>
</div>

<script>
  const menuData = <?php echo $json_menu; ?>;
  let panier = [];
  let etapeCommande = 1;

  function renderMenu(filtre = 'all') {
    const grid = document.getElementById('menuGrid');
    const items = filtre === 'all' ? menuData : menuData.filter(i => i.cat === filtre);
    
    grid.innerHTML = items.map(item => `
      <div class="alcove-card fade-in visible">
        <div class="card-image-wrapper">
          <img src="${item.img}" alt="${item.nom}" class="card-image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=400&q=80'">
        </div>
        <div class="alcove-content">
          <h4>${item.nom}</h4>
          <p>${item.desc}</p>
          <div class="price-row">
            <span class="price">${item.prix.toFixed(2).replace('.', ',')} €</span>
            <div id="controls-${item.id}" class="controls-container">
              ${getControlsHTML(item.id)}
            </div>
          </div>
        </div>
      </div>
    `).join('');
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

    updateAllCardControls();

    const body = document.getElementById('cartBody');
    const formClient = document.getElementById('formClientInfo');
    const btnOrder = document.getElementById('btnOrderMain');

    if (panier.length === 0) {
      body.innerHTML = `
        <div style="text-align:center; padding:4rem 1rem; color:var(--text-muted);">
          <div style="font-size:5rem; margin-bottom:1.5rem; opacity:0.5;">🧺</div>
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
    }

    body.innerHTML = panier.map(item => `
      <div class="cart-item">
        <img src="${item.img}" alt="${item.nom}" class="image" onerror="this.src='https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=300&q=80'">
        <div class="info">
          <div class="title">${item.nom}</div>
          <div class="price">${(item.prix * item.qty).toFixed(2).replace('.', ',')} €</div>
        </div>
        <div class="cart-controls">
          <button class="ctrl-btn" onclick="modifierQty(${item.id}, -1)">-</button>
          <span style="font-weight:800; font-size:1.1rem; width:25px; text-align:center;">${item.qty}</span>
          <button class="ctrl-btn" onclick="modifierQty(${item.id}, 1)">+</button>
        </div>
      </div>
    `).join('');
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
    const note = document.getElementById('clientNote').value.trim(); // On récupère la note

    if(nom === "" || tel === "") {
      alert("Veuillez renseigner votre nom et numéro de téléphone.");
      return;
    }

    if(heure === "") {
      alert("Le restaurant est actuellement fermé.");
      return;
    }
    
    const btn = document.getElementById('btnOrderMain');
    btn.innerText = "Redirection...";
    btn.disabled = true;

    fetch('checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ 
        panier: panier,
        client: nom,
        tel: tel,
        heure: heure,
        note: note // On l'envoie vers checkout.php
      })
    })
    .then(response => response.json())
    .then(session => {
      if (session.error) {
        alert("Détail de l'erreur : " + JSON.stringify(session.error));
        btn.innerText = "Valider & Payer";
        btn.disabled = false;
      } else {
        return stripe.redirectToCheckout({ sessionId: session.id });
      }
    })
    .catch(error => {
      alert("Erreur de connexion avec le serveur : " + error.message);
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
  renderMenu();
  verifScroll();
</script>
</body>
</html>