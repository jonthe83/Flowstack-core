<?php

declare(strict_types=1);

/** Inkorg: intag, idempotens, regler från data, generisk utvärderare, ommärkning, testrensning. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-inkorg-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ik(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$mail = fn(string $id, string $from, string $subj, string $body, array $h = []) => ['message_id' => "<{$id}@test>", 'from' => $from, 'to' => 'inkorg+inkorg@example.test', 'subject' => $subj, 'body_text' => $body, 'headers' => $h, 'source' => 'test-unit', 'test' => true];

// 1) Utvärderaren är generisk: tomma regler och listor ger ingen träff.
$rulesPath = jeab_inkorg_dir() . '/rules.json';
file_put_contents($rulesPath, json_encode(['rules' => [], 'lists' => [], 'removed_seeds' => array_column(jeab_inkorg_seed_rules(), 'id'), 'removed_lists' => array_keys(jeab_inkorg_seed_lists())]));
$junk = ['from' => 'noreply@shop.se', 'subject' => 'Orderbekräftelse nyhetsbrev', 'body' => 'Hello, the best solar battery for you and your home. Thanks!', 'headers' => ['List-Unsubscribe' => '<mailto:x>', 'Precedence' => 'bulk']];
$l = jeab_inkorg_classify($junk);
ik($l['type'] === 'Oklassad' && $l['source'] === 'none', 'inga regler → steg 1 klassar inget (Oklassad)');
ik(!jeab_inkorg_urgent('strömavbrott vattenläcka'), 'inga listor → ingen höjning');
$src = '';
foreach (['jeab_inkorg_cond_matches', 'jeab_inkorg_rule_matches', 'jeab_inkorg_rule_normalize', 'jeab_inkorg_rules_ordered', 'jeab_inkorg_entry_hits', 'jeab_inkorg_list_entries', 'jeab_inkorg_urgent', 'jeab_inkorg_finish_labels', 'jeab_inkorg_header'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $lines = file($rf->getFileName());
    $src .= implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
}
$rf = new ReflectionFunction('jeab_inkorg_classify');
$lines = file($rf->getFileName());
$cls = implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
$src .= substr($cls, 0, (int) strpos($cls, 'jeab_inkorg_llm_classify'));
foreach (['noreply', 'unsubscribe', 'solcell', 'battery', 'stockholm', 'malmö', "'the'", '+46', 'strömavbrott', 'nyhetsbrev@'] as $needle) {
    ik(stripos($src, $needle) === false, "utvärderaren innehåller inte «{$needle}»");
}
unlink($rulesPath);

// 2) En inbyggd webbshopregel; leadkällor och testadress står i instruktionen, inte i koden.
$rules = jeab_inkorg_rules_ordered();
ik(count($rules) === 1 && ($rules[0]['id'] ?? '') === 'builtin-webshop-order' && !empty($rules[0]['builtin']), 'förinställt: en inbyggd webbshopregel');
$intent = jeab_inkorg_seed_settings()['intent'];
foreach (['HemSol', 'Leadkit', 'kalkylformuläret', 'Bra Byggfirmor', 'Weply', 'demo.person@example.invalid'] as $needle) {
    ik(str_contains($intent, $needle), "instruktionen nämner «{$needle}»");
}
ik(!array_filter(array_keys(jeab_inkorg_lists()), fn($id) => str_starts_with((string) $id, 'produkt-')), 'inga produktordlistor');
$codeSrc = file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
foreach (['hemsol', 'leadkit', 'weply', 'brabyggfirmor', 'littenstrom'] as $needle) {
    ik(stripos($codeSrc, $needle) === false, "koden innehåller inte «{$needle}»");
}
ik(isset(jeab_inkorg_lists()['mejlbox-malmo']) && !isset(jeab_inkorg_lists()['sprak-engelska']), 'mejlboxlistor kvar, språklistor borta');

// 3) Intag.
$r = jeab_inkorg_ingest($mail('n1', 'Nyhet <news@brand.se>', 'Veckans erbjudanden', 'Hej! Kolla våra erbjudanden.', ['List-Unsubscribe' => '<https://x>']));
ik($r['ok'] && !$r['duplicate'] && $r['labels']['type'] === 'Oklassad', 'ingen innehållsregel: utan modell → Oklassad');
$r2 = jeab_inkorg_ingest($mail('n1', 'news@brand.se', 'x', 'y'));
ik($r2['duplicate'] === true && count(jeab_inkorg_messages()) === 1, 'idempotent på Message-ID');
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Ny lead","action":"svara","priority":1,"confidence":95,"product":"solceller, batteri","tags":[]}';
$r = jeab_inkorg_ingest($mail('l2', 'elias@hemsol.se', 'Nytt lead (HemSol)', 'Kunden vill ha solceller med batteri.'));
ik($r['labels']['source'] === 'llm' && $r['labels']['type'] === 'Ny lead' && $r['labels']['product'] === 'solceller, batteri', 'köpt lead klassas av Grok, produkt från Grok');
ik(in_array('sol', jeab_inkorg_thread_meta($r['thread_id'])['tags'], true) && in_array('batteri', jeab_inkorg_thread_meta($r['thread_id'])['tags'], true), 'Sol + Batteri');
unset($GLOBALS['_JEAB_INKORG_LLM']);

// Offert på solceller är köpintresse även utan modell.
$r = jeab_inkorg_ingest($mail('s1', 'kund@telia.com', 'Fråga om offert', 'Hej, jag undrar över er offert på solceller. Mvh'));
ik($r['labels']['type'] === 'Oklassad' && ($r['labels']['offer'] ?? 'sol') === '' && ($r['labels']['product'] ?? 'solceller') === '' && $r['labels']['needs_review'] && ($r['labels']['reason'] ?? '') === 'Behöver granskas', 'utan modell blir det granskning och ingen standardprodukt');

// Modell (injicerad) + höjning.
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Ärende från befintlig kund","action":"svara","priority":2,"confidence":91}';
$r = jeab_inkorg_ingest($mail('s2', 'kund2@telia.com', 'Strömavbrott', 'Hej, vi har strömavbrott sedan installationen. Mvh'));
ik($r['labels']['source'] === 'llm' && $r['labels']['type'] === 'Support', 'gammal typ från modellen blir Support (prioritet finns inte längre)');
unset($GLOBALS['_JEAB_INKORG_LLM']);

// 4) Regel-CRUD påverkar klassningen (egna regler går fortfarande att skapa).
$new = jeab_inkorg_rule_save(['name' => 'Leverantör', 'match' => 'all', 'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'lev.se']], 'type' => 'Kräver åtgärd från oss', 'action' => 'åtgärda', 'priority' => 2]);
ik(jeab_inkorg_rules_ordered()[0]['id'] === $new['id'], 'ny regel hamnar överst');
$c = jeab_inkorg_classify(['from' => 'order@sub.lev.se', 'subject' => 'Restnotering', 'body' => 'Kan ni återkomma?', 'headers' => []]);
ik(($c['rule_id'] ?? '') === $new['id'], 'domänregel träffar underdomän');
jeab_inkorg_rule_delete($new['id']);
ik(!in_array($new['id'], array_column(jeab_inkorg_rules(), 'id'), true), 'regel borttagen');
try {
    jeab_inkorg_rule_save(['conditions' => [['field' => 'subject', 'op' => 'regex', 'value' => '/(']], 'type' => 'Spara', 'action' => 'arkivera']);
    ik(false, 'ogiltig regex nekas');
} catch (InvalidArgumentException $e) {
    ik(true, 'ogiltig regex nekas');
}

// 4b) Grok-instruktion, Information, exempel och Skräp/Inte skräp.
$seen = [];
$GLOBALS['_JEAB_INKORG_LLM'] = function (array $msgs) use (&$seen) {
    $seen[] = $msgs[0]['content'];
    return '{"type":"Information","action":"arkivera","priority":4,"confidence":92,"product":""}';
};
jeab_inkorg_settings_save('Erbjuder avsändaren oss något är det skräp. TESTINSTRUKTION', ['solceller' => 'sol', 'batteri' => 'batteri']);
$r = jeab_inkorg_ingest($mail('i1', 'order@lev.se', 'Orderbekräftelse', 'Tack, leverans v. 44 enligt plan.'));
ik($r['labels']['type'] === 'Information' && $r['labels']['source'] === 'llm' && str_contains(end($seen), 'TESTINSTRUKTION'), 'Grok får instruktionen och kan svara Information');
$views = array_column(jeab_inkorg_views(), null, 'id');
$th = array_values(array_filter(jeab_inkorg_threads(), fn($t) => $t['id'] === $r['thread_id']))[0];
ik(jeab_inkorg_thread_matches($th, $views['v-information']['filter']) && !jeab_inkorg_thread_matches($th, $views['v-skrap']['filter']), 'vyn Information visar den, Skräp inte');
$beforeFlag = count($seen);
jeab_inkorg_flag($r['thread_id'], 'skrap', 'Test');
ik(count($seen) === $beforeFlag, 'Skräp-knappen väntar inte på modellen');
$th = array_values(array_filter(jeab_inkorg_threads(), fn($t) => $t['id'] === $r['thread_id']))[0];
ik($th['labels']['type'] === 'Skräp' && $th['labels']['source'] === 'manual' && jeab_inkorg_thread_matches($th, $views['v-skrap']['filter']), 'Skräp-knappen flyttar till Skräp');
jeab_inkorg_learn_drain();
$ex = jeab_inkorg_settings()['examples'];
ik(count($ex) === 1 && $ex[0]['label'] === 'skrap' && $ex[0]['from'] === 'order@lev.se', 'markeringen sparas som exempel');
jeab_inkorg_ingest($mail('i2', 'info@lev.se', 'Ny info', 'Hej'));
ik(str_contains(end($seen), 'order@lev.se') && str_contains(end($seen), 'SKRÄP'), 'exemplet följer med i prompten');
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Fråga från befintlig kund","action":"svara","priority":2,"confidence":88}';
$beforeNot = count($seen);
jeab_inkorg_flag($r['thread_id'], 'inte_skrap', 'Test');
ik(count($seen) === $beforeNot, 'Inte skräp sparar utan att vänta på modellen');
jeab_inkorg_learn_drain();
$th = array_values(array_filter(jeab_inkorg_threads(), fn($t) => $t['id'] === $r['thread_id']))[0];
ik($th['labels']['type'] === 'Support' && count(jeab_inkorg_settings()['examples']) === 1 && jeab_inkorg_settings()['examples'][0]['label'] === 'inte_skrap', 'Inte skräp: ny klassning, exemplet ersätts');
jeab_inkorg_example_delete(jeab_inkorg_settings()['examples'][0]['id']);
ik(jeab_inkorg_settings()['examples'] === [], 'exempel kan tas bort');
for ($i = 0; $i < 25; $i++) {
    jeab_inkorg_example_add(['id' => 'm' . $i, 'from' => "a{$i}@x.se", 'subject' => 's', 'body' => 'b'], 'skrap', 'Test');
}
ik(count(jeab_inkorg_examples_for(['from' => 'z@y.se'], 20)) === 20, 'högst 20 exempel i prompten');
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Ny lead","action":"svara","priority":1,"confidence":60,"product":"solceller"}';
$r = jeab_inkorg_ingest($mail('i3', 'kund@telia.com', 'Offert', 'Hej, vill ha solceller.'));
ik($r['labels']['needs_review'] && jeab_inkorg_thread_meta($r['thread_id'])['tags'] === ['sol'], 'under 80 → granska; produkt → etikett Sol');
unset($GLOBALS['_JEAB_INKORG_LLM']);

// 4c) Städning: alla regler bort, med säkerhetskopia.
jeab_inkorg_locked('rules.json', function (?array $d) {
    $d['rules'][] = ['id' => 'r-old', 'name' => 'Gammal', 'enabled' => true, 'match' => 'all', 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'x']], 'type' => 'Spara', 'action' => 'arkivera', 'priority' => 4];
    $d['lists']['produkt-solceller'] = ['name' => 'x', 'entries' => ['sol']];
    return [null, $d];
});
$mig = jeab_inkorg_rules_grok_only();
ik($mig['deleted'] === 2 && is_file(jeab_inkorg_dir() . '/' . $mig['backup']) && count(jeab_inkorg_rules()) === 0 && !isset(jeab_inkorg_lists()['produkt-solceller']), 'städning: inga regler kvar, kopia sparad');
ik(jeab_inkorg_settings()['intent'] === jeab_inkorg_seed_settings()['intent'] && jeab_inkorg_settings()['product_tags']['elbilsladdare'] === 'elbilsladdare', 'instruktionen och Elbilsladdare-etiketten satta');

// 5) Ommärkning sparas som korrigering + förslag.
$id = substr(hash('sha256', 's1@test'), 0, 16);
$row = jeab_inkorg_relabel($id, ['type' => 'Fråga från befintlig kund', 'action' => 'svara', 'priority' => 2], 'Test');
ik($row['labels']['source'] === 'manual' && is_file(jeab_inkorg_dir() . '/corrections.jsonl'), 'ommärkning sparad som korrigering');
$sug = jeab_inkorg_rule_suggestions($row);
ik(count($sug) === 1 && $sug[0]['field'] === 'from', 'förslag: avsändare (gratisdomän ger ingen domänregel)');

// 6) P1-lista + hook + rensning.
$p1 = jeab_inkorg_pending_p1();
ik(count($p1) >= 1 && $p1[0]['labels']['priority'] === 1, 'obesvarade P1 listas');
$statusHook = jeab_inkorg_hook(['action' => 'inbox.status']);
ik(!empty($statusHook['ok']) && array_key_exists('rebook_enabled', $statusHook) && is_bool($statusHook['rebook_enabled']), 'inbox.status läser rebook_enabled');
$n = jeab_inkorg_delete_source('test-unit');
ik($n >= 4 && count(jeab_inkorg_messages()) === 0, 'testbatch rensad');
try {
    jeab_inkorg_delete_source('bridge');
    ik(false, 'riktig källa kan inte rensas');
} catch (InvalidArgumentException $e) {
    ik(true, 'riktig källa kan inte rensas');
}

// 6b) Ärenden: trådning, status, återöppning, etiketter, vyer.
$a = jeab_inkorg_ingest($mail('t1', 'kund@telia.com', 'Service laddbox', 'Hej, laddboxen fungerar inte.'));
$b = jeab_inkorg_ingest(['message_id' => '<t2@test>', 'from' => 'kund@telia.com', 'subject' => 'Sv: Service laddbox', 'body_text' => 'Mer info', 'in_reply_to' => '<t1@test>', 'source' => 'test-unit', 'test' => true]);
ik($a['thread_id'] === $b['thread_id'], 'In-Reply-To trådar ihop');
$c2 = jeab_inkorg_ingest($mail('t3', 'kund@telia.com', 'RE: Service laddbox', 'Hallå?'));
ik($c2['thread_id'] === $a['thread_id'], 'rubrik + avsändare som reserv');
jeab_inkorg_tag_save('', 'Servicejobb');
jeab_inkorg_thread_set($a['thread_id'], 'closed', ['servicejobb']);
ik(jeab_inkorg_thread_meta($a['thread_id'])['status'] === 'closed', 'ärende stängt');
jeab_inkorg_ingest(['message_id' => '<t4@test>', 'from' => 'kund@telia.com', 'subject' => 'Sv: Service laddbox', 'body_text' => 'Den slutade igen', 'references' => '<t1@test> <t2@test>', 'source' => 'test-unit', 'test' => true]);
$meta = jeab_inkorg_thread_meta($a['thread_id']);
ik($meta['status'] === 'open' && !empty($meta['reopened_at']) && $meta['tags'] === ['servicejobb'], 'svar i stängt ärende öppnar igen, etikett kvar');
$th = array_values(array_filter(jeab_inkorg_threads(), fn($t) => $t['id'] === $a['thread_id']))[0];
ik(count($th['messages']) === 4, 'ärendet har 4 mejl');
$views = jeab_inkorg_views();
$svc = array_values(array_filter($views, fn($v) => $v['id'] === 'v-service'))[0];
ik(jeab_inkorg_thread_matches($th, $svc['filter']), 'vyn Servicejobb – öppna visar ärendet');
$nv = jeab_inkorg_view_save(['name' => 'Väntar på lev', 'status' => ['waiting_supplier']]);
ik(!jeab_inkorg_thread_matches($th, $nv['filter']), 'ny vy filtrerar på status');
jeab_inkorg_view_delete($nv['id']);
ik(!in_array($nv['id'], array_column(jeab_inkorg_views(), 'id'), true), 'vy borttagen');
$tagRule = jeab_inkorg_rule_save(['name' => 'Service', 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'servicebesök']], 'type' => 'Ärende från befintlig kund', 'action' => 'åtgärda', 'priority' => 2, 'add_tags' => ['servicejobb']]);
$r = jeab_inkorg_ingest($mail('t5', 'ny@telia.com', 'Boka servicebesök', 'Hej'));
ik(jeab_inkorg_thread_meta($r['thread_id'])['tags'] === ['servicejobb'], 'regel lägger till etikett');
jeab_inkorg_rule_delete($tagRule['id']);
jeab_inkorg_delete_source('test-unit');

// 6c) Etiketter från produktfältet (mappning i inställningarna), ägarvyer.
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Ny lead","action":"svara","priority":1,"confidence":95,"product":"elbilsladdare"}';
$r = jeab_inkorg_ingest($mail('g1', 'support@weply.se', 'Ny förfrågan', 'Kunden vill ha en laddbox.'));
ik(jeab_inkorg_thread_meta($r['thread_id'])['tags'] === ['elbilsladdare'], 'produkt elbilsladdare ger etiketten Elbilsladdare');
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Ny lead","action":"svara","priority":1,"confidence":95,"product":"solceller"}';
$ids = array_column(jeab_inkorg_views(), 'owner', 'id');
ik(($ids['v-allt'] ?? 'x') === '' && ($ids['v-solceller'] ?? '') === 'SamL' && ($ids['v-batteri-oppna'] ?? '') === 'Roberto' && ($ids['v-elbil-osorterat'] ?? '') === 'Elin', 'ägarvyer finns som data');
ik(jeab_inkorg_default_view(jeab_inkorg_views(), '') === 'v-oppna', 'standardvyn utan identitet är Öppna');
jeab_inkorg_settings_save(jeab_inkorg_settings()['intent'], ['solceller' => '', 'batteri' => 'batteri']);
$r = jeab_inkorg_ingest($mail('g2', 'support@weply.se', 'Ny förfrågan 2', 'Kunden vill ha solceller.'));
ik(!in_array('sol', jeab_inkorg_thread_meta($r['thread_id'])['tags'], true), 'utan mappning ingen Sol-etikett');
unset($GLOBALS['_JEAB_INKORG_LLM']);
jeab_inkorg_delete_source('test-unit');

// 6d) Fas 2: testregel, tilldelning, svar/anteckning, godkännande, Grok Bot-utkast, endpoints.
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Spara","action":"arkivera","priority":4,"confidence":99,"product":"","tags":["Test","Okänd"]}';
$r = jeab_inkorg_ingest($mail('j1', 'demo.person@example.invalid', 'Solceller test', 'Hej, test av solceller från weply.'));
ik($r['labels']['type'] === 'Information' && jeab_inkorg_thread_meta($r['thread_id'])['tags'] === ['test'], 'Grok sätter etiketten Test (okända etiketter ignoreras)');
unset($GLOBALS['_JEAB_INKORG_LLM']);
$k = jeab_inkorg_ingest($mail('k1', 'kund9@telia.com', 'Fråga', 'Hej, när kommer ni? Mvh'));
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => '{"type":"Fråga från befintlig kund","action":"svara","priority":2,"confidence":95}';
$k2 = jeab_inkorg_ingest($mail('k2', 'kund8@telia.com', 'Fråga 2', 'Hej, kan ni ringa? Mvh'));
unset($GLOBALS['_JEAB_INKORG_LLM']);
$batch = jeab_inkorg_hook(['action' => 'inbox.pending_batch']);
ik(in_array($k2['thread_id'], array_column($batch['items'], 'thread_id'), true), 'pending_batch listar ärende som behöver svar');
$sd = jeab_inkorg_hook(['action' => 'inbox.save_draft', 'thread_id' => $k2['thread_id'], 'body' => 'Hej! Vi ringer i morgon.']);
$sd2 = jeab_inkorg_hook(['action' => 'inbox.save_draft', 'thread_id' => $k2['thread_id'], 'body' => 'Hej! Vi ringer i morgon förmiddag.']);
$th = array_values(array_filter(jeab_inkorg_threads(), fn($t) => $t['id'] === $k2['thread_id']))[0];
ik($sd['ok'] && $sd['draft_id'] === $sd2['draft_id'] && count($th['drafts']) === 1 && $th['drafts'][0]['source'] === 'grok' && $th['drafts'][0]['state'] === 'pending', 'save_draft idempotent per ärende+mejl, väntar på godkännande');
$batch = jeab_inkorg_hook(['action' => 'inbox.pending_batch']);
ik(!in_array($k2['thread_id'], array_column($batch['items'], 'thread_id'), true), 'ärende med utkast försvinner från pending_batch');
$ap = jeab_inkorg_approve($k2['thread_id'], $sd['draft_id'], 'Test');
ik($ap['state'] === 'approved' && $ap['sent'] === false && jeab_inkorg_thread_meta($k2['thread_id'])['status'] === 'open', 'godkänd, ej skickad; status ändras inte förrän Skicka');
ik(!jeab_inkorg_can_approve(), 'ingen inloggad kan inte godkänna');
$note = jeab_inkorg_add_draft($k['thread_id'], ['kind' => 'note', 'body' => 'Intern notis', 'status_after' => 'closed'], 'Test');
ik($note['state'] === 'note' && jeab_inkorg_thread_meta($k['thread_id'])['status'] !== 'closed', 'anteckning sparas direkt och ändrar inte status');
$rp = jeab_inkorg_add_draft($k['thread_id'], ['kind' => 'reply', 'body' => 'Svar', 'cc' => 'a@b.se'], 'Test');
ik($rp['state'] === 'pending' && $rp['cc'] === ['a@b.se'], 'svar med CC blir utkast');
jeab_inkorg_assign($k['thread_id'], 'Elin');
$t2 = array_values(array_filter(jeab_inkorg_threads(), fn($t) => $t['id'] === $k['thread_id']))[0];
ik(jeab_inkorg_thread_matches($t2, ['assignee' => 'Elin']) && !jeab_inkorg_thread_matches($t2, ['assignee' => 'none']), 'tilldelning filtreras i vy');
$ar = jeab_inkorg_rule_save(['name' => 'Tilldela', 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'garantiärende']], 'type' => 'Ärende från befintlig kund', 'action' => 'åtgärda', 'priority' => 2, 'assign' => 'Roberto']);
$r = jeab_inkorg_ingest($mail('as1', 'kund7@telia.com', 'Garantiärende', 'Hej'));
ik((jeab_inkorg_thread_meta($r['thread_id'])['assignee'] ?? '') === 'Roberto', 'regel tilldelar');
jeab_inkorg_rule_delete($ar['id']);
ik(in_array('v-mig', array_column(jeab_inkorg_views(), 'id'), true) && in_array('v-otilldelade', array_column(jeab_inkorg_views(), 'id'), true), 'vyerna Tilldelade mig/Otilldelade finns');
jeab_inkorg_delete_source('test-unit');

// 7) Help Scout-import med fejkat GET (inget nätverk).
$GLOBALS['_JEAB_INKORG_HS_GET'] = function (string $path, array $q) {
    if ($q['status'] === 'spam') {
        return ['_embedded' => ['conversations' => []]];
    }
    return ['_embedded' => ['conversations' => [[
        'id' => 100 + (int) $q['mailbox'] % 7, 'type' => 'email', 'subject' => 'Offert', 'createdAt' => '2026-10-01T10:00:00Z',
        '_embedded' => ['threads' => [['type' => 'customer', 'customer' => ['email' => 'k@telia.com'], 'body' => '<p>Hej, jag vill ha offert</p>']]],
    ]]]];
};
$res = jeab_inkorg_import_helpscout(50);
ik($res['imported'] >= 1 && $res['stats']['total'] >= 1, 'Help Scout-import (bara GET) märkt helpscout-test');
ik(jeab_inkorg_delete_source('helpscout-test') >= 1, 'Help Scout-batch rensas i ett svep');
ik(!is_dir(dirname(__DIR__) . '/data/.private/inkorg') || $GLOBALS['_JEAB_DATA_DIR'] === $tmp, 'skriver bara i testkatalogen');
echo "inkorg_test klart\n";
