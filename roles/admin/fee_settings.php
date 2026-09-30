<?php
session_start();
require_once __DIR__ . '/../../bootstrap.php';

require_login('admin');

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
// One row per changed amount, so "who changed what, from what to what" is
// always answerable. Undo writes its own rows.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS fee_settings_history (
        history_id   INT AUTO_INCREMENT PRIMARY KEY,
        grade_level  VARCHAR(2) NOT NULL,
        field_name   VARCHAR(20) NOT NULL,
        old_value    DECIMAL(10,2) NOT NULL,
        new_value    DECIMAL(10,2) NOT NULL,
        note         VARCHAR(255) NULL,
        changed_by   VARCHAR(100) NOT NULL,
        changed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

const FEE_FIELDS = [
    'tuition' => ['col' => 'tuition_amount', 'label' => 'Tuition Fee'],
    'misc'    => ['col' => 'misc_fee',       'label' => 'Miscellaneous Fee'],
    'voucher' => ['col' => 'shs_voucher',    'label' => 'SHS Voucher Subsidy'],
];
const FEE_UNDO_WINDOW = 120; // seconds the server keeps honoring an Undo

$admin_name   = (string)($_SESSION['username'] ?? 'Admin');
$grade_levels = ['11', '12'];
$error = '';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function peso(float $n): string { return '₱' . number_format($n, 2); }

// Current saved amounts as [grade => [tuition, misc, voucher]] (floats).
function fee_load(mysqli $conn): array {
    $out = [];
    $res = mysqli_query($conn, "SELECT grade_level, tuition_amount, misc_fee, shs_voucher FROM tuition_fees");
    while ($row = mysqli_fetch_assoc($res)) {
        $out[$row['grade_level']] = [
            'tuition' => (float)$row['tuition_amount'],
            'misc'    => (float)$row['misc_fee'],
            'voucher' => (float)$row['shs_voucher'],
        ];
    }
    return $out;
}

// Writes $values ([grade => [tuition, misc, voucher]]) over $before and logs
// every amount that actually differs. Caller owns the transaction.
function fee_write(mysqli $conn, array $before, array $values, string $by, ?string $note): int {
    $logged = 0;
    foreach ($values as $g => $v) {
        $stmt = $conn->prepare("UPDATE tuition_fees SET tuition_amount=?, misc_fee=?, shs_voucher=? WHERE grade_level=?");
        $stmt->bind_param('ddds', $v['tuition'], $v['misc'], $v['voucher'], $g);
        if (!$stmt->execute()) {
            throw new mysqli_sql_exception($stmt->error);
        }
        $stmt->close();

        foreach (FEE_FIELDS as $key => $_) {
            if (abs($before[$g][$key] - $v[$key]) < 0.005) continue;
            $log = $conn->prepare("INSERT INTO fee_settings_history (grade_level, field_name, old_value, new_value, note, changed_by) VALUES (?, ?, ?, ?, ?, ?)");
            $log->bind_param('ssddss', $g, $key, $before[$g][$key], $v[$key], $note, $by);
            $log->execute();
            $log->close();
            $logged++;
        }
    }
    return $logged;
}

// ── Save fee changes ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fees'])) {
    $before = fee_load($conn);
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
        // "Voucher eligible" off means no subsidy is saved — the switch is real,
        // not just a preview.
        $eligible = isset($_POST["eligible_$g"]);
        $values[$g] = [
            'tuition' => round((float)$tuition, 2),
            'misc'    => round((float)$misc, 2),
            'voucher' => $eligible ? round((float)$voucher, 2) : 0.0,
        ];
    }

    if (!$error) {
        $changed = [];
        foreach ($values as $g => $v) {
            foreach (FEE_FIELDS as $key => $_) {
                if (abs($before[$g][$key] - $v[$key]) >= 0.005) { $changed[$g] = true; }
            }
        }

        if (!$changed) {
            $_SESSION['fee_toast'] = ['type' => 'info', 'title' => 'Nothing to save', 'message' => 'The amounts match what is already saved.', 'undo' => false];
            header("Location: fee_settings"); exit();
        }

        $conn->begin_transaction();
        try {
            fee_write($conn, $before, $values, $admin_name, null);
            $conn->commit();
            $_SESSION['fee_undo'] = [
                'old' => array_intersect_key($before, $changed),
                'new' => array_intersect_key($values, $changed),
                'at'  => time(),
            ];
            $_SESSION['fee_toast'] = ['type' => 'success', 'title' => 'Fee settings saved', 'message' => 'Treasury uses the new amounts for the next assessment.', 'undo' => true];
            header("Location: fee_settings"); exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = 'Could not save fee settings. Please try again.';
        }
    }
}

// ── Undo the last save (server-checked, not just a client timer) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_fees'])) {
    $u = $_SESSION['fee_undo'] ?? null;
    unset($_SESSION['fee_undo']);
    $before = fee_load($conn);

    $still_current = (bool)$u && time() - $u['at'] <= FEE_UNDO_WINDOW;
    if ($still_current) {
        foreach ($u['new'] as $g => $v) {
            foreach (FEE_FIELDS as $key => $_) {
                if (abs($before[$g][$key] - $v[$key]) >= 0.005) { $still_current = false; }
            }
        }
    }

    if (!$still_current) {
        $error = 'Those fees were changed again (or the undo window passed), so this undo was skipped.';
    } else {
        $conn->begin_transaction();
        try {
            fee_write($conn, $before, $u['old'], $admin_name, 'Undo');
            $conn->commit();
            $_SESSION['fee_toast'] = ['type' => 'info', 'title' => 'Change undone', 'message' => 'Fees are back to their previous amounts.', 'undo' => false];
            header("Location: fee_settings"); exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = 'Could not undo that change. Please try again.';
        }
    }
}

$toast = $_SESSION['fee_toast'] ?? null;
unset($_SESSION['fee_toast']);

// ── Fetch current fees + history ─────────────────────────────────────────
$fees = fee_load($conn);
$history = mysqli_query($conn, "SELECT grade_level, field_name, old_value, new_value, note, changed_by, changed_at FROM fee_settings_history ORDER BY history_id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
$last = $history[0] ?? null;
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
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/css_admin.css') ?>">
</head>
<body>

  <?php include_once BASE_PATH . '/shared/includes/admin_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-right"><span class="topbar-date"><?= date('F j, Y') ?></span></div>
    </div>
    <div class="content">

      <div class="staff-hero">
        <p class="page-eyebrow">Admin Portal</p>
        <h1 class="page-title">Fee Settings</h1>
        <p class="page-sub">Set the tuition, miscellaneous fee and SHS voucher for each grade level. Treasury's Assessment screen uses these amounts as soon as you save.</p>
      </div>

      <div class="staff-overlap sy-wrap">

        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>

        <!-- What Treasury charges right now (saved values, not the draft below) -->
        <div class="sy-tiles">
          <?php foreach ($grade_levels as $g):
            $f = $fees[$g] ?? ['tuition' => 0, 'misc' => 0, 'voucher' => 0];
            $gross = $f['tuition'] + $f['misc'];
            $balance = max(0, $gross - $f['voucher']);
            $v_on = $f['voucher'] > 0;
          ?>
            <div class="sy-tile">
              <div class="sy-tile-head">
                <span class="sy-tile-label">Grade <?= $g ?> · Live in Treasury</span>
                <span class="sy-status <?= $v_on ? 'sy-status--open' : 'sy-status--closed' ?>"><i></i><?= $v_on ? 'Voucher on' : 'Voucher off' ?></span>
              </div>
              <p class="sy-tile-year"><?= peso($balance) ?></p>
              <p class="sy-tile-sub">Balance due per student · <strong><?= peso($gross) ?></strong> total<?= $v_on ? ' − ' . peso($f['voucher']) . ' voucher' : '' ?></p>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card sy-card">
          <div class="sy-card-head">
            <div>
              <h2 class="sy-card-title">Fees per grade level</h2>
            </div>
            <div class="sy-card-actions">
              <div class="fee-last-updated">
                <span class="fee-last-label">Last updated</span>
                <span class="sy-when"><?= $last ? h(date('M j, Y g:i A', strtotime($last['changed_at']))) : 'Not changed since setup' ?></span>
                <span class="sy-by"><?= $last ? 'by ' . h($last['changed_by']) : 'Using default amounts' ?></span>
              </div>
              <button type="button" class="sy-btn sy-btn--outline" id="feeHistoryBtn" aria-expanded="false" aria-controls="feeHistory">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>
                <span>View history</span>
              </button>
            </div>
          </div>

          <form method="POST" action="fee_settings" id="feeForm" novalidate>
            <input type="hidden" name="update_fees" value="1">
            <div class="fee-body">
              <div class="fee-grid">
                <?php foreach ($grade_levels as $g):
                  $f = $fees[$g] ?? ['tuition' => 0, 'misc' => 0, 'voucher' => 0];
                  $eligible = $f['voucher'] > 0;
                ?>
                  <div class="fee-card" data-fee-card data-grade="<?= $g ?>">
                    <div class="fee-card-header">
                      <div class="fee-card-heading">
                        <span class="fee-badge"><?= $g ?></span>
                        <span class="fee-card-title">Grade <?= $g ?></span>
                      </div>
                    </div>

                    <div class="fee-fields">
                    <?php foreach (FEE_FIELDS as $key => $meta):
                      $tips = [
                        'tuition' => 'The base tuition amount charged for this grade level.',
                        'misc'    => 'Non-tuition fees (ID, library, laboratory, etc.) charged for this grade level.',
                        'voucher' => 'DepEd SHS Voucher subsidy for this grade level, applied to students from a public junior high school. Any remainder still shows as Balance Due. Turn the switch off if this grade has no subsidy — it saves as ₱0.00.',
                      ];
                      $amount = number_format((float)$f[$key], 2, '.', '');
                    ?>
                      <div class="fee-field<?= $key === 'voucher' ? ' fee-field--wide' : '' ?>" data-fee-field-row="<?= $key ?>">
                        <div class="fee-field-head">
                          <label for="<?= $key ?>_<?= $g ?>"><?= $meta['label'] ?>
                            <span class="fee-info" title="<?= h($tips[$key]) ?>">i</span>
                          </label>
                          <?php if ($key === 'voucher'): ?>
                            <label class="fee-toggle" for="eligible_<?= $g ?>">
                              <input type="checkbox" id="eligible_<?= $g ?>" name="eligible_<?= $g ?>" value="1"
                                     class="fee-toggle-input" data-fee-eligible data-orig="<?= $eligible ? '1' : '0' ?>" <?= $eligible ? 'checked' : '' ?>>
                              <span class="fee-toggle-track"><span class="fee-toggle-thumb"></span></span>
                              <span class="fee-toggle-state" data-fee-eligible-text><?= $eligible ? 'Enabled' : 'Not eligible' ?></span>
                            </label>
                          <?php else: ?>
                            <span class="fee-was" data-fee-was></span>
                          <?php endif; ?>
                        </div>
                        <div class="fee-input-wrap" <?= $key === 'voucher' ? 'data-fee-voucher-wrap' : '' ?>>
                          <span class="fee-currency">₱</span>
                          <input id="<?= $key ?>_<?= $g ?>" type="number" name="<?= $key ?>_<?= $g ?>" min="0" step="0.01"
                                 value="<?= $amount ?>" required class="fee-input"
                                 data-fee-field="<?= $key ?>" data-orig="<?= $amount ?>" data-label="<?= h($meta['label']) ?>">
                        </div>
                        <?php if ($key === 'voucher'): ?><p class="fee-was" data-fee-was></p><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                    </div>

                    <div class="fee-summary">
                      <span class="fee-summary-formula" data-fee-formula></span>
                      <span class="fee-summary-balance">
                        <span class="fee-summary-label">Balance Due</span>
                        <span class="fee-summary-amount" data-fee-balance></span>
                      </span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="fee-savebar" id="feeSavebar">
              <span class="fee-savebar-status" role="status" aria-live="polite"><i></i><span id="feeStatusText">No unsaved changes</span></span>
              <div class="fee-savebar-actions">
                <button type="button" class="sy-btn sy-btn--outline" id="feeDiscard" disabled>Discard</button>
                <button type="submit" class="sy-btn sy-btn--primary" id="feeSave" disabled>Review &amp; save</button>
              </div>
            </div>
          </form>
        </div>

        <div class="card sy-card sy-history" id="feeHistory" hidden>
          <div class="sy-card-head">
            <div>
              <h2 class="sy-card-title">Change history</h2>
              <p class="sy-card-sub">The 20 most recent amount changes, newest first.</p>
            </div>
          </div>
          <?php if (empty($history)): ?>
            <p class="empty-state">No changes recorded yet.</p>
          <?php else: ?>
            <div class="sy-table-wrap">
              <table class="data-table sy-table">
                <thead><tr><th>When</th><th>Grade</th><th>Amount</th><th>Change</th><th>By</th></tr></thead>
                <tbody>
                  <?php foreach ($history as $r): ?>
                    <tr>
                      <td><span class="sy-when"><?= h(date('M j, Y g:i A', strtotime($r['changed_at']))) ?></span></td>
                      <td><span class="sy-year sy-year--sm">Grade <?= h($r['grade_level']) ?></span></td>
                      <td><?= h(FEE_FIELDS[$r['field_name']]['label'] ?? $r['field_name']) ?></td>
                      <td><?= peso((float)$r['old_value']) ?> → <strong><?= peso((float)$r['new_value']) ?></strong><?= $r['note'] ? ' · ' . h($r['note']) : '' ?></td>
                      <td><?= h($r['changed_by']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>

    </div>
  </div>

  <script>
    (function () {
      const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
      const num = v => Math.max(0, parseFloat(v) || 0);
      const loader = document.getElementById('pageLoader');

      const form = document.getElementById('feeForm');
      const bar = document.getElementById('feeSavebar');
      const statusText = document.getElementById('feeStatusText');
      const saveBtn = document.getElementById('feeSave');
      const discardBtn = document.getElementById('feeDiscard');
      let submitting = false;

      const cards = Array.from(document.querySelectorAll('[data-fee-card]')).map(card => ({
        grade: card.dataset.grade,
        card,
        fields: ['tuition', 'misc', 'voucher'].map(key => {
          const input = card.querySelector(`[data-fee-field="${key}"]`);
          return { key, input, row: input.closest('[data-fee-field-row]'), was: input.closest('[data-fee-field-row]').querySelector('[data-fee-was]'), label: input.dataset.label, orig: num(input.dataset.orig) };
        }),
        eligible: card.querySelector('[data-fee-eligible]'),
        eligibleText: card.querySelector('[data-fee-eligible-text]'),
        origEligible: card.querySelector('[data-fee-eligible]').dataset.orig === '1',
        voucherWrap: card.querySelector('[data-fee-voucher-wrap]'),
        formulaEl: card.querySelector('[data-fee-formula]'),
        balanceEl: card.querySelector('[data-fee-balance]')
      }));

      // The amount that will actually be saved for a field (voucher is 0 when not eligible).
      const effective = (c, f) => f.key === 'voucher' && !c.eligible.checked ? 0 : num(f.input.value);

      function recalc(c) {
        const [t, m, v] = c.fields.map(f => effective(c, f));
        const gross = t + m;
        c.voucherWrap.classList.toggle('is-muted', !c.eligible.checked);
        c.eligibleText.textContent = c.eligible.checked ? 'Enabled' : 'Not eligible';
        c.formulaEl.textContent = c.eligible.checked && v > 0
          ? `${peso(t)} + ${peso(m)} – ${peso(v)} subsidy`
          : `${peso(t)} + ${peso(m)}`;
        c.balanceEl.textContent = peso(Math.max(0, gross - v));

        c.fields.forEach(f => {
          const changed = Math.abs(effective(c, f) - f.orig) >= 0.005
            || (f.key === 'voucher' && c.eligible.checked !== c.origEligible);
          f.row.classList.toggle('is-changed', changed);
          f.was.textContent = f.key === 'voucher' && !c.eligible.checked
            ? `Was ${peso(f.orig)} — will save as ${peso(0)} (not eligible)`
            : `Was ${peso(f.orig)}`;
        });
      }

      const diffs = () => cards.flatMap(c => c.fields
        .filter(f => Math.abs(effective(c, f) - f.orig) >= 0.005)
        .map(f => ({ name: `Grade ${c.grade} · ${f.label}`, from: f.orig, to: effective(c, f), inelig: f.key === 'voucher' && !c.eligible.checked })));

      function refresh() {
        cards.forEach(recalc);
        const n = diffs().length;
        bar.classList.toggle('is-dirty', n > 0);
        statusText.textContent = n ? `${n} unsaved change${n === 1 ? '' : 's'}` : 'No unsaved changes';
        saveBtn.disabled = discardBtn.disabled = n === 0;
      }

      cards.forEach(c => {
        c.fields.forEach(f => {
          f.input.addEventListener('input', refresh);
          // Always show two decimals once the admin leaves the field (5000 → 5000.00).
          f.input.addEventListener('blur', () => {
            if (f.input.value.trim() !== '' && !isNaN(f.input.value)) f.input.value = num(f.input.value).toFixed(2);
            refresh();
          });
        });
        c.eligible.addEventListener('change', refresh);
      });

      discardBtn.addEventListener('click', () => {
        cards.forEach(c => {
          c.fields.forEach(f => { f.input.value = f.input.dataset.orig; });
          c.eligible.checked = c.origEligible;
        });
        refresh();
      });

      // Warn before navigating away with staged edits.
      window.addEventListener('beforeunload', e => {
        if (!submitting && diffs().length) { e.preventDefault(); e.returnValue = ''; }
      });

      // ── Review the exact changes before they hit Treasury ────────────────
      form.addEventListener('submit', e => {
        if (submitting) return;
        e.preventDefault();

        const bad = cards.flatMap(c => c.fields).find(f => f.input.value.trim() === '' || Number(f.input.value) < 0);
        if (bad) { bad.input.focus(); if (window.uiToast) uiToast.error('Enter a valid, non-negative amount for ' + bad.label + '.'); return; }

        const list = diffs();
        if (!list.length) return;

        const go = () => {
          submitting = true;
          if (loader) loader.classList.add('show');
          HTMLFormElement.prototype.submit.call(form);
        };

        if (typeof Swal === 'undefined') {
          if (window.confirm('Save ' + list.length + ' fee change(s)? Treasury will use them immediately.')) go();
          return;
        }
        const html = '<ul class="fee-diff">' + list.map(d =>
          `<li><span class="fee-diff-name">${esc(d.name)}</span><span class="fee-diff-val">${peso(d.from)} → ${peso(d.to)}</span></li>`
        ).join('') + '</ul><p class="sy-impact-note">Treasury will use these amounts for the next assessment. You can undo for 8 seconds afterwards.</p>';
        Swal.fire({
          title: `Save ${list.length} fee change${list.length === 1 ? '' : 's'}?`,
          html,
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Save changes',
          cancelButtonText: 'Keep editing'
        }).then(r => { if (r.isConfirmed) go(); });
      });

      // ── History panel ────────────────────────────────────────────────────
      const histBtn = document.getElementById('feeHistoryBtn');
      const histPanel = document.getElementById('feeHistory');
      histBtn.addEventListener('click', () => {
        const show = histPanel.hidden;
        histPanel.hidden = !show;
        histBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
        histBtn.querySelector('span').textContent = show ? 'Hide history' : 'View history';
        if (show) histPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });

      refresh();

      // ── Post-save toast with a timed Undo ────────────────────────────────
      <?php if ($toast): ?>
      const toast = <?= json_encode($toast, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      const opts = { type: toast.type, title: toast.title, message: toast.message };
      if (toast.undo) {
        opts.duration = 8000;
        opts.action = {
          label: 'Undo',
          onClick: () => {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'fee_settings';
            f.innerHTML = '<input type="hidden" name="undo_fees" value="1">';
            document.body.appendChild(f);
            submitting = true;
            if (loader) loader.classList.add('show');
            f.submit();
          }
        };
      }
      uiToast(opts);
      <?php endif; ?>
    })();
  </script>

</body>
</html>
