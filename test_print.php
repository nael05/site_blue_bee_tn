<?php
/**
 * test_print.php
 *
 * Envoie UN ticket de demonstration a l'imprimante, SANS toucher
 * a la base de donnees. A executer en CLI pour valider que :
 *   1. Le partage Windows "\\localhost\TICKET" est bien configure
 *   2. L'imprimante AURES ODP 333 imprime correctement
 *
 * Utilisation :
 *   C:\wamp64\bin\php\phpX.X.X\php.exe test_print.php
 */

const PRINTER_SHARE = '\\\\localhost\\TICKET';
const TEMP_DIR      = __DIR__ . '/print_tmp';
const TICKET_WIDTH  = 42;
const ESC = "\x1B";
const GS  = "\x1D";
const LF  = "\x0A";

if (!is_dir(TEMP_DIR)) { @mkdir(TEMP_DIR, 0777, true); }

function txt(string $s): string {
    $out = @iconv('UTF-8', 'CP858//TRANSLIT//IGNORE', $s);
    return $out !== false ? $out : $s;
}

// === Construction d'un ticket de demo ===
$d  = '';
$d .= ESC . '@';
$d .= ESC . 't' . chr(19);

$d .= ESC . 'a' . chr(1);
$d .= GS  . '!' . chr(0x11);
$d .= ESC . 'E' . chr(1);
$d .= txt("BlueBeeTN") . LF;
$d .= GS  . '!' . chr(0x00);
$d .= ESC . 'E' . chr(0);
$d .= txt("Test d'impression") . LF;
$d .= str_repeat('=', TICKET_WIDTH) . LF;

$d .= ESC . 'a' . chr(0);
$d .= ESC . 'E' . chr(1);
$d .= txt("Si vous lisez ce ticket :") . LF;
$d .= ESC . 'E' . chr(0);
$d .= txt("- Le partage Windows fonctionne") . LF;
$d .= txt("- L'imprimante ODP 333 repond") . LF;
$d .= txt("- L'encodage CP858 est OK (eaecu)") . LF;
$d .= LF;

$d .= ESC . 'a' . chr(1);
$d .= txt("Caracteres accentues :") . LF;
$d .= txt("a e i o u e e a c E A e u") . LF;
$d .= txt("Euro : EUR") . LF;
$d .= LF;

$d .= ESC . 'a' . chr(0);
$d .= str_repeat('-', TICKET_WIDTH) . LF;
$d .= txt("Heure : " . date('d/m/Y H:i:s')) . LF;
$d .= LF . LF . LF . LF;
$d .= GS . 'V' . chr(1);

// === Envoi ===
$tmp = TEMP_DIR . '/test_' . uniqid('', true) . '.bin';
file_put_contents($tmp, $d);

echo "Envoi du ticket de test a " . PRINTER_SHARE . "...\n";
$output = shell_exec('copy /b "' . $tmp . '" "' . PRINTER_SHARE . '" 2>&1');
@unlink($tmp);

echo "Reponse de 'copy' :\n" . $output . "\n";

if (stripos($output ?? '', 'copi') !== false || stripos($output ?? '', 'copied') !== false) {
    echo "[OK] Le ticket a ete envoye au spooler Windows.\n";
    echo "Verifie que l'imprimante a bien sorti le papier.\n";
    exit(0);
} else {
    echo "[ECHEC] Verifier :\n";
    echo "  - Que l'imprimante est branchee et allumee\n";
    echo "  - Que dans 'Imprimantes', le partage 'TICKET' est bien active\n";
    echo "  - Que le nom de partage est exactement 'TICKET' (sans espace)\n";
    exit(1);
}
