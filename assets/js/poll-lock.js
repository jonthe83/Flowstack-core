/* En poller per webbläsare. Dold flik släpper låset. */
(function (root) {
  var KEY = 'jeab.poll.lock';
  var TTL = 90000;

  function jeabPollDecide(now, self, stored, hidden, ttl) {
    ttl = ttl || TTL;
    if (hidden) {
      return { leader: false, release: true };
    }
    if (!stored || stored.tab === self || (now - Number(stored.at || 0)) >= ttl) {
      return { leader: true, release: false, next: { tab: self, at: now } };
    }
    return { leader: false, release: false };
  }

  function jeabPollSelf() {
    if (!root.__jeabPollTab) {
      root.__jeabPollTab = Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    return root.__jeabPollTab;
  }

  function readLock() {
    try {
      return JSON.parse(root.localStorage.getItem(KEY) || 'null');
    } catch (e) {
      return null;
    }
  }

  function jeabPollRelease() {
    var self = jeabPollSelf();
    var stored = readLock();
    if (stored && stored.tab === self) {
      try { root.localStorage.removeItem(KEY); } catch (e) {}
    }
    try {
      if (root.__jeabPollBc) {
        root.__jeabPollBc.postMessage({ type: 'release', tab: self });
      }
    } catch (e) {}
  }

  function jeabPollClaim(hidden) {
    var self = jeabPollSelf();
    var now = Date.now();
    var stored = readLock();
    var decision = jeabPollDecide(now, self, stored, !!hidden, TTL);
    if (decision.release) {
      if (stored && stored.tab === self) {
        try { root.localStorage.removeItem(KEY); } catch (e) {}
      }
      return false;
    }
    if (!decision.leader) {
      return false;
    }
    try {
      root.localStorage.setItem(KEY, JSON.stringify(decision.next));
    } catch (e) {
      return true;
    }
    try {
      if (root.__jeabPollBc) {
        root.__jeabPollBc.postMessage({ type: 'leader', tab: self });
      }
    } catch (e2) {}
    return true;
  }

  root.jeabPollDecide = jeabPollDecide;
  root.jeabPollClaim = jeabPollClaim;
  root.jeabPollRelease = jeabPollRelease;
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { jeabPollDecide: jeabPollDecide };
  }
  if (typeof root.BroadcastChannel === 'function') {
    try {
      root.__jeabPollBc = new root.BroadcastChannel('jeab.poll');
    } catch (e) {}
  }
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        jeabPollRelease();
        if (root.jeabBackground && root.jeabBackground.abort) {
          root.jeabBackground.abort();
        }
      }
    });
  }
})(typeof window !== 'undefined' ? window : globalThis);
