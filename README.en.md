# Blue Bee - Catering Platform

**Project developed for a client**

## Description
This project is a custom-made web platform developed for the catering industry (orders, kitchen management, administration, etc.). It is a complete application that allows for menu management, customer order placement (checkout), email sending via Brevo, kitchen management, and automated receipt printing.

## Tech Stack
- **Language**: PHP (Vanilla), HTML, CSS, JavaScript
- **Database**: MySQL
- **Mailing**: Brevo API (formerly Sendinblue)
- **Key Features**:
  - `index.php`: Main customer interface.
  - `admin.php`: Global administration panel.
  - `cuisine.php`: Kitchen staff interface.
  - `checkout.php` / `success.php`: Order process.
  - `print_daemon.php` / `ticket_print.php`: Automatic receipt printing system.

## Installation Prerequisites
- Web Server (Apache/Nginx) with PHP 8+
- MySQL Server
- PDO Extension for PHP

## Installation and Launch
1. Clone this repository.
2. Move the folder to your local server directory (e.g., `htdocs` for XAMPP or `www` for WAMP).
3. Import the database (if an SQL dump is provided, otherwise configure your access).
4. On Windows, you can double-click the `run.bat` file to quickly launch a small local PHP development server, or access the site via your standard local URL (e.g., `http://localhost/site_blue_bee_tn`).

## Project Structure
```
site_blue_bee_tn/
├── admin.php              # Administration interface
├── cuisine.php            # Kitchen order management interface
├── index.php              # Public interface (menu, orders)
├── checkout.php           # Payment/validation page
├── success.php            # Order confirmation page
├── api_commandes.php      # API endpoint for order management
├── mailer_brevo.php       # Brevo API integration for emails
├── print_daemon.php       # Background printing daemon
├── ticket_print.php       # Receipt formatting logic
├── cron_daily_summary.php # Scheduled script for daily summary
├── images/                # Folder containing graphic resources
├── scratch/               # DB maintenance/debug scripts
└── README.md              # Project documentation
```
