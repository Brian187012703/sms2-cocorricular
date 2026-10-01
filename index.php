<?php
// ============================================================
//  INDEX.PHP — Laravel 11 + Vue 3 / Inertia Entry Point
//  Directs all incoming traffic to the modern Laravel front controller
// ============================================================
$target = 'public/';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $target);
exit;
