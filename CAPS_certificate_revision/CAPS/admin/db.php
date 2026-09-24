<?php

// Prevent direct browser access to this config file
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/security.php';

// Philippine time for date()/strtotime() (XAMPP's PHP default is Europe/Berlin).
date_default_timezone_set('Asia/Manila');



$host = 'localhost';
$db   = 'barangay_db'; // Siguraduhing ito ang pangalan sa MySQL Workbench
$user = 'root';
$pass = 'password'; // Default ng XAMPP ay empty
$charset = 'utf8mb4';

/** * 1. PDO CONNECTION (Ito ang gamit ng process_staff.php mo)
 */
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+08:00'"); // NOW()/CURDATE() in Philippine time too
} catch (\PDOException $e) {
    error_log("PDO Connection failed: " . $e->getMessage());
    http_response_code(500);
    die("A server error occurred. Please try again later.");
}

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    error_log("MySQLi Connection failed: " . $conn->connect_error);
    http_response_code(500);
    die("A server error occurred. Please try again later.");
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");
$logo_placeholder = "https://via.placeholder.com/150";
$residents = [];

$staff = [
    [
        'Employee_id' => '', 
        'Name' => '', 
        'Position' => '', 
        'is_logged_in' => true,
        'Personal_email' => '',
        'Phone' => '',
        'Address' => '',
        'WorkLoad' => [''],
        'Work_email' => ''
    ],
];

$officials = [
    [
        'OfficialID' => '',      
        'Name' => '',
        'Position' => '',
        'TermStart' => '',
        'TermEnd' => '',    
        'Email' => '',
        'Phone' => '',
        'Address' => '',
        'Image' => ''
    ]
];

// Re-declared residents based on your snippet
$residents = [
    ['id' => '', 'name' => ''], 
];

$residents = [
    [
        'ResidentID' => '',
        'FirstName' => '',
        'MiddleName' => '',
        'LastName' => '',
        'Suffix' => '',
        'Gender' => '',
        'BirthDate' => '',
        'Age' => 0,
        'CivilStatus' => '',
        'Phone' => '',
        'Email' => '',
        'Address' => '',
        'EmploymentStatus' => '',
        'Occupation' => '',
        'Education' => '',
        'LifeStatus' => '',
        'IsPWD' => false,
        'IsSenior' => false,
        'IsHead' => false,
        'IsDeceased' => false
    ],
];

$blotter_cases = [
    ['id' => '', 'type' => '', 'role' => 'Complainant', 'date' => '', 'status' => ''],
];

$complaints = [
    ['id' => '', 'title' => '', 'date' => '', 'status' => ''],
];

/** * 3. CLAIMS TRACKER PLACEHOLDER (DAGDAG)
 */
$claims_tracker = []; // Placeholder para sa mga data mula sa table na gagawin natin

?>