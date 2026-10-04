<?php
// Helper de peticiones HTTP para hosting compartido.
// Orden de métodos (detección automática):
//   1. cURL (si la extensión existe)
//   2. file_get_contents + allow_url_fopen (si está activo)
//   3. fsockopen (socket directo; funciona sin los dos anteriores)
//
// Uso:
//   list($status, $body, $err, $metodo) = http_request('GET', $url, $opciones);
// $opciones: headers (array asociativo), body (string), timeout (segundos)

function http_request($method, $url, $options = array(), &$used = null) {
    $method  = strtoupper($method);
    $headers = isset($options['headers']) ? $options['headers'] : array();
    $body    = (isset($options['body']) && $options['body'] !== null) ? (string)$options['body'] : null;
    $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 20;

    // 1) cURL
    if (function_exists('curl_init')) {
        $used = 'curl';
        $ch = curl_init($url);
        $curlHeaders = array();
        foreach ($headers as $k => $v) { $curlHeaders[] = $k . ': ' . $v; }
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => $curlHeaders,
        ));
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($resp === false) { return array(0, '', $err); }
        return array($status, (string)$resp, '');
    }

    // 2) Streams (requiere allow_url_fopen = On)
    if ((bool)ini_get('allow_url_fopen')) {
        $used = 'allow_url_fopen';
        $headerLines = '';
        foreach ($headers as $k => $v) { $headerLines .= $k . ': ' . $v . "\r\n"; }
        $http = array(
            'method'          => $method,
            'header'          => $headerLines,
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'ignore_errors'   => true,
        );
        if ($body !== null) { $http['content'] = $body; }
        $ctx  = stream_context_create(array(
            'http' => $http,
            'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp !== false) {
            $status = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
                    $status = (int)$m[1];
                }
            }
            return array($status, (string)$resp, '');
        }
        // Si falla, cae al método 3
    }

    // 3) fsockopen (socket directo, sin cURL ni allow_url_fopen)
    if (function_exists('fsockopen')) {
        $used = 'fsockopen';
        return http_fsockopen_request($method, $url, $headers, $body, $timeout, 0);
    }

    $used = 'ninguno';
    return array(0, '', 'Este hosting no permite peticiones HTTP salientes: sin cURL, sin allow_url_fopen y sin fsockopen');
}

function http_fsockopen_request($method, $url, $headers, $body, $timeout, $redirects) {
    $parts = parse_url($url);
    if (!isset($parts['host'])) { return array(0, '', 'URL invalida'); }
    $scheme = (isset($parts['scheme']) && $parts['scheme'] === 'https') ? 'https' : 'http';
    $host   = $parts['host'];
    $port   = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $path   = (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $transport = ($scheme === 'https') ? 'ssl://' . $host : 'tcp://' . $host;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($transport, $port, $errno, $errstr, $timeout);
    if ($fp === false) {
        return array(0, '', 'fsockopen: ' . $errstr . ' (' . $errno . ')');
    }
    stream_set_timeout($fp, $timeout);

    $hdrs = array(
        'Host'       => $host . (($port === 80 || $port === 443) ? '' : ':' . $port),
        'Connection' => 'close',
    );
    foreach ($headers as $k => $v) { $hdrs[$k] = $v; }
    if ($body !== null) { $hdrs['Content-Length'] = strlen($body); }

    $req = $method . ' ' . $path . " HTTP/1.1\r\n";
    foreach ($hdrs as $k => $v) { $req .= $k . ': ' . $v . "\r\n"; }
    $req .= "\r\n";
    if ($body !== null) { $req .= $body; }

    fwrite($fp, $req);

    $raw = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') { break; }
        $raw .= $chunk;
    }
    fclose($fp);

    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) { return array(0, '', 'Respuesta sin cabeceras'); }
    $rawHeaders = substr($raw, 0, $pos);
    $respBody   = substr($raw, $pos + 4);

    // Decodificar transferencia chunked (los otros métodos lo hacen solos)
    if (preg_match('/^Transfer-Encoding:\s*chunked/im', $rawHeaders)) {
        $respBody = http_dechunk($respBody);
    }

    $status = 0;
    if (preg_match('#HTTP/\S+\s+(\d+)#', $rawHeaders, $m)) { $status = (int)$m[1]; }

    // Seguir redirecciones manualmente (301/302/303/307/308)
    if ($status >= 300 && $status < 400 && $redirects < 5) {
        if (preg_match('/^Location:\s*(.+)$/im', $rawHeaders, $lm)) {
            $location = trim($lm[1]);
            if (strpos($location, 'http://') === 0 || strpos($location, 'https://') === 0) {
                $newUrl = $location;
            } elseif (strpos($location, '/') === 0) {
                $newUrl = $scheme . '://' . $host . $location;
            } else {
                return array($status, $respBody, '');
            }
            return http_fsockopen_request($method, $newUrl, $headers, $body, $timeout, $redirects + 1);
        }
    }

    return array($status, $respBody, '');
}

function http_dechunk($body) {
    $out = '';
    $pos = 0;
    $len = strlen($body);
    while ($pos < $len) {
        $lineEnd = strpos($body, "\r\n", $pos);
        if ($lineEnd === false) { break; }
        $size = hexdec(trim(substr($body, $pos, $lineEnd - $pos)));
        if (!$size) { break; }
        $out .= substr($body, $lineEnd + 2, $size);
        $pos  = $lineEnd + 2 + $size + 2;
    }
    return $out;
}
