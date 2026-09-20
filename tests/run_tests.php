<?php

declare(strict_types=1);

/**
 * Dependency-free test harness for the plain-PHP taskboard. Exercises the
 * real PDO/SQLite stack and the `php_tb_api_dispatch` router directly (no
 * HTTP needed), covering CRUD, search/filter, LIKE escaping, validation
 * negatives, CSRF enforcement, schema/seed idempotency, restart persistence
 * and readiness. Exit code 0 only when every check passes.
 */

error_reporting(E_ALL);

$root = dirname(__DIR__);
require $root . '/src/config.php';
require $root . '/src/session.php';
require $root . '/src/db.php';
require $root . '/src/api.php';

$pass = 0;
$fail = 0;

/** @param mixed $cond */
function check(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "ok   $name\n";
    } else {
        $fail++;
        echo "FAIL $name\n";
    }
}

function fail(string $message): never
{
    echo "FAIL $message\n";
    exit(1);
}

$work = sys_get_temp_dir() . '/php-taskboard-tests-' . bin2hex(random_bytes(6));
if (!mkdir($work, 0777, true)) {
    fail('cannot create temp work dir');
}
// A regular file at $work/blocked.txt makes any directory creation (and thus
// any database open) below it fail — used for the outage probes below.
touch($work . '/blocked.txt');
$dbPath = $work . '/taskboard.db';

function makeCfg(string $dbPath): PhpTaskboard_Cfg
{
    return PhpTaskboard_Cfg::fromEnv([
        'DATA_DIR' => dirname($dbPath),
        'DATABASE_PATH' => $dbPath,
        'PORT' => '8080',
        'BIND_HOST' => '127.0.0.1',
        'BUILD_MARKER' => 'test-marker',
    ]);
}

$cfg = makeCfg($dbPath);
$sessionToken = 'test-session-token';

function dispatch(string $method, array $segments, array $query, ?array $body, string $csrf, string $dbPath, PhpTaskboard_Cfg $cfg): array
{
    global $sessionToken;
    return php_tb_api_dispatch($method, $segments, $query, $body, false, $sessionToken, $csrf, $dbPath, $cfg);
}

// ---------------------------------------------------------------- schema/seed
$pdo = php_tb_open($dbPath);
// Opening a brand-new database applies schema + seed data once.
$initialProjects = (int)(new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM project')->fetchColumn();
$initialTasks = (int)(new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM task')->fetchColumn();
check($initialProjects === count(PHP_TB_SEED_PROJECTS), 'first open creates the expected projects');
check($initialTasks === count(PHP_TB_SEED_TASKS), 'first open creates the expected tasks');

$seed2 = php_tb_seed($pdo);
check($seed2['seeded'] === false, 'second seed run is a no-op (idempotent)');
check($seed2['projects'] === count(PHP_TB_SEED_PROJECTS), 'seed idempotent: project count stable');
check($seed2['tasks'] === count(PHP_TB_SEED_TASKS), 'seed idempotent: task count stable');

php_tb_open($dbPath); // reopening the same path again must not duplicate rows
$counts = php_tb_seed(php_tb_open($dbPath));
check($counts['projects'] === count(PHP_TB_SEED_PROJECTS), 'schema/seed idempotent across fresh connections');

// ---------------------------------------------------------------- health/meta
$live = dispatch('GET', ['api', 'health', 'live'], [], null, '', $dbPath, $cfg);
check($live['status'] === 200, 'liveness returns 200');
check(($live['body']['status'] ?? '') === 'alive', 'liveness body reports alive');

$ready = dispatch('GET', ['api', 'health', 'ready'], [], null, '', $dbPath, $cfg);
check($ready['status'] === 200, 'readiness returns 200 when the database is available');
check(($ready['body']['status'] ?? '') === 'ready', 'readiness body reports ready');

$probe = php_tb_probe($dbPath);
check($probe['ok'] === true, 'probe succeeds on a usable database path');
$badProbe = php_tb_probe($work . '/blocked.txt' . '/db.sqlite');
check($badProbe['ok'] === false, 'probe fails when the database cannot be opened');

$meta = dispatch('GET', ['api', 'meta'], [], null, '', $dbPath, $cfg);
check($meta['status'] === 200, 'meta returns 200');
check(($meta['body']['release'] ?? '') === 'test-marker', 'meta exposes the release marker');
check(($meta['body']['runtime']['name'] ?? '') === 'php', 'meta reports php runtime');
check(($meta['body']['csrf'] ?? '') === $sessionToken, 'meta exposes the session CSRF token');

// ---------------------------------------------------------------- CSRF guard
$noCsrf = dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => 'x'], '', $dbPath, $cfg);
check($noCsrf['status'] === 403, 'mutation without a CSRF token is rejected (403)');
$badCsrf = dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => 'x'], 'wrong-token', $dbPath, $cfg);
check($badCsrf['status'] === 403, 'mutation with a mismatched CSRF token is rejected (403)');

// CSRF guard must not block reads.
$okRead = dispatch('GET', ['api', 'projects'], [], null, '', $dbPath, $cfg);
check($okRead['status'] === 200, 'read routes pass without a CSRF token');

// ---------------------------------------------------------------- projects CRUD
$createProject = dispatch('POST', ['api', 'projects'], [], ['name' => 'Test Project', 'description' => 'desc', 'status' => 'active'], $sessionToken, $dbPath, $cfg);
check($createProject['status'] === 201, 'create project returns 201');
$project = $createProject['body']['project'] ?? null;
check(is_array($project) && !empty($project['id']), 'created project has an id');
$pid = (int)($project['id'] ?? 0);

$projectDetail = dispatch('GET', ['api', 'projects', (string)$pid], [], null, '', $dbPath, $cfg);
check($projectDetail['status'] === 200 && ($projectDetail['body']['project']['name'] ?? '') === 'Test Project', 'read project returns the created project');

$updateProject = dispatch('PATCH', ['api', 'projects', (string)$pid], [], ['name' => 'Test Project (renamed)'], $sessionToken, $dbPath, $cfg);
check($updateProject['status'] === 200, 'update project returns 200');
check(($updateProject['body']['project']['name'] ?? '') === 'Test Project (renamed)', 'project update is reflected');

$projectsList = dispatch('GET', ['api', 'projects'], [], null, '', $dbPath, $cfg);
$names = array_column($projectsList['body']['projects'] ?? [], 'name');
check(in_array('Test Project (renamed)', $names, true), 'project list contains the updated project');
check(in_array('Launch checklist', $names, true), 'seeded project still present in the list');

// ---------------------------------------------------------------- tasks CRUD
$createTask = dispatch('POST', ['api', 'tasks'], [], ['project_id' => $pid, 'title' => 'First task', 'description' => 'about it', 'status' => 'in_progress', 'priority' => 'high'], $sessionToken, $dbPath, $cfg);
check($createTask['status'] === 201, 'create task returns 201');
$task = $createTask['body']['task'] ?? null;
check(is_array($task) && !empty($task['id']), 'created task has an id');
$tid = (int)($task['id'] ?? 0);

dispatch('POST', ['api', 'tasks'], [], ['project_id' => $pid, 'title' => 'Second task', 'status' => 'done', 'priority' => 'low'], $sessionToken, $dbPath, $cfg);

$taskDetail = dispatch('GET', ['api', 'tasks', (string)$tid], [], null, '', $dbPath, $cfg);
check(($taskDetail['body']['task']['title'] ?? '') === 'First task', 'read task returns the created task');
check(($taskDetail['body']['task']['project_name'] ?? '') === 'Test Project (renamed)', 'task join exposes the project name');

$updateTask = dispatch('PATCH', ['api', 'tasks', (string)$tid], [], ['title' => 'First task (updated)', 'status' => 'done'], $sessionToken, $dbPath, $cfg);
check($updateTask['status'] === 200, 'update task returns 200');
check(($updateTask['body']['task']['title'] ?? '') === 'First task (updated)', 'task update is reflected');

$move = dispatch('PATCH', ['api', 'tasks', (string)$tid], [], ['project_id' => 2], $sessionToken, $dbPath, $cfg);
check($move['status'] === 200 && ($move['body']['task']['project_id'] ?? 0) === 2, 'task can be moved to another project');

$deleteTask = dispatch('DELETE', ['api', 'tasks', (string)$tid], [], null, $sessionToken, $dbPath, $cfg);
check($deleteTask['status'] === 204, 'delete task returns 204');
$gone = dispatch('GET', ['api', 'tasks', (string)$tid], [], null, '', $dbPath, $cfg);
check($gone['status'] === 404, 'deleted task is gone (404)');

// ---------------------------------------------------------------- search/filter
dispatch('POST', ['api', 'tasks'], [], ['project_id' => $pid, 'title' => 'Alpha widget error', 'status' => 'todo', 'priority' => 'low'], $sessionToken, $dbPath, $cfg);
dispatch('POST', ['api', 'tasks'], [], ['project_id' => $pid, 'title' => 'Beta widget', 'status' => 'in_progress', 'priority' => 'high'], $sessionToken, $dbPath, $cfg);

$search = dispatch('GET', ['api', 'tasks'], ['q' => 'widget error'], null, '', $dbPath, $cfg);
$titles = array_column($search['body']['tasks'] ?? [], 'title');
check(in_array('Alpha widget error', $titles, true), 'search finds the matching task');
check(!in_array('Beta widget', $titles, true), 'search excludes non-matching tasks');
check(($search['body']['count'] ?? 0) === 1, 'search count reflects only matches');

$statusFilter = dispatch('GET', ['api', 'tasks'], ['status' => 'done', 'project_id' => '2'], null, '', $dbPath, $cfg);
$statuses = array_unique(array_column($statusFilter['body']['tasks'] ?? [], 'status'));
check($statuses === ['done'] || $statuses === [], 'status filter narrows results to the requested status');

$projectFilter = dispatch('GET', ['api', 'tasks'], ['project_id' => (string)$pid], null, '', $dbPath, $cfg);
$pids = array_values(array_column($projectFilter['body']['tasks'] ?? [], 'project_id'));
$allMatch = $pids === [] ? true : (array_filter($pids, fn($v) => $v === $pid) === $pids);
check($allMatch, 'project filter narrows results to the requested project');

$invalidFilter = dispatch('GET', ['api', 'tasks'], ['status' => 'warp'], null, '', $dbPath, $cfg);
check($invalidFilter['status'] === 400, 'invalid status filter is rejected (400)');

// LIKE wildcards in the query must be escaped, not matched literally.
$wild = dispatch('GET', ['api', 'tasks'], ['q' => '%'], null, '', $dbPath, $cfg);
check(($wild['body']['count'] ?? -1) === 0, 'search for "%" matches nothing while no record contains "%" (LIKE wildcards escaped)');

dispatch('POST', ['api', 'tasks'], [], ['project_id' => $pid, 'title' => '100% complete review'], $sessionToken, $dbPath, $cfg);
$literal = dispatch('GET', ['api', 'tasks'], ['q' => '100% complete'], null, '', $dbPath, $cfg);
check(($literal['body']['count'] ?? -1) === 1, 'literal "%" in the query still matches its own text');
$wild2 = dispatch('GET', ['api', 'tasks'], ['q' => '%'], null, '', $dbPath, $cfg);
check(($wild2['body']['count'] ?? -1) === 1, 'search for "%" now matches only the literal "%" record, not every task');

// ---------------------------------------------------------------- validation negatives
$cases = [
    ['name' => 'blank task title', 'res' => dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => '   '], $sessionToken, $dbPath, $cfg)],
    ['name' => 'missing project_id', 'res' => dispatch('POST', ['api', 'tasks'], [], ['title' => 'x'], $sessionToken, $dbPath, $cfg)],
    ['name' => 'invalid task status', 'res' => dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => 'x', 'status' => 'warp'], $sessionToken, $dbPath, $cfg)],
    ['name' => 'invalid priority', 'res' => dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => 'x', 'priority' => 'urgent'], $sessionToken, $dbPath, $cfg)],
    ['name' => 'blank project name', 'res' => dispatch('POST', ['api', 'projects'], [], ['name' => '  '], $sessionToken, $dbPath, $cfg)],
    ['name' => 'invalid project status', 'res' => dispatch('POST', ['api', 'projects'], [], ['name' => 'x', 'status' => 'warp'], $sessionToken, $dbPath, $cfg)],
    ['name' => 'nonexistent project on task create', 'res' => dispatch('POST', ['api', 'tasks'], [], ['project_id' => 999999, 'title' => 'x'], $sessionToken, $dbPath, $cfg)],
];
foreach ($cases as $case) {
    check($case['res']['status'] === 400, "validation: {$case['name']} -> 400");
}

$longTitle = str_repeat('a', 201);
$overlong = dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => $longTitle], $sessionToken, $dbPath, $cfg);
check($overlong['status'] === 400, 'validation: overlong task title -> 400');

$nonString = dispatch('POST', ['api', 'tasks'], [], ['project_id' => 1, 'title' => 42], $sessionToken, $dbPath, $cfg);
check($nonString['status'] === 400, 'validation: non-string task title -> 400');

$intProject = dispatch('POST', ['api', 'tasks'], [], ['project_id' => 'abc', 'title' => 'x'], $sessionToken, $dbPath, $cfg);
check($intProject['status'] === 400, 'validation: non-integer project_id -> 400');

$emptyPatch = dispatch('PATCH', ['api', 'tasks', '1'], [], [], $sessionToken, $dbPath, $cfg);
check($emptyPatch['status'] === 400, 'validation: empty PATCH -> 400');

$emptyProjectPatch = dispatch('PATCH', ['api', 'projects', '1'], [], [], $sessionToken, $dbPath, $cfg);
check($emptyProjectPatch['status'] === 400, 'validation: empty project PATCH -> 400');

$malformed = php_tb_api_dispatch('POST', ['api', 'tasks'], [], null, true, $sessionToken, $sessionToken, $dbPath, $cfg);
check($malformed['status'] === 400, 'validation: malformed JSON -> 400');

$errorFields = dispatch('POST', ['api', 'tasks'], [], ['title' => 'no project'], $sessionToken, $dbPath, $cfg);
check(is_array($errorFields['body']['fields'] ?? null), 'validation error includes a fields map');
check(isset($errorFields['body']['fields']['project_id']), 'validation fields mention project_id');

// ---------------------------------------------------------------- not found / method
check(dispatch('GET', ['api', 'tasks', '999999'], [], null, '', $dbPath, $cfg)['status'] === 404, 'unknown task -> 404');
check(dispatch('GET', ['api', 'projects', '999999'], [], null, '', $dbPath, $cfg)['status'] === 404, 'unknown project -> 404');
check(dispatch('PUT', ['api', 'tasks'], [], [], $sessionToken, $dbPath, $cfg)['status'] === 405, 'unhandled method -> 405');
check(dispatch('GET', ['api', 'unknown'], [], null, '', $dbPath, $cfg)['status'] === 404, 'unknown API route -> 404');
check(dispatch('PATCH', ['api', 'tasks', 'a'], [], ['title' => 'x'], $sessionToken, $dbPath, $cfg)['status'] === 400, 'invalid id -> 400');

// ---------------------------------------------------------------- persistence across an open/close cycle
$pdo2 = php_tb_open($dbPath);
$row = $pdo2->prepare('SELECT title FROM task WHERE title = ?');
$row->execute(['100% complete review']);
check((string)$row->fetchColumn() === '100% complete review', 'restart-style persistence: a record survives a fresh connection on the same path');
$pdo2 = null;

// project cascade delete removes its tasks
$delProject = dispatch('DELETE', ['api', 'projects', (string)$pid], [], null, $sessionToken, $dbPath, $cfg);
check($delProject['status'] === 204, 'delete project returns 204');
$orphanCount = (int)(new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM task WHERE project_id = ' . $pid)->fetchColumn();
check($orphanCount === 0, 'project delete cascades to its tasks');

// ---------------------------------------------------------------- readiness against an unusable path
// $work/blocked.txt is a regular file, so nothing under it can be opened.
$badCfg = makeCfg($work . '/blocked.txt' . '/taskboard.db');
$down = dispatch('GET', ['api', 'health', 'ready'], [], null, '', $badCfg->dbPath, $badCfg);
check($down['status'] === 503, 'readiness -> 503 when the database is unavailable');
check(($down['body']['status'] ?? '') === 'unavailable', 'readiness body reports unavailable');
$downAlive = dispatch('GET', ['api', 'health', 'live'], [], null, '', $badCfg->dbPath, $badCfg);
check($downAlive['status'] === 200, 'liveness -> 200 while the database is unavailable');
$downApi = dispatch('GET', ['api', 'projects'], [], null, '', $badCfg->dbPath, $badCfg);
check($downApi['status'] === 503, 'API list route -> 503 while the database is unavailable');

// cleanup
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}
rrmdir($work);

echo "\n";
echo "=== test summary: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);