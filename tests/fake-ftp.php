<?php
/**
 * Minimal FTP server used by the test suite to exercise FtpDriver without a
 * real FTP host. Speaks just enough of RFC 959 — login, passive transfers,
 * MKD/RMD, STOR/RETR with REST, SIZE, DELE and RNFR/RNTO — and stores files
 * under a scratch directory.
 *
 * Usage: php tests/fake-ftp.php <port> <root-dir> <marker-file> [user] [pass]
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 2521);
$root = rtrim(str_replace('\\', '/', (string) ($argv[2] ?? sys_get_temp_dir() . '/fake-ftp')), '/');
$marker = (string) ($argv[3] ?? sys_get_temp_dir() . '/fake-ftp.json');
$expectedUser = (string) ($argv[4] ?? 'tester');
$expectedPass = (string) ($argv[5] ?? 'secret');

@mkdir($root, 0775, true);

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "cannot listen on {$port}: {$errstr}\n");
    exit(1);
}

@file_put_contents($marker, json_encode(['listening' => true, 'root' => $root]));

/** Resolve a client-supplied path inside the scratch root. */
$resolve = static function (string $path) use ($root): ?string {
    $path = ltrim(str_replace('\\', '/', $path), '/');

    foreach (explode('/', $path) as $segment) {
        if ($segment === '..' || $segment === '.') {
            return null;
        }
    }

    return $path === '' ? $root : $root . '/' . $path;
};

// One connection at a time is plenty; the suite is single-threaded.
$deadline = time() + 60;

while (time() < $deadline) {
    $client = @stream_socket_accept($server, 5);

    if ($client === false) {
        continue;
    }

    stream_set_timeout($client, 15);
    fwrite($client, "220 fake ftp ready\r\n");

    $authenticatedUser = null;
    $loggedIn = false;
    $dataServer = null;
    $renameFrom = null;
    $restartAt = 0;

    while (($line = fgets($client, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        $space = strpos($line, ' ');
        $command = strtoupper($space === false ? $line : substr($line, 0, $space));
        $argument = $space === false ? '' : substr($line, $space + 1);

        if ($command === 'USER') {
            $authenticatedUser = $argument;
            fwrite($client, "331 password required\r\n");
            continue;
        }

        if ($command === 'PASS') {
            $loggedIn = $authenticatedUser === $expectedUser && $argument === $expectedPass;
            fwrite($client, $loggedIn ? "230 logged in\r\n" : "530 login incorrect\r\n");
            continue;
        }

        if ($command === 'QUIT') {
            fwrite($client, "221 bye\r\n");
            break;
        }

        if (!$loggedIn) {
            fwrite($client, "530 log in first\r\n");
            continue;
        }

        switch ($command) {
            case 'SYST':
                fwrite($client, "215 UNIX Type: L8\r\n");
                break;

            case 'FEAT':
                fwrite($client, "211-Features:\r\n SIZE\r\n MDTM\r\n REST STREAM\r\n211 End\r\n");
                break;

            case 'TYPE':
            case 'NOOP':
            case 'OPTS':
                fwrite($client, "200 ok\r\n");
                break;

            case 'PWD':
                fwrite($client, "257 \"/\" is the current directory\r\n");
                break;

            case 'CWD':
                fwrite($client, "250 ok\r\n");
                break;

            case 'REST':
                $restartAt = max(0, (int) $argument);
                fwrite($client, "350 restarting at {$restartAt}\r\n");
                break;

            case 'PASV':
                if ($dataServer !== null) {
                    fclose($dataServer);
                }

                $dataServer = @stream_socket_server('tcp://127.0.0.1:0', $dataErrno, $dataError);

                if ($dataServer === false) {
                    fwrite($client, "425 cannot open a data connection\r\n");
                    break;
                }

                $name = (string) stream_socket_get_name($dataServer, false);
                $dataPort = (int) substr($name, (int) strrpos($name, ':') + 1);

                fwrite($client, sprintf(
                    "227 entering passive mode (127,0,0,1,%d,%d)\r\n",
                    $dataPort >> 8,
                    $dataPort & 0xFF
                ));
                break;

            case 'MKD':
                $target = $resolve($argument);

                if ($target === null) {
                    fwrite($client, "550 invalid path\r\n");
                    break;
                }

                if (is_dir($target)) {
                    fwrite($client, "550 directory exists\r\n");
                    break;
                }

                fwrite($client, @mkdir($target, 0775, true) ? "257 created\r\n" : "550 cannot create\r\n");
                break;

            case 'RMD':
                $target = $resolve($argument);
                fwrite($client, $target !== null && @rmdir($target) ? "250 removed\r\n" : "550 cannot remove\r\n");
                break;

            case 'DELE':
                $target = $resolve($argument);
                fwrite($client, $target !== null && is_file($target) && @unlink($target)
                    ? "250 deleted\r\n"
                    : "550 no such file\r\n");
                break;

            case 'SIZE':
                $target = $resolve($argument);
                fwrite($client, $target !== null && is_file($target)
                    ? '213 ' . filesize($target) . "\r\n"
                    : "550 no such file\r\n");
                break;

            case 'MDTM':
                $target = $resolve($argument);
                fwrite($client, $target !== null && is_file($target)
                    ? '213 ' . gmdate('YmdHis', (int) filemtime($target)) . "\r\n"
                    : "550 no such file\r\n");
                break;

            case 'RNFR':
                $renameFrom = $resolve($argument);
                fwrite($client, $renameFrom !== null && file_exists($renameFrom)
                    ? "350 ready for the new name\r\n"
                    : "550 no such file\r\n");
                break;

            case 'RNTO':
                $target = $resolve($argument);
                fwrite($client, $renameFrom !== null && $target !== null && @rename($renameFrom, $target)
                    ? "250 renamed\r\n"
                    : "550 cannot rename\r\n");
                $renameFrom = null;
                break;

            case 'STOR':
            case 'RETR':
                $target = $resolve($argument);

                if ($target === null || $dataServer === null) {
                    fwrite($client, "425 no data connection\r\n");
                    break;
                }

                if ($command === 'RETR' && !is_file($target)) {
                    fwrite($client, "550 no such file\r\n");
                    break;
                }

                fwrite($client, "150 opening the data connection\r\n");
                $data = @stream_socket_accept($dataServer, 10);

                if ($data === false) {
                    fwrite($client, "426 data connection failed\r\n");
                    break;
                }

                if ($command === 'STOR') {
                    @mkdir(dirname($target), 0775, true);
                    $contents = stream_get_contents($data);
                    file_put_contents($target, $contents === false ? '' : $contents);
                } else {
                    $contents = (string) file_get_contents($target);
                    fwrite($data, $restartAt > 0 ? substr($contents, $restartAt) : $contents);
                }

                fclose($data);
                fclose($dataServer);
                $dataServer = null;
                $restartAt = 0;

                fwrite($client, "226 transfer complete\r\n");
                break;

            case 'LIST':
            case 'NLST':
                if ($dataServer === null) {
                    fwrite($client, "425 no data connection\r\n");
                    break;
                }

                fwrite($client, "150 here comes the listing\r\n");
                $data = @stream_socket_accept($dataServer, 10);

                if ($data !== false) {
                    fclose($data);
                }

                fclose($dataServer);
                $dataServer = null;
                fwrite($client, "226 listing sent\r\n");
                break;

            default:
                fwrite($client, "502 not implemented\r\n");
        }
    }

    if ($dataServer !== null) {
        fclose($dataServer);
    }

    fclose($client);
}

fclose($server);
@unlink($marker);
