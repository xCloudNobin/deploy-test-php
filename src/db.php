<?php

declare(strict_types=1);

/**
 * SQLite persistence for the plain-PHP taskboard.
 *
 * The schema is idempotent (`CREATE TABLE IF NOT EXISTS`) and the seed data is
 * guarded by a one-time `seed_flag` row, so opening the database any number of
 * times never duplicates rows. Every data operation uses PDO prepared
 * statements with bound parameters — user input never reaches SQL text.
 */

const PHP_TB_TASK_STATUSES = ['todo', 'in_progress', 'done'];
const PHP_TB_PRIORITIES = ['low', 'medium', 'high'];
const PHP_TB_PROJECT_STATUSES = ['active', 'archived'];

const PHP_TB_SCHEMA_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS project (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'active'
              CHECK (status IN ('active', 'archived')),
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS task (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id  INTEGER NOT NULL REFERENCES project(id) ON DELETE CASCADE,
  title       TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'todo'
              CHECK (status IN ('todo', 'in_progress', 'done')),
  priority    TEXT NOT NULL DEFAULT 'medium'
              CHECK (priority IN ('low', 'medium', 'high')),
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_task_project ON task(project_id, status);
CREATE INDEX IF NOT EXISTS idx_task_status ON task(status);

CREATE TABLE IF NOT EXISTS seed_flag (
  id         INTEGER PRIMARY KEY CHECK (id = 1),
  seeded_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS heartbeat (
  id         INTEGER PRIMARY KEY CHECK (id = 1),
  checked_at TEXT NOT NULL
);
SQL;

const PHP_TB_SEED_PROJECTS = [
    ['name' => 'Launch checklist', 'description' => 'Demo project created by the idempotent seeder.'],
    ['name' => 'Support triage', 'description' => 'Second demo board, also created once.'],
];

const PHP_TB_SEED_TASKS = [
    ['project' => 0, 'title' => 'Write the launch blurb', 'status' => 'done', 'priority' => 'high'],
    ['project' => 0, 'title' => 'Schedule demo for the team', 'status' => 'in_progress', 'priority' => 'medium'],
    ['project' => 0, 'title' => 'Prepare rollback notes', 'status' => 'todo', 'priority' => 'low'],
    ['project' => 1, 'title' => 'Reproduce reported bug #42', 'status' => 'in_progress', 'priority' => 'high'],
];

/** @return array{seeded:bool, projects:int, tasks:int} */
function php_tb_seed(PDO $pdo): array
{
    $flag = $pdo->query('SELECT seeded_at FROM seed_flag WHERE id = 1')->fetch(PDO::FETCH_COLUMN);
    if ($flag !== false) {
        $projects = (int)$pdo->query('SELECT COUNT(*) FROM project')->fetchColumn();
        $tasks = (int)$pdo->query('SELECT COUNT(*) FROM task')->fetchColumn();
        return ['seeded' => false, 'projects' => $projects, 'tasks' => $tasks];
    }

    $pdo->beginTransaction();
    try {
        $projectStmt = $pdo->prepare('INSERT INTO project (name, description, status) VALUES (?, ?, \'active\')');
        $projectIds = [];
        foreach (PHP_TB_SEED_PROJECTS as $project) {
            $projectStmt->execute([$project['name'], $project['description']]);
            $projectIds[] = (int)$pdo->lastInsertId();
        }
        $taskStmt = $pdo->prepare(
            'INSERT INTO task (project_id, title, description, status, priority) VALUES (?, ?, \'\', ?, ?)'
        );
        foreach (PHP_TB_SEED_TASKS as $task) {
            $taskStmt->execute([
                $projectIds[$task['project']],
                $task['title'],
                $task['status'],
                $task['priority'],
            ]);
        }
        $pdo->exec('INSERT OR REPLACE INTO seed_flag (id, seeded_at) VALUES (1, datetime(\'now\'))');
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return [
        'seeded' => true,
        'projects' => count(PHP_TB_SEED_PROJECTS),
        'tasks' => count(PHP_TB_SEED_TASKS),
    ];
}

/** Open a connection and ensure schema + guarded seed data. */
function php_tb_open(string $path): PDO
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('cannot create data directory "%s"', $dir));
        }
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec(PHP_TB_SCHEMA_SQL);
    $seed = php_tb_seed($pdo);
    error_log(sprintf(
        '[taskboard] sqlite ready %s (seeded=%s projects=%d tasks=%d)',
        $path,
        $seed['seeded'] ? 'yes' : 'no',
        $seed['projects'],
        $seed['tasks'],
    ));
    return $pdo;
}

/**
 * Genuine readiness probe: open a fresh connection to the configured path,
 * ensure the schema exists, perform a real write + read, then close. Returns
 * the outcome without throwing; callers turn a failed probe into a 503.
 *
 * @return array{ok:bool, detail:string}
 */
function php_tb_probe(string $path): array
{
    $pdo = null;
    try {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return ['ok' => false, 'detail' => sprintf('data directory "%s" is not writable', $dir)];
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(PHP_TB_SCHEMA_SQL);
        $pdo->exec('INSERT OR REPLACE INTO heartbeat (id, checked_at) VALUES (1, datetime(\'now\'))');
        $checked = $pdo->query('SELECT checked_at FROM heartbeat WHERE id = 1')->fetchColumn();
        return ['ok' => true, 'detail' => (string)$checked];
    } catch (Throwable $error) {
        return ['ok' => false, 'detail' => $error->getMessage()];
    } finally {
        $pdo = null;
    }
}