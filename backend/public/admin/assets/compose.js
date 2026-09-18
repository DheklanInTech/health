/* Compose-page conveniences: showing only the relevant audience fields, and
   the phone/desktop preview toggle.

   External file rather than inline so the Content-Security-Policy can stay
   strict (script-src 'self', no 'unsafe-inline'). Everything here is an
   enhancement — with scripting off, all audience panels are visible and the
   preview renders at desktop width, which is usable, just less tidy. */

(function () {
  "use strict";

  // ------------------------------------------------------- audience panels

  var picks = Array.prototype.slice.call(
    document.querySelectorAll('.audience-picker input[name="audience"]')
  );
  var panels = Array.prototype.slice.call(document.querySelectorAll(".audience-panel"));

  function syncPanels() {
    var selected = picks.filter(function (input) {
      return input.checked;
    })[0];
    if (!selected) return;

    panels.forEach(function (panel) {
      var applies = (panel.getAttribute("data-audience") || "").split(" ");
      panel.hidden = applies.indexOf(selected.value) === -1;
    });

    picks.forEach(function (input) {
      var label = input.closest(".pick");
      if (label) label.classList.toggle("on", input.checked);
    });

    // "One address" takes a single address; the list view takes many.
    var addresses = document.getElementById("addresses");
    if (addresses) {
      addresses.rows = selected.value === "single" ? 1 : 3;
      addresses.setAttribute(
        "placeholder",
        selected.value === "single"
          ? "someone@example.org"
          : "someone@example.org, another@example.org"
      );
    }
  }

  if (picks.length && panels.length) {
    picks.forEach(function (input) {
      input.addEventListener("change", syncPanels);
    });
    syncPanels();
  }

  // -------------------------------------------------------- preview widths

  var toggle = document.getElementById("viewport-toggle");
  var frame = document.getElementById("preview-frame");

  if (toggle && frame) {
    toggle.addEventListener("click", function (event) {
      var button = event.target.closest("button.vp");
      if (!button) return;

      frame.classList.toggle("phone", button.getAttribute("data-width") === "phone");

      Array.prototype.slice.call(toggle.querySelectorAll("button.vp")).forEach(function (other) {
        other.classList.toggle("on", other === button);
        other.setAttribute("aria-pressed", other === button ? "true" : "false");
      });
    });
  }

  // --------------------------------------------------------- subject meter

  var subject = document.getElementById("subject");
  if (subject) {
    var meter = document.createElement("span");
    meter.className = "xsmall muted counter";
    subject.parentNode.appendChild(meter);

    var update = function () {
      var length = subject.value.length;
      meter.textContent = length + " characters" + (length > 60 ? " — inboxes will cut this off" : "");
      meter.classList.toggle("over", length > 60);
    };
    subject.addEventListener("input", update);
    update();
  }
})();
