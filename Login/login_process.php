<?php
session_start();
include '../db_connection.php';

/* ✅ CSRF VALIDATION FIRST */
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF validation failed");
}

/* ✅ CHECK REQUEST METHOD */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $identifier = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($identifier) || empty($password)) {
        header("Location: login.php?error=Please fill all fields");
        exit();
    }

    try {

        /* 1️⃣ ADMIN LOGIN */
        $adminStmt = $conn->prepare("
            SELECT * FROM admin WHERE email = :email LIMIT 1
        ");
        $adminStmt->execute([':email' => $identifier]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password'])) {

            session_regenerate_id(true);

            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['email'] = $admin['email'];
            $_SESSION['role'] = $admin['role'];

            header("Location: ../AdminPage/admin_dashboard.php");
            exit();
        }

        /* 2️⃣ USER LOGIN */
        $stmt = $conn->prepare("
            SELECT * FROM users 
            WHERE email = :identifier OR username = :identifier 
            LIMIT 1
        ");
        $stmt->execute([':identifier' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {

            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = 'user';
                $_SESSION['login_success'] = true;

                header("Location: /Nutrition_System/dashboard.php");
                exit();

            } else {
                header("Location: login.php?error=Invalid credentials");
                exit();
            }

        } else {
            header("Location: login.php?error=No account found");
            exit();
        }

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }

} else {
    header("Location: login.php");
    exit();
}
?>