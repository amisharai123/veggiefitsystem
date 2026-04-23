<?php
// Start session if needed
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutrition & Meal Planner</title>
    <link rel="stylesheet" href="/Nutrition_System/header/header.css">
</head>
<body>

<header class="main-header">
    <div class="logo">
        <img src="/Nutrition_System/Web_image/logo.jpeg" alt="Logo">
        <h2>VeggieFit</h2>
    </div>

    <nav class="navbar">
        <ul>
            <li><a href="/Nutrition_System/home_page.php">Home</a></li>
            <li><a href="/Nutrition_System/User/about_us.php">About Us</a></li>

            <?php
                if (isset($_SESSION['username'])) {
                    echo '<li><a href="/Nutrition_System/Login/logout.php">Log Out</a></li>';
                } else {
                    echo '<li><a href="/Nutrition_System/Login/login.php">Login</a></li>';
                }
            ?>
        </ul>
    </nav>
</header>

</body>
</html>
