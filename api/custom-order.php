<?php
/**
 * POST /api/custom-order.php
 * Sends a custom order inquiry and optional reference files to the studio.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

ggs_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

function ggs_custom_order_error(string $message, string $code, int $status): void
{
  ggs_json_response([
    'success' => false,
    'error' => $message,
    'error_code' => $code,
  ], $status);
}

function ggs_custom_order_ini_bytes(string $value): int
{
  $value = trim($value);
  if ($value === '') return 0;

  $unit = strtolower(substr($value, -1));
  $size = (int) $value;
  if ($unit === 'g') return $size * 1024 * 1024 * 1024;
  if ($unit === 'm') return $size * 1024 * 1024;
  if ($unit === 'k') return $size * 1024;
  return $size;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postLimit = ggs_custom_order_ini_bytes((string) ini_get('post_max_size'));
if ($postLimit > 0 && $contentLength > $postLimit && empty($_POST) && empty($_FILES)) {
  ggs_custom_order_error('The request is too large. Please choose smaller files.', 'request_too_large', 413);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$body = str_contains($contentType, 'application/json') ? ggs_read_json_body() : $_POST;
$name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
$email = trim(is_string($body['email'] ?? null) ? $body['email'] : '');
$need = trim(is_string($body['need'] ?? null) ? $body['need'] : '');
$budget = trim(is_string($body['budget'] ?? null) ? $body['budget'] : '');

if ($name === '' || $need === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  ggs_custom_order_error('Please fill in your name, a valid email, and what you need.', 'invalid_form', 422);
}

$maxFiles = 5;
$maxFileBytes = 5 * 1024 * 1024;
$maxTotalBytes = 8 * 1024 * 1024;
$allowedTypes = [
  'jpg' => ['image/jpeg'],
  'jpeg' => ['image/jpeg'],
  'png' => ['image/png'],
  'pdf' => ['application/pdf'],
  // STL and native SolidWorks files may be detected as generic binary data.
  'stl' => ['application/octet-stream', 'application/sla', 'application/vnd.ms-pki.stl', 'model/stl', 'text/plain'],
  'sldprt' => ['application/octet-stream', 'application/CDFV2', 'application/x-ole-storage'],
  'sldasm' => ['application/octet-stream', 'application/CDFV2', 'application/x-ole-storage'],
  'slddrw' => ['application/octet-stream', 'application/CDFV2', 'application/x-ole-storage'],
];
$attachments = [];
$totalBytes = 0;

if (isset($_FILES['attachments'])) {
  $uploaded = $_FILES['attachments'];
  foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
    if (!is_array($uploaded[$field] ?? null)) {
      ggs_custom_order_error('Please upload reference files using the file picker.', 'invalid_upload', 422);
    }
  }
  $fileCount = count($uploaded['name']);
  foreach (['type', 'tmp_name', 'error', 'size'] as $field) {
    if (count($uploaded[$field]) !== $fileCount) {
      ggs_custom_order_error('Please upload reference files using the file picker.', 'invalid_upload', 422);
    }
  }
  if ($fileCount > $maxFiles) {
    ggs_custom_order_error('You can attach up to 5 files.', 'too_many_files', 422);
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  for ($i = 0; $i < $fileCount; $i++) {
    $error = (int) ($uploaded['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) continue;
    if ($error !== UPLOAD_ERR_OK) {
      $code = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
        ? 'file_too_large'
        : 'upload_failed';
      $message = $code === 'file_too_large'
        ? 'A file is too large. Each file must be no larger than 5 MB.'
        : 'A file could not be uploaded. Please try again.';
      ggs_custom_order_error($message, $code, 422);
    }

    $tmpPath = (string) ($uploaded['tmp_name'][$i] ?? '');
    $fileSize = (int) ($uploaded['size'][$i] ?? 0);
    $originalName = (string) ($uploaded['name'][$i] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!is_uploaded_file($tmpPath) || !isset($allowedTypes[$extension])) {
      ggs_custom_order_error('Use JPG, PNG, STL, SolidWorks or PDF reference files.', 'invalid_file_type', 422);
    }
    if ($fileSize <= 0 || $fileSize > $maxFileBytes) {
      ggs_custom_order_error('Each file must be no larger than 5 MB.', 'file_too_large', 422);
    }

    $mimeType = $finfo->file($tmpPath);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
      ggs_custom_order_error('A file type could not be verified. Please use JPG, PNG, STL, SolidWorks or PDF.', 'invalid_file_type', 422);
    }

    $totalBytes += $fileSize;
    if ($totalBytes > $maxTotalBytes) {
      ggs_custom_order_error('The combined size of all files must be no larger than 8 MB.', 'files_too_large', 422);
    }

    $safeName = basename(str_replace('\\', '/', $originalName));
    $safeName = preg_replace('/[\x00-\x1F\x7F]/', '', $safeName) ?: '';
    $safeName = trim(substr($safeName, 0, 150));
    if ($safeName === '') $safeName = 'reference-' . ($i + 1) . '.' . $extension;

    $attachments[] = ['path' => $tmpPath, 'name' => $safeName];
  }
}

$html = '
  <h2>New custom order inquiry</h2>
  <p><strong>Name:</strong> ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>
  <p><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>
  <p><strong>Budget:</strong> ' . htmlspecialchars($budget ?: 'Not specified', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>
  <p><strong>What they need:</strong><br>' . nl2br(htmlspecialchars($need, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>
';

if ($attachments) {
  $fileNames = array_map(
    static fn(array $attachment): string => htmlspecialchars($attachment['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    $attachments
  );
  $html .= '<p><strong>Attached reference files:</strong> ' . implode(', ', $fileNames) . '</p>';
}

$sent = ggs_send_mail('contact@gomezgarage.eu', "[GGS] Custom order inquiry from {$name}", $html, null, $attachments);

ggs_json_response(['success' => $sent], $sent ? 200 : 502);
