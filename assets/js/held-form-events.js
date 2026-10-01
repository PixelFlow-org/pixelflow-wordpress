(function () {
  "use strict";

  // Flushes form events held while a consent banner was unanswered, once the visitor decides
  // on the same page. Independent of held-events.js, which serves the WooCommerce queue and
  // loads only when WooCommerce tracking is on.
  var config = window.pixelflowHeldFormEvents;
  if (!config || !config.stateUrl || !config.flushUrl) {
    return;
  }

  var resolving = false;
  var pollMs = 10000;
  var delayMs = 2000;
  var maxDelayMs = 16000;
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

  // Unlike the WooCommerce script, this one never asks the server without the hint: a form is
  // submitted over AJAX or a full POST whose response still sets cookies, and asking on every
  // page view would cost an admin-ajax request per page on every site with form tracking on.
  function hasHeldForms() {
    return readCookie(config.heldCookie) !== "";
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

  // The server answers whether a queue exists and hands back a nonce minted now, so neither
  // answer can be served stale by a full-page cache.
  function resolveHeldForms() {
    if (stopped || resolving || isHold() || !hasHeldForms()) {
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
    if (!isHold()) {
      resolveHeldForms();
    }
    if (!resolving) {
      schedule(pollMs);
    }
  }

  schedule(pollMs);

  document.addEventListener("wp_listen_for_consent_change", function () {
    delayMs = 2000;
    refusals = 0;
    resolveHeldForms();
  });

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      delayMs = 2000;
      tick();
    }
  });
})();
