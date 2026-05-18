<?php
/**
 * print_daemon.php
 *
 * Daemon d'impression automatique des tickets cuisine BlueBeeTN.
 * Lance par le Planificateur de taches Windows au demarrage du PC.
 * Tourne en arriere-plan sans fenetre, n'interfere PAS avec CLIO :
 * les deux logiciels envoient au spooler Windows qui serialise les jobs.
 *
 * Imprimante : AURES ODP 333 (ESC/POS, USB)
 * Partage Windows requis : \\localhost\TICKET
 */

require_once __DIR__ . '/config.php';

// ============ CONFIGURATION ============
const PRINTER_SHARE  = '\\\\localhost\\TICKET'; // Nom du partage Windows local
const POLL_INTERVAL  = 2;                        // Secondes entre 2 verifications DB
const LOG_FILE       = __DIR__ . '/print_daemon.log';
const TEMP_DIR       = __DIR__ . '/print_tmp';
const TICKET_WIDTH   = 42;                       // Colonnes (Font A sur ODP 333)

// ============ ESC/POS ============
const ESC = "\x1B";
const GS  = "\x1D";
const LF  = "\x0A";

date_default_timezone_set('Europe/Paris');

if (!is_dir(TEMP_DIR)) { @mkdir(TEMP_DIR, 0777, true); }

// ============ LOGGING ============
function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

// ============ DB ============
function connect_db(): PDO {
    while (true) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            return $pdo;
        } catch (PDOException $e) {
            log_msg("ERREUR DB : " . $e->getMessage() . " - retry dans 5s");
            sleep(5);
        }
    }
}

// ============ CONVERSION UTF-8 -> CP858 (Europe + euro) ============
function txt(string $s): string {
    $out = @iconv('UTF-8', 'CP858//TRANSLIT//IGNORE', $s);
    return $out !== false ? $out : $s;
}

// ============ CONSTRUCTION DU TICKET ESC/POS ============
function build_ticket(array $cmd): string {
    $d  = '';

    // Init imprimante + page de code CP858
    $d .= ESC . '@';
    $d .= ESC . 't' . chr(19);

    // -------- HEADER --------
    $d .= ESC . 'a' . chr(1);              // Centre
    $d .= GS  . '!' . chr(0x11);           // Double largeur + hauteur
    $d .= ESC . 'E' . chr(1);              // Gras
    $d .= txt("BlueBeeTN") . LF;
    $d .= GS  . '!' . chr(0x00);
    $d .= ESC . 'E' . chr(0);
    $d .= txt("Service Cuisine") . LF;
    $d .= str_repeat('=', TICKET_WIDTH) . LF;

    // -------- NUMERO DE COMMANDE --------
    $d .= ESC . 'a' . chr(1);
    $d .= GS  . '!' . chr(0x11);
    $d .= ESC . 'E' . chr(1);
    $d .= txt("COMMANDE #" . $cmd['id']) . LF;
    $d .= ESC . 'E' . chr(0);
    $d .= GS  . '!' . chr(0x00);
    $d .= LF;

    // -------- INFOS PISTE + HORAIRES --------
    $d .= ESC . 'a' . chr(0);              // Gauche
    $debut = $cmd['heure_debut_prep'] ? date('H:i', strtotime($cmd['heure_debut_prep'])) : '--:--';
    $fin   = $cmd['heure_fin_estimee'] ? date('H:i', strtotime($cmd['heure_fin_estimee'])) : '--:--';
    $retrait = !empty($cmd['heure_retrait']) ? $cmd['heure_retrait'] : $fin;

    $d .= ESC . 'E' . chr(1);
    $d .= txt("CUISINIER " . $cmd['piste_id']) . LF;
    $d .= ESC . 'E' . chr(0);

    // Heure "Lancer a" en gros (la plus urgente pour la cuisine)
    $d .= txt("Lancer a    : ");
    $d .= GS  . '!' . chr(0x01);           // Double hauteur
    $d .= ESC . 'E' . chr(1);
    $d .= $debut . LF;
    $d .= ESC . 'E' . chr(0);
    $d .= GS  . '!' . chr(0x00);

    $d .= txt("Pret pour   : ") . $fin . LF;
    $d .= txt("Retrait     : ") . txt((string)$retrait) . LF;
    $d .= str_repeat('-', TICKET_WIDTH) . LF;

    // -------- CLIENT --------
    $d .= ESC . 'E' . chr(1);
    $d .= txt("Client : " . $cmd['client_nom']) . LF;
    $d .= ESC . 'E' . chr(0);
    $d .= txt("Tel    : " . $cmd['client_tel']) . LF;
    $d .= str_repeat('-', TICKET_WIDTH) . LF;

    // -------- ITEMS DU PANIER --------
    $panier_raw = json_decode($cmd['details_panier'], true);
    $items = (is_array($panier_raw) && isset($panier_raw['items'])) ? $panier_raw['items'] : $panier_raw;
    $note  = (is_array($panier_raw) && isset($panier_raw['note']))  ? $panier_raw['note']  : '';

    $total = 0.0;

    if (is_array($items)) {
        $d .= ESC . 'E' . chr(1);
        $d .= txt("ARTICLES") . LF;
        $d .= ESC . 'E' . chr(0);
        $d .= LF;

        foreach ($items as $item) {
            $qty  = (int)($item['qty']  ?? 1);
            $nom  = (string)($item['nom']  ?? '');
            $prix = (float)($item['prix'] ?? 0);
            $sub  = $qty * $prix;
            $total += $sub;

            // Ligne quantite + nom (en gras, eventuellement sur 2 lignes)
            $prefix    = $qty . "x ";
            $maxNomLen = TICKET_WIDTH - mb_strlen($prefix);
            $nomLines  = explode("\n", wordwrap($nom, $maxNomLen, "\n", true));

            $d .= ESC . 'E' . chr(1);
            $d .= txt($prefix . $nomLines[0]) . LF;
            for ($i = 1; $i < count($nomLines); $i++) {
                $d .= str_repeat(' ', mb_strlen($prefix)) . txt($nomLines[$i]) . LF;
            }
            $d .= ESC . 'E' . chr(0);

            // Ligne prix unitaire + sous-total aligne a droite
            $detail = "   (" . number_format($prix, 2, ',', ' ') . " EUR/u)";
            $right  = number_format($sub, 2, ',', ' ') . " EUR";
            $space  = TICKET_WIDTH - mb_strlen($detail) - mb_strlen($right);
            if ($space < 1) $space = 1;
            $d .= txt($detail) . str_repeat(' ', $space) . txt($right) . LF;
        }
    }

    $d .= str_repeat('-', TICKET_WIDTH) . LF;

    // -------- TOTAL --------
    $d .= ESC . 'a' . chr(2);              // Droite
    $d .= GS  . '!' . chr(0x11);           // Double H+L
    $d .= ESC . 'E' . chr(1);
    $d .= txt("TOTAL " . number_format($total, 2, ',', ' ') . " EUR") . LF;
    $d .= ESC . 'E' . chr(0);
    $d .= GS  . '!' . chr(0x00);
    $d .= ESC . 'a' . chr(0);

    // -------- NOTE CLIENT --------
    if (!empty(trim((string)$note))) {
        $d .= LF;
        $d .= str_repeat('*', TICKET_WIDTH) . LF;
        $d .= ESC . 'E' . chr(1);
        $d .= txt("NOTE DU CLIENT :") . LF;
        $d .= ESC . 'E' . chr(0);
        foreach (explode("\n", wordwrap($note, TICKET_WIDTH, "\n", true)) as $line) {
            $d .= txt($line) . LF;
        }
        $d .= str_repeat('*', TICKET_WIDTH) . LF;
    }

    // -------- PIED --------
    $d .= LF;
    $d .= ESC . 'a' . chr(1);
    $d .= txt("Imprime le " . date('d/m/Y a H:i:s')) . LF;
    $d .= LF . LF . LF . LF;

    // Coupe partielle du papier
    $d .= GS . 'V' . chr(1);

    return $d;
}

// ============ ENVOI A L'IMPRIMANTE (Windows shared printer) ============
function send_to_printer(string $data): bool {
    $tmp_file = TEMP_DIR . '/ticket_' . uniqid('', true) . '.bin';
    if (file_put_contents($tmp_file, $data) === false) {
        log_msg("ERREUR : impossible d'ecrire le fichier temp $tmp_file");
        return false;
    }

    // "copy /b" envoie le fichier en mode binaire au partage Windows.
    // Le spooler Windows met en file d'attente, donc aucun conflit avec CLIO.
    $cmd = 'copy /b "' . $tmp_file . '" "' . PRINTER_SHARE . '" 2>&1';
    $output = @shell_exec($cmd);
    @unlink($tmp_file);

    if ($output === null) {
        log_msg("ERREUR shell_exec (null)");
        return false;
    }

    // Windows FR : "1 fichier(s) copie(s)"  /  Windows EN : "1 file(s) copied"
    if (stripos($output, 'copi') !== false || stripos($output, 'copied') !== false) {
        return true;
    }

    log_msg("ECHEC IMPRESSION : " . trim($output));
    return false;
}

// ============ BOUCLE PRINCIPALE ============
log_msg("================================================");
log_msg("Demarrage du daemon d'impression BlueBeeTN");
log_msg("Imprimante  : " . PRINTER_SHARE);
log_msg("Intervalle  : " . POLL_INTERVAL . "s");
log_msg("================================================");

$pdo = connect_db();

while (true) {
    try {
        $stmt = $pdo->query(
            "SELECT id, client_nom, client_tel, heure_retrait, details_panier,
                    heure_debut_prep, heure_fin_estimee, piste_id, statut
             FROM commandes
             WHERE statut = 'en attente' AND ticket_imprime = 0
             ORDER BY id ASC
             LIMIT 10"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $cmd) {
            log_msg("Impression commande #{$cmd['id']} (" . $cmd['client_nom'] . ")");

            $ticket = build_ticket($cmd);
            $ok = send_to_printer($ticket);

            if ($ok) {
                $u = $pdo->prepare("UPDATE commandes SET ticket_imprime = 1 WHERE id = ?");
                $u->execute([$cmd['id']]);
                log_msg("  -> OK commande #{$cmd['id']}");
            } else {
                log_msg("  -> ECHEC commande #{$cmd['id']} (sera retentee au prochain tour)");
                sleep(3); // Petit delai pour ne pas spammer si imprimante hors ligne
            }
        }
    } catch (PDOException $e) {
        log_msg("ERREUR DB (boucle) : " . $e->getMessage() . " - reconnexion");
        $pdo = connect_db();
    } catch (Throwable $e) {
        log_msg("EXCEPTION : " . $e->getMessage());
    }

    sleep(POLL_INTERVAL);
}
