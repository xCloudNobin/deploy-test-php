<?php

declare(strict_types=1);

/**
 * Core application: validated CRUD, search/filter, health/readiness and CSRF
 * enforcement. Pure functions over PDO so the test harness can exercise every
 * branch without HTTP; `public/index.php` adapts the environment to them.
 */

const PHP_TB_TITLE_MAX = 200;
const PHP_TB_DESCRIPTION_MAX = 2000;
const PHP_TB_NAME_MAX = 120;

final class PhpTaskboard_ApiError extends Exception
{
    public int $statusCode;
    /** @var array<string,string> */
    public array $fields;

    /** @param array<string,string> $fields */
    public function __construct(int $status, string $message, array $fields = [])
    {
        parent::__construct($message);
        $this->statusCode = $status;
        $this->fields = $fields;
    }
}

function php_tb_json_error(int $status, string $message, array $fields = []): array
{
    $body = ['error' => $message];
    if ($fields !== []) {
        $body['fields'] = $fields;
    }
    return ['status' => $status, 'headers' => [], 'body' => $body];
}

/** @throws PhpTaskboard_ApiError */
function php_tb_require_db(string $dbPath): PDO
{
    try {
        return php_tb_open($dbPath);
    } catch (Throwable $error) {
        error_log('[taskboard] database unavailable: ' . $error->getMessage());
        throw new PhpTaskboard_ApiError(503, 'Service Unavailable', ['db' => 'unavailable']);
    }
}

/** @throws PhpTaskboard_ApiError */
function php_tb_parse_id(mixed $value): int
{
    if (is_int($value)) {
        return $value >= 1 ? $value : throw new PhpTaskboard_ApiError(400, 'Invalid identifier');
    }
    if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
        $id = (int)$value;
        return $id >= 1 ? $id : throw new PhpTaskboard_ApiError(400, 'Invalid identifier');
    }
    throw new PhpTaskboard_ApiError(400, 'Invalid identifier');
}

/** @throws PhpTaskboard_ApiError */
function php_tb_optional_string(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new PhpTaskboard_ApiError(400, 'Field must be a string');
    }
    return $value;
}

/** @throws PhpTaskboard_ApiError */
function php_tb_parse_task_status(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'todo';
    }
    if (!in_array($value, PHP_TB_TASK_STATUSES, true)) {
        throw new PhpTaskboard_ApiError(400, 'Status must be one of: ' . implode(', ', PHP_TB_TASK_STATUSES));
    }
    return (string)$value;
}

/** @throws PhpTaskboard_ApiError */
function php_tb_parse_priority(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'medium';
    }
    if (!in_array($value, PHP_TB_PRIORITIES, true)) {
        throw new PhpTaskboard_ApiError(400, 'Priority must be one of: ' . implode(', ', PHP_TB_PRIORITIES));
    }
    return (string)$value;
}

/** @throws PhpTaskboard_ApiError */
function php_tb_parse_project_status(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'active';
    }
    if (!in_array($value, PHP_TB_PROJECT_STATUSES, true)) {
        throw new PhpTaskboard_ApiError(
            400,
            'Project status must be one of: ' . implode(', ', PHP_TB_PROJECT_STATUSES),
        );
    }
    return (string)$value;
}

/** @return array{project_id:int, title:string, description:string, status:string, priority:string} */
function php_tb_validate_task_input(array $body, bool $requireProject): array
{
    $fields = [];

    $projectId = 0;
    $projectValue = $body['project_id'] ?? null;
    if ($projectValue === null) {
        if ($requireProject) {
            $fields['project_id'] = 'project_id is required';
        }
    } elseif (!is_int($projectValue) || $projectValue < 1) {
        $fields['project_id'] = 'project_id must be a positive integer';
    } else {
        $projectId = $projectValue;
    }

    $title = '';
    $titleValue = $body['title'] ?? null;
    if (!is_string($titleValue) || trim($titleValue) === '') {
        $fields['title'] = is_string($titleValue)
            ? 'title is required and must not be blank'
            : 'title is required and must be a string';
    } else {
        $title = trim($titleValue);
        if (mb_strlen($title) > PHP_TB_TITLE_MAX) {
            $fields['title'] = sprintf('title must be %d characters or fewer', PHP_TB_TITLE_MAX);
        }
    }

    $description = '';
    $descriptionValue = array_key_exists('description', $body)
        ? php_tb_optional_string($body['description'])
        : null;
    if ($descriptionValue !== null) {
        $description = trim($descriptionValue);
        if (mb_strlen($description) > PHP_TB_DESCRIPTION_MAX) {
            $fields['description'] = sprintf(
                'description must be %d characters or fewer',
                PHP_TB_DESCRIPTION_MAX,
            );
        }
    }

    $status = 'todo';
    $priority = 'medium';
    try {
        $status = php_tb_parse_task_status($body['status'] ?? null);
        $priority = php_tb_parse_priority($body['priority'] ?? null);
    } catch (PhpTaskboard_ApiError $error) {
        $fields['status'] = $error->getMessage();
    }

    if ($fields !== []) {
        throw new PhpTaskboard_ApiError(400, 'Validation failed', $fields);
    }
    if ($requireProject && $projectId < 1) {
        throw new PhpTaskboard_ApiError(400, 'Validation failed', ['project_id' => 'project_id is required']);
    }

    return ['project_id' => $projectId, 'title' => $title, 'description' => $description, 'status' => $status, 'priority' => $priority];
}

/** @return array{name:string, description:string, status:string} */
function php_tb_validate_project_input(array $body): array
{
    $fields = [];

    $name = '';
    $nameValue = $body['name'] ?? null;
    if (!is_string($nameValue) || trim($nameValue) === '') {
        $fields['name'] = is_string($nameValue)
            ? 'name is required and must not be blank'
            : 'name is required and must be a string';
    } else {
        $name = trim($nameValue);
        if (mb_strlen($name) > PHP_TB_NAME_MAX) {
            $fields['name'] = sprintf('name must be %d characters or fewer', PHP_TB_NAME_MAX);
        }
    }

    $description = '';
    $descriptionValue = array_key_exists('description', $body)
        ? php_tb_optional_string($body['description'])
        : null;
    if ($descriptionValue !== null) {
        $description = trim($descriptionValue);
        if (mb_strlen($description) > PHP_TB_DESCRIPTION_MAX) {
            $fields['description'] = sprintf(
                'description must be %d characters or fewer',
                PHP_TB_DESCRIPTION_MAX,
            );
        }
    }

    $status = 'active';
    try {
        $status = php_tb_parse_project_status($body['status'] ?? null);
    } catch (PhpTaskboard_ApiError $error) {
        $fields['status'] = $error->getMessage();
    }

    if ($fields !== []) {
        throw new PhpTaskboard_ApiError(400, 'Validation failed', $fields);
    }

    return ['name' => $name, 'description' => $description, 'status' => $status];
}

/** @return array<string,mixed> Cast an SQLite row into JSON-friendly data. */
function php_tb_map_task_row(array $row, ?PDO $pdo = null): array
{
    return [
        'id' => (int)$row['id'],
        'project_id' => (int)$row['project_id'],
        'project_name' => (string)($row['project_name'] ?? php_tb_project_name($pdo, (int)$row['project_id'])),
        'title' => (string)$row['title'],
        'description' => (string)$row['description'],
        'status' => (string)$row['status'],
        'priority' => (string)$row['priority'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function php_tb_project_name(?PDO $pdo, int $id): string
{
    if ($pdo === null) {
        return '';
    }
    $name = $pdo->prepare('SELECT name FROM project WHERE id = ?');
    $name->execute([$id]);
    return (string)$name->fetchColumn();
}

/** @return array<string,mixed> */
function php_tb_map_project_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'description' => (string)$row['description'],
        'status' => (string)$row['status'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'task_total' => (int)($row['task_total'] ?? 0),
        'todo' => (int)($row['todo'] ?? 0),
        'in_progress' => (int)($row['in_progress'] ?? 0),
        'done' => (int)($row['done'] ?? 0),
    ];
}

/** @throws PhpTaskboard_ApiError */
function php_tb_get_project(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, description, status, created_at, updated_at FROM project WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** @throws PhpTaskboard_ApiError */
function php_tb_get_task(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT t.id, t.project_id, p.name AS project_name, t.title, t.description, t.status,
                t.priority, t.created_at, t.updated_at
           FROM task t JOIN project p ON p.id = t.project_id
          WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_list_projects(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT p.id, p.name, p.description, p.status, p.created_at, p.updated_at,
                COUNT(t.id) AS task_total,
                SUM(CASE WHEN t.status = \'todo\' THEN 1 ELSE 0 END) AS todo,
                SUM(CASE WHEN t.status = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN t.status = \'done\' THEN 1 ELSE 0 END) AS done
           FROM project p LEFT JOIN task t ON t.project_id = p.id
          GROUP BY p.id ORDER BY p.id ASC'
    )->fetchAll();
    $projects = array_map('php_tb_map_project_row', $rows);
    return ['status' => 200, 'headers' => [], 'body' => ['projects' => $projects]];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_create_project(PDO $pdo, array $body): array
{
    $input = php_tb_validate_project_input($body);
    $stmt = $pdo->prepare('INSERT INTO project (name, description, status) VALUES (?, ?, ?)');
    $stmt->execute([$input['name'], $input['description'], $input['status']]);
    $project = php_tb_get_project($pdo, (int)$pdo->lastInsertId());
    return ['status' => 201, 'headers' => [], 'body' => ['project' => php_tb_map_project_row($project)]];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_get_project_detail(PDO $pdo, int $id): array
{
    $project = php_tb_get_project($pdo, $id);
    if ($project === null) {
        return php_tb_json_error(404, 'Project not found');
    }
    $stmt = $pdo->prepare('SELECT * FROM task WHERE project_id = ? ORDER BY id DESC');
    $stmt->execute([$id]);
    $tasks = array_map(fn(array $row) => php_tb_map_task_row($row, $pdo), $stmt->fetchAll());
    return [
        'status' => 200,
        'headers' => [],
        'body' => ['project' => php_tb_map_project_row($project), 'tasks' => $tasks],
    ];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_update_project(PDO $pdo, int $id, array $body): array
{
    $current = php_tb_get_project($pdo, $id);
    if ($current === null) {
        return php_tb_json_error(404, 'Project not found');
    }
    $keys = ['name', 'description', 'status'];
    $present = array_values(array_filter($keys, fn(string $k) => array_key_exists($k, $body))) !== [];
    if (!$present) {
        throw new PhpTaskboard_ApiError(
            400,
            'Nothing to update: provide at least one of name, description, status'
        );
    }
    $merged = [
        'name' => array_key_exists('name', $body) ? $body['name'] : $current['name'],
        'description' => array_key_exists('description', $body) ? $body['description'] : $current['description'],
        'status' => array_key_exists('status', $body) ? $body['status'] : $current['status'],
    ];
    $input = php_tb_validate_project_input($merged);
    $stmt = $pdo->prepare(
        "UPDATE project SET name = ?, description = ?, status = ?, updated_at = datetime('now') WHERE id = ?"
    );
    $stmt->execute([$input['name'], $input['description'], $input['status'], $id]);
    return ['status' => 200, 'headers' => [], 'body' => ['project' => php_tb_map_project_row(php_tb_get_project($pdo, $id))]];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_delete_project(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('DELETE FROM project WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        return php_tb_json_error(404, 'Project not found');
    }
    return ['status' => 204, 'headers' => [], 'body' => null];
}

function php_tb_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_list_tasks(PDO $pdo, array $query): array
{
    $q = trim((string)($query['q'] ?? ''));
    $statusParam = trim((string)($query['status'] ?? ''));
    $priorityParam = trim((string)($query['priority'] ?? ''));
    $projectRaw = trim((string)($query['project_id'] ?? ''));

    if ($statusParam !== '' && !in_array($statusParam, PHP_TB_TASK_STATUSES, true)) {
        throw new PhpTaskboard_ApiError(
            400,
            'status filter must be one of: ' . implode(', ', PHP_TB_TASK_STATUSES)
        );
    }
    if ($priorityParam !== '' && !in_array($priorityParam, PHP_TB_PRIORITIES, true)) {
        throw new PhpTaskboard_ApiError(
            400,
            'priority filter must be one of: ' . implode(', ', PHP_TB_PRIORITIES)
        );
    }
    $projectId = null;
    if ($projectRaw !== '') {
        $projectId = php_tb_parse_id($projectRaw);
    }

    $like = $q === '' ? null : '%' . php_tb_escape_like($q) . '%';

    $stmt = $pdo->prepare(
        "SELECT t.id, t.project_id, p.name AS project_name, t.title, t.description, t.status,
                t.priority, t.created_at, t.updated_at
           FROM task t JOIN project p ON p.id = t.project_id
          WHERE (:project IS NULL OR t.project_id = :project)
            AND (:status IS NULL OR t.status = :status)
            AND (:priority IS NULL OR t.priority = :priority)
            AND (:q IS NULL OR t.title LIKE :q ESCAPE '\\' OR t.description LIKE :q ESCAPE '\\')
          ORDER BY t.id DESC"
    );
    $stmt->bindValue(':project', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':status', $statusParam === '' ? null : $statusParam);
    $stmt->bindValue(':priority', $priorityParam === '' ? null : $priorityParam);
    $stmt->bindValue(':q', $like);
    $stmt->execute();

    $tasks = array_map(fn(array $row) => php_tb_map_task_row($row, $pdo), $stmt->fetchAll());
    return [
        'status' => 200,
        'headers' => [],
        'body' => [
            'tasks' => $tasks,
            'count' => count($tasks),
            'query' => ['q' => $q, 'status' => $statusParam === '' ? null : $statusParam, 'priority' => $priorityParam === '' ? null : $priorityParam, 'project_id' => $projectId],
        ],
    ];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_create_task(PDO $pdo, array $body): array
{
    $input = php_tb_validate_task_input($body, true);
    if (php_tb_get_project($pdo, $input['project_id']) === null) {
        throw new PhpTaskboard_ApiError(400, 'project_id does not exist', ['project_id' => 'no project with that id']);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO task (project_id, title, description, status, priority) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$input['project_id'], $input['title'], $input['description'], $input['status'], $input['priority']]);
    $task = php_tb_get_task($pdo, (int)$pdo->lastInsertId());
    return ['status' => 201, 'headers' => [], 'body' => ['task' => php_tb_map_task_row($task, $pdo)]];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_get_task_detail(PDO $pdo, int $id): array
{
    $task = php_tb_get_task($pdo, $id);
    if ($task === null) {
        return php_tb_json_error(404, 'Task not found');
    }
    return ['status' => 200, 'headers' => [], 'body' => ['task' => php_tb_map_task_row($task, $pdo)]];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_update_task(PDO $pdo, int $id, array $body): array
{
    $current = php_tb_get_task($pdo, $id);
    if ($current === null) {
        return php_tb_json_error(404, 'Task not found');
    }

    $keys = ['title', 'description', 'status', 'priority', 'project_id'];
    $present = arrays_any_key($keys, $body);
    if (!$present) {
        throw new PhpTaskboard_ApiError(
            400,
            'Nothing to update: provide at least one of title, description, status, priority, project_id'
        );
    }

    // PATCH semantics: fields the caller omits keep their current value.
    $merged = [
        'title' => $current['title'],
        'description' => $current['description'],
        'status' => $current['status'],
        'priority' => $current['priority'],
        'project_id' => (int)$current['project_id'],
    ];
    foreach ($keys as $key) {
        if (array_key_exists($key, $body)) {
            $merged[$key] = $body[$key];
        }
    }
    $input = php_tb_validate_task_input($merged, false);
    $targetProject = $input['project_id'] > 0 ? $input['project_id'] : (int)$current['project_id'];
    if (php_tb_get_project($pdo, $targetProject) === null) {
        throw new PhpTaskboard_ApiError(400, 'project_id does not exist', ['project_id' => 'no project with that id']);
    }

    $stmt = $pdo->prepare(
        "UPDATE task SET project_id = ?, title = ?, description = ?, status = ?, priority = ?,
                updated_at = datetime('now') WHERE id = ?"
    );
    $stmt->execute([
        $targetProject,
        $input['title'],
        $input['description'],
        $input['status'],
        $input['priority'],
        $id,
    ]);
    return ['status' => 200, 'headers' => [], 'body' => ['task' => php_tb_map_task_row(php_tb_get_task($pdo, $id), $pdo)]];
}

/** @return array{status:int, headers:array<string,string>, body:mixed} */
function php_tb_delete_task(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('DELETE FROM task WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        return php_tb_json_error(404, 'Task not found');
    }
    return ['status' => 204, 'headers' => [], 'body' => null];
}

function arrays_any_key(array $keys, array $body): bool
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $body)) {
            return true;
        }
    }
    return false;
}

/**
 * Route an API request. Pure and HTTP-free so the test harness can drive it
 * directly. `$body` is the decoded JSON object (null when the request had an
 * empty body); `$malformed` reports an undecodable JSON body.
 *
 * @param string[] $segments path segments, e.g. ['api', 'projects', '1']
 * @param array<string,string> $query
 * @param array<string,mixed>|null $body
 * @return array{status:int, headers:array<string,string>, body:mixed}
 */
function php_tb_api_dispatch(
    string $method,
    array $segments,
    array $query,
    ?array $body,
    bool $malformed,
    string $sessionToken,
    string $providedCsrf,
    string $dbPath,
    PhpTaskboard_Cfg $cfg
): array {
    $mutating = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    if ($mutating && !hash_equals($sessionToken, $providedCsrf)) {
        return php_tb_json_error(403, 'CSRF token missing or invalid');
    }
    if ($malformed) {
        return php_tb_json_error(400, 'Request body is not valid JSON');
    }

    $route = implode('/', $segments);

    try {
        if ($route === 'api/health/live' && $method === 'GET') {
            return ['status' => 200, 'headers' => [], 'body' => ['status' => 'alive', 'timestamp' => gmdate('c')]];
        }
        if ($route === 'api/health/ready' && $method === 'GET') {
            $probe = php_tb_probe($dbPath);
            if ($probe['ok']) {
                return ['status' => 200, 'headers' => [], 'body' => ['status' => 'ready', 'db' => 'sqlite', 'checked_at' => $probe['detail']]];
            }
            return ['status' => 503, 'headers' => [], 'body' => ['status' => 'unavailable', 'db' => 'sqlite', 'detail' => $probe['detail']]];
        }
        if ($route === 'api/meta' && $method === 'GET') {
            return [
                'status' => 200,
                'headers' => [],
                'body' => [
                    'name' => 'deploy-test-php',
                    'description' => 'Plain PHP taskboard: PDO/SQLite persistence, sessions + CSRF, validated CRUD, search/filter.',
                    'release' => $cfg->marker,
                    'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
                    'database' => ['engine' => 'sqlite', 'path' => $cfg->dbPath],
                    'csrf' => $sessionToken,
                ],
            ];
        }
        if ($route === 'api/csrf' && $method === 'GET') {
            return ['status' => 200, 'headers' => [], 'body' => ['csrf' => $sessionToken]];
        }

        if (count($segments) === 2 && $segments[0] === 'api' && $segments[1] === 'projects') {
            if ($method === 'GET') {
                return php_tb_list_projects(php_tb_require_db($dbPath));
            }
            if ($method === 'POST') {
                return php_tb_create_project(php_tb_require_db($dbPath), $body ?? []);
            }
            return ['status' => 405, 'headers' => ['Allow' => 'GET, POST'], 'body' => php_tb_json_error(405, 'Method Not Allowed')['body']];
        }

        if (count($segments) === 3 && $segments[0] === 'api' && $segments[1] === 'projects') {
            $id = php_tb_parse_id($segments[2]);
            if ($method === 'GET') {
                return php_tb_get_project_detail(php_tb_require_db($dbPath), $id);
            }
            if ($method === 'PATCH') {
                return php_tb_update_project(php_tb_require_db($dbPath), $id, $body ?? []);
            }
            if ($method === 'DELETE') {
                return php_tb_delete_project(php_tb_require_db($dbPath), $id);
            }
            return ['status' => 405, 'headers' => ['Allow' => 'GET, PATCH, DELETE'], 'body' => php_tb_json_error(405, 'Method Not Allowed')['body']];
        }

        if (count($segments) === 2 && $segments[0] === 'api' && $segments[1] === 'tasks') {
            if ($method === 'GET') {
                return php_tb_list_tasks(php_tb_require_db($dbPath), $query);
            }
            if ($method === 'POST') {
                return php_tb_create_task(php_tb_require_db($dbPath), $body ?? []);
            }
            return ['status' => 405, 'headers' => ['Allow' => 'GET, POST'], 'body' => php_tb_json_error(405, 'Method Not Allowed')['body']];
        }

        if (count($segments) === 3 && $segments[0] === 'api' && $segments[1] === 'tasks') {
            $id = php_tb_parse_id($segments[2]);
            if ($method === 'GET') {
                return php_tb_get_task_detail(php_tb_require_db($dbPath), $id);
            }
            if ($method === 'PATCH') {
                return php_tb_update_task(php_tb_require_db($dbPath), $id, $body ?? []);
            }
            if ($method === 'DELETE') {
                return php_tb_delete_task(php_tb_require_db($dbPath), $id);
            }
            return ['status' => 405, 'headers' => ['Allow' => 'GET, PATCH, DELETE'], 'body' => php_tb_json_error(405, 'Method Not Allowed')['body']];
        }

        return php_tb_json_error(404, 'Not found');
    } catch (PhpTaskboard_ApiError $error) {
        return php_tb_json_error($error->statusCode, $error->getMessage(), $error->fields);
    } catch (Throwable $error) {
        error_log('[taskboard] request failed: ' . $error->getMessage());
        return php_tb_json_error(500, 'Internal Server Error');
    }
}