<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'ok' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput ?: '', true);

if (!is_array($payload)) {
    respond(400, [
        'ok' => false,
        'error' => 'Invalid JSON request body.'
    ]);
}

$url = isset($payload['url']) ? trim((string) $payload['url']) : '';
$method = strtoupper(trim((string) ($payload['method'] ?? 'GET')));
$body = $payload['body'] ?? null;
$headers = normalizeHeaders($payload['headers'] ?? null);

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    respond(400, [
        'ok' => false,
        'error' => 'A valid http or https URL is required.'
    ]);
}

$scheme = parse_url($url, PHP_URL_SCHEME);
if (!in_array($scheme, ['http', 'https'], true)) {
    respond(400, [
        'ok' => false,
        'error' => 'Only http and https URLs are allowed.'
    ]);
}

$allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
if (!in_array($method, $allowedMethods, true)) {
    respond(400, [
        'ok' => false,
        'error' => 'Unsupported HTTP method.'
    ]);
}

$defaultAuthHeader = loadDefaultAuthHeader();

$encodedBody = null;
if ($body !== null && $method !== 'GET') {
    $encodedBody = json_encode($body);
    if ($encodedBody === false) {
        respond(400, [
            'ok' => false,
            'error' => 'The request body could not be encoded as JSON.'
        ]);
    }
}

if (!$headers['ok']) {
    respond(400, [
        'ok' => false,
        'error' => $headers['error']
    ]);
}

$result = executeUpstreamRequest($url, $scheme, $method, $headers['headers'], $defaultAuthHeader, $encodedBody);
if (!$result['ok']) {
    respond(502, [
        'ok' => false,
        'error' => 'Upstream request failed.',
        'details' => $result['error']
    ]);
}

$status = $result['status'];
$responseBody = $result['body'];
$contentType = $result['contentType'];

$decodedBody = json_decode($responseBody, true);
$data = json_last_error() === JSON_ERROR_NONE ? $decodedBody : $responseBody;

respond($status > 0 ? $status : 502, [
    'ok' => $status >= 200 && $status < 300,
    'status' => $status,
    'contentType' => $contentType,
    'data' => $data
]);

function loadAuthKey(): string
{
    $envValue = getenv('API_AUTH_KEY');
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    $filePath = getenv('API_AUTH_KEY_FILE');
    if (!is_string($filePath) || trim($filePath) === '') {
        return '';
    }

    $resolvedPath = trim($filePath);
    if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
        return '';
    }

    $contents = file_get_contents($resolvedPath);
    if (!is_string($contents)) {
        return '';
    }

    return trim($contents);
}

function loadDefaultAuthHeader(): ?string
{
    $authKey = loadAuthKey();
    if ($authKey === '') {
        return null;
    }

    $headerName = getenv('API_AUTH_HEADER');
    $resolvedHeaderName = is_string($headerName) && trim($headerName) !== ''
        ? trim($headerName)
        : 'X-Auth-Key';

    return $resolvedHeaderName . ': ' . $authKey;
}

function normalizeHeaders(mixed $rawHeaders): array
{
    if ($rawHeaders === null) {
        return [
            'ok' => true,
            'headers' => []
        ];
    }

    if (!is_array($rawHeaders)) {
        return [
            'ok' => false,
            'error' => 'Headers must be provided as a JSON object.'
        ];
    }

    $headers = [];

    foreach ($rawHeaders as $name => $value) {
        $headerName = trim((string) $name);
        if ($headerName === '') {
            return [
                'ok' => false,
                'error' => 'Header names cannot be empty.'
            ];
        }

        if (!preg_match('/^[A-Za-z0-9-]+$/', $headerName)) {
            return [
                'ok' => false,
                'error' => 'Header names may only contain letters, numbers, and hyphens.'
            ];
        }

        if (!is_scalar($value) && $value !== null) {
            return [
                'ok' => false,
                'error' => 'Header values must be strings, numbers, booleans, or null.'
            ];
        }

        if ($value === null) {
            continue;
        }

        $headers[] = $headerName . ': ' . trim((string) $value);
    }

    return [
        'ok' => true,
        'headers' => $headers
    ];
}

function buildRequestHeaders(array $headers, ?string $defaultAuthHeader, ?string $encodedBody): array
{
    $resolvedHeaders = ['Accept: application/json'];
    $hasContentType = false;
    $hasDefaultAuthHeader = false;
    $defaultAuthHeaderName = null;

    if ($defaultAuthHeader !== null) {
        [$defaultName] = explode(':', $defaultAuthHeader, 2);
        $defaultAuthHeaderName = strtolower(trim($defaultName));
    }

    foreach ($headers as $header) {
        $resolvedHeaders[] = $header;

        [$name] = explode(':', $header, 2);
        $normalizedName = strtolower(trim($name));

        if ($normalizedName === 'content-type') {
            $hasContentType = true;
        }

        if ($defaultAuthHeaderName !== null && $normalizedName === $defaultAuthHeaderName) {
            $hasDefaultAuthHeader = true;
        }
    }

    if ($defaultAuthHeader !== null && !$hasDefaultAuthHeader) {
        $resolvedHeaders[] = $defaultAuthHeader;
    }

    if ($encodedBody !== null && !$hasContentType) {
        $resolvedHeaders[] = 'Content-Type: application/json';
    }

    return $resolvedHeaders;
}

function executeUpstreamRequest(string $url, string $scheme, string $method, array $headers, ?string $defaultAuthHeader, ?string $encodedBody): array
{
    if (extension_loaded('curl')) {
        $curlResult = executeWithPhpCurl($url, $scheme, $method, $headers, $defaultAuthHeader, $encodedBody);
        if ($curlResult['ok']) {
            return $curlResult;
        }

        if ($scheme === 'https' && isSslProtocolError($curlResult['error'])) {
            $systemCurlResult = executeWithSystemCurl($url, $method, $headers, $defaultAuthHeader, $encodedBody);
            if ($systemCurlResult['ok']) {
                return $systemCurlResult;
            }

            return $systemCurlResult;
        }

        return $curlResult;
    }

    return executeWithSystemCurl($url, $method, $headers, $defaultAuthHeader, $encodedBody);
}

function executeWithPhpCurl(string $url, string $scheme, string $method, array $headers, ?string $defaultAuthHeader, ?string $encodedBody): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'error' => 'Failed to initialize the upstream request.'
        ];
    }

    $requestHeaders = buildRequestHeaders($headers, $defaultAuthHeader, $encodedBody);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ];

    if ($scheme === 'https' && defined('CURL_SSLVERSION_TLSv1_2')) {
        $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
    }

    if ($encodedBody !== null) {
        $options[CURLOPT_POSTFIELDS] = $encodedBody;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    if ($response === false) {
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'ok' => false,
            'error' => $curlError !== '' ? $curlError : 'Unknown cURL error.'
        ];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);

    return [
        'ok' => true,
        'status' => $status > 0 ? $status : 502,
        'body' => $responseBody,
        'contentType' => $contentType
    ];
}

function executeWithSystemCurl(string $url, string $method, array $headers, ?string $defaultAuthHeader, ?string $encodedBody): array
{
    $curlBinary = '/usr/bin/curl';
    if (!is_executable($curlBinary)) {
        return [
            'ok' => false,
            'error' => 'System curl is not available for TLS fallback.'
        ];
    }

    $command = [
        $curlBinary,
        '--silent',
        '--show-error',
        '--location',
        '--request',
        $method,
        '--tlsv1.2',
        '--write-out',
        "\n__CODE__:%{http_code}\n__CTYPE__:%{content_type}",
        $url
    ];

    foreach (buildRequestHeaders($headers, $defaultAuthHeader, $encodedBody) as $header) {
        $command[] = '--header';
        $command[] = $header;
    }

    if ($encodedBody !== null) {
        $command[] = '--data';
        $command[] = $encodedBody;
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'error' => 'Failed to start system curl.'
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        return [
            'ok' => false,
            'error' => trim($stderr) !== '' ? trim($stderr) : 'System curl request failed.'
        ];
    }

    $matches = [];
    if (!preg_match("/\n__CODE__:(\d+)\n__CTYPE__:(.*)$/s", $stdout, $matches)) {
        return [
            'ok' => false,
            'error' => 'Could not parse the system curl response.'
        ];
    }

    $responseBody = substr($stdout, 0, (int) strpos($stdout, "\n__CODE__:"));

    return [
        'ok' => true,
        'status' => (int) $matches[1],
        'body' => $responseBody,
        'contentType' => trim($matches[2])
    ];
}

function isSslProtocolError(string $error): bool
{
    $normalized = strtolower($error);

    return str_contains($normalized, 'ssl') || str_contains($normalized, 'tls') || str_contains($normalized, 'protocol version');
}

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
