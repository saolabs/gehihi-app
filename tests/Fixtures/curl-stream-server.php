<?php

// Isolated TLS fixture: the parent test trusts this certificate only.
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2]]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (! $server) {
    exit(1);
}
fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
fflush(STDOUT);
while ($connection = @stream_socket_accept($server, 10)) {
    $line = fgets($connection);
    $path = explode(' ', $line ?: '')[1] ?? '/';
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
    }
    if ($path === '/redirect') {
        fwrite($connection, "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1/private\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    } elseif ($path === '/zero') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 1\r\nConnection: close\r\n\r\n0");
    } elseif ($path === '/oversized') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n");
        for ($i = 0; $i < 700; $i++) {
            if (@fwrite($connection, str_repeat('x', 8192)) === false) {
                break;
            }
        }
    } elseif ($path === '/truncated') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 9999\r\nConnection: close\r\n\r\nfirst");
    } else {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\ndata: first\n\n");
        fflush($connection);
        usleep(1000000);
        @fwrite($connection, "data: second\n\n");
    }
    fclose($connection);
}
fclose($server);
