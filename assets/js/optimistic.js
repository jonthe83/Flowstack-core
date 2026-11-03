/* Optimistisk klick: rita först, spara i bakgrunden, återställ vid fel.
   Kundutskick och Fortnox väntar på svar och ska inte gå den här vägen. */
(function (root) {
  function jeabParkDueLabel(by, now, dueValue) {
    var base = new Date(now.getTime());
    var raw = String(dueValue || '').trim();
    var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (match) {
      var parsed = new Date(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
        match[4] ? Number(match[4]) : 0,
        match[5] ? Number(match[5]) : 0,
        0,
        0
      );
      if (parsed.getTime() > base.getTime()) {
        base = parsed;
      }
    }
    if (by === '2h') {
      base = new Date(base.getTime() + 2 * 3600000);
    } else if (by === '2d' || by === '1w') {
      base = new Date(base.getFullYear(), base.getMonth(), base.getDate() + (by === '2d' ? 2 : 7), 0, 0, 0, 0);
    }
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var day = new Date(base.getFullYear(), base.getMonth(), base.getDate());
    var diff = Math.round((day.getTime() - today.getTime()) / 86400000);
    var label;
    if (diff === 0) {
      label = 'idag';
    } else if (diff === 1) {
      label = 'imorgon';
    } else if (diff === -1) {
      label = 'igår';
    } else {
      var weekdays = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
      var months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
      label = weekdays[base.getDay()] + ' ' + base.getDate() + ' ' + months[base.getMonth()];
    }
    if (by === '2h') {
      var hh = base.getHours();
      var mm = base.getMinutes();
      label += ' ' + (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
    }
    return label;
  }

  /* true = vänta på servern (utskick eller Fortnox). false = rita direkt. */
  function jeabClickWaits(action, fields) {
    var name = String(action || '');
    var extra = fields || {};
    var intent = String(extra.intent || '');
    var tag = String(extra.tag || '');
    if (tag === 'vunnen') {
      return true;
    }
    var send = {
      'ordrar.slutfor': true,
      'ordrar.fortnox': true,
      'ordrar.fortnox_utan_bokning': true,
      'ordrar.fakturering': true,
      'ordrar.foranmalan': true,
      'ordrar.foranmalan_resend': true,
      'ordrar.fullmakt_resend': true,
      'ordrar.fullmakt_ny': true,
      'ordrar.boka': true,
      'ordrar.bestall': true,
      'ordrar.solkoll': true,
      'ordrar.advance': true,
      'ordrar.payout_retry': true
    };
    if (send[name]) {
      return true;
    }
    if ((name === 'ordrar.bestall_steg' || name === 'ordrar.batterisol' || name === 'ordrar.boka_bestall') && (intent === 'next' || intent === 'nasta')) {
      return true;
    }
    return false;
  }

  /* Knuffen sparas direkt. Ingen väntan före POST. */
  function jeabSnoozeLeaveMs() {
    return 0;
  }

  /* Ångra är en återställning efteråt: knuffen är redan sparad och Ångra
     skriver tillbaka den tidigare tiden. Det här är bara hur länge knappen syns. */
  function jeabSnoozeUndoMs() {
    return 6000;
  }

  function jeabPad2(n) {
    return (n < 10 ? '0' : '') + n;
  }

  /* Nästa tid efter en knuff. Utgångspunkten är den tidigare tiden om den ligger
     framåt, annars nu. 2h lägger två timmar. 2d och 1v lägger dagar och nollar klockan. */
  function jeabSnoozeAdvance(by, now, dueRaw) {
    var base = new Date(now.getTime());
    var raw = String(dueRaw || '').trim();
    var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (match) {
      var parsed = new Date(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
        match[4] ? Number(match[4]) : 0,
        match[5] ? Number(match[5]) : 0,
        match[6] ? Number(match[6]) : 0,
        0
      );
      if (parsed.getTime() > base.getTime()) {
        base = parsed;
      }
    }
    if (by === '2h') {
      base = new Date(base.getTime() + 2 * 3600000);
    } else if (by === '2d' || by === '1w') {
      base = new Date(base.getFullYear(), base.getMonth(), base.getDate() + (by === '2d' ? 2 : 7), 0, 0, 0, 0);
    } else {
      return { stamp: '', label: '', date: '', contact: '' };
    }
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var day = new Date(base.getFullYear(), base.getMonth(), base.getDate());
    var diff = Math.round((day.getTime() - today.getTime()) / 86400000);
    var hh = base.getHours();
    var mm = base.getMinutes();
    var label;
    /* 2d och 1v nollar klockan. «idag»/«imorgon»/veckodag döljer då dygnshoppet.
       Samma korta datum som det stängda kortet: 23/10 blir 25/10. */
    if (by === '2d' || by === '1w') {
      label = base.getDate() + '/' + (base.getMonth() + 1);
    } else if (diff === 0) {
      label = 'idag';
    } else if (diff === 1) {
      label = 'imorgon';
    } else if (diff === -1) {
      label = 'igår';
    } else {
      var weekdays = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
      var months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
      label = weekdays[base.getDay()] + ' ' + base.getDate() + ' ' + months[base.getMonth()];
    }
    if (by === '2h' && (hh !== 0 || mm !== 0)) {
      label += ' ' + jeabPad2(hh) + ':' + jeabPad2(mm);
    }
    var stamp = base.getFullYear() + '-' + jeabPad2(base.getMonth() + 1) + '-' + jeabPad2(base.getDate())
      + ' ' + jeabPad2(hh) + ':' + jeabPad2(mm) + ':' + jeabPad2(base.getSeconds());
    return {
      stamp: stamp,
      label: label,
      date: stamp.slice(0, 10),
      contact: label
    };
  }

  /* pointerup räknas. submit/click inom 700 ms efter den är samma gest.
     Ett nytt tryck räknas igen. Enter och requestSubmit utan pekare räknas. */
  function jeabSnoozeTapCounts(state, eventName, nowMs) {
    var now = Number(nowMs) || 0;
    var prev = state || { at: -100000, kind: '' };
    var name = String(eventName || '');
    var since = now - Number(prev.at || 0);
    if (name === 'pointerup' || name === 'touchend') {
      if (prev.kind === 'pointer' && since >= 0 && since < 40) {
        return { count: false, state: prev };
      }
      return { count: true, state: { at: now, kind: 'pointer' } };
    }
    if (name === 'click' || name === 'submit') {
      if (prev.kind === 'pointer' && since >= 0 && since < 700) {
        return { count: false, state: prev };
      }
      if ((prev.kind === 'click' || prev.kind === 'submit') && since >= 0 && since < 40) {
        return { count: false, state: prev };
      }
      return { count: true, state: { at: now, kind: name } };
    }
    return { count: false, state: prev };
  }

  /* Tryck bara när fingret eller musen rört sig mindre än 24 px.
     Ett vågrätt svep på tröskeln vinner och öppnar inte kortet. */
  function jeabReminderGesture(pointerType, dx, dy, eventType) {
    var x = Number(dx) || 0;
    var y = Number(dy) || 0;
    var dist = Math.hypot(x, y);
    var up = eventType === 'pointerup';
    if (!up) {
      return 'none';
    }
    if (Math.abs(x) >= 80 && Math.abs(x) > Math.abs(y)) {
      return x > 0 ? 'done' : 'snooze';
    }
    if (dist < 24) {
      return 'open';
    }
    return 'none';
  }

  /* Senaste trycket bestämmer när kortet lämnar listan och hur länge Ångra syns. */
  function jeabSnoozeAfterTaps(now, dueRaw, taps) {
    var stamp = String(dueRaw || '');
    var label = '';
    var last = 0;
    var list = taps || [];
    for (var i = 0; i < list.length; i++) {
      var tap = list[i] || {};
      var at = new Date(now.getTime() + Number(tap.t || 0));
      var got = jeabSnoozeAdvance(tap.by, at, stamp);
      stamp = got.stamp;
      label = got.label;
      last = Number(tap.t || 0);
    }
    return {
      stamp: stamp,
      label: label,
      removeAt: last + jeabSnoozeLeaveMs(),
      undoUntil: last + jeabSnoozeUndoMs()
    };
  }

  /* Kortet stannar i listan under knuffens 2 s även när «förfaller nu»
     eller en omritning inte längre har med id:t. Redan lämnat: släpp. */
  function jeabSnoozeHoldSurvives(inFilteredList, holding, alreadyLeft) {
    if (alreadyLeft) {
      return false;
    }
    if (holding) {
      return true;
    }
    return !!inFilteredList;
  }

  /* Pekarspärren är till för när listan hoppar ihop. Under väntan ska 2h gå att trycka igen. */
  function jeabSnoozePointerBlock(phase) {
    return String(phase || '') === 'leave';
  }

  /* Synk får inte bygga en ny rad åt ett kort som redan lämnat listan,
     och inte byta ut raden medan timern sitter på den. */
  function jeabSnoozeSyncKeeps(id, suppressed, live) {
    var key = String(id || '');
    if (!key) {
      return 'replace';
    }
    var hidden = suppressed && (typeof suppressed.has === 'function' ? suppressed.has(key) : !!suppressed[key]);
    if (hidden) {
      return 'drop';
    }
    var staying = live && (typeof live.has === 'function' ? live.has(key) : !!live[key]);
    if (staying) {
      return 'keep';
    }
    return 'replace';
  }

  /* Väggklocka från serverns due_at. Samma ord som kortet: idag/imorgon/igår
     eller «fre 23 okt», plus HH:MM när klockan inte är midnatt.
     todayYmd är serverns datum. Klockan klipps ur strängen, aldrig via Date. */
  function jeabSnoozeDueLabel(dueAt, todayYmd) {
    var stamp = jeabSnoozeDueStamp(dueAt);
    if (!stamp) {
      return '';
    }
    var y = Number(stamp.slice(0, 4));
    var mo = Number(stamp.slice(5, 7));
    var d = Number(stamp.slice(8, 10));
    var clock = stamp.slice(11, 16);
    var today = String(todayYmd || '');
    var label = d + '/' + mo;
    if (/^\d{4}-\d{2}-\d{2}$/.test(today)) {
      var parts = today.split('-');
      var diff = Math.round((Date.UTC(y, mo - 1, d) - Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]))) / 86400000);
      if (diff === 0) {
        label = 'idag';
      } else if (diff === 1) {
        label = 'imorgon';
      } else if (diff === -1) {
        label = 'igår';
      } else {
        var weekdays = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
        var months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
        label = weekdays[new Date(Date.UTC(y, mo - 1, d)).getUTCDay()] + ' ' + d + ' ' + months[mo - 1];
      }
    }
    if (clock !== '00:00') {
      label += ' ' + clock;
    }
    return label;
  }

  /* Europe/Stockholm från ett ögonblick. getHours() följer enhetens zon och
     skulle lägga 2h på UTC när processen inte står i Stockholm. */
  function jeabStockholmParts(instant) {
    var dt = instant instanceof Date ? instant : new Date(instant);
    if (!dt || isNaN(dt.getTime()) || typeof Intl === 'undefined' || typeof Intl.DateTimeFormat !== 'function') {
      return null;
    }
    var fmt = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Europe/Stockholm',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23'
    });
    var map = {};
    fmt.formatToParts(dt).forEach(function (part) {
      map[part.type] = part.value;
    });
    if (!map.year || !map.month || !map.day) {
      return null;
    }
    var hh = Number(map.hour);
    if (hh === 24) {
      hh = 0;
    }
    return {
      y: Number(map.year),
      mo: Number(map.month),
      d: Number(map.day),
      hh: hh,
      mm: Number(map.minute),
      ss: Number(map.second)
    };
  }

  function jeabStockholmStamp(instant) {
    var parts = jeabStockholmParts(instant);
    if (!parts) {
      return '';
    }
    return parts.y + '-' + jeabPad2(parts.mo) + '-' + jeabPad2(parts.d)
      + ' ' + jeabPad2(parts.hh) + ':' + jeabPad2(parts.mm) + ':' + jeabPad2(parts.ss);
  }

  function jeabStockholmToday(instant) {
    var parts = jeabStockholmParts(instant);
    if (!parts) {
      return '';
    }
    return parts.y + '-' + jeabPad2(parts.mo) + '-' + jeabPad2(parts.d);
  }

  /* Väggklocka plus timmar eller dygn. Siffrorna räknas i UTC så enhetens
     tidszon inte flyttar 19:30. 2h lägger två timmar. 2d och 1v nollar klockan.
     Med now är basen max(nu i Stockholm, due). En försenad 02:00 blir alltså
     nu+2h eller idag+N, inte det gamla klockslaget. */
  function jeabSnoozeStack(dueAt, taps, now) {
    var stamp = jeabSnoozeDueStamp(dueAt);
    if (now != null && now !== '') {
      var wall = jeabStockholmStamp(now);
      if (wall && (!stamp || wall > stamp)) {
        stamp = wall;
      }
    }
    if (!stamp) {
      return '';
    }
    var list = taps || [];
    for (var i = 0; i < list.length; i++) {
      var by = String(list[i] || '');
      var y = Number(stamp.slice(0, 4));
      var mo = Number(stamp.slice(5, 7));
      var d = Number(stamp.slice(8, 10));
      var hh = Number(stamp.slice(11, 13));
      var mm = Number(stamp.slice(14, 16));
      var ss = Number(stamp.slice(17, 19));
      var ms;
      if (by === '2h') {
        ms = Date.UTC(y, mo - 1, d, hh + 2, mm, ss);
      } else if (by === '2d' || by === '1w') {
        ms = Date.UTC(y, mo - 1, d + (by === '2d' ? 2 : 7), 0, 0, 0);
      } else {
        continue;
      }
      var dt = new Date(ms);
      stamp = dt.getUTCFullYear() + '-' + jeabPad2(dt.getUTCMonth() + 1) + '-' + jeabPad2(dt.getUTCDate())
        + ' ' + jeabPad2(dt.getUTCHours()) + ':' + jeabPad2(dt.getUTCMinutes()) + ':' + jeabPad2(dt.getUTCSeconds());
    }
    return stamp;
  }

  /* Ångra efter att kortet lämnat listan. Tiden är den från före första trycket. */
  function jeabSnoozeUndoPlan(record, nowMs, inList) {
    if (!jeabSnoozeUndoLive(record, nowMs)) {
      return { act: 'ignore', dueAt: '', reinsert: false };
    }
    return {
      act: 'restore',
      dueAt: String(record.dueAt || ''),
      reinsert: !inList
    };
  }

  /* Varje tryck flyttar lämna-tiden till trycket plus 2 s. Ett tidigare tryck gäller inte. */
  function jeabSnoozeLeaveDeadline(tapAt) {
    return Number(tapAt) + jeabSnoozeLeaveMs();
  }

  /* Serverns due_at, «ÅÅÅÅ-MM-DD HH:MM:SS». Siffrorna tas som de står.
     Date/toISOString skulle flytta 12:54 till 18:54 mellan UTC och Stockholm. */
  function jeabSnoozeDueStamp(raw) {
    var s = String(raw == null ? '' : raw).trim().replace('T', ' ');
    var m = s.match(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(?::(\d{2}))?/);
    if (!m) {
      return '';
    }
    return m[1] + ' ' + m[2] + ':' + (m[3] || '00');
  }

  /* Ångra lever kvar efter att kortet lämnat DOM. Tiden är den ursprungliga. */
  function jeabSnoozeUndoRecord(id, dueAt, completedAt, nowMs) {
    var stamp = jeabSnoozeDueStamp(dueAt);
    return {
      id: String(id || ''),
      dueAt: stamp !== '' ? stamp : (dueAt == null ? '' : String(dueAt)),
      completedAt: completedAt == null ? '' : String(completedAt),
      until: Number(nowMs || 0) + jeabSnoozeUndoMs()
    };
  }

  function jeabSnoozeUndoLive(record, nowMs) {
    if (!record || !record.id || record.undone) {
      return false;
    }
    return Number(nowMs || 0) <= Number(record.until);
  }

  /* Svar från en tidigare knuff gäller bara om epoken är densamma. */
  function jeabSnoozeEpochAllows(sentEpoch, liveEpoch) {
    return Number(sentEpoch) === Number(liveEpoch);
  }

  /* Ångra höjer epoken. Inom de 2 s avbryts sparningen. Har POST redan gått
     återställs ursprungstiden, och ett senare svar med gammal epok ignoreras. */
  function jeabSnoozeGuard(state, event) {
    var epoch = Number(state && state.epoch) || 0;
    var phase = String((state && state.phase) || 'idle');
    var original = String((state && state.original) || '');
    var due = String((state && state.due) || '');
    var type = String((event && event.type) || '');
    if (type === 'hold') {
      var orig = original !== '' ? original : String((event && event.due) || '');
      return {
        epoch: epoch,
        phase: 'holding',
        original: orig,
        due: String((event && event.due) || due),
        post: '',
        ignore: false
      };
    }
    if (type === 'undo') {
      var restore = phase === 'inflight' || phase === 'saved';
      return {
        epoch: epoch + 1,
        phase: restore ? 'restoring' : 'cancelled',
        original: original,
        due: original,
        post: restore ? 'undo' : '',
        ignore: false
      };
    }
    if (type === 'snooze-send') {
      if (phase !== 'holding') {
        return { epoch: epoch, phase: phase, original: original, due: due, post: '', ignore: true };
      }
      return {
        epoch: epoch,
        phase: 'inflight',
        original: original,
        due: String((event && event.due) || due),
        post: 'snooze',
        ignore: false
      };
    }
    if (type === 'snooze-response' || type === 'undo-response') {
      var sent = Number(event && event.epoch);
      var stale = !jeabSnoozeEpochAllows(sent, epoch);
      var closed = phase === 'cancelled' || phase === 'restoring' || phase === 'restored';
      if (stale || (type === 'snooze-response' && (closed || phase !== 'inflight'))) {
        return { epoch: epoch, phase: phase, original: original, due: due, post: '', ignore: true };
      }
      if (type === 'undo-response') {
        return { epoch: epoch, phase: 'restored', original: original, due: original, post: '', ignore: false };
      }
      return {
        epoch: epoch,
        phase: 'saved',
        original: original,
        due: String((event && event.due) || due),
        post: '',
        ignore: false
      };
    }
    return { epoch: epoch, phase: phase, original: original, due: due, post: '', ignore: true };
  }

  /* Medan listan hoppar ihop ska ett sent tryck inte träffa knappen under. */
  function jeabSnoozeShiftMs() {
    return 400;
  }

  /* Öppningen på mobil sker på pointerup. Klicket direkt efter landar ofta på
     adressfältet som just visades (Safari flyttar klicket när den stängda länken
     döljs) och ska inte stänga kortet. Textfält, länk och knapp rörs inte.
     Ett senare tryck på huvudet stänger. header-row är adress- och namnraden
     på mobil, även när fältet redan har fokus. Knapp och länk är inte header-row. */
  function jeabReminderHeadClick(openedAt, now, target) {
    var kind = String(target || '');
    if (kind === 'textarea' || kind === 'select' || kind === 'link' || kind === 'button' || kind === 'input-focused') {
      return 'keep';
    }
    var since = Number(now) - Number(openedAt);
    if (since >= 0 && since < 400) {
      return 'keep';
    }
    return 'close';
  }

  /* #vunnen med luckor ska säga till, inte tystna. Komplett data går till
     bekräftelsen. Avstängning frågar som förut. Köpet själv rörs inte. */
  function jeabVunnenClickPlan(needText, turningOff) {
    if (turningOff) {
      return { action: 'ask' };
    }
    var need = String(needText || '').trim();
    if (need !== '') {
      var message = need.indexOf('Ordern skapades inte') === 0
        ? need
        : ('Ordern skapades inte. ' + need);
      return { action: 'message', message: message };
    }
    return { action: 'purchase' };
  }

  /* Bocken är den enda som får markera klar. En taggknapp, även om klicket
     landar på bocken efter preventDefault på mousedown, får inte. */
  function jeabReminderDoneSubmit(hasSubmitter, submitterIsCheck, fromTag) {
    if (fromTag) {
      return 'ignore';
    }
    if (hasSubmitter && !submitterIsCheck) {
      return 'ignore';
    }
    return 'done';
  }

  /* Högerkolumnen hör till det öppna kortet. Ett tryck där ska inte stänga
     kortet (det åt upp #vunnen, #admin och kalenderlänken). Utanför stängs det. */
  function jeabReminderOutsidePlan(where) {
    var place = String(where || '');
    if (place === 'side' || place === 'open-row') {
      return 'keep';
    }
    return 'close';
  }

  /* Öppna kortet på plats. Saknas raden eller historiken blir det en omladdning. */
  function jeabReminderOpenPlan(hasRow, hasPushState) {
    if (!hasRow || !hasPushState) {
      return 'reload';
    }
    return 'push';
  }

  /* En anteckning som redan syns på det stängda kortet ska inte läggas till igen. */
  function jeabShutNotePlan(existingCount, note) {
    var text = String(note || '').trim();
    var count = Number(existingCount) || 0;
    if (!text) {
      return count > 0 ? 'clear' : 'none';
    }
    return count > 0 ? 'keep' : 'append';
  }

  /* Idag i kortets popup. Redan idag: stäng och visa kortet, rör inte tiden.
     Annan dag med klockslag: flytta dagen och behåll klockan. */
  function jeabCalPopupToday(cardDay, cardTime, targetDay) {
    var time = String(cardTime || '');
    var valid = /^([01]\d|2[0-3]):[0-5]\d$/.test(time) && time !== '00:00';
    var card = String(cardDay || '');
    var target = String(targetDay || '');
    if (card !== '' && target !== '' && card === target) {
      return { action: 'feedback', day: card, time: valid ? time : '' };
    }
    if (valid && target !== '') {
      return { action: 'set-today', day: target, time: time };
    }
    return { action: 'close', day: target, time: '' };
  }

  /* Idag i kalendern. Redan idag med giltig tid: visa det, rör inte klockan.
     Öppet formulär på en annan dag: sätt idag och behåll klockan. Annars navigera. */
  function jeabCalTodayPlan(view, focusDay, targetDay, cardDay, cardTime, cardKind) {
    var time = String(cardTime || '');
    var valid = /^([01]\d|2[0-3]):[0-5]\d$/.test(time) && time !== '00:00';
    var target = String(targetDay || '');
    var focus = String(focusDay || '');
    var card = String(cardDay || '');
    var kind = String(cardKind || '');
    var onTargetView = String(view || '') === 'day' && focus !== '' && focus === target;
    var cardOnTarget = card !== '' && target !== '' && card === target;
    if (cardOnTarget && valid) {
      return { action: 'feedback', day: target, time: time };
    }
    if (kind === 'sheet' && valid && target !== '' && card !== '' && card !== target) {
      return { action: 'set-today', day: target, time: time };
    }
    if (onTargetView) {
      return { action: 'feedback', day: target, time: valid ? time : '' };
    }
    return { action: 'navigate', day: target, time: valid ? time : '' };
  }

  /* «Visa fler» använder serverns next-cursor, inte hur många kort som syns. */
  function jeabReminderMoreOffset(stored) {
    var raw = stored == null ? '' : String(stored).trim();
    if (/^[0-9]+$/.test(raw)) {
      return String(parseInt(raw, 10));
    }
    return '0';
  }

  /* 2h, 2d och 1v stannar kvar tills väntetiden gått. Ångra avbryter. */
  function jeabSnoozeHides(by, elapsedMs, undone) {
    if (undone || (by !== '2h' && by !== '2d' && by !== '1w')) {
      return false;
    }
    return Number(elapsedMs) >= jeabSnoozeLeaveMs();
  }

  root.jeabParkDueLabel = jeabParkDueLabel;
  root.jeabClickWaits = jeabClickWaits;
  root.jeabSnoozeLeaveMs = jeabSnoozeLeaveMs;
  root.jeabSnoozeUndoMs = jeabSnoozeUndoMs;
  root.jeabSnoozeHides = jeabSnoozeHides;
  root.jeabSnoozeAdvance = jeabSnoozeAdvance;
  root.jeabSnoozeAfterTaps = jeabSnoozeAfterTaps;
  root.jeabSnoozeHoldSurvives = jeabSnoozeHoldSurvives;
  root.jeabSnoozePointerBlock = jeabSnoozePointerBlock;
  root.jeabSnoozeSyncKeeps = jeabSnoozeSyncKeeps;
  root.jeabSnoozeDueLabel = jeabSnoozeDueLabel;
  root.jeabSnoozeStack = jeabSnoozeStack;
  root.jeabStockholmStamp = jeabStockholmStamp;
  root.jeabStockholmToday = jeabStockholmToday;
  root.jeabSnoozeUndoPlan = jeabSnoozeUndoPlan;
  root.jeabSnoozeLeaveDeadline = jeabSnoozeLeaveDeadline;
  root.jeabCalPopupToday = jeabCalPopupToday;
  root.jeabSnoozeDueStamp = jeabSnoozeDueStamp;
  root.jeabSnoozeUndoRecord = jeabSnoozeUndoRecord;
  root.jeabSnoozeUndoLive = jeabSnoozeUndoLive;
  root.jeabSnoozeEpochAllows = jeabSnoozeEpochAllows;
  root.jeabSnoozeGuard = jeabSnoozeGuard;
  root.jeabSnoozeShiftMs = jeabSnoozeShiftMs;
  root.jeabReminderHeadClick = jeabReminderHeadClick;
  root.jeabVunnenClickPlan = jeabVunnenClickPlan;
  root.jeabReminderDoneSubmit = jeabReminderDoneSubmit;
  root.jeabReminderOutsidePlan = jeabReminderOutsidePlan;
  root.jeabReminderOpenPlan = jeabReminderOpenPlan;
  root.jeabShutNotePlan = jeabShutNotePlan;
  root.jeabCalTodayPlan = jeabCalTodayPlan;
  root.jeabSnoozeTapCounts = jeabSnoozeTapCounts;
  root.jeabReminderGesture = jeabReminderGesture;
  root.jeabReminderMoreOffset = jeabReminderMoreOffset;
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
      jeabParkDueLabel: jeabParkDueLabel,
      jeabClickWaits: jeabClickWaits,
      jeabSnoozeLeaveMs: jeabSnoozeLeaveMs,
      jeabSnoozeUndoMs: jeabSnoozeUndoMs,
      jeabSnoozeHides: jeabSnoozeHides,
      jeabSnoozeAdvance: jeabSnoozeAdvance,
      jeabSnoozeAfterTaps: jeabSnoozeAfterTaps,
      jeabSnoozeHoldSurvives: jeabSnoozeHoldSurvives,
      jeabSnoozePointerBlock: jeabSnoozePointerBlock,
      jeabSnoozeSyncKeeps: jeabSnoozeSyncKeeps,
      jeabSnoozeDueLabel: jeabSnoozeDueLabel,
      jeabSnoozeStack: jeabSnoozeStack,
      jeabStockholmStamp: jeabStockholmStamp,
      jeabStockholmToday: jeabStockholmToday,
      jeabSnoozeUndoPlan: jeabSnoozeUndoPlan,
      jeabSnoozeLeaveDeadline: jeabSnoozeLeaveDeadline,
      jeabCalPopupToday: jeabCalPopupToday,
      jeabSnoozeDueStamp: jeabSnoozeDueStamp,
      jeabSnoozeUndoRecord: jeabSnoozeUndoRecord,
      jeabSnoozeUndoLive: jeabSnoozeUndoLive,
      jeabSnoozeEpochAllows: jeabSnoozeEpochAllows,
      jeabSnoozeGuard: jeabSnoozeGuard,
      jeabSnoozeShiftMs: jeabSnoozeShiftMs,
      jeabReminderHeadClick: jeabReminderHeadClick,
      jeabVunnenClickPlan: jeabVunnenClickPlan,
      jeabReminderDoneSubmit: jeabReminderDoneSubmit,
      jeabReminderOutsidePlan: jeabReminderOutsidePlan,
      jeabReminderOpenPlan: jeabReminderOpenPlan,
      jeabShutNotePlan: jeabShutNotePlan,
      jeabCalTodayPlan: jeabCalTodayPlan,
      jeabSnoozeTapCounts: jeabSnoozeTapCounts,
      jeabReminderGesture: jeabReminderGesture,
      jeabReminderMoreOffset: jeabReminderMoreOffset
    };
  }
})(typeof window !== 'undefined' ? window : global);
