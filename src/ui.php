<?php

declare(strict_types=1);

/**
 * SPA shell for the taskboard UI. Served for every non-API GET so nested
 * client routes (e.g. `/project/3`) render the same page, letting the client
 * deep-link and restore filters from the URL.
 *
 * The shell embeds only the per-session CSRF token (a random 64-hex value),
 * the non-sensitive release marker and runtime versions. User-controlled task
 * text is never inlined here — the client renders it via `textContent`.
 */
function php_tb_render_ui(PhpTaskboard_Cfg $cfg, string $csrfToken, string $path): void
{
    $base = $cfg->basePath === '' ? '' : $cfg->basePath;
    $csrfEsc = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $theme  = htmlspecialchars('dark', ENT_QUOTES, 'UTF-8');
    $release = htmlspecialchars($cfg->marker, ENT_QUOTES, 'UTF-8');

    $title = 'PHP Taskboard';
    $footer = 'deploy-test-php · plain PHP + PDO/SQLite + sessions/CSRF · MIT';

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <link rel="stylesheet" href="{$base}/style.css">
</head>
<body>
  <header class="topbar">
    <div class="wrap">
      <h1>PHP Taskboard</h1>
      <div class="topmeta">
        <span id="health" class="badge health">connecting…</span>
        <span id="release" class="badge release">release …</span>
      </div>
    </div>
  </header>

  <main class="wrap">
    <section class="panel">
      <h2>Projects</h2>
      <form id="project-form" class="rowform" autocomplete="off">
        <input id="project-name" name="name" type="text" maxlength="120" placeholder="Project name" required>
        <input id="project-desc" name="description" type="text" maxlength="2000" placeholder="Description (optional)">
        <button type="submit">Add project</button>
      </form>
      <p id="project-error" class="error" hidden></p>
      <label>
        Active project
        <select id="project-select"></select>
      </label>
    </section>

    <section class="panel">
      <h2>Tasks</h2>
      <form id="task-form" class="rowform" autocomplete="off">
        <input id="task-title" name="title" type="text" maxlength="200" placeholder="What needs doing?" required>
        <input id="task-desc" name="description" type="text" maxlength="2000" placeholder="Description (optional)">
        <select id="task-status" name="status">
          <option value="todo">To do</option>
          <option value="in_progress">In progress</option>
          <option value="done">Done</option>
        </select>
        <select id="task-priority" name="priority">
          <option value="low">Low priority</option>
          <option value="medium" selected>Medium priority</option>
          <option value="high">High priority</option>
        </select>
        <button type="submit">Add task</button>
      </form>
      <p id="task-error" class="error" hidden></p>

      <div class="toolbar">
        <input id="search" type="search" placeholder="Search titles and descriptions">
        <select id="filter-status">
          <option value="">All statuses</option>
          <option value="todo">To do</option>
          <option value="in_progress">In progress</option>
          <option value="done">Done</option>
        </select>
        <select id="filter-priority">
          <option value="">All priorities</option>
          <option value="low">Low</option>
          <option value="medium">Medium</option>
          <option value="high">High</option>
        </select>
        <span id="task-count" class="count"></span>
      </div>

      <ul id="task-list" class="tasklist"></ul>
      <p id="task-empty" class="empty" hidden>No tasks match.</p>
    </section>
  </main>

  <footer class="wrap">{$footer}</footer>

  <template id="task-template">
    <li class="task">
      <div class="task-main">
        <span class="task-title"></span>
        <span class="task-meta"></span>
        <span class="task-desc"></span>
      </div>
      <div class="task-actions">
        <button class="edit" type="button">Edit</button>
        <button class="delete" type="button">Delete</button>
      </div>
      <form class="task-editform" hidden autocomplete="off">
        <input class="edit-title" type="text" maxlength="200" required>
        <input class="edit-desc" type="text" maxlength="2000">
        <select class="edit-status">
          <option value="todo">To do</option>
          <option value="in_progress">In progress</option>
          <option value="done">Done</option>
        </select>
        <select class="edit-priority">
          <option value="low">Low</option>
          <option value="medium">Medium</option>
          <option value="high">High</option>
        </select>
        <button type="submit">Save</button>
        <button type="button" class="cancel">Cancel</button>
        <span class="error edit-error" hidden></span>
      </form>
    </li>
  </template>

  <script src="{$base}/app.js" defer></script>
  <script>
    window.APP = { basePath: "{$base}", csrf: "{$csrfEsc}", release: "{$release}", theme: "{$theme}" };
  </script>
</body>
</html>
HTML;
}