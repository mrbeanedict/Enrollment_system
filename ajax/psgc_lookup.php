<?php
declare(strict_types=1);
// Public, unauthenticated PSGC (province/city/barangay) cascading lookup —
// backs the address dropdowns on roles/student/public/admission.php (public, no login)
// and, going forward, any other address form that adopts the same
// province -> city/municipality -> barangay cascade. Reference data lives
// in psgc_provinces / psgc_cities / psgc_barangays, seeded once from the
// PSA-derived open dataset (see scratchpad/seed_psgc.php at seed time —
// not part of the request-time code path).
//
// GET params: type=provinces | type=cities&province_code=X | type=barangays&city_code=Y
include('../config.php');

header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection unavailable.']);
    exit();
}

$type = trim($_GET['type'] ?? '');

if ($type === 'provinces') {
    $res = $conn->query("SELECT province_code, province_name FROM psgc_provinces ORDER BY province_name ASC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    echo json_encode(['provinces' => $out]);
    exit();
}

if ($type === 'cities') {
    $provinceCode = trim($_GET['province_code'] ?? '');
    if ($provinceCode === '') {
        echo json_encode(['cities' => []]);
        exit();
    }
    $stmt = $conn->prepare("SELECT city_code, city_name FROM psgc_cities WHERE province_code = ? ORDER BY city_name ASC");
    $stmt->bind_param('s', $provinceCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    $stmt->close();
    echo json_encode(['cities' => $out]);
    exit();
}

if ($type === 'barangays') {
    $cityCode = trim($_GET['city_code'] ?? '');
    if ($cityCode === '') {
        echo json_encode(['barangays' => []]);
        exit();
    }
    $stmt = $conn->prepare("SELECT brgy_code, brgy_name FROM psgc_barangays WHERE city_code = ? ORDER BY brgy_name ASC");
    $stmt->bind_param('s', $cityCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    $stmt->close();
    echo json_encode(['barangays' => $out]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Invalid type.']);
