<?php
declare(strict_types=1);

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
$phpMailerClass = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
$phpMailerSmtp = __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
$phpMailerException = __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    if (file_exists($phpMailerException)) {
        require_once $phpMailerException;
    }

    if (file_exists($phpMailerSmtp)) {
        require_once $phpMailerSmtp;
    }

    if (file_exists($phpMailerClass)) {
        require_once $phpMailerClass;
    }
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Send an email through IONOS SMTP using admin@doggiedorians.com
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $htmlBody
 * @param string $textBody
 * @param array<int, array{path:string,name?:string}> $attachments
 * @param array<int, array{email:string,name?:string}> $cc
 * @param array<int, array{email:string,name?:string}> $bcc
 * @param array{email:string,name?:string}|null $replyTo
 * @return array{success:bool,error:?string}
 */
function dd_send_email(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    array $attachments = [],
    array $cc = [],
    array $bcc = [],
    ?array $replyTo = null
): array {
    if (!class_exists(PHPMailer::class)) {
        return [
            'success' => false,
            'error' => 'PHPMailer package is still missing on the server at /vendor/phpmailer/phpmailer/src/',
        ];
    }

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.ionos.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'admin@doggiedorians.com';
        $mail->Password = 'Cmf8282!!!';
        $mail->Port = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;

        $mail->setFrom('admin@doggiedorians.com', 'Doggie Dorian\'s');
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);

        if ($replyTo && !empty($replyTo['email'])) {
            $mail->addReplyTo($replyTo['email'], $replyTo['name'] ?? '');
        } else {
            $mail->addReplyTo('admin@doggiedorians.com', 'Doggie Dorian\'s');
        }

        foreach ($cc as $row) {
            if (!empty($row['email'])) {
                $mail->addCC($row['email'], $row['name'] ?? '');
            }
        }

        foreach ($bcc as $row) {
            if (!empty($row['email'])) {
                $mail->addBCC($row['email'], $row['name'] ?? '');
            }
        }

        foreach ($attachments as $attachment) {
            if (!empty($attachment['path']) && is_file($attachment['path'])) {
                $mail->addAttachment(
                    $attachment['path'],
                    $attachment['name'] ?? basename($attachment['path'])
                );
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== ''
            ? $textBody
            : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();

        return [
            'success' => true,
            'error' => null,
        ];
    } catch (Exception $e) {
        error_log('Doggie Dorian\'s mailer error: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    } catch (\Throwable $e) {
        error_log('Doggie Dorian\'s mailer fatal error: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}