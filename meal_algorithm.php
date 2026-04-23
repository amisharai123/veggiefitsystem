<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "db_connection.php";

// ── HEALTH CALCULATIONS ──────────────────────────────────────

function calculateBMI($weight, $height_cm) {
    $height_m = $height_cm / 100;
    return round($weight / ($height_m * $height_m), 2);
}

// Mifflin-St Jeor equation
function calculateBMR($gender, $weight, $height_cm, $age) {
    if (strtolower($gender) === 'male') {
        return (10 * $weight) + (6.25 * $height_cm) - (5 * $age) + 5;
    }
    return (10 * $weight) + (6.25 * $height_cm) - (5 * $age) - 161;
}

// BMR x activity multiplier = total daily energy expenditure
function calculateTDEE($bmr, $activity_level) {
    $factors = [
        'Sedentary'   => 1.2,
        'Light'       => 1.375,
        'Moderate'    => 1.55,
        'Active'      => 1.725,
        'Very Active' => 1.9,
    ];
    return $bmr * ($factors[$activity_level] ?? 1.375);
}

// Calculates daily calorie target based on goal weight loss and time frame
// Formula: daily deficit = (kg x 7700) / (weeks x 7), capped at 1000 kcal/day
function calculateTimeFrameCalories($tdee, $target_loss_kg, $target_weeks, $gender = 'female') {
    $target_loss_kg = ($target_loss_kg > 0) ? (float)$target_loss_kg : 4.0;
    $target_weeks   = ($target_weeks   > 0) ? (int)$target_weeks     : 8;

    $total_deficit_needed    = $target_loss_kg * 7700;
    $requested_daily_deficit = $total_deficit_needed / ($target_weeks * 7);
    $safe_daily_deficit      = min($requested_daily_deficit, 1000); // cap for safety

    // Minimum safe intake: 1500 kcal men, 1200 kcal women
    $safe_min       = (strtolower($gender) === 'male') ? 1500 : 1200;
    $calorie_target = max($safe_min, round($tdee - $safe_daily_deficit));

    $actual_deficit = $tdee - $calorie_target;
    $weekly_loss_kg = round(($actual_deficit * 7) / 7700, 2);

    return [
        'calories'       => (int)$calorie_target,
        'daily_deficit'  => (int)$actual_deficit,
        'weekly_loss_kg' => $weekly_loss_kg,
    ];
}

// If calorieTarget given: protein = (calories x ratio) / 4, else weight x g/kg multiplier
function calculateProteinTarget($weight, $activity_level = 'Moderate', $calorieTarget = 0) {
    if ($calorieTarget > 0) {
        $ratio = (stripos($activity_level, 'Active') !== false) ? 0.25 : 0.22;
        return round(($calorieTarget * $ratio) / 4);
    }
    $map = [
        'Sedentary'   => 0.8,
        'Light'       => 0.9,
        'Moderate'    => 1.0,
        'Active'      => 1.2,
        'Very Active' => 1.5,
    ];
    return round($weight * ($map[$activity_level] ?? 1.0));
}

function getBMICategory($bmi) {
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 25)   return 'Normal';
    if ($bmi < 30)   return 'Overweight';
    return 'Obese';
}

// ── CONTENT-BASED MATCHING — COSINE SIMILARITY ──
define('SIMILARITY_THRESHOLD', 0.90);

function activityIndex($level) {
    $map = [
        'sedentary'   => 0,
        'light'       => 1,
        'moderate'    => 2,
        'active'      => 3,
        'very active' => 4,
    ];
    return $map[strtolower(trim($level))] ?? 2;
}

// Scales any value to [0, 1] within the given range
function normalise($value, $min, $max) {
    if ($max === $min) return 0.0;
    return (max($min, min($max, (float)$value)) - $min) / ($max - $min);
}

function buildFeatureVector($weight, $height, $age, $activity_level, $bmi) {
    return [
        normalise($weight,                         40,  150),
        normalise($height,                        140,  200),
        normalise($age,                            15,   80),
        normalise(activityIndex($activity_level),   0,    4),
        normalise($bmi,                            15,   40),
    ];
}

// Returns cosine similarity score between two vectors in [0, 1]
function cosineSimilarity(array $a, array $b) {
    $dot = $magA = $magB = 0.0;
    for ($i = 0; $i < count($a); $i++) {
        $dot  += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }
    $magA = sqrt($magA);
    $magB = sqrt($magB);
    if ($magA == 0 || $magB == 0) return 0.0;
    return $dot / ($magA * $magB);
}

function findSimilarUserPlan($current_user_id, $gender, $weight, $height, $age, $activity_level, $diet_preference) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT u.user_id, u.weight_kg, u.height_cm, u.age, u.activity_level,
               mp.plan_id, mp.total_calories, mp.total_protein, mp.total_carbs, mp.total_fats
        FROM meal_plans mp
        JOIN users u ON mp.user_id = u.user_id
        WHERE u.user_id                     != :uid
          AND LOWER(TRIM(u.gender))          = LOWER(TRIM(:gender))
          AND LOWER(TRIM(u.diet_preference)) = LOWER(TRIM(:diet))
          AND LOWER(TRIM(u.activity_level))  = LOWER(TRIM(:activity))
          AND ABS(u.age - :age)             <= 2
          AND ABS(u.weight_kg - :weight)    <= 2
          AND ABS(u.height_cm - :height)    <= 2
        ORDER BY mp.plan_id DESC
        LIMIT 100
    ");
    $stmt->execute([
        ':uid'      => $current_user_id, 
        ':gender'   => $gender, 
        ':diet'     => $diet_preference,
        ':activity' => $activity_level,
        ':age'      => $age,
        ':weight'   => $weight,
        ':height'   => $height
    ]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) return null;

    $currentBMI = calculateBMI($weight, $height);
    $queryVec   = buildFeatureVector($weight, $height, $age, $activity_level, $currentBMI);

    $bestScore = -1;
    $bestPlan  = null;

    foreach ($candidates as $candidate) {
        $candidateBMI = calculateBMI($candidate['weight_kg'], $candidate['height_cm']);
        $candidateVec = buildFeatureVector(
            $candidate['weight_kg'], $candidate['height_cm'],
            $candidate['age'], $candidate['activity_level'], $candidateBMI
        );
        $score = cosineSimilarity($queryVec, $candidateVec);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestPlan  = $candidate;
        }
    }

    if ($bestPlan) {
        $bestPlan['similarity_score'] = round($bestScore, 4);
        return $bestPlan;
    }
    return null;
}

// ── FOOD HELPERS ─────────────────────────────────────────────

// Picks one random food matching keywords, within calorie limit and not already used
function getFoodByKeywords($keywords, $meal, $states, $calLimit, $excludeIds = []) {
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM foods WHERE category = ? AND diet_type = 'Vegetarian'");
    $stmt->execute([$meal]);
    $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pool)) return null;

    $candidates = [];

    foreach ($pool as $food) {
        if (in_array($food['food_id'], $excludeIds))  continue;
        if (!in_array($food['food_state'], $states))  continue;
        if ((float)$food['calories'] > $calLimit)     continue;

        $matchesKeyword = empty($keywords);
        foreach ($keywords as $k) {
            if (stripos($food['food_name'], $k) !== false) { $matchesKeyword = true; break; }
        }
        if (!$matchesKeyword) continue;

        $food['_score'] = mt_rand(1, 1000); // random score for variety
        $candidates[]   = $food;
    }

    // Fallback: ignore excludeIds if DB is too small to find a fresh match
    if (empty($candidates)) {
        foreach ($pool as $food) {
            if (!in_array($food['food_state'], $states)) continue;
            if ((float)$food['calories'] > $calLimit)    continue;

            $matchesKeyword = empty($keywords);
            foreach ($keywords as $k) {
                if (stripos($food['food_name'], $k) !== false) { $matchesKeyword = true; break; }
            }
            if (!$matchesKeyword) continue;

            $food['_score'] = mt_rand(1, 1000);
            $candidates[]   = $food;
        }
    }

    if (empty($candidates)) return null;

    usort($candidates, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return $candidates[0];
}

// Returns the highest-protein food from a known protein-rich keyword list
function getExtraProteinFood($meal, $states, $excludeIds = []) {
    global $conn;

    $proteinFoods  = ['Paneer','Dal','Chana','Chickpea','Rajma','Sprouts','Tofu'];
    $conditions    = array_map(fn($p) => "food_name LIKE ?", $proteinFoods);
    $keywordParams = array_map(fn($p) => "%$p%", $proteinFoods);

    $excludeClause = '';
    if (!empty($excludeIds)) {
        $excludeClause = "AND food_id NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")";
    }

    $sql = "
        SELECT * FROM foods
        WHERE category   = ?
          AND diet_type  = 'Vegetarian'
          AND food_state IN (" . implode(',', array_fill(0, count($states), '?')) . ")
          AND (" . implode(' OR ', $conditions) . ")
          $excludeClause
        ORDER BY protein DESC LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$meal], $states, $keywordParams, $excludeIds));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── MAIN MEAL GENERATOR ──────────────────────────────────────

function generateMealPlan($plan_id, $dailyCalories, $dailyProtein) {
    global $conn;

    $meals = [
        'Breakfast' => ['ratio' => 0.25, 'states' => ['Raw','Fresh','Plain','Cooked','Boiled']],
        'Lunch'     => ['ratio' => 0.35, 'states' => ['Cooked','Boiled']],
        'Dinner'    => ['ratio' => 0.30, 'states' => ['Cooked','Boiled']],
        'Snack'     => ['ratio' => 0.10, 'states' => ['Raw','Fresh','Plain']],
    ];

    // Lunch/Dinner meal budget split: carb 45%, protein food 35%, veg 20%
    $roleRatios = ['carb' => 0.45, 'protein' => 0.35, 'veg' => 0.20];

    $roles = [
        'carb'    => ['Rice','Roti','Chapati','Poha','Upma'],
        'protein' => ['Dal','Paneer','Chana','Chickpea','Rajma','Sprouts'],
        'veg'     => ['Veg','Sabji','Curry','Saag'],
    ];

    $totalCalories  = $totalProtein = $totalCarbs = $totalFats = 0;
    $usedFoodIds    = [];
    $proteinCeiling = $dailyProtein * 1.10; // allow 10% buffer over protein target

    foreach ($meals as $meal => $config) {

        $mealCalLimit  = $dailyCalories * $config['ratio'];
        $foodsToInsert = [];

        // Lunch & Dinner: pick one food per role within its calorie share
        if ($meal === 'Lunch' || $meal === 'Dinner') {

            $remainingMealBudget   = $mealCalLimit;
            $remainingProteinSpace = $proteinCeiling - $totalProtein;

            foreach (['carb', 'protein', 'veg'] as $role) {
                $roleCalLimit = min($mealCalLimit * $roleRatios[$role], $remainingMealBudget);
                if ($roleCalLimit <= 0) break;

                $food = getFoodByKeywords($roles[$role], $meal, $config['states'], $roleCalLimit, $usedFoodIds);

                // Skip food if it alone would push protein over the ceiling
                if ($food && $food['protein'] > $remainingProteinSpace) $food = null;

                if ($food) {
                    $foodsToInsert[]        = $food;
                    $usedFoodIds[]          = $food['food_id'];
                    $remainingMealBudget   -= $food['calories'];
                    $remainingProteinSpace -= $food['protein'];
                }
            }

        // Breakfast & Snack: greedily add foods until meal calorie budget is 95% full
        } else {

            $mealCaloriesSoFar = 0;
            $stmt = $conn->prepare("
                SELECT * FROM foods
                WHERE category   = ?
                  AND diet_type  = 'Vegetarian'
                  AND food_state IN (" . implode(',', array_fill(0, count($config['states']), '?')) . ")
                ORDER BY RAND()
            ");
            $stmt->execute(array_merge([$meal], $config['states']));
            $allFoods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allFoods as $food) {
                if (!$food) continue;
                if (in_array($food['food_id'], $usedFoodIds)) continue;
                if ($mealCaloriesSoFar + $food['calories'] > $mealCalLimit) continue; // skip if over budget
                if ($totalProtein + $food['protein'] > $proteinCeiling) continue;     // skip if over protein

                $foodsToInsert[]    = $food;
                $usedFoodIds[]      = $food['food_id'];
                $mealCaloriesSoFar += $food['calories'];

                if ($mealCaloriesSoFar >= $mealCalLimit * 0.95) break;
            }
        }

        foreach ($foodsToInsert as $food) {
            $conn->prepare("
                INSERT INTO meal_items (plan_id, meal_type, food_id, quantity) VALUES (?, ?, ?, 1)
            ")->execute([$plan_id, $meal, $food['food_id']]);

            $totalCalories += $food['calories'];
            $totalProtein  += $food['protein'];
            $totalCarbs    += $food['carbs'];
            $totalFats     += $food['fats'];
        }
    }

    // Protein boost: add one high-protein food if still > 5g under target and calories allow
    $proteinGap       = $dailyProtein - $totalProtein;
    $caloriesLeftOver = $dailyCalories - $totalCalories;

    if ($proteinGap > 5 && $caloriesLeftOver > 0) {
        foreach (['Snack', 'Dinner'] as $boostMeal) {
            $states = ($boostMeal === 'Snack') ? ['Raw','Fresh','Plain'] : ['Cooked','Boiled'];
            $extra  = getExtraProteinFood($boostMeal, $states, $usedFoodIds);

            if ($extra && $extra['calories'] <= $caloriesLeftOver) {
                $conn->prepare("
                    INSERT INTO meal_items (plan_id, meal_type, food_id, quantity) VALUES (?, ?, ?, 1)
                ")->execute([$plan_id, $boostMeal, $extra['food_id']]);

                $totalCalories += $extra['calories'];
                $totalProtein  += $extra['protein'];
                $totalCarbs    += $extra['carbs'];
                $totalFats     += $extra['fats'];
                break;
            }
        }
    }

    return $conn->prepare("
        UPDATE meal_plans SET total_calories=?, total_protein=?, total_carbs=?, total_fats=? WHERE plan_id=?
    ")->execute([round($totalCalories), round($totalProtein,2), round($totalCarbs,2), round($totalFats,2), $plan_id]);
}

// ── TEMPLATE-BASED GENERATION ────────────────────────────────
// Used when a similar user is found via cosine matching.
// Borrows meal structure from matched plan, fills foods fresh
// to hit this user's calorie and protein targets.

function cloneMealPlan($source_plan_id, $new_plan_id) {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO meal_items (plan_id, meal_type, food_id, quantity)
        SELECT :new_id, meal_type, food_id, quantity
        FROM meal_items WHERE plan_id = :src_id
    ");
    return $stmt->execute([':new_id' => $new_plan_id, ':src_id' => $source_plan_id]);
}

function generateMealPlanFromTemplate($source_plan_id, $new_plan_id, $calorieTarget, $proteinTarget) {
    global $conn;

    if (!cloneMealPlan($source_plan_id, $new_plan_id)) return false;

    // Fetch all cloned meal items to pick one to swap for variety
    $stmt = $conn->prepare("SELECT meal_item_id, meal_type, food_id FROM meal_items WHERE plan_id = ?");
    $stmt->execute([$new_plan_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($items)) {
        // Pick one random food item to swap
        $swapIndex = array_rand($items);
        $itemToSwap = $items[$swapIndex];

        // Fetch original food to know its macro profile (to maintain the meal's integrity)
        $stmt = $conn->prepare("SELECT * FROM foods WHERE food_id = ?");
        $stmt->execute([$itemToSwap['food_id']]);
        $originalFood = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($originalFood) {
            // Keep similar macronutrient structure (e.g., high protein swaps with high protein)
            $isHighProtein = $originalFood['protein'] >= 8;
            $proteinCondition = $isHighProtein ? "AND protein >= 8" : "AND protein < 8";

            $stmt = $conn->prepare("
                SELECT * FROM foods 
                WHERE category = ? 
                  AND diet_type = 'Vegetarian' 
                  AND food_id != ? 
                  $proteinCondition
                ORDER BY RAND() 
                LIMIT 1
            ");
            $stmt->execute([$itemToSwap['meal_type'], $itemToSwap['food_id']]);
            $newFood = $stmt->fetch(PDO::FETCH_ASSOC);

            // If a macro-matching substitute is found, update it. Otherwise fallback to any food in that category.
            if (!$newFood) {
                $stmt = $conn->prepare("
                    SELECT * FROM foods 
                    WHERE category = ? AND diet_type = 'Vegetarian' AND food_id != ? 
                    ORDER BY RAND() LIMIT 1
                ");
                $stmt->execute([$itemToSwap['meal_type'], $itemToSwap['food_id']]);
                $newFood = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($newFood) {
                $conn->prepare("UPDATE meal_items SET food_id = ? WHERE meal_item_id = ?")
                     ->execute([$newFood['food_id'], $itemToSwap['meal_item_id']]);
            }
        }
    }

    // Recalculate totals for the whole plan after the swap
    $stmt = $conn->prepare("
        SELECT 
            SUM(f.calories * mi.quantity) as total_cal, 
            SUM(f.protein * mi.quantity) as total_pro, 
            SUM(f.carbs * mi.quantity) as total_carb, 
            SUM(f.fats * mi.quantity) as total_fat
        FROM meal_items mi
        JOIN foods f ON mi.food_id = f.food_id
        WHERE mi.plan_id = ?
    ");
    $stmt->execute([$new_plan_id]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    // Update plan with the newly recalculated totals
    if ($totals) {
        return $conn->prepare("
            UPDATE meal_plans 
            SET total_calories=?, total_protein=?, total_carbs=?, total_fats=? 
            WHERE plan_id=?
        ")->execute([
            round($totals['total_cal'] ?? 0), 
            round($totals['total_pro'] ?? 0, 2), 
            round($totals['total_carb'] ?? 0, 2), 
            round($totals['total_fat'] ?? 0, 2), 
            $new_plan_id
        ]);
    }
    
    return true;
}
