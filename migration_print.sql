-- =====================================================================
-- Migration : ajoute le suivi d'impression des tickets cuisine
-- À exécuter UNE SEULE FOIS dans phpMyAdmin (base BlueBeeTN)
-- =====================================================================

ALTER TABLE commandes
    ADD COLUMN ticket_imprime TINYINT NOT NULL DEFAULT 0 AFTER statut;

ALTER TABLE commandes
    ADD INDEX idx_print_queue (statut, ticket_imprime);

-- IMPORTANT : on marque toutes les commandes EXISTANTES comme déjà imprimées,
-- sinon le daemon réimprimerait tout l'historique au premier démarrage.
UPDATE commandes SET ticket_imprime = 1;
