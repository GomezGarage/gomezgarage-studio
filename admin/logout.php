<?php
require_once __DIR__ . '/../includes/auth.php';

ggs_admin_logout();
header('Location: /studio/admin/login.php');
exit;
