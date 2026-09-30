// Filter chips for Student LMS task lists (student_quizzes.php,
// student_assignments.php) — the same All / … chips as LMS Home's To-Do.
//
// Markup: a chip group <div data-filter-list="LIST_ID"> of
// <button class="hm-chip" data-filter="all|KEY">, a container #LIST_ID whose
// items carry data-status="KEY [KEY…]", optional subject headings marked
// [data-filter-group] (hidden when nothing under them is showing), and a
// sibling .lms-filter-none shown when the filter matches nothing.
(function () {
  document.querySelectorAll('[data-filter-list]').forEach(function (group) {
    var list = document.getElementById(group.dataset.filterList);
    if (!list) return;
    var chips = group.querySelectorAll('.hm-chip');
    var none  = list.parentNode.querySelector('.lms-filter-none');

    function apply(f) {
      var shown = 0;
      list.querySelectorAll('[data-status]').forEach(function (el) {
        var show = f === 'all' || (' ' + el.dataset.status + ' ').indexOf(' ' + f + ' ') !== -1;
        el.hidden = !show;
        if (show) shown++;
      });
      list.querySelectorAll('[data-filter-group]').forEach(function (h) {
        var box = h.nextElementSibling;
        var any = box && box.querySelector('[data-status]:not([hidden])');
        h.hidden = !any;
        if (box) box.hidden = !any;
      });
      if (none) none.hidden = shown > 0;
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        chips.forEach(function (c) { c.classList.toggle('is-on', c === chip); c.setAttribute('aria-pressed', c === chip); });
        apply(chip.dataset.filter);
      });
    });
  });
})();
