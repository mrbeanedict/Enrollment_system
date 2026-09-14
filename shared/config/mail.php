<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers/env.php';

use PHPMailer\PHPMailer\PHPMailer;

function getMailer(): PHPMailer
{
    load_env();

    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $mail->Host = 'smtp.gmail.com';

    $mail->SMTPAuth = true;

    $mail->Username = $_ENV['GMAIL_SMTP_USER'] ?? '';

    $mail->Password = $_ENV['GMAIL_SMTP_PASSWORD'] ?? '';

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Port = 587;

    // PHPMailer defaults to a 300s connection timeout — longer than this
    // app's max_execution_time (120s in php.ini), so a slow/unreachable
    // SMTP server doesn't just fail the email, it kills the whole PHP
    // request before it can respond at all. Every caller already wraps
    // send_branded_email() in try/catch treating email as best-effort
    // (never undo the real action it's confirming), but that catch can
    // only help if the timeout is short enough to actually be reached —
    // a low timeout here is what makes a flaky/unreachable SMTP server
    // fail fast instead of hanging the whole page.
    $mail->Timeout = 10;

    $mail->setFrom(
        $_ENV['GMAIL_SMTP_USER'] ?? '',
        'Greenfield Senior High School'
    );

    return $mail;
}

// A prominent dashed-border box for a single "the one thing that matters"
// value — a control number, a code, etc. Callers drop this inline into
// their $bodyHtml wherever it belongs.
function email_highlight_box(string $value): string
{
    $safe = htmlspecialchars($value);
    return <<<HTML
<div style="border:1.5px dashed #1E4D3B; background:#EAF3EE; border-radius:10px; padding:18px; text-align:center; margin:18px 0;">
  <span style="font-size:20px; font-weight:bold; letter-spacing:2px; color:#1E4D3B; font-family:Arial, Helvetica, sans-serif;">{$safe}</span>
</div>
HTML;
}

// A label/value detail table — pass an associative array, e.g.
// ['Applicant' => 'Jane Doe', 'Grade & Strand' => 'Grade 11 — STEM'].
// Renders as label left (muted) / value right (bold), one row per entry,
// matching the rest of the app's read-only detail-view convention.
function email_detail_rows(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:4px;">';
    foreach ($rows as $label => $value) {
        $safeLabel = htmlspecialchars((string) $label);
        $safeValue = htmlspecialchars((string) $value);
        $html .= <<<HTML
<tr>
  <td style="padding:9px 0; border-bottom:1px solid #EBEBF0; color:#5A5A72; font-size:12.5px; font-family:Arial, Helvetica, sans-serif;">{$safeLabel}</td>
  <td style="padding:9px 0; border-bottom:1px solid #EBEBF0; color:#1A1A2E; font-size:13px; font-weight:bold; text-align:right; font-family:Arial, Helvetica, sans-serif;">{$safeValue}</td>
</tr>
HTML;
    }
    $html .= '</table>';
    return $html;
}

// Wraps a caller's message HTML (plain <p>/<ul> content, plus optional
// email_highlight_box()/email_detail_rows() pieces) in the school's
// branded email shell: a wordmark + subtitle header sitting directly on a
// soft cream page background, then a white card body, then the same
// contact footer used site-wide (see roles/student/public/admission.php's footer).
// Table-based layout with inline styles throughout, since HTML email
// clients don't reliably support flexbox/grid or external/<style> CSS.
// $subtitle is a short uppercase label under the logo describing what
// this email is (e.g. "Admission Confirmation"). $logoCid must match
// whatever Content-ID the caller embedded the logo under (see
// send_branded_email() below).
function render_email_html(string $subtitle, string $bodyHtml, string $logoCid): string
{
    $year = date('Y');
    $safeSubtitle = htmlspecialchars($subtitle);
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background:#F2F9F4; font-family:Arial, Helvetica, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F9F4; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
          <tr>
            <td align="center" style="padding:8px 24px 22px;">
              <img src="cid:{$logoCid}" alt="Greenfield Senior High School" width="220" height="146" style="display:block; margin:0 auto 10px; max-width:100%; height:auto;">
              <div style="color:#5A5A72; font-size:11px; font-weight:bold; letter-spacing:2px; text-transform:uppercase; font-family:Arial, Helvetica, sans-serif;">{$safeSubtitle}</div>
            </td>
          </tr>
          <tr>
            <td style="background:#ffffff; border-radius:14px; border:1px solid #EBEBF0; padding:28px 28px 8px; color:#1A1A2E; font-size:14px; line-height:1.6; font-family:Arial, Helvetica, sans-serif;">
              {$bodyHtml}
              <div style="border-top:1px solid #EBEBF0; margin-top:20px; padding-top:16px; padding-bottom:20px; color:#5A5A72; font-size:11.5px; line-height:1.6; font-family:Arial, Helvetica, sans-serif;">
                Greenfield Senior High School &middot; 412 Meadowbrook Lane, Riverton<br>
                (555) 123-4567 &middot; enroll@greenfieldshs.edu.ph<br>
                &copy; {$year} Greenfield Senior High School. All rights reserved.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

// Embeds the school logo, wraps $bodyHtml in the branded shell above, and
// sends. Centralizes what every HTML-email call site previously duplicated
// by hand (isHTML/Body/AltBody/send()) — callers only ever need to supply
// their own message content, not rebuild the surrounding template.
function send_branded_email(PHPMailer $mail, string $to, string $toName, string $subject, string $subtitle, string $bodyHtml, string $altBody): bool
{
    $mail->addAddress($to, $toName);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $subject;

    $logoCid = 'schoollogo';
    $mail->addEmbeddedImage(__DIR__ . '/../../assets/images/logo2.png', $logoCid, 'logo.png');

    $mail->Body = render_email_html($subtitle, $bodyHtml, $logoCid);
    $mail->AltBody = $altBody;

    return $mail->send();
}