<?php
session_start();
include '../db_connection.php';

if (isset($_POST['register'])) {

    // Collect & sanitize inputs //
    $old = [
        'username'            => trim($_POST['username'] ?? ''),
        'email'               => trim($_POST['email'] ?? ''),
        'password'            => $_POST['password'] ?? '',
        'confirm_password'    => $_POST['confirm_password'] ?? '',
        'gender'              => $_POST['gender'] ?? '',
        'age'                 => $_POST['age'] ?? '',
        'height_cm'           => $_POST['height_cm'] ?? '',
        'weight_kg'           => $_POST['weight_kg'] ?? '',
        'activity_level'      => $_POST['activity_level'] ?? '',
        // ── NEW ──
        'target_weight_loss'  => $_POST['target_weight_loss'] ?? '',
        'target_weeks'        => $_POST['target_weeks'] ?? '',
    ];

    $username           = $old['username'];
    $email              = $old['email'];
    $password           = $old['password'];
    $confirmPassword    = $old['confirm_password'];
    $gender             = $old['gender'];
    $age                = (int)   ($old['age']       !== '' ? $old['age']       : 0);
    $height_cm          = (float) ($old['height_cm'] !== '' ? $old['height_cm'] : 0);
    $weight_kg          = (float) ($old['weight_kg'] !== '' ? $old['weight_kg'] : 0);
    $activity_level     = $old['activity_level'];
    // ── NEW ──
    $target_weight_loss = (float) ($old['target_weight_loss'] !== '' ? $old['target_weight_loss'] : 0);
    $target_weeks       = (int)   ($old['target_weeks']       !== '' ? $old['target_weeks']       : 0);

    // Field-wise errors so we can color each input.
    $fieldErrors = [];

    // Validation (field by field) //
    if ($username === '')        $fieldErrors['username']         = "Full name is required.";
    if ($email === '')           $fieldErrors['email']            = "Email is required.";
    if ($password === '')        $fieldErrors['password']         = "Password is required.";
    if ($confirmPassword === '') $fieldErrors['confirm_password'] = "Confirm password is required.";
    if ($gender === '')          $fieldErrors['gender']           = "Gender is required.";
    if ($age === 0)              $fieldErrors['age']              = "Age is required.";
    if ($height_cm === 0.0)      $fieldErrors['height_cm']        = "Height is required.";
    if ($weight_kg === 0.0)      $fieldErrors['weight_kg']        = "Weight is required.";
    if ($activity_level === '')  $fieldErrors['activity_level']   = "Activity level is required.";
    // ── NEW validations ──
    if ($target_weight_loss <= 0) $fieldErrors['target_weight_loss'] = "Please enter how much weight you want to lose.";
    if ($target_weeks <= 0)       $fieldErrors['target_weeks']       = "Please enter how many weeks you want to achieve your goal in.";

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = "Invalid email format.";
    }

    if ($email !== '' && preg_match('/^[A-Z]/', $email)) {
        $fieldErrors['email'] = "Email must not start with a capital letter.";
    }

    if ($username !== '' && !preg_match('/^[A-Z][a-z]+(\s[A-Z][a-z]+)*$/', $username)) {
        $fieldErrors['username'] = "Full name must start with a capital letter, each word's first letter capitalized, only letters and spaces allowed.";
    }

    if ($password !== '' && !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/', $password)) {
        $fieldErrors['password'] = "Password must be at least 8 chars and include uppercase, lowercase, number and special character.";
    }

    if ($password !== '' && $confirmPassword !== '' && $password !== $confirmPassword) {
        $fieldErrors['confirm_password'] = "Passwords do not match.";
    }

    if ($age !== 0 && ($age < 10 || $age > 100)) {
        $fieldErrors['age'] = "Age must be between 10 and 100.";
    }

    $valid_genders = ['Male', 'Female', 'Other'];
    if ($gender !== '' && !in_array($gender, $valid_genders, true)) {
        $fieldErrors['gender'] = "Invalid gender selected.";
    }

    if ($height_cm !== 0.0) {
        if ($height_cm <= 0) {
            $fieldErrors['height_cm'] = "Height must be greater than 0.";
        } elseif ($height_cm < 50) {
            $height_cm = $height_cm * 30.48;
            if ($height_cm > 250) {
                $fieldErrors['height_cm'] = "Height cannot exceed 250 cm.";
            }
        } elseif ($height_cm > 250) {
            $fieldErrors['height_cm'] = "Height cannot exceed 250 cm.";
        }
    }

    if ($weight_kg !== 0.0 && ($weight_kg < 25 || $weight_kg > 300)) {
        $fieldErrors['weight_kg'] = "Weight must be between 25 kg and 300 kg.";
    }

    // ── NEW range checks ──
    if ($target_weight_loss > 0 && ($target_weight_loss < 0.5 || $target_weight_loss > 50)) {
        $fieldErrors['target_weight_loss'] = "Weight loss goal must be between 0.5 kg and 50 kg.";
    }

    if ($target_weeks > 0 && ($target_weeks < 1 || $target_weeks > 104)) {
        $fieldErrors['target_weeks'] = "Target weeks must be between 1 and 104 (2 years max).";
    }
    
   // 2. Safety Ratio Check (ONLY if weeks are valid and greater than 0)
if (empty($fieldErrors['target_weeks']) && $target_weight_loss > 0 && $target_weeks > 0) {
    $loss_per_week = $target_weight_loss / $target_weeks;

    // If the user tries to lose more than 1.0kg per week, it is unhealthy.
    if ($loss_per_week > 1.0) {
        $fieldErrors['target_weight_loss'] = "This goal is too aggressive. For your safety, please aim for a recommended 0.5kg to 1kg of weight loss per week.";
        $fieldErrors['target_weeks'] = "Try increasing the number of weeks to reach this weight goal safely.";
    }
}

    // Activity level — now stores the label string, not the multiplier
    $valid_levels = ['Sedentary', 'Light', 'Moderate', 'Active', 'Very Active'];
    if ($activity_level !== '' && !in_array($activity_level, $valid_levels, true)) {
        $fieldErrors['activity_level'] = "Invalid activity level selected.";
    }

    if (!empty($fieldErrors)) {
        $_SESSION['register_old']          = $old;
        $_SESSION['register_field_errors'] = $fieldErrors;
        header("Location: register.php");
        exit();
    }

    try {
        // Check email uniqueness //
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $fieldErrors['email'] = "This email is already registered.";
            $_SESSION['register_old']          = $old;
            $_SESSION['register_field_errors'] = $fieldErrors;
            header("Location: register.php");
            exit();
        }

        // Insert user — now includes the two goal columns //
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users 
                    (username, email, password, gender, age, height_cm, weight_kg,
                     activity_level, diet_preference,
                     target_weight_loss, target_weeks)
                VALUES 
                    (:username, :email, :password, :gender, :age, :height_cm, :weight_kg,
                     :activity_level, 'Vegetarian',
                     :target_weight_loss, :target_weeks)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':username'           => $username,
            ':email'              => $email,
            ':password'           => $hashedPassword,
            ':gender'             => $gender,
            ':age'                => $age,
            ':height_cm'          => $height_cm,
            ':weight_kg'          => $weight_kg,
            ':activity_level'     => $activity_level,
            // ── NEW ──
            ':target_weight_loss' => $target_weight_loss,
            ':target_weeks'       => $target_weeks,
        ]);

        header("Location: login.php?registered=success");
        exit();

    } catch (PDOException $e) {
        $_SESSION['register_old']          = $old;
        $_SESSION['register_field_errors'] = [
            'email' => 'Database error. Please try again.'
        ];
        header("Location: register.php");
        exit();
    }
}
?>
