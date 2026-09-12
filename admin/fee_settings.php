<?php
session_start();
include_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login"); exit();
}

// ── Auto-create + seed tuition_fees table if missing (same bootstrap as
//    payment.php, so this page works standalone even before treasury has
//    ever opened a payment screen) ─────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS tuition_fees (
        grade_level     VARCHAR(2) PRIMARY KEY,
        tuition_amount  DECIMAL(10,2) NOT NULL,
        shs_voucher     DECIMAL(10,2) NOT NULL DEFAULT 0,
        misc_fee        DECIMAL(10,2) NOT NULL DEFAULT 0
    )
");
mysqli_query($conn, "
    INSERT IGNORE INTO tuition_fees (grade_level, tuition_amount, shs_voucher, misc_fee) VALUES
        ('11', 5000.00, 5500.00, 500.00),
        ('12', 10000.00, 10500.00, 500.00)
");

$success = '';
$error   = '';
$grade_levels = ['11', '12'];

// ── Save fee changes ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fees'])) {
    $values = [];
    foreach ($grade_levels as $g) {
        $tuition = trim($_POST["tuition_$g"] ?? '');
        $misc    = trim($_POST["misc_$g"] ?? '');
        $voucher = trim($_POST["voucher_$g"] ?? '');

        if (!is_numeric($tuition) || (float)$tuition < 0
            || !is_numeric($misc) || (float)$misc < 0
            || !is_numeric($voucher) || (float)$voucher < 0) {
            $error = "Please enter valid, non-negative amounts for Grade $g.";
            break;
        }
        $values[$g] = [
            'tuition' => round((float)$tuition, 2),
            'misc'    => round((float)$misc, 2),
            'voucher' => round((float)$voucher, 2),
        ];
    }

    if (!$error) {
        $conn->begin_transaction();
        try {
            foreach ($values as $g => $v) {
                $stmt = $conn->prepare(
                    "UPDATE tuition_fees SET tuition_amount=?, misc_fee=?, shs_voucher=? WHERE grade_level=?"
                );
                $stmt->bind_param('ddds', $v['tuition'], $v['misc'], $v['voucher'], $g);
                if (!$stmt->execute()) {
                    throw new mysqli_sql_exception($stmt->error);
                }
                $stmt->close();
            }
            $conn->commit();
            header("Location: fee_settings?saved=1"); exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = 'Could not save fee settings. Please try again.';
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Fee settings saved. Changes apply to the next assessment shown in Treasury.';
}

// ── Fetch current fees ───────────────────────────────────────────────────
$fees = [];
$res = mysqli_query($conn, "SELECT grade_level, tuition_amount, misc_fee, shs_voucher FROM tuition_fees");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $fees[$row['grade_level']] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fee Settings — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/css_admin.css?v=<?= filemtime(__DIR__ . '/../css/css_admin.css') ?>">
  <style>
    .alert { font-size:13px; border-radius:8px; padding:9px 13px; margin-bottom:1rem; }
    .alert-success { background:#EBF7F2; border:0.5px solid #A8D9C5; color:#1A6B4A; }
    .alert-error   { background:#FDF0EF; border:0.5px solid #F5C6C2; color:#C0392B; }
  </style>
</head>
<body>

  <?php include_once 'sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="staff-breadcrumb"><span>Admin Portal</span> / <span class="staff-breadcrumb-current">Fee Settings</span></p>
        <h1 class="page-title">Fee Settings</h1>
        <p class="page-sub">Set Tuition Fee, Miscellaneous Fee, and SHS Voucher Subsidy per grade level. Treasury's Assessment screen reads these values live.</p>
      </div>

      <div class="staff-overlap">

      <div class="card">
        <div class="card-header">
          <span class="card-title">Per-Grade-Level Fees</span>
        </div>

        <?php if ($success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="fee_settings"
              data-confirm="Save these fee changes? Treasury's Assessment screen will use the new amounts immediately." data-icon="question">
          <div class="fee-grid">
            <?php foreach ($grade_levels as $g):
              $f = $fees[$g] ?? ['tuition_amount' => 0, 'misc_fee' => 0, 'shs_voucher' => 0];
              $eligible = ((float)$f['shs_voucher'] > 0);
            ?>
              <div class="fee-card" data-fee-card data-grade="<?= $g ?>">
                <div class="fee-card-header">
                  <div class="fee-card-heading">
                    <span class="fee-badge"><?= $g ?></span>
                    <span class="fee-card-title">Grade <?= $g ?></span>
                  </div>
                  <label class="fee-toggle" for="eligible_<?= $g ?>">
                    <span class="fee-toggle-label">Voucher eligible</span>
                    <input type="checkbox" id="eligible_<?= $g ?>" class="fee-toggle-input"
                           data-fee-eligible <?= $eligible ? 'checked' : '' ?>>
                    <span class="fee-toggle-track"><span class="fee-toggle-thumb"></span></span>
                  </label>
                </div>

                <div class="fee-field">
                  <label for="tuition_<?= $g ?>">Tuition Fee
                    <span class="fee-info" title="The base tuition amount charged for this grade level.">i</span>
                  </label>
                  <div class="fee-input-wrap">
                    <span class="fee-currency">₱</span>
                    <input id="tuition_<?= $g ?>" type="number" name="tuition_<?= $g ?>" min="0" step="0.01"
                           value="<?= htmlspecialchars((string)$f['tuition_amount']) ?>" required
                           class="fee-input" data-fee-field="tuition">
                  </div>
                </div>

                <div class="fee-field">
                  <label for="misc_<?= $g ?>">Miscellaneous Fee
                    <span class="fee-info" title="Non-tuition fees (ID, library, laboratory, etc.) charged for this grade level.">i</span>
                  </label>
                  <div class="fee-input-wrap">
                    <span class="fee-currency">₱</span>
                    <input id="misc_<?= $g ?>" type="number" name="misc_<?= $g ?>" min="0" step="0.01"
                           value="<?= htmlspecialchars((string)$f['misc_fee']) ?>" required
                           class="fee-input" data-fee-field="misc">
                  </div>
                </div>

                <div class="fee-field">
                  <label for="voucher_<?= $g ?>">SHS Voucher Subsidy
                    <span class="fee-info" title="The DepEd SHS Voucher Program subsidy amount for this grade level, as published for your school's category.">i</span>
                  </label>
                  <div class="fee-input-wrap" data-fee-voucher-wrap>
                    <span class="fee-currency">₱</span>
                    <input id="voucher_<?= $g ?>" type="number" name="voucher_<?= $g ?>" min="0" step="0.01"
                           value="<?= htmlspecialchars((string)$f['shs_voucher']) ?>" required
                           class="fee-input" data-fee-field="voucher">
                  </div>
                  <p class="fee-hint">Applies automatically to students who came from a public junior high school. Leave at 0, or turn off "Voucher eligible" above, if this grade isn't voucher-eligible this year — any remainder still shows as a real Balance Due.</p>
                </div>

                <div class="fee-summary">
                  <div class="fee-summary-row">
                    <span class="fee-summary-formula" data-fee-formula></span>
                    <span class="fee-summary-balance">
                      <span class="fee-summary-label">Balance Due</span>
                      <span class="fee-summary-amount" data-fee-balance></span>
                    </span>
                  </div>
                  <div class="fee-progress-track">
                    <div class="fee-progress-fill" data-fee-progress></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <button type="submit" name="update_fees" class="btn-save" data-fee-save disabled>Save Changes</button>
        </form>
      </div>

      </div>

    </div>
  </div>

  <script>
    (function () {
      const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const saveBtn = document.querySelector('[data-fee-save]');

      document.querySelectorAll('[data-fee-card]').forEach(card => {
        const tuition   = card.querySelector('[data-fee-field="tuition"]');
        const misc      = card.querySelector('[data-fee-field="misc"]');
        const voucher   = card.querySelector('[data-fee-field="voucher"]');
        const eligible  = card.querySelector('[data-fee-eligible]');
        const voucherWrap = card.querySelector('[data-fee-voucher-wrap]');
        const formulaEl = card.querySelector('[data-fee-formula]');
        const balanceEl = card.querySelector('[data-fee-balance]');
        const progressEl = card.querySelector('[data-fee-progress]');

        function recalc() {
          const t = Math.max(0, parseFloat(tuition.value) || 0);
          const m = Math.max(0, parseFloat(misc.value) || 0);
          const v = eligible.checked ? Math.max(0, parseFloat(voucher.value) || 0) : 0;
          const gross = t + m;
          const balance = Math.max(0, gross - v);

          voucherWrap.classList.toggle('is-muted', !eligible.checked);

          formulaEl.textContent = eligible.checked && v > 0
            ? `${peso(t)} + ${peso(m)} – ${peso(v)} subsidy`
            : `${peso(t)} + ${peso(m)}`;
          balanceEl.textContent = peso(balance);
          progressEl.style.width = gross > 0 ? `${Math.min(100, (v / gross) * 100)}%` : '0%';
        }

        [tuition, misc, voucher].forEach(input => input.addEventListener('input', () => {
          recalc();
          saveBtn.disabled = false;
        }));
        eligible.addEventListener('change', () => {
          recalc();
          saveBtn.disabled = false;
        });

        recalc();
      });
    })();
  </script>

</body>
</html>
