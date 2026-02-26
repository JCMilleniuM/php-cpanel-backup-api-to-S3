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
define('S3_REGION', 'us-east-1'); // e.g., auto, us-east-1
define('S3_BUCKET', 'my-backup-bucket'); // Bucket name
define('S3_ACCESS_KEY', 'YOUR_ACCESS_KEY');
define('S3_SECRET_KEY', 'YOUR_SECRET_KEY');
define('S3_PATH_PREFIX', 'backups/'); // Optional prefix (folder)

// Notification
define('NOTIFY_EMAIL', 'admin@example.com');
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Send a request to the cPanel UAPI.
 */
function cpanel_api_request(string $module, string $function, array $params = []): array
{
    $query = http_build_query($params);
    $url = sprintf('https://%s:%d/execute/%s/%s?%s', CPANEL_HOST, CPANEL_PORT, $module, $function, $query);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error)
        return ['success' => false, 'error' => 'cURL error: ' . $error];

    $data = json_decode($response, true);
    return $data ?: ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $response];
}

/**
 * Uploads a file to S3 using AWS Signature v4
 */
function upload_to_s3(string $filepath): bool
{
    if (!file_exists($filepath)) {
        echo "[ERROR] File not found: $filepath\n";
        return false;
    }

    $filename = basename($filepath);
    $s3Key = trim(S3_PATH_PREFIX, '/') . '/' . $filename;
    // Handle root prefix
    if ($s3Key[0] === '/')
        $s3Key = substr($s3Key, 1);

    $endpoint = rtrim(S3_ENDPOINT, '/');
    // Handle bucket in hostname vs path style. 
    // Cloudflare R2 / generic S3 often uses path style: https://endpoint/bucket/key
    // AWS S3 often uses virtual host: https://bucket.s3.amazonaws.com
    // For simplicity, we'll assume the user provides the generic endpoint and we append bucket/key (Path Style)
    // UNLESS the endpoint already contains the bucket.

    $uri = "/$s3Key";
    $url = "$endpoint/" . S3_BUCKET . $uri;

    // AWS Signature v4 Constants
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $timestamp = time();
    $amzDate = gmdate('Ymd\THis\Z', $timestamp);
    $dateStamp = gmdate('Ymd', $timestamp);

    // 1. Canonical Headers
    $host = parse_url($endpoint, PHP_URL_HOST);
    $payloadHash = hash_file('sha256', $filepath);

    $canonicalHeaders = "host:$host\n" .
        "x-amz-content-sha256:$payloadHash\n" .
        "x-amz-date:$amzDate\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    // 2. Canonical Request
    $canonicalRequest = "PUT\n" .
        "/" . S3_BUCKET . $uri . "\n" . // Canonical URI (Path Style)
        "\n" . // Canonical Query String (empty)
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    // 3. String to Sign
    $credentialScope = "$dateStamp/" . S3_REGION . "/$service/aws4_request";
    $stringToSign = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

    // 4. Calculate Signature
    $kSecret = 'AWS4' . S3_SECRET_KEY;
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', S3_REGION, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    // 5. Authorization Header
    $authorization = "$algorithm Credential=" . S3_ACCESS_KEY . "/$credentialScope, " .
        "SignedHeaders=$signedHeaders, Signature=$signature";

    $headers = [
        "Authorization: $authorization",
        "x-amz-date: $amzDate",
        "x-amz-content-sha256: $payloadHash",
        "Content-Type: application/octet-stream",
        "Content-Length: " . filesize($filepath)
    ];

    echo "Uploading to S3 ($url)...\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_PUT => true,
        CURLOPT_INFILE => fopen($filepath, 'r'),
        CURLOPT_INFILESIZE => filesize($filepath),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => false, // We want to read the error body
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "[ERROR] cURL Error: $error\n";
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "[SUCCESS] Upload complete.\n";
        return true;
    }
    else {
        echo "[ERROR] S3 Upload Failed (HTTP $httpCode):\n$response\n";
        return false;
    }
}

/**
 * Polls the home directory for a completed backup file.
 */
function wait_for_backup_completion(int $timeout = 600): ?string
{
    $start = time();
    $homeDir = getenv('HOME') ?: '/home/' . CPANEL_USER;
    echo "Monitoring $homeDir for new backup file...\n";

    $fileFound = false;
    $targetFile = null;
    $files = [];

    // Wait for file to appear
    while (time() - $start < 120) {
        $files = glob("$homeDir/backup-*.tar.gz");
        if (!empty($files)) {
            usort($files, function ($a, $b) {
                return filemtime($a) <=> filemtime($b); });
            $newestFile = end($files);
            if (time() - filemtime($newestFile) < 120) {
                $targetFile = $newestFile;
                $fileFound = true;
                break;
            }
        }
        sleep(5);
    }

    if (!$fileFound)
        return null;

    echo "Found: " . basename($targetFile) . "\nWaiting for write completion...\n";

    $lastSize = -1;
    $stableCount = 0;

    while (time() - $start < $timeout) {
        clearstatcache(true, $targetFile);
        $currentSize = filesize($targetFile);
        echo "Size: " . number_format($currentSize / 1024 / 1024, 2) . " MB... \r";

        if ($currentSize === $lastSize) {
            $stableCount++;
        }
        else {
            $lastSize = $currentSize;
            $stableCount = 0;
        }

        if ($stableCount >= 6 && $currentSize > 1024 * 1024) {
            echo "\nFile stable.\n";
            return $targetFile;
        }
        sleep(5);
    }
    return null;
}

// ─── Main Execution ───────────────────────────────────────────────────────────
echo "Starting cPanel Backup to S3...\n";

// 1. Trigger Local Backup
echo "Requesting local backup...\n";
$response = cpanel_api_request('Backup', 'fullbackup_to_homedir', ['email' => NOTIFY_EMAIL]);
$result = $response['result'] ?? $response;

if ((!isset($result['status']) || $result['status'] == 0)) {
    echo "[WARN] API reported error: " . print_r($result['errors'] ?? $result, true) . "\n";
    echo "Attempting to monitor anyway...\n";
}
else {
    echo "Backup initiated (PID: " . ($result['data']['pid'] ?? '?') . ").\n";
}

// 2. Wait for File
$backupFile = wait_for_backup_completion();

if ($backupFile) {
    echo "Local backup ready: $backupFile\n";

    // 3. Upload to S3
    if (upload_to_s3($backupFile)) {
        // 4. Delete Local
        if (unlink($backupFile))
            echo "Local file deleted.\n";

        // Notify Success
        $subject = "[Backup Success] Saved to S3";
        $body = "Backup of " . CPANEL_HOST . " uploaded to S3 bucket " . S3_BUCKET . ".\nFile: " . basename($backupFile);
        mail(NOTIFY_EMAIL, $subject, $body);
        echo "[OK] process initiated complete.\n";
    }
    else {
        // Notify Failure
        $subject = "[Backup FAILED] S3 Upload Error";
        mail(NOTIFY_EMAIL, $subject, "Failed to upload backup to S3. Check server logs.");
        echo "[ERROR] Upload failed.\n";
    }
}
else {
    echo "[ERROR] Backup creation timed out.\n";
}
