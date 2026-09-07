(function () {
  "use strict";

  var config = window.pixelflowHeldEvents;
  if (!config || !config.stateUrl || !config.flushUrl) {
    return;
  }

  var resolving = false;
  // The timer is the fallback path: a banner that announces its decision through
  // the WP Consent API is acted on immediately, so this can be relaxed.
  var pollMs = 10000;
  var delayMs = 2000;
  var maxDelayMs = 16000;
  var probed = false;
  var refusals = 0;
  var maxRefusals = 5;
  var stopped = false;
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

  // A local hint only. The cookie is skipped whenever the queue was created in a
  // request that had already sent its headers, so the server is asked as well.
  function cookieSuggestsQueue() {
    var raw = readCookie(config.heldCookie);
    return raw !== "" && raw !== "[]";
  }

  function isHold() {
    return readCookie(config.holdCookie) === config.holdValue;
  }

  function schedule(ms) {
    if (stopped) {
      return;
    }
    if (timer !== null) {
      window.clearTimeout(timer);
    }
    timer = window.setTimeout(tick, ms);
  }

  function backOff() {
    refusals += 1;
    if (refusals >= maxRefusals) {
      // The refusal that lands once the delay is already at the ceiling is the
      // last one: a permanently unavailable endpoint is not polled forever.
      stopped = true;
      return;
    }
    schedule(delayMs);
    delayMs = Math.min(delayMs * 2, maxDelayMs);
  }

  function succeeded() {
    refusals = 0;
    delayMs = 2000;
    schedule(pollMs);
  }

  function flush(nonce) {
    var body = new URLSearchParams();
    body.set("nonce", nonce);

    fetch(config.flushUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
      keepalive: true,
    })
      .then(function (response) {
        resolving = false;
        if (!response || !response.ok) {
          backOff();
          return;
        }
        succeeded();
      })
      .catch(function () {
        resolving = false;
        backOff();
      });
  }

  // The server answers whether a queue exists and hands back a nonce minted now,
  // so neither answer can be served stale by a full-page cache.
  function resolveHeldEvents() {
    if (stopped || resolving || isHold()) {
      return;
    }
    resolving = true;

    fetch(config.stateUrl, { method: "GET", credentials: "same-origin" })
      .then(function (response) {
        if (!response || !response.ok) {
          resolving = false;
          backOff();
          return;
        }
        return response.json().then(function (payload) {
          var data = payload && payload.data ? payload.data : null;
          if (!data || !data.hasQueue || !data.nonce) {
            resolving = false;
            succeeded();
            return;
          }
          flush(data.nonce);
        });
      })
      .catch(function () {
        resolving = false;
        backOff();
      });
  }

  function tick() {
    if (isHold()) {
      delayMs = 2000;
      schedule(pollMs);
      return;
    }
    // Asked once per page load whatever the cookie says: a queue created in a
    // request that had already sent its headers left no cookie behind, and only
    // the server knows it exists. After that the cookie is hint enough.
    if (!probed || cookieSuggestsQueue()) {
      probed = true;
      resolveHeldEvents();
      return;
    }
    schedule(pollMs);
  }

  schedule(pollMs);

  // The consent management platform announces the shopper's answer through the WP
  // Consent API, so a decision made on this page is acted on without waiting out
  // the polling interval.
  document.addEventListener("wp_listen_for_consent_change", function () {
    delayMs = 2000;
    refusals = 0;
    resolveHeldEvents();
  });

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      delayMs = 2000;
      tick();
    }
  });
})();
