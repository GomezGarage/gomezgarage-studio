<?php
/**
 * POST /api/custom-order.php
 * Sends a custom order inquiry and optional reference files to the studio.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/customer-auth.php';

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
$customerAccount = ggs_customer_current();

if (!ggs_customer_verify_csrf()) {
  ggs_custom_order_error('Your session expired. Refresh the page and try again.', 'csrf_invalid', 403);
}

if ($name === '' || $need === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  ggs_custom_order_error('Please fill in your name, a valid email, and what you need.', 'invalid_form', 422);
}
if ($customerAccount && strtolower($customerAccount['email']) !== strtolower($email)) {
  ggs_custom_order_error('Use the email address linked to your account, or sign out before sending this request.', 'account_email_mismatch', 422);
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

$inquiryNumber = 'GGC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
$attachmentNames = array_map(static fn(array $attachment): string => $attachment['name'], $attachments);
$db = ggs_db();
$db->beginTransaction();
try {
  $db->prepare('INSERT INTO custom_inquiries (inquiry_number, customer_account_id, customer_name, customer_email, need_text, budget, attachment_names, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$inquiryNumber, $customerAccount['id'] ?? null, $name, strtolower($email), $need, $budget, json_encode($attachmentNames), 'received']);
  $inquiryId = (int) $db->lastInsertId();
  $db->prepare('INSERT INTO custom_inquiry_history (inquiry_id, status, note) VALUES (?, ?, ?)')
    ->execute([$inquiryId, 'received', 'Request submitted']);
  $db->commit();
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('GGS custom inquiry could not be saved: ' . $e->getMessage());
  ggs_custom_order_error('We could not save your request right now. Please try again.', 'save_failed', 503);
}

$inquiry = [
  'id' => $inquiryId,
  'inquiry_number' => $inquiryNumber,
  'customer_account_id' => $customerAccount['id'] ?? null,
  'customer_name' => $name,
  'customer_email' => strtolower($email),
  'need_text' => $need,
  'budget' => $budget,
  'attachment_names' => json_encode($attachmentNames),
  'status' => 'received',
];

$html = '
  <h2>New custom order inquiry</h2>
  <p><strong>Request number:</strong> ' . htmlspecialchars($inquiryNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>
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

$sent = ggs_send_mail('contact@gomezgarage.eu', "[GGS] Custom order inquiry {$inquiryNumber} from {$name}", $html, null, $attachments);
ggs_send_customer_custom_received($inquiry);

if (!$sent) error_log('GGS custom inquiry owner notification email failed for ' . $inquiryNumber);
ggs_json_response(['success' => true, 'inquiry_number' => $inquiryNumber, 'notification_sent' => $sent], 200);
