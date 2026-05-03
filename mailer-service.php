<?php
// mailer-service.php - Final API Version
require_once 'mail-config.php';

class Mailer {
    public static function sendOTP($to, $code) {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        
        $api_key = SMTP_PASS; 
        $url = 'https://api.brevo.com/v3/smtp/email';

        $data = [
            'sender' => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM],
            'to' => [['email' => $to]],
            'subject' => "Verification Code: $code",
            'htmlContent' => "<html><body style='font-family:sans-serif;'><h2>Your code is: <span style='color:#6366f1;'>$code</span></h2><p>AutoFix Hub Team</p></body></html>"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . trim($api_key),
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        // Mahalaga ito para sa SSL issues sa ilang hosting
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 201 || $httpCode == 200) {
            return true;
        } else {
            $_SESSION['debug_info'] = "Status: $httpCode | Error: $curlError | Msg: " . $result;
            return false;
        }
    }
}
?>
