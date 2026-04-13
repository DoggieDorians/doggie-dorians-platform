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

$ddMailerConfig = array(
    'host' => getenv('DD_SMTP_HOST') !== false ? (string) getenv('DD_SMTP_HOST') : 'smtp.ionos.com',
    'username' => getenv('DD_SMTP_USERNAME') !== false ? (string) getenv('DD_SMTP_USERNAME') : 'admin@doggiedorians.com',
    'password' => getenv('DD_SMTP_PASSWORD') !== false ? (string) getenv('DD_SMTP_PASSWORD') : 'Cmf8282!!!',
    'port' => getenv('DD_SMTP_PORT') !== false ? (int) getenv('DD_SMTP_PORT') : 587,
    'from_email' => getenv('DD_SMTP_FROM_EMAIL') !== false ? (string) getenv('DD_SMTP_FROM_EMAIL') : 'admin@doggiedorians.com',
    'from_name' => getenv('DD_SMTP_FROM_NAME') !== false ? (string) getenv('DD_SMTP_FROM_NAME') : 'Doggie Dorian\'s',
    'timeout' => 30,
);

/**
 * Send an email through IONOS SMTP using Doggie Dorian's mail configuration.
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
    global $ddMailerConfig;

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return [
            'success' => false,
            'error' => 'PHPMailer package is still missing on the server at /vendor/phpmailer/phpmailer/src/',
        ];
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = (string) $ddMailerConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $ddMailerConfig['username'];
        $mail->Password = (string) $ddMailerConfig['password'];
        $mail->Port = (int) $ddMailerConfig['port'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = (int) $ddMailerConfig['timeout'];

        $mail->setFrom(
            (string) $ddMailerConfig['from_email'],
            (string) $ddMailerConfig['from_name']
        );

        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);

        if ($replyTo && !empty($replyTo['email'])) {
            $mail->addReplyTo($replyTo['email'], $replyTo['name'] ?? '');
        } else {
            $mail->addReplyTo(
                (string) $ddMailerConfig['from_email'],
                (string) $ddMailerConfig['from_name']
            );
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
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Doggie Dorian\'s mailer error: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => 'Email could not be sent right now.',
        ];
    } catch (\Throwable $e) {
        error_log('Doggie Dorian\'s mailer fatal error: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => 'Email could not be sent right now.',
        ];
    }
}