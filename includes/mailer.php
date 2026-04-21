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

function dd_private_mailer_env_candidates(): array
{
    $candidates = [];

    $override = getenv('DD_MAILER_ENV_PATH');
    if ($override !== false) {
        $override = trim((string) $override);
        if ($override !== '') {
            $candidates[] = $override;
        }
    }

    $candidates[] = '/private/mailer-env.php';
    $candidates[] = dirname(__DIR__) . '/private/mailer-env.php';
    $candidates[] = __DIR__ . '/../private/mailer-env.php';
    $candidates[] = dirname(dirname(__DIR__)) . '/private/mailer-env.php';

    $normalized = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        $real = realpath($candidate);
        $key = $real !== false ? $real : $candidate;

        if (!isset($normalized[$key])) {
            $normalized[$key] = $candidate;
        }
    }

    return array_values($normalized);
}

function dd_private_mailer_env_path(): string
{
    foreach (dd_private_mailer_env_candidates() as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    $candidates = dd_private_mailer_env_candidates();
    return $candidates[0] ?? '/private/mailer-env.php';
}

function dd_load_private_mailer_env(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [];

    foreach (dd_private_mailer_env_candidates() as $path) {
        if (!is_file($path)) {
            continue;
        }

        $loaded = require $path;

        if (is_array($loaded)) {
            $config = $loaded;
            return $config;
        }

        error_log('Doggie Dorian\'s mailer config file did not return an array: ' . $path);
    }

    return $config;
}

function dd_mailer_env(string $key, mixed $default = null): mixed
{
    $envValue = getenv($key);

    if ($envValue !== false) {
        $envValue = trim((string) $envValue);
        if ($envValue !== '') {
            return $envValue;
        }
    }

    $privateConfig = dd_load_private_mailer_env();

    if (array_key_exists($key, $privateConfig)) {
        $value = $privateConfig[$key];

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return $default;
            }
        }

        return $value;
    }

    return $default;
}

function dd_mailer_secure_mode(string $value): string
{
    $value = strtolower(trim($value));

    if ($value === '' || $value === 'tls' || $value === 'starttls') {
        return \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    if ($value === 'ssl' || $value === 'smtps') {
        return \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    }

    if ($value === 'none' || $value === 'off' || $value === 'disabled') {
        return '';
    }

    return \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
}

$ddMailerConfig = array(
    'host' => (string) dd_mailer_env('DD_SMTP_HOST', 'smtp.ionos.com'),
    'username' => (string) dd_mailer_env('DD_SMTP_USERNAME', ''),
    'password' => (string) dd_mailer_env('DD_SMTP_PASSWORD', ''),
    'port' => (int) dd_mailer_env('DD_SMTP_PORT', 587),
    'secure' => (string) dd_mailer_env('DD_SMTP_ENCRYPTION', 'tls'),
    'from_email' => (string) dd_mailer_env('DD_SMTP_FROM_EMAIL', 'support@doggiedorians.com'),
    'from_name' => (string) dd_mailer_env('DD_SMTP_FROM_NAME', 'Doggie Dorian\'s'),
    'timeout' => (int) dd_mailer_env('DD_SMTP_TIMEOUT', 30),
);

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

    if (
        trim((string) $ddMailerConfig['host']) === ''
        || trim((string) $ddMailerConfig['username']) === ''
        || trim((string) $ddMailerConfig['password']) === ''
        || trim((string) $ddMailerConfig['from_email']) === ''
    ) {
        error_log('Doggie Dorian\'s mailer configuration is incomplete. Resolved path: ' . dd_private_mailer_env_path());

        return [
            'success' => false,
            'error' => 'Email could not be sent right now.',
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
        $mail->SMTPAutoTLS = true;

        $secureMode = dd_mailer_secure_mode((string) $ddMailerConfig['secure']);
        if ($secureMode !== '') {
            $mail->SMTPSecure = $secureMode;
        }

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
        error_log(
            'Doggie Dorian\'s mailer error: '
            . $e->getMessage()
            . ' | host=' . (string) $ddMailerConfig['host']
            . ' | port=' . (string) $ddMailerConfig['port']
            . ' | secure=' . (string) $ddMailerConfig['secure']
            . ' | user=' . (string) $ddMailerConfig['username']
        );

        return [
            'success' => false,
            'error' => 'Email could not be sent right now.',
        ];
    } catch (\Throwable $e) {
        error_log(
            'Doggie Dorian\'s mailer fatal error: '
            . $e->getMessage()
            . ' | host=' . (string) $ddMailerConfig['host']
            . ' | port=' . (string) $ddMailerConfig['port']
            . ' | secure=' . (string) $ddMailerConfig['secure']
            . ' | user=' . (string) $ddMailerConfig['username']
        );

        return [
            'success' => false,
            'error' => 'Email could not be sent right now.',
        ];
    }
}