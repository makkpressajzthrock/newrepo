<?php
require_once 'config.php';

// Protect this page
if (!isLoggedIn()) {
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h2>Welcome, <?= sanitize($_SESSION['username']) ?>! 🎉</h2>
        <p style="text-align:center; color:#666; margin-bottom:10px;">You are successfully logged in.</p>
        <p style="text-align:center; color:#999; font-size:13px;">User ID: <?= $_SESSION['user_id'] ?></p>
        <a href="logout.php" class="btn btn-logout">Logout</a>
    </div>
</body>
</html>
