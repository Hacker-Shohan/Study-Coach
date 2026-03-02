<?php
// ═══════════════════════════════════════════════════════
//  StudyCoach Mailer — Hostinger SMTP (Pure PHP)
//  Pre-configured for reminnder.alfahmax.com
// ═══════════════════════════════════════════════════════
define('SMTP_HOST',      '');
define('SMTP_PORT',      465);
define('SMTP_SECURE',    'ssl');
define('SMTP_USER',      '');
define('SMTP_PASS',      '~1bALy/442');
define('MAIL_FROM',      '');
define('MAIL_FROM_NAME', 'StudyCoach');
define('APP_URL',        '');

/**
 * Send HTML email via Hostinger SMTP — no PHPMailer needed
 */
function sendMail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $boundary = '==SC_' . md5(uniqid((string)rand(), true));

    $plainText = wordwrap(
        strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $htmlBody)),
        75, "\n", true
    );

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainText)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode(buildEmailHTML($subject, $htmlBody))) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
    $encodedToName   = '=?UTF-8?B?' . base64_encode($toName) . '?=';

    $headers  = "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: <" . time() . "." . md5($to) . "@alfahmax.com>\r\n";
    $headers .= "From: {$encodedFromName} <" . MAIL_FROM . ">\r\n";
    $headers .= "To: {$encodedToName} <{$to}>\r\n";
    $headers .= "Subject: {$encodedSubject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: StudyCoach/1.0\r\n";

    $fullMessage = $headers . "\r\n" . $body;

    // Open SSL socket to Hostinger SMTP
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]
    ]);

    $socket = @stream_socket_client(
        "ssl://" . SMTP_HOST . ":" . SMTP_PORT,
        $errno, $errstr, 30,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        error_log("StudyCoach SMTP connect failed ({$errno}): {$errstr}");
        return false;
    }

    stream_set_timeout($socket, 30);

    $steps = [
        [null,                                               [220]],
        ["EHLO reminnder.alfahmax.com\r\n",                 [250]],
        ["AUTH LOGIN\r\n",                                   [334]],
        [base64_encode(SMTP_USER) . "\r\n",                  [334]],
        [base64_encode(SMTP_PASS) . "\r\n",                  [235]],
        ["MAIL FROM:<" . MAIL_FROM . ">\r\n",                [250]],
        ["RCPT TO:<{$to}>\r\n",                              [250, 251]],
        ["DATA\r\n",                                         [354]],
        [$fullMessage . "\r\n.\r\n",                         [250]],
        ["QUIT\r\n",                                         [221, 250]],
    ];

    foreach ($steps as [$cmd, $expected]) {
        if ($cmd !== null) fwrite($socket, $cmd);
        $response = smtpRead($socket);
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected)) {
            error_log("StudyCoach SMTP error (expected " . implode('/', $expected) . " got {$code}): {$response}");
            fclose($socket);
            return false;
        }
    }

    fclose($socket);
    return true;
}

function smtpRead($socket): string {
    $data = '';
    while ($line = fgets($socket, 515)) {
        $data .= $line;
        if (substr($line, 3, 1) !== '-') break;
    }
    return $data;
}

function buildEmailHTML(string $subject, string $content): string {
    $appUrl = APP_URL;
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$subject}</title>
<style>
  body{margin:0;padding:0;background:#0a0a0f;font-family:'Segoe UI',Arial,sans-serif;}
  .wrap{max-width:600px;margin:0 auto;padding:20px;}
  .card{background:#12121f;border:1px solid #e94560;border-radius:14px;overflow:hidden;}
  .hdr{background:linear-gradient(135deg,#e94560 0%,#0f3460 100%);padding:32px;text-align:center;}
  .hdr h1{color:#fff;margin:0;font-size:28px;letter-spacing:3px;text-transform:uppercase;font-weight:900;}
  .hdr p{color:rgba(255,255,255,0.65);margin:6px 0 0;font-size:12px;letter-spacing:2px;}
  .bdy{padding:32px;color:#dde0ee;line-height:1.75;font-size:15px;}
  .bdy h2{color:#e94560;margin-top:0;font-size:21px;font-weight:700;}
  .stat{background:#0a0a1a;border:1px solid #1e1e3a;border-radius:10px;padding:18px;margin:16px 0;text-align:center;}
  .stat .n{font-size:40px;font-weight:900;color:#e94560;line-height:1;}
  .stat .l{color:#666;font-size:11px;text-transform:uppercase;letter-spacing:2px;margin-top:4px;}
  .prog{margin:18px 0;}
  .prog-lbl{display:flex;justify-content:space-between;font-size:11px;color:#555;margin-bottom:7px;letter-spacing:1px;}
  .prog-track{background:#0a0a1a;border-radius:50px;height:10px;overflow:hidden;border:1px solid #1e1e3a;}
  .prog-fill{height:100%;border-radius:50px;background:linear-gradient(90deg,#e94560,#ff6b9d);}
  .excuse{background:#1a0008;border-left:4px solid #e94560;padding:12px 16px;border-radius:6px;color:#ff9999;margin:14px 0;font-style:italic;font-size:14px;}
  .cta{display:block;margin:28px auto 0;padding:15px 40px;background:linear-gradient(135deg,#e94560,#c73652);color:#fff!important;text-decoration:none;border-radius:10px;font-weight:800;text-align:center;font-size:16px;letter-spacing:1.5px;}
  .ftr{padding:20px 32px;border-top:1px solid #1e1e3a;color:#444;font-size:12px;text-align:center;}
  .ftr a{color:#e94560;text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="hdr">
      <h1>📚 StudyCoach</h1>
      <p>YOUR PERSONAL ACCOUNTABILITY SYSTEM</p>
    </div>
    <div class="bdy">
      {$content}
      <a href="{$appUrl}/index.php?page=log" class="cta">LOG STUDY SESSION NOW →</a>
    </div>
    <div class="ftr">
      You signed up for StudyCoach reminders at reminnder.alfahmax.com<br>
      <a href="{$appUrl}/index.php?page=settings">Manage settings</a>
    </div>
  </div>
</div>
</body>
</html>
HTML;
}
