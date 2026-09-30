/* Shared list behaviour for the admin table pages (enrollments, students,
 * teacher_accounts) — the same search / filter / sort / paginate / select /
 * export experience staff_manage.php has, driven by markup conventions so
 * each page only supplies rows and a few options.
 *
 * Markup contract (all ids/attrs optional unless noted):
 *   table#dataTable > tbody > tr        one row per record (required)
 *     data-search="…"                   lowercase blob the search box matches
 *     data-<attr>="…"                   value a filter <select> / status pill matches
 *     data-sort-<key>="…"               value the sort <select> orders by
 *     .staff-row-checkbox[value=id]     row checkbox (required)
 *   #tblSearch                          search input (Esc clears it)
 *   [data-filter-select][name][data-attr]   exact-match dropdown filters
 *   [data-pills][data-attr][data-param] > .staff-pill[data-value] > [data-count]
 *   #tblSort                            options "key:asc|desc"; add data-num for numeric keys
 *   #tblReset, [data-clear-filters]     clear every filter
 *   #tblSelectAll, #tblBulkBar, #tblBulkCount, [data-bulk="export|clear"]
 *   #tblExport (+ #tblExportLabel)      header export: everything, or what the filters show
 *   #tblSummary, #tblSize, #tblPages, #tblNumbers, #tblPrev, #tblNext, [data-no-match]
 *
 * Filters are mirrored into the URL (replaceState) so a filtered view can be
 * bookmarked or shared, and old ?status=…&grade=… links still work.
 * Export POSTs the row ids to the current page, which has no URL-length limit.
 */
(function (global) {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  // "1 2 3 … 38 39 40": first/last 3 pages plus the current page's neighbours,
  // any gap collapsed to one ellipsis.
  function buildPageList(current, total) {
    var boundary = 3, sibling = 1, pages = {}, i;
    for (i = 1; i <= Math.min(boundary, total); i++) pages[i] = true;
    for (i = Math.max(1, total - boundary + 1); i <= total; i++) pages[i] = true;
    for (i = Math.max(1, current - sibling); i <= Math.min(total, current + sibling); i++) pages[i] = true;

    var sorted = Object.keys(pages).map(Number).sort(function (a, b) { return a - b; });
    var result = [], prev = 0;
    sorted.forEach(function (p) {
      if (prev && p - prev > 1) result.push('ellipsis');
      result.push(p);
      prev = p;
    });
    return result;
  }

  function init(cfg) {
    cfg = cfg || {};
    var noun  = cfg.noun || 'records';
    var table = byId('dataTable');
    if (!table) return;

    var tbody     = table.tBodies[0];
    var rows      = Array.prototype.slice.call(tbody.rows);
    var searchEl  = byId('tblSearch');
    var sortEl    = byId('tblSort');
    var sizeSel   = byId('tblSize');
    var summaryEl = byId('tblSummary');
    var pagesEl   = byId('tblPages');
    var numbersEl = byId('tblNumbers');
    var prevBtn   = byId('tblPrev');
    var nextBtn   = byId('tblNext');
    var selectAll = byId('tblSelectAll');
    var bulkBar   = byId('tblBulkBar');
    var bulkCount = byId('tblBulkCount');
    var exportBtn = byId('tblExport');
    var exportLbl = byId('tblExportLabel');
    var resetBtn  = byId('tblReset');
    var noMatchEl = document.querySelector('[data-no-match]');
    var selects   = Array.prototype.slice.call(document.querySelectorAll('[data-filter-select]'));
    var pillWrap  = document.querySelector('[data-pills]');
    var pills     = pillWrap ? Array.prototype.slice.call(pillWrap.querySelectorAll('.staff-pill')) : [];
    var pillAttr  = pillWrap ? pillWrap.getAttribute('data-attr') : '';
    var pillParam = pillWrap ? (pillWrap.getAttribute('data-param') || pillAttr) : '';
    var activePill = '';
    var page = 1;
    var filtersActive = false;

    rows.forEach(function (r) {
      r._search = r.getAttribute('data-search') || '';
      r._cb     = r.querySelector('.staff-row-checkbox');
      r._match  = true;
    });

    // ── Restore filters from the URL ─────────────────────────────────────
    var params = new URLSearchParams(window.location.search);
    if (searchEl && params.get('q')) searchEl.value = params.get('q');
    selects.forEach(function (s) {
      var v = params.get(s.name);
      if (v) { s.value = v; if (s.value !== v) s.value = ''; }
    });
    if (pillWrap && params.get(pillParam)) {
      var want = params.get(pillParam);
      if (pills.some(function (p) { return p.getAttribute('data-value') === want; })) activePill = want;
    }

    function syncUrl() {
      var p = new URLSearchParams();
      if (searchEl && searchEl.value.trim()) p.set('q', searchEl.value.trim());
      selects.forEach(function (s) { if (s.value) p.set(s.name, s.value); });
      if (activePill) p.set(pillParam, activePill);
      var qs = p.toString();
      try { window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) {}
    }

    // ── Sort ─────────────────────────────────────────────────────────────
    function sortRows() {
      if (!sortEl) return;
      var parts = sortEl.value.split(':');
      var dir   = parts[1] === 'desc' ? -1 : 1;
      var attr  = 'data-sort-' + parts[0];
      var isNum = sortEl.options[sortEl.selectedIndex].hasAttribute('data-num');
      rows.sort(function (a, b) {
        var av = a.getAttribute(attr) || '', bv = b.getAttribute(attr) || '';
        var c = isNum ? Number(av) - Number(bv) : av.localeCompare(bv);
        if (c) return c * dir;
        return (a.getAttribute('data-sort-name') || '').localeCompare(b.getAttribute('data-sort-name') || '');
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
    }

    // ── Filter ───────────────────────────────────────────────────────────
    function applyFilters(keepPage) {
      var q = searchEl ? searchEl.value.trim().toLowerCase() : '';
      var active = selects.map(function (s) { return { attr: s.getAttribute('data-attr'), val: s.value }; })
                          .filter(function (f) { return f.val; });
      // Pill counts follow the search + dropdown filters, so each pill says how
      // many rows it would show right now.
      var counts = {}, base = 0;

      rows.forEach(function (r) {
        var ok = (!q || r._search.indexOf(q) !== -1) && active.every(function (f) {
          return r.getAttribute('data-' + f.attr) === f.val;
        });
        if (ok) {
          base++;
          if (pillAttr) { var pv = r.getAttribute('data-' + pillAttr); counts[pv] = (counts[pv] || 0) + 1; }
        }
        r._match = ok && (!activePill || r.getAttribute('data-' + pillAttr) === activePill);
      });

      pills.forEach(function (p) {
        var v  = p.getAttribute('data-value');
        var on = v === activePill;
        p.classList.toggle('active', on);
        p.setAttribute('aria-pressed', on ? 'true' : 'false');
        var c = p.querySelector('[data-count]');
        if (c) c.textContent = v === '' ? base : (counts[v] || 0);
      });

      filtersActive = !!(q || active.length || activePill);
      if (resetBtn) resetBtn.classList.toggle('hidden', !filtersActive);

      // Filtered-out rows can't stay selected — they'd be invisible in "n selected".
      rows.forEach(function (r) { if (!r._match) r._cb.checked = false; });

      if (!keepPage) page = 1;
      syncUrl();
      render();
    }

    function clearFilters() {
      if (searchEl) searchEl.value = '';
      selects.forEach(function (s) { s.value = ''; });
      activePill = '';
      applyFilters();
    }

    // ── Paginate ─────────────────────────────────────────────────────────
    function render() {
      var per     = parseInt(sizeSel.value, 10);
      var matched = rows.filter(function (r) { return r._match; });
      var total   = matched.length;
      var pageCount = Math.max(1, Math.ceil(total / per));
      if (page > pageCount) page = pageCount;

      var start = (page - 1) * per, end = start + per;
      rows.forEach(function (r) { r.classList.add('hidden'); });
      matched.slice(start, end).forEach(function (r) { r.classList.remove('hidden'); });

      if (total === 0) {
        summaryEl.textContent = 'No ' + noun + ' to show';
      } else if (pageCount === 1) {
        summaryEl.textContent = 'Showing ' + total + ' of ' + rows.length + ' ' + noun;
      } else {
        summaryEl.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total + ' ' + noun;
      }
      if (noMatchEl) noMatchEl.classList.toggle('hidden', total > 0);
      table.classList.toggle('hidden', total === 0);

      // Nothing to page through (or choose a page size for) until there's more than one page's worth.
      sizeSel.closest('.staff-pagination-rows').classList.toggle('hidden', rows.length <= parseInt(sizeSel.options[0].value, 10));
      pagesEl.classList.toggle('hidden', pageCount <= 1);

      numbersEl.innerHTML = '';
      buildPageList(page, pageCount).forEach(function (item) {
        if (item === 'ellipsis') {
          var span = document.createElement('span');
          span.className = 'staff-page-ellipsis';
          span.textContent = '…';
          numbersEl.appendChild(span);
          return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'staff-page-btn' + (item === page ? ' active' : '');
        btn.textContent = item;
        btn.setAttribute('aria-label', 'Page ' + item);
        if (item === page) btn.setAttribute('aria-current', 'page');
        btn.addEventListener('click', function () { page = item; render(); });
        numbersEl.appendChild(btn);
      });
      prevBtn.disabled = page <= 1;
      nextBtn.disabled = page >= pageCount;

      if (exportBtn) {
        exportBtn.disabled = total === 0;
        exportLbl.textContent = filtersActive ? 'Export filtered (' + total + ')' : 'Export CSV';
      }
      updateSelection();
    }

    // ── Selection + bulk bar ─────────────────────────────────────────────
    // Selection survives page changes (so "Export selected" can span pages)
    // and is dropped only when a filter hides the row.
    function selectedRows() { return rows.filter(function (r) { return r._cb.checked; }); }

    function updateSelection() {
      var sel = selectedRows();
      if (bulkBar) {
        bulkBar.classList.toggle('hidden', sel.length === 0);
        bulkCount.textContent = sel.length + ' selected';
        (cfg.bulk || []).forEach(function (b) {
          var el = byId(b.id);
          if (el) el.disabled = !b.when(sel);
        });
      }
      rows.forEach(function (r) { r.classList.toggle('is-selected', r._cb.checked); });
      if (selectAll) {
        var visible = rows.filter(function (r) { return !r.classList.contains('hidden'); });
        var checked = visible.filter(function (r) { return r._cb.checked; }).length;
        selectAll.checked = visible.length > 0 && checked === visible.length;
        selectAll.indeterminate = checked > 0 && checked < visible.length;
      }
    }

    function clearSelection() {
      rows.forEach(function (r) { r._cb.checked = false; });
      updateSelection();
    }

    function ids(list) { return list.map(function (r) { return r._cb.value; }); }

    function exportCsv(idList) {
      var f = document.createElement('form');
      f.method = 'POST';
      f.action = window.location.pathname;
      f.style.display = 'none';
      [['export', 'csv'], ['ids', idList && idList.length ? idList.join(',') : '']].forEach(function (kv) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
      setTimeout(function () { document.body.removeChild(f); }, 0);
    }

    // ── Wire up ──────────────────────────────────────────────────────────
    if (searchEl) {
      searchEl.addEventListener('input', function () { applyFilters(); });
      searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && searchEl.value) { searchEl.value = ''; applyFilters(); }
      });
    }
    selects.forEach(function (s) { s.addEventListener('change', function () { applyFilters(); }); });
    pills.forEach(function (p) {
      p.addEventListener('click', function () { activePill = p.getAttribute('data-value'); applyFilters(); });
    });
    if (sortEl) sortEl.addEventListener('change', function () { sortRows(); applyFilters(); });
    if (resetBtn) resetBtn.addEventListener('click', clearFilters);
    Array.prototype.forEach.call(document.querySelectorAll('[data-clear-filters]'), function (b) {
      b.addEventListener('click', clearFilters);
    });

    sizeSel.addEventListener('change', function () { page = 1; render(); });
    prevBtn.addEventListener('click', function () { if (page > 1) { page--; render(); } });
    nextBtn.addEventListener('click', function () { page++; render(); });

    rows.forEach(function (r) { r._cb.addEventListener('change', updateSelection); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        rows.forEach(function (r) { if (!r.classList.contains('hidden')) r._cb.checked = selectAll.checked; });
        updateSelection();
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        exportCsv(filtersActive ? ids(rows.filter(function (r) { return r._match; })) : null);
      });
    }
    if (bulkBar) {
      var clearEl  = bulkBar.querySelector('[data-bulk="clear"]');
      var exportEl = bulkBar.querySelector('[data-bulk="export"]');
      if (clearEl)  clearEl.addEventListener('click', clearSelection);
      if (exportEl) exportEl.addEventListener('click', function () { exportCsv(ids(selectedRows())); });
      (cfg.bulk || []).forEach(function (b) {
        var el = byId(b.id);
        if (el) el.addEventListener('click', function () { b.run(selectedRows()); });
      });
    }

    sortRows();
    applyFilters(true);
  }

  global.AdminTable = { init: init };
})(window);
