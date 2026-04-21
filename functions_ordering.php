<?php

/**
 * Calcule le temps total de préparation du panier avec réduction pour les doublons.
 */
function calculerTempsPanier($panier, $reduction_doublon) {
    $temps_total = 0;
    foreach ($panier as $item) {
        $temps_base = $item['temps_prep_min'] ?? 5;
        $qty = (int) $item['qty'];
        if ($qty <= 0) continue;

        // Le 1er prend le temps plein, les suivants prennent le temps réduit
        $temps_ligne = $temps_base + (($qty - 1) * max(0, $temps_base - $reduction_doublon));
        $temps_total += $temps_ligne;
    }
    return $temps_total;
}

/**
 * Vérifie si les articles de type 'reel' sont disponibles en stock.
 */
function verifierStocks($panier, $pdo) {
    foreach ($panier as $item) {
        $stmt = $pdo->prepare("SELECT type_stock, stock_actuel, nom FROM carte_restaurant WHERE id = ?");
        $stmt->execute([$item['id']]);
        $plat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($plat && $plat['type_stock'] === 'reel') {
            if ($plat['stock_actuel'] < $item['qty']) {
                return [
                    'success' => false,
                    'message' => "Désolé, il n'y a plus assez de " . $plat['nom'] . " (Reste : " . $plat['stock_actuel'] . ")"
                ];
            }
        }
    }
    return ['success' => true];
}

/**
 * Algorithme de file d'attente gérant les pistes de la cuisine.
 * Retourne ['heure_debut', 'heure_fin', 'piste_id'] ou false si complet.
 */
function trouverDisponibilite($temps_total_prep, $type_commande, $heure_souhaitee, $pdo) {
    $stmtSet = $pdo->query("SELECT s_value FROM commandes_settings WHERE s_key = 'nombre_pistes_simultanees'");
    $nombre_pistes = (int) ($stmtSet->fetchColumn() ?: 1);

    $now = new DateTime();
    
    if ($type_commande === 'asap') {
        $meilleur_debut = null;
        $meilleure_piste = 1;

        for ($piste = 1; $piste <= $nombre_pistes; $piste++) {
            // Récupérer toutes les commandes à venir sur cette piste, triées
            // On inclut 'en attente' ET 'attente_paiement' (si récent < 15 min)
            $stmt = $pdo->prepare("SELECT heure_debut_prep, heure_fin_estimee FROM commandes 
                                   WHERE DATE(date_commande) = CURDATE() 
                                   AND piste_id = ? 
                                   AND (
                                       statut = 'en attente' 
                                       OR (statut = 'attente_paiement' AND date_commande > NOW() - INTERVAL 15 MINUTE)
                                   )
                                   AND heure_fin_estimee > ?
                                   ORDER BY heure_debut_prep ASC");
            $stmt->execute([$piste, $now->format('Y-m-d H:i:s')]);
            $commandes_piste = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $debut_piste = clone $now;

            foreach ($commandes_piste as $cmd) {
                $cmd_debut = new DateTime($cmd['heure_debut_prep']);
                $cmd_fin = new DateTime($cmd['heure_fin_estimee']);

                // Si l'espace avant cette commande est suffisant
                $interval = $debut_piste->diff($cmd_debut);
                $minutes_libres = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

                if ($minutes_libres >= $temps_total_prep) {
                    break; // On a trouvé un créneau avant cette commande
                } else {
                    $debut_piste = ($cmd_fin > $debut_piste) ? $cmd_fin : $debut_piste;
                }
            }

            if ($meilleur_debut === null || $debut_piste < $meilleur_debut) {
                $meilleur_debut = $debut_piste;
                $meilleure_piste = $piste;
            }
        }

        $heure_fin = clone $meilleur_debut;
        $heure_fin->modify("+$temps_total_prep minutes");

        return [
            'piste_id' => $meilleure_piste,
            'heure_debut' => $meilleur_debut->format('Y-m-d H:i:s'),
            'heure_fin' => $heure_fin->format('Y-m-d H:i:s'),
            'display_time' => $heure_fin->format('H:i')
        ];

    } else {
        // Logique "Planifié" (ex: 13:00)
        $target_fin = new DateTime(date('Y-m-d ') . $heure_souhaitee);
        $target_debut = clone $target_fin;
        $target_debut->modify("-$temps_total_prep minutes");

        if ($target_debut < $now) {
            return false; // Trop tard pour cette heure
        }

        for ($piste = 1; $piste <= $nombre_pistes; $piste++) {
            // Vérifier si la piste est occupée entre target_debut et target_fin
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM commandes 
                                   WHERE DATE(date_commande) = CURDATE() 
                                   AND piste_id = ? 
                                   AND (statut = 'en attente' OR (statut = 'attente_paiement' AND date_commande > NOW() - INTERVAL 15 MINUTE))
                                   AND (
                                       (heure_debut_prep < ? AND heure_fin_estimee > ?)
                                   )");
            $stmt->execute([$piste, $target_fin->format('Y-m-d H:i:s'), $target_debut->format('Y-m-d H:i:s')]);
            
            if ($stmt->fetchColumn() == 0) {
                // Piste libre !
                return [
                    'piste_id' => $piste,
                    'heure_debut' => $target_debut->format('Y-m-d H:i:s'),
                    'heure_fin' => $target_fin->format('Y-m-d H:i:s'),
                    'display_time' => $target_fin->format('H:i')
                ];
            }
        }
    }

    return false; // Complet
}
