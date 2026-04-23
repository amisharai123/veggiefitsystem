<?php
session_start();
require_once "db_connection.php";
require_once "meal_algorithm.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: Login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* ---- Guard: already have a plan today? ---- */
$checkStmt = $conn->prepare("
    SELECT plan_id FROM meal_plans
    WHERE user_id = ? AND date = CURDATE()
");
$checkStmt->execute([$user_id]);

if ($checkStmt->fetch()) {
    $_SESSION['message'] = "You already have a meal plan for today.";
    header("Location: dashboard.php");
    exit();
}

/* ---- Fetch user profile (including goal columns) ---- */
$stmt = $conn->prepare("
    SELECT username, gender, age, height_cm, weight_kg,
           diet_preference, activity_level,
           target_weight_loss, target_weeks
    FROM users WHERE user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: Login/login.php");
    exit();
}

/* ---- BMI check ---- */
$bmi         = calculateBMI($user['weight_kg'], $user['height_cm']);
$bmiCategory = getBMICategory($bmi);

if ($bmiCategory === 'Underweight') {
    $_SESSION['warning'] = "Meal planning is not recommended for underweight individuals. Please consult a healthcare professional.";
    header("Location: dashboard.php");
    exit();
}

/* ---- Calorie & protein targets (time-frame aware) ---- */
$bmr  = calculateBMR($user['gender'], $user['weight_kg'], $user['height_cm'], $user['age']);
$tdee = calculateTDEE($bmr, $user['activity_level'] ?? 'Moderate');

// Use the goal-aware calculation instead of a flat −500 rule
$goalResult    = calculateTimeFrameCalories(
    $tdee,
    $user['target_weight_loss'] ?? 4.0,   // default 4 kg
    $user['target_weeks']       ?? 8,      // default 8 weeks
    $user['gender']
);
$calorieTarget = $goalResult['calories'];
$proteinTarget = calculateProteinTarget($user['weight_kg'], $user['activity_level'] ?? 'Moderate');

// Store projected rate for dashboard display
$_SESSION['projected_weekly_loss'] = $goalResult['weekly_loss_kg'];
$_SESSION['daily_deficit']         = $goalResult['daily_deficit'];

/* ---- Generate plan ---- */
$date = date('Y-m-d');

try {
    $conn->beginTransaction();

    /*
     * CONTENT-BASED MATCHING (cosine similarity in PHP — see meal_algorithm.php).
     * SQL fetches up to 100 candidates with the same gender + diet.
     * PHP scores all of them with cosineSimilarity() and picks the best.
     * A match is only accepted if the score >= SIMILARITY_THRESHOLD (0.90).
     */
    $matchedPlan = findSimilarUserPlan(
        $user_id,
        $user['gender'],
        $user['weight_kg'],
        $user['height_cm'],
        $user['age'],
        $user['activity_level']   ?? 'Moderate',
        $user['diet_preference']  ?? 'Vegetarian'
    );

    $similarity = $matchedPlan ? round($matchedPlan['similarity_score'] * 100, 1) : 0;

    if ($matchedPlan && $similarity >= 90) {
        /* Similar user found — clone their plan, swap one item for variety */

        $planStmt = $conn->prepare("
            INSERT INTO meal_plans (user_id, date, total_calories, total_protein, total_carbs, total_fats)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $planStmt->execute([
            $user_id,
            $date,
            $matchedPlan['total_calories'],
            $matchedPlan['total_protein'],
            $matchedPlan['total_carbs'],
            $matchedPlan['total_fats'],
        ]);

        $plan_id = $conn->lastInsertId();

        $success = generateMealPlanFromTemplate(
            $matchedPlan['plan_id'],
            $plan_id,
            $calorieTarget,
            $proteinTarget
        );

        if ($success) {
            $_SESSION['success'] = "Meal plan generated using content-based matching (Similarity: {$similarity}%).";
        }

    } else {
        /* No similar user — generate a fresh plan from scratch */

        $planStmt = $conn->prepare("
            INSERT INTO meal_plans (user_id, date, total_calories, total_protein)
            VALUES (?, ?, ?, 0)
        ");
        $planStmt->execute([$user_id, $date, $calorieTarget]);

        $plan_id = $conn->lastInsertId();
        $success = generateMealPlan($plan_id, $calorieTarget, $proteinTarget);

        if ($success) {
            if ($matchedPlan) {
                $_SESSION['success'] = "Fresh meal plan generated (Low similarity: {$similarity}%).";
            } else {
                $_SESSION['success'] = "Fresh meal plan generated successfully!";
            }
        }
    }

    if ($success) {
        $conn->commit();
    } else {
        $conn->rollBack();
        $_SESSION['error'] = "Failed to generate meal plan.";
    }

} catch (PDOException $e) {
    $conn->rollBack();
    $_SESSION['error'] = "Error generating meal plan: " . $e->getMessage();
}

header("Location: dashboard.php");
exit();