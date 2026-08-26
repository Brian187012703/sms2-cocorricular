<?php
// ============================================================
//  ATTENDANCE.PHP  (dashboard/)
//  Tracking Portal Router — Redirects to dedicated tracking views
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
  header('Location: ../auth/signin.php');
  exit;
}

$role = $_SESSION['role'] ?? 'student';

if ($role === 'student') {
  header('Location: tracking_history.php');
} else {
  header('Location: tracking_attendance_list.php');
}
exit;
