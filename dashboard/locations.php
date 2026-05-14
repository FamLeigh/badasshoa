<?php
// Locations are now managed inside the Settings page.
require __DIR__ . '/_bootstrap.php';

// Preserve ?action=new and ?action=edit&id=N links
$query = [];
if (isset($_GET['action'])) $query['action'] = $_GET['action'];
if (isset($_GET['id']))     $query['id']      = (int)$_GET['id'];
$qs = $query ? '?' . http_build_query($query) : '';
redirect('/dashboard/settings.php' . $qs . '#locations');
