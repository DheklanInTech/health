/* Small conveniences for the applications table.
   External file rather than inline so the Content-Security-Policy can stay
   strict (script-src 'self', no 'unsafe-inline'). */

(function () {
  "use strict";

  var checkAll = document.getElementById("check-all");
  if (!checkAll) return;

  var boxes = function () {
    return Array.prototype.slice.call(
      document.querySelectorAll('input[type="checkbox"][name="ids[]"]')
    );
  };

  checkAll.addEventListener("change", function () {
    boxes().forEach(function (box) {
      box.checked = checkAll.checked;
    });
  });

  // Keep the header box in step when rows are ticked individually.
  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!target || target.name !== "ids[]") return;

    var all = boxes();
    var checked = all.filter(function (box) {
      return box.checked;
    });

    checkAll.checked = checked.length === all.length && all.length > 0;
    checkAll.indeterminate = checked.length > 0 && checked.length < all.length;
  });
})();
