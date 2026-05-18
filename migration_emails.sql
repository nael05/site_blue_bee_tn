-- =====================================================================
-- Migration : ajoute le champ email client pour les notifications Brevo
-- À exécuter UNE SEULE FOIS sur la base
-- =====================================================================

ALTER TABLE commandes
    ADD COLUMN client_email VARCHAR(255) NULL AFTER client_tel;

-- Index pour les requetes futures (recap quotidien, etc.)
ALTER TABLE commandes
    ADD INDEX idx_date_statut (date_commande, statut);

-- Une ligne dans commandes_settings pour eviter NULL au premier acces
INSERT INTO commandes_settings (s_key, s_value)
VALUES ('restaurant_email', '')
ON DUPLICATE KEY UPDATE s_value = s_value;
