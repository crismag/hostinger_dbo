<?php

declare(strict_types=1);

/**
 * Web installer for the DBO REST gateway (manual FTP / git-clone flow).
 *
 * SECURITY: this page can write config and create database tables. It only
 * operates while the gateway is NOT yet configured, verifies a CSRF token, and
 * writes an install lock on completion. Serve it over HTTPS and DELETE this file
 * once installation finishes.
 */

use App\Install\Installer;

$root = dirname(__DIR__);
require $root . '/src/Install/Installer.php';

$installer = new Installer($root);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden the installer session cookie.
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    ]);
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf'];

/** @var list<string> $errors */
$errors = [];
$notices = [];
$result = null;
$step = 'connect';

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function post(string $k, string $d = ''): string
{
    return isset($_POST[$k]) ? trim((string) $_POST[$k]) : $d;
}
function checked(string $k): bool
{
    return isset($_POST[$k]) && $_POST[$k] !== '';
}

$alreadyInstalled = $installer->isInstalled();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost && !$alreadyInstalled) {
    if (!hash_equals($csrf, post('csrf'))) {
        $errors[] = 'Security token mismatch. Reload the page and try again.';
    } else {
        $action = post('action');

        if ($action === 'connect') {
            $db = [
                'host' => post('db_host', 'localhost'),
                'port' => post('db_port', '3306'),
                'database' => post('db_database', 'dbo_gateway'),
                'username' => post('db_username', 'root'),
                'password' => post('db_password'),
                'charset' => 'utf8mb4',
            ];
            $createDb = checked('create_database');
            $withExamples = checked('with_examples');
            $test = $installer->testDatabase($db, $createDb);
            if (!$test['ok']) {
                $errors[] = 'Database connection failed: ' . $test['error'];
            } else {
                try {
                    $pdo = $installer->connect($db, false);
                    foreach ($installer->loadSchema($pdo, $withExamples) as $r) {
                        $notices[] = "{$r['file']}: {$r['note']}";
                    }
                    $_SESSION['install_db'] = $db;
                    $_SESSION['install_entities'] = $installer->registeredEntities($pdo);
                    $step = 'configure';
                } catch (Throwable $ex) {
                    $errors[] = 'Schema load failed: ' . $ex->getMessage();
                }
            }
            if ($errors !== []) {
                $step = 'connect';
            }
        } elseif ($action === 'configure') {
            $db = $_SESSION['install_db'] ?? null;
            if (!is_array($db)) {
                $errors[] = 'Session expired. Restart from the connection step.';
                $step = 'connect';
            } else {
                try {
                    $pdo = $installer->connect($db, false);
                    $registered = $installer->registeredEntities($pdo);
                    $entities = array_values(array_intersect($registered, $_POST['entities'] ?? []));
                    $actions = array_values(array_intersect(Installer::ACTIONS, $_POST['actions'] ?? []));
                    $clientId = post('client_id', 'primary-client');

                    $client = $installer->createClient(
                        $pdo,
                        $clientId,
                        post('client_name', 'Primary service'),
                        $entities,
                        $actions,
                    );

                    $proxies = array_values(array_filter(
                        array_map('trim', explode(',', post('trusted_proxies'))),
                        static fn (string $p): bool => $p !== ''
                    ));

                    $installer->ensureStorage();
                    $installer->writeDatabaseConfig($db);
                    $installer->writeSecurityConfig(
                        [
                            'require_https' => checked('require_https'),
                            'trusted_proxies' => $proxies,
                        ],
                        [$client['client_id'] => $client['secret']],
                        [$client['client_id'] => ['enforced_filters' => [], 'allow_bulk_updates' => false]],
                    );
                    $perms = $installer->hardenPermissions();
                    $installer->lock("client={$client['client_id']} via web installer");

                    unset($_SESSION['install_db'], $_SESSION['install_entities']);
                    $result = ['client' => $client, 'perms' => $perms, 'entities' => $entities, 'actions' => $actions];
                    $step = 'done';
                } catch (Throwable $ex) {
                    $errors[] = 'Install failed: ' . $ex->getMessage();
                    $step = 'configure';
                }
            }
        }
    }
} elseif (!$alreadyInstalled && isset($_SESSION['install_db'])) {
    // Returning to a half-finished install.
    $step = 'configure';
}

$preflight = $installer->preflight();
$preflightOk = $installer->preflightPasses();
$registeredEntities = $_SESSION['install_entities'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DBO Gateway — Installer</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 system-ui, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
  .wrap { max-width: 720px; margin: 0 auto; padding: 32px 20px 64px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  h2 { font-size: 16px; margin: 28px 0 10px; color: #93c5fd; }
  .sub { color: #94a3b8; margin: 0 0 24px; }
  .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 20px; }
  label { display: block; margin: 12px 0 4px; font-weight: 600; font-size: 13px; }
  input[type=text], input[type=password], input[type=number] {
    width: 100%; padding: 9px 10px; border-radius: 7px; border: 1px solid #475569;
    background: #0f172a; color: #e2e8f0; font: inherit;
  }
  .row { display: flex; gap: 14px; }
  .row > div { flex: 1; }
  .check { display: flex; align-items: center; gap: 8px; margin: 10px 0; font-weight: 500; }
  .check input { width: auto; }
  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px 18px; margin-top: 6px; }
  button { margin-top: 22px; background: #2563eb; color: #fff; border: 0; padding: 11px 18px;
    border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
  button:hover { background: #1d4ed8; }
  .msg { padding: 11px 14px; border-radius: 8px; margin: 8px 0; font-size: 14px; }
  .err { background: #450a0a; border: 1px solid #b91c1c; color: #fecaca; }
  .ok { background: #052e16; border: 1px solid #15803d; color: #bbf7d0; }
  .warn { background: #422006; border: 1px solid #b45309; color: #fed7aa; }
  .pf { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #33415544; font-size: 13px; }
  .pf .v { color: #94a3b8; }
  .pill { font-size: 11px; padding: 1px 7px; border-radius: 999px; font-weight: 700; }
  .pill.ok { background: #15803d; color: #fff; }
  .pill.no { background: #b91c1c; color: #fff; }
  .pill.warnp { background: #b45309; color: #fff; }
  code { background: #0f172a; padding: 2px 6px; border-radius: 5px; border: 1px solid #334155; }
  .secret { font-family: ui-monospace, monospace; font-size: 15px; word-break: break-all;
    background: #0f172a; border: 1px solid #2563eb; border-radius: 8px; padding: 14px; margin: 8px 0; }
  ol { padding-left: 18px; } ol li { margin: 6px 0; }
  .steps { display: flex; gap: 8px; font-size: 12px; color: #64748b; margin-bottom: 18px; }
  .steps .on { color: #93c5fd; font-weight: 700; }
</style>
</head>
<body>
<div class="wrap">
  <h1>DBO REST Gateway — Installer</h1>
  <p class="sub">Configure the database connection, load the schema, and create your first API client.</p>

<?php if ($alreadyInstalled): ?>
  <div class="card">
    <div class="msg ok">This gateway is already installed.</div>
    <p>An install lock or <code>config/security.php</code> already exists, so the installer is disabled.</p>
    <div class="msg warn"><strong>Delete this file now:</strong> <code>public/install.php</code> — it should not remain on a live server.</div>
    <p>To re-run intentionally, remove <code>storage/.install-lock</code> (and the config files) on the server, then reload.</p>
  </div>
</div></body></html>
<?php return; endif; ?>

  <div class="steps">
    <span class="<?= $step === 'connect' ? 'on' : '' ?>">1 · Database & schema</span> ›
    <span class="<?= $step === 'configure' ? 'on' : '' ?>">2 · Client & security</span> ›
    <span class="<?= $step === 'done' ? 'on' : '' ?>">3 · Done</span>
  </div>

<?php foreach ($errors as $msg): ?>
  <div class="msg err"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $msg): ?>
  <div class="msg ok"><?= e($msg) ?></div>
<?php endforeach; ?>

<?php if ($step === 'connect'): ?>
  <div class="card">
    <h2 style="margin-top:0">Environment</h2>
    <?php foreach ($preflight as $c): ?>
      <div class="pf">
        <span><?= e($c['name']) ?> <span class="v"><?= e($c['detail']) ?></span></span>
        <span class="pill <?= $c['ok'] ? 'ok' : ($c['fatal'] ? 'no' : 'warnp') ?>"><?= $c['ok'] ? 'ok' : ($c['fatal'] ? 'fail' : 'warn') ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$preflightOk): ?>
      <div class="msg err">Resolve the failing checks above before continuing.</div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="connect">
      <h2>Database connection</h2>
      <div class="row">
        <div><label>Host</label><input type="text" name="db_host" value="<?= e(post('db_host', 'localhost')) ?>"></div>
        <div><label>Port</label><input type="text" name="db_port" value="<?= e(post('db_port', '3306')) ?>"></div>
      </div>
      <label>Database name</label>
      <input type="text" name="db_database" value="<?= e(post('db_database', 'dbo_gateway')) ?>">
      <div class="row">
        <div><label>Username</label><input type="text" name="db_username" value="<?= e(post('db_username', 'root')) ?>"></div>
        <div><label>Password</label><input type="password" name="db_password" value="<?= e(post('db_password')) ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="create_database" <?= checked('create_database') ? 'checked' : '' ?>> Create the database if it does not exist</label>
      <label class="check"><input type="checkbox" name="with_examples" checked> Load example object tables (projects, users)</label>
      <button type="submit" <?= $preflightOk ? '' : 'disabled' ?>>Connect &amp; load schema →</button>
    </form>
  </div>

<?php elseif ($step === 'configure'): ?>
  <div class="card">
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="configure">
      <h2 style="margin-top:0">First API client</h2>
      <div class="row">
        <div><label>Client id</label><input type="text" name="client_id" value="primary-client"></div>
        <div><label>Client name</label><input type="text" name="client_name" value="Primary service"></div>
      </div>

      <label>Entities this client may access</label>
      <?php if ($registeredEntities === []): ?>
        <div class="msg warn">No entities are registered. Load the example tables or register entities in <code>api_entities</code>.</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($registeredEntities as $ent): ?>
            <label class="check"><input type="checkbox" name="entities[]" value="<?= e($ent) ?>" checked> <?= e($ent) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <label>Allowed actions</label>
      <div class="grid">
        <?php foreach (Installer::ACTIONS as $act): ?>
          <label class="check"><input type="checkbox" name="actions[]" value="<?= e($act) ?>" <?= $act === 'select' ? 'checked' : '' ?>> <?= e($act) ?></label>
        <?php endforeach; ?>
      </div>

      <h2>Security</h2>
      <label class="check"><input type="checkbox" name="require_https" checked> Enforce HTTPS (reject plaintext HTTP)</label>
      <label>Trusted proxies (comma-separated IPs/CIDRs — leave blank if directly exposed)</label>
      <input type="text" name="trusted_proxies" value="" placeholder="e.g. 10.0.0.1, 2001:db8::/32">

      <button type="submit">Complete installation →</button>
    </form>
  </div>

<?php elseif ($step === 'done' && $result !== null): ?>
  <div class="card">
    <div class="msg ok">Installation complete.</div>
    <h2 style="margin-top:8px">Your HMAC secret — shown once</h2>
    <p>Store this now. It is written into <code>config/security.php</code> on the server but will not be displayed again.</p>
    <div><strong>client id:</strong> <code><?= e($result['client']['client_id']) ?></code></div>
    <div class="secret"><?= e($result['client']['secret']) ?></div>

    <h2>Permissions applied</h2>
    <?php foreach ($result['perms'] as $p): ?>
      <div class="pf"><span class="v"><?= e($p['path']) ?></span>
        <span class="pill <?= $p['ok'] ? 'ok' : 'warnp' ?>"><?= e($p['mode']) ?></span></div>
    <?php endforeach; ?>

    <div class="msg warn" style="margin-top:18px">
      <strong>Final step — delete this installer:</strong> remove <code>public/install.php</code> from the server now.
    </div>
    <h2>Next</h2>
    <ol>
      <li>Confirm the web server document root points at <code>public/</code> only.</li>
      <li>Serve over HTTPS.</li>
      <li>Smoke test from a shell: <code>php tests/hardening_smoke.php</code></li>
    </ol>
  </div>
<?php endif; ?>
</div>
</body>
</html>
