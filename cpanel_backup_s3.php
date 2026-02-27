<?php

/**
 * cPanel Full Backup to S3-Compatible Storage
 * Supports AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces, etc.
 */

// ─── Configuration ────────────────────────────────────────────────────────────
// cPanel Server Details
define('CPANEL_HOST', 'cpanel.example.com');
define('CPANEL_PORT', 2083);
define('CPANEL_USER', 'your_username');
define('CPANEL_API_TOKEN', 'YOUR_API_TOKEN');

// S3 / Object Storage Details
define('S3_ENDPOINT', 'https://s3.amazonaws.com'); // e.g., https://<accountid>.r2.cloudflarestorage.com
define('S3_REGION', 'us-east-1');                  // e.g., auto, us-east-1
define('S3_BUCKET', 'my-backup-bucket');            // Bucket name
define('S3_ACCESS_KEY', 'YOUR_ACCESS_KEY');
define('S3_SECRET_KEY', 'YOUR_SECRET_KEY');
define('S3_PATH_PREFIX', 'backups/');               // Optional prefix (folder)

// Notification
define('NOTIFY_EMAIL', 'admin@example.com');

// Backup Retention: maximum number of backups to keep in the S3 folder.
// Set to 0 to disable retention management (keep all backups).
define('MAX_BACKUPS', 5);
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Send a request to the cPanel UAPI.
 *
 * @param string $module   UAPI module name (e.g., 'Backup').
 * @param string $function UAPI function name (e.g., 'fullbackup_to_homedir').
 * @param array  $params   Optional query parameters.
 *
 * @return array Decoded JSON response or error array.
 */
function cpanel_api_request(string $module, string $function, array $params = []): array
{
    $query = http_build_query($params);
    $url = sprintf('https://%s:%d/execute/%s/%s?%s', CPANEL_HOST, CPANEL_PORT, $module, $function, $query);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'cURL error: ' . $error];
    }

    $data = json_decode($response, true);
    return $data ?: ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $response];
}

/**
 * Builds the AWS Signature v4 authorization headers for an S3 request.
 *
 * @param string $method       HTTP method (e.g., 'PUT', 'DELETE', 'GET').
 * @param string $url          Full request URL.
 * @param string $canonicalUri URI path for the canonical request (e.g., '/bucket/key').
 * @param string $payloadHash  SHA-256 hash of the request body.
 * @param array  $extraHeaders Additional headers to include and sign (key => value).
 *
 * @return array HTTP headers array ready for cURL.
 */
function s3_build_auth_headers(
    string $method,
    string $url,
    string $canonicalUri,
    string $payloadHash,
    array  $extraHeaders = []
): array {
    $service   = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $timestamp = time();
    $amzDate   = gmdate('Ymd\THis\Z', $timestamp);
    $dateStamp = gmdate('Ymd', $timestamp);
    $host      = parse_url(rtrim(S3_ENDPOINT, '/'), PHP_URL_HOST);

    // Build canonical headers (sorted, lower-cased)
    $headersToSign = array_merge(
        [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ],
        array_change_key_case($extraHeaders, CASE_LOWER)
    );
    ksort($headersToSign);

    $canonicalHeaders  = '';
    $signedHeadersList = [];
    foreach ($headersToSign as $k => $v) {
        $canonicalHeaders   .= "$k:$v\n";
        $signedHeadersList[] = $k;
    }
    $signedHeaders = implode(';', $signedHeadersList);

    // Canonical request
    $canonicalRequest = "$method\n$canonicalUri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

    // String to sign
    $credentialScope = "$dateStamp/" . S3_REGION . "/$service/aws4_request";
    $stringToSign    = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

    // Signing key derivation
    $kSecret   = 'AWS4' . S3_SECRET_KEY;
    $kDate     = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion   = hash_hmac('sha256', S3_REGION, $kDate, true);
    $kService  = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning  = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    // Authorization header
    $authorization = "$algorithm Credential=" . S3_ACCESS_KEY . "/$credentialScope, " .
        "SignedHeaders=$signedHeaders, Signature=$signature";

    $headers = [
        "Authorization: $authorization",
        "x-amz-date: $amzDate",
        "x-amz-content-sha256: $payloadHash",
    ];

    // Append extra headers (Content-Type, Content-Length, etc.)
    foreach ($extraHeaders as $k => $v) {
        $headers[] = "$k: $v";
    }

    return $headers;
}

/**
 * Uploads a local file to S3 using AWS Signature v4.
 *
 * @param string $filepath Local path to the file to upload.
 *
 * @return int|false Size of the uploaded file in bytes, or false on failure.
 */
function upload_to_s3(string $filepath): int|false
{
    if (!file_exists($filepath)) {
        echo "[ERROR] File not found: $filepath\n";
        return false;
    }

    $filesize    = filesize($filepath);
    $filename    = basename($filepath);
    // Strip ALL whitespace (including injected newlines) then remove any slashes.
    $s3Key       = trim(trim(S3_PATH_PREFIX), '/') . '/' . $filename;

    // Remove any leading slash from the key
    if ($s3Key[0] === '/') {
        $s3Key = substr($s3Key, 1);
    }

    $endpoint     = rtrim(S3_ENDPOINT, '/');
    $canonicalUri = '/' . S3_BUCKET . '/' . $s3Key;
    $url          = $endpoint . $canonicalUri;
    $payloadHash  = hash_file('sha256', $filepath);

    $extraHeaders = [
        'Content-Type'   => 'application/octet-stream',
        'Content-Length' => (string) $filesize,
    ];

    $headers = s3_build_auth_headers('PUT', $url, $canonicalUri, $payloadHash, $extraHeaders);

    echo "Uploading to S3 ($url)...\n";

    $fh = fopen($filepath, 'r');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $filesize,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR    => false, // Read error body
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($error) {
        echo "[ERROR] cURL Error: $error\n";
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "[SUCCESS] Upload complete.\n";
        return $filesize;
    }

    echo "[ERROR] S3 Upload Failed (HTTP $httpCode):\n$response\n";
    return false;
}

/**
 * Lists all backup objects in the configured S3 prefix.
 * Uses the S3 ListObjectsV2 API.
 *
 * @return array Array of ['key' => string, 'last_modified' => int, 'size' => int], oldest first.
 */
function list_s3_backups(): array
{
    $endpoint  = rtrim(S3_ENDPOINT, '/');
    // Strip ALL whitespace (including injected newlines) then remove any slashes.
    $prefix    = trim(trim(S3_PATH_PREFIX), '/') . '/';
    $service   = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $timestamp = time();
    $amzDate   = gmdate('Ymd\THis\Z', $timestamp);
    $dateStamp = gmdate('Ymd', $timestamp);
    $host      = parse_url($endpoint, PHP_URL_HOST);

    // Canonical query string (sorted, RFC-3986 encoded)
    $queryParams = ['list-type' => '2', 'prefix' => $prefix];
    ksort($queryParams);
    $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

    $payloadHash      = hash('sha256', '');
    $canonicalUri     = '/' . S3_BUCKET;
    $canonicalHeaders = "host:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n";
    $signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = "GET\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

    $credentialScope = "$dateStamp/" . S3_REGION . "/$service/aws4_request";
    $stringToSign    = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

    $kSecret   = 'AWS4' . S3_SECRET_KEY;
    $kDate     = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion   = hash_hmac('sha256', S3_REGION, $kDate, true);
    $kService  = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning  = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "$algorithm Credential=" . S3_ACCESS_KEY . "/$credentialScope, " .
        "SignedHeaders=$signedHeaders, Signature=$signature";

    $url     = "$endpoint$canonicalUri?$canonicalQueryString";
    $headers = [
        "Authorization: $authorization",
        "x-amz-date: $amzDate",
        "x-amz-content-sha256: $payloadHash",
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "[WARN] Could not list S3 backups: $error\n";
        return [];
    }

    // Parse the XML ListBucketResult response
    $xml = @simplexml_load_string($response);
    if ($xml === false) {
        echo "[WARN] Could not parse S3 list response.\n";
        return [];
    }

    $objects = [];
    foreach ($xml->Contents as $item) {
        $objects[] = [
            'key'           => (string) $item->Key,
            'last_modified' => strtotime((string) $item->LastModified),
        ];
    }

    // Sort oldest first so we can shift the oldest off the front
    usort($objects, fn($a, $b) => $a['last_modified'] <=> $b['last_modified']);

    return $objects;
}

/**
 * Returns the total size (in bytes) of all objects under the configured S3 prefix.
 * Accepts an already-fetched backup list to avoid a redundant S3 API call.
 *
 * @param array $backups Optional pre-fetched list from list_s3_backups().
 *                       Fetched automatically when omitted.
 *
 * @return int Total size in bytes of all objects in the prefix.
 */
function get_s3_folder_size(array $backups = []): int
{
    // Fetch the list only when not already available
    if (empty($backups)) {
        $backups = list_s3_backups();
    }

    // Sum the 'size' field of every object returned by list_s3_backups()
    return (int) array_sum(array_column($backups, 'size'));
}

/**
 * Deletes a single object from S3 by its key.
 *
 * @param string $key The S3 object key to delete.
 *
 * @return bool True on success, false on failure.
 */
function delete_s3_object(string $key): bool
{
    $endpoint     = rtrim(S3_ENDPOINT, '/');
    $canonicalUri = '/' . S3_BUCKET . '/' . ltrim($key, '/');
    $url          = $endpoint . $canonicalUri;
    $payloadHash  = hash('sha256', ''); // Empty body for DELETE

    $headers = s3_build_auth_headers('DELETE', $url, $canonicalUri, $payloadHash);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "[ERROR] cURL error deleting $key: $error\n";
        return false;
    }

    // S3 returns 204 No Content on successful DELETE
    if ($httpCode === 204 || ($httpCode >= 200 && $httpCode < 300)) {
        echo "[SUCCESS] Deleted old backup from S3: $key\n";
        return true;
    }

    echo "[ERROR] Failed to delete $key from S3 (HTTP $httpCode):\n$response\n";
    return false;
}

/**
 * Enforces the backup retention limit on the S3 prefix.
 * Deletes the oldest backup(s) until the count is within MAX_BACKUPS.
 *
 * @param int $maxBackups Maximum number of backups to keep (0 = unlimited).
 *
 * @return array List of deleted S3 object keys.
 */
function enforce_retention_limit(int $maxBackups): array
{
    if ($maxBackups <= 0) {
        // Retention management is disabled
        return [];
    }

    $backups = list_s3_backups();
    $deleted = [];

    // Pop from the front (oldest) until we are within the limit
    while (count($backups) > $maxBackups) {
        $oldest = array_shift($backups);
        if (delete_s3_object($oldest['key'])) {
            $deleted[] = $oldest['key'];
        }
    }

    return $deleted;
}

/**
 * Polls the home directory for a completed backup file.
 *
 * @param int $timeout Maximum total seconds to wait for the backup (default 600).
 *
 * @return string|null Path to the completed backup file, or null on timeout.
 */
function wait_for_backup_completion(int $timeout = 600): ?string
{
    $start   = time();
    $homeDir = getenv('HOME') ?: '/home/' . CPANEL_USER;
    echo "Monitoring $homeDir for new backup file...\n";

    $fileFound  = false;
    $targetFile = null;

    // Phase 1: Wait up to 120 s for the backup file to appear
    while (time() - $start < 120) {
        $files = glob("$homeDir/backup-*.tar.gz");
        if (!empty($files)) {
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            $newestFile = end($files);
            if (time() - filemtime($newestFile) < 120) {
                $targetFile = $newestFile;
                $fileFound  = true;
                break;
            }
        }
        sleep(5);
    }

    if (!$fileFound) {
        return null;
    }

    echo "Found: " . basename($targetFile) . "\nWaiting for write completion...\n";

    $lastSize    = -1;
    $stableCount = 0;

    // Phase 2: Wait until the file size stops growing (fully written)
    while (time() - $start < $timeout) {
        clearstatcache(true, $targetFile);
        $currentSize = filesize($targetFile);
        echo "Size: " . number_format($currentSize / 1024 / 1024, 2) . " MB... \r";

        if ($currentSize === $lastSize) {
            $stableCount++;
        } else {
            $lastSize    = $currentSize;
            $stableCount = 0;
        }

        // 6 stable reads (30 s) with at least 1 MB means the file is complete
        if ($stableCount >= 6 && $currentSize > 1024 * 1024) {
            echo "\nFile stable.\n";
            return $targetFile;
        }
        sleep(5);
    }

    return null;
}

/**
 * Formats a duration in seconds into a human-readable string (e.g., "3m 42s").
 *
 * @param int $seconds Duration in seconds.
 *
 * @return string Formatted duration string.
 */
function format_duration(int $seconds): string
{
    $hours   = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs    = $seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = "{$hours}h";
    }
    if ($minutes > 0) {
        $parts[] = "{$minutes}m";
    }
    $parts[] = "{$secs}s";

    return implode(' ', $parts);
}

/**
 * Formats a byte count into a human-readable size string (e.g., "1.23 GB").
 *
 * @param int $bytes    Number of bytes.
 * @param int $decimals Decimal places to show (default 2).
 *
 * @return string Formatted size string.
 */
function format_bytes(int $bytes, int $decimals = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i     = 0;
    $size  = (float) $bytes;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return number_format($size, $decimals) . ' ' . $units[$i];
}

// ─── Main Execution ───────────────────────────────────────────────────────────
$scriptStart = time();
$hostname    = CPANEL_HOST;
$mailHeaders = implode("\r\n", [
    "From: Backup to S3 - {$hostname} <noreply@{$hostname}>",
    "Reply-To: noreply@{$hostname}",
    "X-Mailer: PHP/" . phpversion()
]);

echo "Starting cPanel Backup to S3...\\n";

// 1. Trigger Local Backup via cPanel UAPI
echo "Requesting local backup...\n";
$response = cpanel_api_request('Backup', 'fullbackup_to_homedir', ['email' => NOTIFY_EMAIL]);
$result   = $response['result'] ?? $response;

if (!isset($result['status']) || $result['status'] == 0) {
    echo "[WARN] API reported error: " . print_r($result['errors'] ?? $result, true) . "\n";
    echo "Attempting to monitor anyway...\n";
} else {
    echo "Backup initiated (PID: " . ($result['data']['pid'] ?? '?') . ").\n";
}

// 2. Wait for Backup File to be Ready
$backupFile = wait_for_backup_completion();

if ($backupFile) {
    echo "Local backup ready: $backupFile\n";

    // 3. Upload to S3 and capture uploaded file size (returns bytes or false)
    $uploadedBytes = upload_to_s3($backupFile);

    if ($uploadedBytes !== false) {
        // 4. Delete Local Copy
        if (unlink($backupFile)) {
            echo "Local file deleted.\n";
        }

        // 5. Enforce Backup Retention: delete oldest S3 backup(s) if over the limit
        $deletedBackups = enforce_retention_limit(MAX_BACKUPS);
        $duration       = time() - $scriptStart;

        // 6. Get total S3 folder size after retention clean-up (reuse the fresh list)
        $remainingBackups  = list_s3_backups();
        $s3FolderTotalSize = get_s3_folder_size($remainingBackups);

        // 7. Send Success Notification
        $subject  = "[Backup Success] " . CPANEL_HOST . " -> S3";
        $body     = "Backup completed successfully.\n\n";
        $body    .= "Host          : " . CPANEL_HOST . "\n";
        $body    .= "Bucket        : " . S3_BUCKET . "\n";
        $body    .= "File          : " . basename($backupFile) . "\n";
        $body    .= "Size on S3    : " . format_bytes($uploadedBytes) . "\n";
        $body    .= "Folder total  : " . format_bytes($s3FolderTotalSize) .
            " (" . count($remainingBackups) . " backup(s))\n";
        $body    .= "Duration      : " . format_duration($duration) . "\n";
        $body    .= "Completed at  : " . date('Y-m-d H:i:s T') . "\n";

        if (!empty($deletedBackups)) {
            $body .= "\nRetention limit (" . MAX_BACKUPS . " max) reached. Removed old backup(s):\n";
            foreach ($deletedBackups as $deletedKey) {
                $body .= "  - " . basename($deletedKey) . "\n";
            }
        }

        mail(NOTIFY_EMAIL, $subject, $body);
        echo "[OK] Process complete. Notification sent.\n";
    } else {
        // Upload Failed Notification
        $duration = time() - $scriptStart;
        $subject  = "[Backup FAILED] " . CPANEL_HOST . " S3 Upload Error";
        $body     = "Backup upload to S3 failed.\n\n";
        $body    .= "Host    : " . CPANEL_HOST . "\n";
        $body    .= "Bucket  : " . S3_BUCKET . "\n";
        $body    .= "File    : " . basename($backupFile) . "\n";
        $body    .= "Duration: " . format_duration($duration) . "\n\n";
        $body    .= "Please check the server logs for details.";
        mail(NOTIFY_EMAIL, $subject, $body);
        echo "[ERROR] Upload failed. Notification sent.\n";
    }
} else {
    // Backup Timeout Notification
    $duration = time() - $scriptStart;
    $subject  = "[Backup FAILED] " . CPANEL_HOST . " Backup Timeout";
    $body     = "Backup creation timed out after " . format_duration($duration) . ".\n\n";
    $body    .= "Host: " . CPANEL_HOST . "\n";
    $body    .= "The backup file was not detected in the home directory in time.\n";
    $body    .= "Please check the cPanel backup logs.";
    mail(NOTIFY_EMAIL, $subject, $body);
    echo "[ERROR] Backup creation timed out. Notification sent.\n";
}
