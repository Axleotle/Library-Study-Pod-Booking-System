<?php
// Single JSON endpoint: POST {op, t, ...}. Ops: init, login, logout, signup, all, insert, update, kv, reset.
session_start();
header('Content-Type: application/json');
require __DIR__ . '/config.php';

$COLS = [
  'students'  => ['sid','name','phone','pw','status'],
  'staff'     => ['sid','email','pw','role'],
  'sessions'  => ['sid','name','pod','mins','status','reqAt','start','end','actualEnd','idHeld','override','endedBy'],
  'incidents' => ['sid','pod','text','at','by'],
  'overrides' => ['sid','name','reason','note','by','at','pod'],
];
$q = fn($c) => '`' . $c . '`';
function out($d) { echo json_encode(['data' => $d]); exit; }
function fail($m, $c = 400) { http_response_code($c); echo json_encode(['error' => $m]); exit; }
function clean($r) { unset($r['pw'], $r['slot'], $r['holder']); return $r; }
function hashpw($r) {
  if (isset($r['pw'])) $r['pw'] = password_hash($r['pw'], PASSWORD_DEFAULT);
  foreach ($r as $k => $v) if (is_bool($v)) $r[$k] = (int)$v;   // MySQL strict mode rejects '' for TINYINT
  return $r;
}
function ins($db, $t, $row) {
  $c = array_keys($row);
  $db->prepare("INSERT INTO `$t`(" . implode(',', array_map(fn($x) => "`$x`", $c)) . ') VALUES(' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row));
  return $db->query("SELECT * FROM `$t` WHERE id=" . (int)$db->lastInsertId())->fetch();
}
function nowms($db) { return (int)(microtime(true) * 1000) + (int)$db->query("SELECT v FROM kv WHERE k='offset'")->fetchColumn(); }
function cfg($db) { return json_decode($db->query("SELECT v FROM kv WHERE k='cfg'")->fetchColumn(), true); }
function hms($ms) { $s = max(0, (int)round($ms / 1000)); return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); }
// Booking rules (FR-002): active student, session length, cooldown, terminal hours
function eligible($db, $sid, $mins, $now) {
  $c = cfg($db);
  $st = $db->prepare('SELECT status FROM students WHERE sid=?'); $st->execute([$sid]); $s = $st->fetchColumn();
  if ($s !== 'active') fail('Your registration is ' . ($s ?: 'missing') . '. Please see the librarian.', 403);
  if ($mins < 15 || $mins > $c['maxHrs'] * 60) fail('Session length can be up to ' . $c['maxHrs'] . ' hours.');
  $l = $db->prepare("SELECT MAX(actualEnd) FROM sessions WHERE sid=? AND status='completed'"); $l->execute([$sid]); $e = $l->fetchColumn();
  if ($e && $e + $c['cooldown'] * 60000 > $now) fail('Cooldown active. You can book again in ' . hms($e + $c['cooldown'] * 60000 - $now) . '.', 409);
  if ($c['open'] && $c['close']) { $n = date('H:i', intdiv($now, 1000)); if ($n < $c['open'] || $n >= $c['close']) fail('Terminal hours are ' . $c['open'] . ' to ' . $c['close'] . '.', 409); }
}

function seed($db) {
  $db->exec('SET FOREIGN_KEY_CHECKS=0');
  foreach (['students','staff','sessions','incidents','overrides','kv'] as $x) $db->exec("TRUNCATE TABLE `$x`");
  $db->exec('SET FOREIGN_KEY_CHECKS=1');
  $s = $db->prepare("INSERT INTO students(sid,name,phone,pw,status) VALUES(?,?,?,?,'active')");
  foreach ([['2023-00123','Cruz, Juan','09170000001'],['2023-00456','Reyes, Ana','09170000002']] as $r)
    $s->execute([...$r, password_hash('pass123', PASSWORD_DEFAULT)]);
  $s = $db->prepare('INSERT INTO staff(sid,email,pw,role) VALUES(?,?,?,?)');
  $s->execute(['L-001','librarian1@school.edu.ph',password_hash('lib123',PASSWORD_DEFAULT),'librarian']);
  $s->execute(['A-001','admin18@school.edu.ph',password_hash('admin123',PASSWORD_DEFAULT),'admin']);
  $k = $db->prepare('INSERT INTO kv(k,v) VALUES(?,?)');
  $k->execute(['cfg', json_encode(['pods'=>16,'maxHrs'=>3,'cooldown'=>60,'grace'=>10,'open'=>'','close'=>''])]);
  $k->execute(['offset', '0']);
}

try {
  $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $op = $in['op'] ?? '';
  $t  = $in['t'] ?? '';
  if ($t !== '' && !isset($COLS[$t])) fail('Unknown table');

  // ---- access control (server-side) ----
  $u = $_SESSION['u'] ?? null;
  $role = $u ? ($u['role'] ?? 'student') : null;
  $staff = in_array($role, ['librarian','admin'], true);
  $label = $role === 'admin' ? 'Admin' : ($role === 'librarian' ? 'Librarian' : 'Student');
  if (!in_array($op, ['init','login','signup','logout'], true) && !$u) fail('Please log in.', 401);
  $key = $op . ':' . $t;
  if (in_array($key, ['update:students','insert:overrides','insert:incidents','reset:','update:staff'], true) && !$staff) fail('Staff only.', 403);
  if (in_array($key, ['update:staff','reset:'], true) && $role !== 'admin') fail('Admin only.', 403);
  if ($op === 'kv' && array_key_exists('v', $in) && ($in['k'] ?? '') !== 'offset' && $role !== 'admin') fail('Admin only.', 403);
  if (in_array($key, ['all:students','all:incidents','all:overrides'], true) && !$staff) fail('Staff only.', 403);
  if ($key === 'all:staff' && $role !== 'admin') fail('Admin only.', 403);

  switch ($op) {
    case 'init':
      if (!$db->query('SELECT COUNT(*) FROM staff')->fetchColumn()) seed($db);
      out(true);

    case 'reset':
      seed($db); session_destroy(); out(true);

    case 'logout':
      session_destroy(); out(true);

    case 'login':
      $tb = ($in['role'] ?? '') === 'staff' ? 'staff' : 'students';
      $s = $db->prepare("SELECT * FROM `$tb` WHERE sid=?");
      $s->execute([trim($in['id'] ?? '')]);
      $r = $s->fetch();
      if (!$r || !password_verify($in['pw'] ?? '', $r['pw'])) fail('Incorrect ID number or password.', 401);
      if (($r['status'] ?? '') === 'deactivated') fail('This registration is deactivated. Please see the librarian.', 403);
      if (($r['status'] ?? '') === 'suspended') fail('This registration is suspended. Please see the librarian.', 403);
      session_regenerate_id(true);
      $_SESSION['u'] = clean($r);
      out(clean($r));

    case 'signup':
      $kind = ($in['role'] ?? '') === 'staff' ? 'staff' : 'students';
      if ($kind === 'staff' && ($u['role'] ?? '') !== 'admin') fail('Staff accounts must be created by an admin.', 403);
      $name = trim($in['name'] ?? ''); $sid = trim($in['sid'] ?? ''); $phone = trim($in['phone'] ?? ''); $pw = $in['pw'] ?? '';
      if (!$name || !$sid || !$phone || !$pw) fail('Please fill in all fields.');
      if ($kind === 'students' && !preg_match('/^\d{4}-\d{5}$/', $sid)) fail('Student ID must look like 2023-00123.');
      if (!preg_match('/^\d{10,11}$/', preg_replace('/\D/', '', $phone))) fail('Enter a valid phone number.');
      if (strlen($pw) < 6) fail('Password must be at least 6 characters.');
      $d = $db->prepare('SELECT (SELECT COUNT(*) FROM students WHERE sid=?) + (SELECT COUNT(*) FROM staff WHERE sid=?)');
      $d->execute([$sid, $sid]);
      if ($d->fetchColumn()) fail('This ID number is already registered.');
      $h = password_hash($pw, PASSWORD_DEFAULT);
      if ($kind === 'staff') $db->prepare("INSERT INTO staff(sid,email,pw,role) VALUES(?,?,?,'librarian')")->execute([$sid, '', $h]);
      else $db->prepare("INSERT INTO students(sid,name,phone,pw,status) VALUES(?,?,?,?,'active')")->execute([$sid, $name, $phone, $h]);
      out(true);

    case 'student': // one student's record (a student may only read their own)
      $sid = $in['sid'] ?? '';
      if (!$staff && $sid !== $u['sid']) fail('Not allowed.', 403);
      $s = $db->prepare('SELECT * FROM students WHERE sid=?'); $s->execute([$sid]); $r = $s->fetch();
      out($r ? clean($r) : null);

    case 'all':
      $rows = $db->query("SELECT * FROM `$t` ORDER BY id")->fetchAll();
      // students only see their own sessions in full; other people's sessions are reduced to pod + status
      if ($t === 'sessions' && !$staff)
        $rows = array_map(fn($r) => $r['sid'] === $u['sid'] ? $r : ['id'=>$r['id'],'pod'=>$r['pod'],'status'=>$r['status'],'sid'=>'','name'=>''], $rows);
      out(array_map('clean', $rows));

    case 'insert':
      $row = hashpw(array_intersect_key($in['row'] ?? [], array_flip($COLS[$t])));
      if ($t !== 'sessions') out(clean(ins($db, $t, $row)));
      $now = nowms($db);
      if (!$staff) { $row['status'] = 'pending'; if (($row['sid'] ?? '') !== $u['sid']) fail('Not your session.', 403); }
      $row['reqAt'] = $now;
      if (($row['pod'] ?? 0) < 1 || $row['pod'] > cfg($db)['pods']) fail('Invalid pod number.');
      $db->beginTransaction();   // lock the student row, validate, insert - all or nothing
      $l = $db->prepare('SELECT id FROM students WHERE sid=? FOR UPDATE'); $l->execute([$row['sid'] ?? '']); $l->fetchAll();
      eligible($db, $row['sid'], (int)$row['mins'], $now);
      $n = $db->prepare('SELECT name FROM students WHERE sid=?'); $n->execute([$row['sid']]); $row['name'] = $n->fetchColumn();
      if (($row['status'] ?? '') === 'active') { $row['start'] = $now; $row['end'] = $now + $row['mins'] * 60000; }
      try { $r = ins($db, $t, $row); $db->commit(); }
      catch (PDOException $e) {
        $db->rollBack();
        if ($e->getCode() === '23000') fail('Study pod is currently occupied, or the student already has a session.', 409);
        throw $e;
      }
      out(clean($r));

    case 'update':
      $p = hashpw(array_intersect_key($in['patch'] ?? [], array_flip($COLS[$t])));
      if ($t === 'sessions') {
        $c = $db->prepare('SELECT * FROM sessions WHERE id=?'); $c->execute([(int)$in['id']]); $cur = $c->fetch();
        if (!$cur) fail('Session not found.', 404);
        if (!$staff) {
          if ($cur['sid'] !== $u['sid']) fail('Not your session.', 403);
          $p = array_intersect_key($p, ['status' => 1]);
          if (isset($p['status']) && !in_array($p['status'], ['cancelled','completed'], true)) fail('Only staff can approve sessions.', 403);
        }
        if (isset($p['status']) && in_array($cur['status'], ['completed','cancelled'], true)) fail('Session already closed.', 409);
        $now = nowms($db);   // all timestamps come from the server clock
        if (($p['status'] ?? '') === 'active') {
          if ($cur['status'] !== 'pending') fail('Only pending requests can be approved.', 409);
          $p['start'] = $now; $p['end'] = $now + $cur['mins'] * 60000;
        }
        if (in_array($p['status'] ?? '', ['completed','cancelled'], true)) {
          $p['actualEnd'] = $now;
          if ($p['status'] === 'completed') {
            $p['idHeld'] = 0; $p['endedBy'] = $label;
            if ($cur['status'] === 'active' && $now > $cur['end'] + cfg($db)['grace'] * 60000)
              ins($db, 'incidents', ['sid'=>$cur['sid'],'pod'=>$cur['pod'],'text'=>'ID not retrieved within grace period','at'=>$now,'by'=>$label]);
          }
        }
      }
      if (!$p) out(true);
      $set = implode(',', array_map(fn($c) => $q($c) . '=?', array_keys($p)));
      $db->prepare("UPDATE `$t` SET $set WHERE id=?")->execute([...array_values($p), (int)$in['id']]);
      out(true);

    case 'kv':
      if (array_key_exists('v', $in)) {
        $db->prepare('REPLACE INTO kv(k,v) VALUES(?,?)')->execute([$in['k'], json_encode($in['v'])]);
        out(true);
      }
      $s = $db->prepare('SELECT v FROM kv WHERE k=?'); $s->execute([$in['k']]);
      $v = $s->fetchColumn();
      out($v === false ? null : json_decode($v, true));

    default:
      fail('Unknown operation');
  }
} catch (Throwable $e) {
  fail($e instanceof PDOException ? 'Database error: ' . $e->getMessage() : $e->getMessage(), 500);
}
