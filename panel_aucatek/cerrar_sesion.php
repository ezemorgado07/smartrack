<?php
require_once 'auth.php';
panel_logout();
header('Location: index.php');
exit();
?>
