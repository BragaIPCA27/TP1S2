<?php
require_once 'config.php';

if (isset($_GET['silent']) && $_GET['silent'] === '1' && isset($_GET['cancel']) && $_GET['cancel'] === '1') {
	unset($_SESSION['auto_logout_request_ts']);
	session_write_close();
	http_response_code(204);
	exit;
}

if (isset($_GET['silent']) && $_GET['silent'] === '1' && isset($_GET['defer']) && $_GET['defer'] === '1') {
	$_SESSION['auto_logout_request_ts'] = time();
	session_write_close();
	http_response_code(204);
	exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
}

session_destroy();

if (isset($_GET['silent']) && $_GET['silent'] === '1') {
	http_response_code(204);
	exit;
}

header('Location: login.php');
exit;
