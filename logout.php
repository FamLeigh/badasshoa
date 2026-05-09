<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

audit('user.logout');
logout_user();
session_start(); // start a fresh session for the flash
flash('success', "You've been signed out.");
redirect('/');
