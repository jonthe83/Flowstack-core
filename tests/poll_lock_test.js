'use strict';

const { jeabPollDecide } = require('../assets/js/poll-lock.js');

let fail = 0;
function ok(cond, msg) {
  console.log((cond ? 'OK  ' : 'FAIL ') + msg);
  if (!cond) {
    fail++;
  }
}

const now = 1_000_000;
const self = 'tab-a';
const ttl = 90000;

const hidden = jeabPollDecide(now, self, { tab: self, at: now - 1000 }, true, ttl);
ok(hidden.leader === false && hidden.release === true, 'dold flik släpper ledarskapet');

const foreign = jeabPollDecide(now, self, { tab: 'tab-b', at: now - 1000 }, false, ttl);
ok(foreign.leader === false && foreign.release === false, 'färskt lås från en annan flik blockerar');

const stale = jeabPollDecide(now, self, { tab: 'tab-b', at: now - ttl }, false, ttl);
ok(stale.leader === true && stale.next && stale.next.tab === self, 'utgånget lås får tas över');

const mine = jeabPollDecide(now, self, { tab: self, at: now - 1000 }, false, ttl);
ok(mine.leader === true && mine.release === false, 'egen flik behåller låset');

const empty = jeabPollDecide(now, self, null, false, ttl);
ok(empty.leader === true && empty.next && empty.next.at === now, 'tomt lås ger en ledare');

process.exit(fail > 0 ? 1 : 0);
