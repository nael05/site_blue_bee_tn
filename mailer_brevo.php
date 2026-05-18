<?php
/**
 * mailer_brevo.php
 *
 * Helper d'envoi d'emails transactionnels via l'API Brevo v3.
 * Documentation : https://developers.brevo.com/reference/sendtransacemail
 */

require_once __DIR__ . '/config.php';

const BREVO_API_URL = 'https://api.brevo.com/v3/smtp/email';
const BREVO_LOG     = __DIR__ . '/brevo.log';

function brevo_log(string $msg): void {
    @file_put_contents(BREVO_LOG, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

/**
 * Envoie un email via Brevo.
 *
 * @param string $to_email   Destinataire
 * @param string $to_name    Nom du destinataire (peut etre vide)
 * @param string $subject    Sujet
 * @param string $html       Corps HTML
 * @param string $text       Version texte (optionnel, fallback)
 * @return array { success: bool, message_id?: string, error?: string }
 */
function envoyer_email_brevo(
    string $to_email,
    string $to_name,
    string $subject,
    string $html,
    string $text = ''
): array {
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        brevo_log("Email invalide refuse : $to_email");
        return ['success' => false, 'error' => 'invalid_email'];
    }

    $payload = [
        'sender' => [
            'name'  => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL,
        ],
        'to' => [[
            'email' => $to_email,
            'name'  => $to_name !== '' ? $to_name : $to_email,
        ]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];
    if ($text !== '') {
        $payload['textContent'] = $text;
    }

    $ch = curl_init(BREVO_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if (defined('CACERT_PATH') && is_file(CACERT_PATH)) {
        curl_setopt($ch, CURLOPT_CAINFO, CACERT_PATH);
    }

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        brevo_log("Erreur cURL pour $to_email : $err");
        return ['success' => false, 'error' => 'curl_error: ' . $err];
    }

    $decoded = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300) {
        $mid = $decoded['messageId'] ?? '';
        brevo_log("OK -> $to_email | sujet='$subject' | id=$mid");
        return ['success' => true, 'message_id' => $mid];
    }

    $errmsg = $decoded['message'] ?? $response;
    brevo_log("ECHEC HTTP $http_code -> $to_email | sujet='$subject' | err=$errmsg");
    return ['success' => false, 'error' => "http_$http_code: $errmsg"];
}

/**
 * Formate une liste d'articles en HTML pour un email recap.
 */
function format_items_html(array $items): array {
    $html = '<table style="width:100%; border-collapse:collapse; margin:15px 0;">';
    $html .= '<thead><tr style="background:#005599; color:white;">';
    $html .= '<th style="padding:10px; text-align:left;">Article</th>';
    $html .= '<th style="padding:10px; text-align:center;">Qte</th>';
    $html .= '<th style="padding:10px; text-align:right;">Prix</th>';
    $html .= '<th style="padding:10px; text-align:right;">Total</th>';
    $html .= '</tr></thead><tbody>';

    $total = 0.0;
    foreach ($items as $item) {
        $qty  = (int)($item['qty']  ?? 1);
        $nom  = (string)($item['nom']  ?? '');
        $prix = (float)($item['prix'] ?? 0);
        $sub  = $qty * $prix;
        $total += $sub;

        $html .= '<tr style="border-bottom:1px solid #e2e8f0;">';
        $html .= '<td style="padding:10px;">' . htmlspecialchars($nom) . '</td>';
        $html .= '<td style="padding:10px; text-align:center;">' . $qty . '</td>';
        $html .= '<td style="padding:10px; text-align:right;">' . number_format($prix, 2, ',', ' ') . ' &euro;</td>';
        $html .= '<td style="padding:10px; text-align:right; font-weight:bold;">' . number_format($sub, 2, ',', ' ') . ' &euro;</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    return ['html' => $html, 'total' => $total];
}

/**
 * Recupere l'email du restaurant configure dans admin.
 */
function get_restaurant_email(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT s_value FROM commandes_settings WHERE s_key = 'restaurant_email'");
    $stmt->execute();
    $email = (string)$stmt->fetchColumn();
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}
