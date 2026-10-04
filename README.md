# Blue Bee - Plateforme de Restauration

**Projet développé pour un client**

## Description
Ce projet est une plateforme web sur-mesure développée pour la restauration (commandes, gestion de cuisine, administration, etc.). Il s'agit d'une application complète permettant la gestion des menus, le passage de commandes par les clients (checkout), l'envoi d'e-mails via Brevo, la gestion en cuisine, et l'impression des tickets de caisse de manière automatisée.

## Stack Technique
- **Langage** : PHP (Vanilla), HTML, CSS, JavaScript
- **Base de données** : MySQL
- **Mailing** : API Brevo (anciennement Sendinblue)
- **Fonctionnalités clés** :
  - `index.php` : Interface client principale.
  - `admin.php` : Panneau d'administration global.
  - `cuisine.php` : Interface pour l'équipe en cuisine.
  - `checkout.php` / `success.php` : Processus de commande.
  - `print_daemon.php` / `ticket_print.php` : Système d'impression automatique des tickets.

## Prérequis d'installation
- Serveur Web (Apache/Nginx) avec PHP 8+
- Serveur MySQL
- Extension PDO pour PHP

## Installation et Lancement
1. Clonez ce dépôt.
2. Déplacez le dossier dans le répertoire de votre serveur local (ex: `htdocs` pour XAMPP ou `www` pour WAMP).
3. Importez la base de données (si un dump SQL est fourni, sinon configurez vos accès).
4. Sous Windows, vous pouvez double-cliquer sur le fichier `run.bat` pour lancer un petit serveur de développement PHP local rapidement, ou accéder au site via votre URL locale classique (ex: `http://localhost/site_blue_bee_tn`).

## Arborescence du Projet
```
site_blue_bee_tn/
├── admin.php              # Interface d'administration
├── cuisine.php            # Interface pour la gestion des commandes en cuisine
├── index.php              # Interface publique (menu, commandes)
├── checkout.php           # Page de paiement/validation
├── success.php            # Page de confirmation de commande
├── api_commandes.php      # Endpoint API pour la gestion des commandes
├── mailer_brevo.php       # Intégration de l'API Brevo pour les emails
├── print_daemon.php       # Démon d'impression en tâche de fond
├── ticket_print.php       # Logique de formatage des tickets
├── cron_daily_summary.php # Script planifié pour le résumé quotidien
├── images/                # Dossier contenant les ressources graphiques
├── scratch/               # Scripts de maintenance/debug de la BDD
└── README.md              # Documentation du projet
```
