<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

require_once "data.php";

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// Role → Emp-ID prefix mapping
function getEmpPrefix($role) {
    $map = [
        'Salesman'      => 'BHFSM',
        'Sales Officer' => 'BHFSO',
        'ASM'           => 'BHFAS',
        'RSM'           => 'BHFRS',
        'ZSM'           => 'BHFZS',
        'Sales Head'    => 'BHFSH'
    ];
    return isset($map[$role]) ? $map[$role] : 'BHFEMP';
}

// Convert "BHFSM" → "BHF@SM", "BHFSO" → "BHF@SO", etc.
function buildPasswordPrefix($role) {
    $empPrefix = getEmpPrefix($role);
    if (strpos($empPrefix, 'BHF') === 0) {
        $tail = substr($empPrefix, 3); // "SM", "SO", "AS", "RS", "ZS", "SH", "EMP"
        return 'BHF@' . $tail;
    }
    return 'BHF@EMP';
}

switch ($method) {
    case 'GET':
    case 'POST':
        try {
            $data = ($method === 'POST')
                ? json_decode(file_get_contents("php://input"), true)
                : $_GET;

            $role      = isset($data['role'])   ? trim($data['role'])   : '';
            $emp_id    = isset($data['emp_id']) ? trim($data['emp_id']) : '';
            $name      = isset($data['name'])   ? trim($data['name'])   : '';

            if (empty($role) || empty($emp_id)) {
                echo json_encode([
                    "status"  => false,
                    "message" => "Both 'role' and 'emp_id' are required"
                ]);
                break;
            }

            // Extract trailing digits from emp_id (BHFSM-01 → 01)
            preg_match('/(\d+)\s*$/', $emp_id, $matches);
            $numberPart = isset($matches[1])
                ? str_pad($matches[1], 2, '0', STR_PAD_LEFT)
                : '01';

            $passwordPrefix = buildPasswordPrefix($role);

            // Build password: BHF@SM-01  (no random suffix — deterministic & matches emp_id)
            $autoPassword = $passwordPrefix . '-' . $numberPart;

            // Optional safety: verify against DB users if same emp_id already exists
            try {
                $verifyQuery = "SELECT id, name, emp_id FROM users 
                                WHERE emp_id = :emp_id LIMIT 1";
                $verifyStmt = $db->prepare($verifyQuery);
                $verifyStmt->bindParam(":emp_id", $emp_id);
                $verifyStmt->execute();
                $existing = $verifyStmt->fetch(PDO::FETCH_ASSOC);

                // If employee already exists, still return the same format but flag it
                if ($existing) {
                    echo json_encode([
                        "status"  => true,
                        "message" => "Auto password generated (employee already exists)",
                        "data"    => [
                            "role"      => $role,
                            "emp_id"    => $emp_id,
                            "name"      => $name,
                            "password"  => $autoPassword,
                            "exists"    => true
                        ]
                    ]);
                    break;
                }
            } catch (Exception $e) {
                // ignore DB verification errors
            }

            echo json_encode([
                "status"  => true,
                "message" => "Auto password generated",
                "data"    => [
                    "role"      => $role,
                    "emp_id"    => $emp_id,
                    "name"      => $name,
                    "password"  => $autoPassword,
                    "exists"    => false
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                "status"  => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
        break;

    default:
        echo json_encode(["status" => false, "message" => "Invalid HTTP method"]);
        break;
}
?>