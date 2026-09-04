(function () {
  "use strict";

  var config = window.pixelflowHeldEvents;
  if (!config || !config.ajaxUrl) {
    return;
  }

  var resolving = false;
  var pollMs = 1000;
  var delayMs = 2000;
  var maxDelayMs = 16000;
  var timer = null;

  function readCookie(name) {
    try {
      var parts = ("; " + document.cookie).split("; " + name + "=");
      if (parts.length < 2) {
        return "";
      }
      return decodeURIComponent(parts.pop().split(";").shift() || "");
    } catch (e) {
      return "";
    }
  }

  function hasHeldEvents() {
    var raw = readCookie(config.heldCookie);
    return raw !== "" && raw !== "[]";
  }

  function isHold() {
    return readCookie(config.holdCookie) === config.holdValue;
  }

  function schedule(ms) {
    if (timer !== null) {
      window.clearTimeout(timer);
    }
    timer = window.setTimeout(tick, ms);
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
      if (hasHeldEvents() && !isHold()) {
        delayMs = Math.min(delayMs * 2, maxDelayMs);
      } else {
        delayMs = 2000;
      }
      schedule(delayMs);
    });
  }

  function tick() {
    if (!hasHeldEvents() || isHold()) {
      delayMs = 2000;
      schedule(pollMs);
      return;
    }
    resolveHeldEvents();
  }

  schedule(pollMs);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      delayMs = 2000;
      tick();
    }
  });
})();
