<?php
require_once __DIR__ . '/../includes/customer-auth.php';
ggs_customer_start_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ggs_customer_verify_csrf()) {
  ggs_customer_logout();
}
header('Location: /studio/account/login.php');
exit;
