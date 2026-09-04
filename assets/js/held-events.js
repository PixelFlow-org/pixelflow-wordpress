(function () {
  "use strict";

  var config = window.pixelflowHeldEvents;
  if (!config || !config.ajaxUrl) {
    return;
  }

  var resolving = false;

  function readCookie(name) {
    var parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length < 2) {
      return "";
    }
    return decodeURIComponent(parts.pop().split(";").shift() || "");
  }

  function hasHeldEvents() {
    var raw = readCookie(config.heldCookie);
    return raw !== "" && raw !== "[]";
  }

  function isHold() {
    return readCookie(config.holdCookie) === config.holdValue;
  }

  function resolveHeldEvents() {
    if (resolving || !hasHeldEvents() || isHold()) {
      return;
    }
    resolving = true;

    var body = new URLSearchParams();
    body.set("action", "pixelflow_resolve_held_events");
    body.set("nonce", config.nonce);

    fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
      keepalive: true,
    }).finally(function () {
      resolving = false;
    });
  }

  window.setInterval(resolveHeldEvents, 400);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      resolveHeldEvents();
    }
  });
})();
