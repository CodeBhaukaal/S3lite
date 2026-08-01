<?php
/**
 * Minimal SMTP server used by the test suite to exercise the Mailer without
 * touching a real mail host. Speaks just enough of RFC 5321 to complete a
 * session, and writes what it received to a JSON file.
 *
 * Usage: php tests/fake-smtp.php <port> <output-file> [--reject-auth]
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 2526);
$outFile = (string) ($argv[2] ?? sys_get_temp_dir() . '/fake-smtp.json');
$rejectAuth = in_array('--reject-auth', $argv, true);

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "cannot listen on {$port}: {$errstr}\n");
    exit(1);
}

@file_put_contents($outFile, json_encode(['listening' => true]));

$client = @stream_socket_accept($server, 20);

if ($client === false) {
    @file_put_contents($outFile, json_encode(['error' => 'no client connected']));
    exit(1);
}

stream_set_timeout($client, 10);

$session = ['commands' => [], 'data' => '', 'authenticated' => false];

fwrite($client, "220 fake.smtp ESMTP ready\r\n");

$inData = false;

while (($line = fgets($client, 2048)) !== false) {
    $line = rtrim($line, "\r\n");

    if ($inData) {
        if ($line === '.') {
            $inData = false;
            fwrite($client, "250 2.0.0 Ok: queued\r\n");
            continue;
        }
        $session['data'] .= $line . "\n";
        continue;
    }

    $session['commands'][] = $line;
    $upper = strtoupper($line);

    if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
        fwrite($client, "250-fake.smtp\r\n250-AUTH LOGIN PLAIN\r\n250 SIZE 10485760\r\n");
    } elseif ($upper === 'AUTH LOGIN') {
        fwrite($client, "334 VXNlcm5hbWU6\r\n");
        $session['auth_user'] = base64_decode(trim((string) fgets($client, 1024)), true) ?: '';
        fwrite($client, "334 UGFzc3dvcmQ6\r\n");
        $session['auth_pass'] = base64_decode(trim((string) fgets($client, 1024)), true) ?: '';

        if ($rejectAuth) {
            fwrite($client, "535 5.7.8 Authentication credentials invalid\r\n");
        } else {
            $session['authenticated'] = true;
            fwrite($client, "235 2.7.0 Authentication successful\r\n");
        }
    } elseif (str_starts_with($upper, 'MAIL FROM')) {
        $session['mail_from'] = $line;
        fwrite($client, "250 2.1.0 Ok\r\n");
    } elseif (str_starts_with($upper, 'RCPT TO')) {
        $session['rcpt_to'] = $line;
        fwrite($client, "250 2.1.5 Ok\r\n");
    } elseif ($upper === 'DATA') {
        $inData = true;
        fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
    } elseif ($upper === 'QUIT') {
        fwrite($client, "221 2.0.0 Bye\r\n");
        break;
    } else {
        fwrite($client, "250 2.0.0 Ok\r\n");
    }
}

@file_put_contents($outFile, json_encode($session, JSON_UNESCAPED_SLASHES));

fclose($client);
fclose($server);
