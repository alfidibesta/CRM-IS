<?php
/**
 * Kanban workflow board with drag & drop (persisted via fetch -> move_task).
 * Dragging, creating and deleting are gated by the signed-in role.
 */

$columns = [
    'backlog'     => ['Backlog', 'blue'],
    'planned'     => ['Planned', 'neutral'],
    'in_progress' => ['In Progress', 'orange'],
    'review'      => ['Review', 'blue'],
    'done'        => ['Done', 'green'],
];

$tasks = $pdo->query('SELECT t.*, i.code AS issue_code, i.title AS issue_title
    FROM tasks t LEFT JOIN issues i ON i.id = t.issue_id
    ORDER BY t.position, t.id')->fetchAll();

$byCol = [];
foreach ($tasks as $t) {
    $byCol[$t['column_key']][] = $t;
}

$canCreate = can('task.create');
$canMove   = can('task.move');
$canDelete = can('task.delete');

$done = count($byCol['done'] ?? []);
$wip  = count($byCol['in_progress'] ?? []);
$review = count($byCol['review'] ?? []);
$total = count($tasks) ?: 1;
$today = date('Y-m-d');
$overdue = count(array_filter($tasks, fn($t) => $t['due_at'] && $t['due_at'] < $today && $t['column_key'] !== 'done'));
?>

<section class="grid grid--stats">
  <article class="card stat stat--orange">
    <p class="stat__label">In progress</p>
    <p class="stat__value"><?= $wip ?></p>
    <p class="stat__hint">work in flight</p>
  </article>
  <article class="card stat stat--blue">
    <p class="stat__label">Awaiting review</p>
    <p class="stat__value"><?= $review ?></p>
    <p class="stat__hint">ready for verification</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Completed</p>
    <p class="stat__value"><?= (int) round($done / $total * 100) ?>%</p>
    <p class="stat__hint"><?= $done ?> of <?= $total ?> tasks</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Overdue</p>
    <p class="stat__value"><?= $overdue ?></p>
    <p class="stat__hint">past due date, not done</p>
  </article>
</section>

<div class="toolbar">
  <div class="chips chips--filter">
    <span class="chips__label">
      <?= $canMove ? 'Drag cards between columns — the board saves itself' : 'Read-only role: cards cannot be moved' ?>
    </span>
  </div>

  <?php if ($canCreate): ?>
  <details class="panel panel--inline">
    <summary class="btn btn--primary btn--summary">+ Add task</summary>
    <form class="card form form--inline" method="post" action="index.php">
      <?= csrf_field() ?>
      <?= once_field() ?>
      <input type="hidden" name="action" value="create_task">
      <input type="hidden" name="back" value="workflow">
      <div class="form__grid">
        <label class="field field--wide"><span>Task</span>
          <input type="text" name="title" required maxlength="200" placeholder="What needs doing?">
        </label>
        <label class="field"><span>Column</span>
          <select name="column">
            <?php foreach ($columns as $key => [$label]): ?>
              <option value="<?= $key ?>" <?= $key === 'backlog' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Priority</span>
          <select name="priority">
            <option>Critical</option><option>High</option><option selected>Medium</option><option>Low</option>
          </select>
        </label>
        <label class="field"><span>Category</span>
          <select name="category">
            <option>Physical</option><option>IT</option><option>Strain</option><option>General</option>
          </select>
        </label>
        <label class="field"><span>Owner</span><input type="text" name="owner" maxlength="100"></label>
        <label class="field"><span>Due</span><input type="date" name="due_at"></label>
        <label class="field field--wide"><span>Linked issue</span>
          <select name="issue_id">
            <option value="">— none —</option>
            <?php foreach ($pdo->query("SELECT id, code, title FROM issues ORDER BY reported_at DESC, id DESC")->fetchAll() as $i): ?>
              <option value="<?= (int) $i['id'] ?>"><?= e($i['code']) ?> · <?= e(mb_substr($i['title'], 0, 60)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Add task</button>
      </div>
    </form>
  </details>
  <?php endif; ?>
</div>

<section class="board">
  <?php foreach ($columns as $key => [$label, $tone]): ?>
    <div class="col" data-column="<?= $key ?>">
      <header class="col__head col__head--<?= $tone ?>">
        <span><?= e($label) ?></span>
        <span class="col__count"><?= count($byCol[$key] ?? []) ?></span>
      </header>

      <div class="col__body" data-drop="<?= $key ?>">
        <?php foreach ($byCol[$key] ?? [] as $t): ?>
          <article class="tcard <?= $canMove ? '' : 'tcard--static' ?>"
                   <?= $canMove ? 'draggable="true"' : '' ?> data-id="<?= (int) $t['id'] ?>">
            <div class="tcard__top">
              <span class="tag tag--<?= scope_tone($t['category']) ?>"><?= e($t['category']) ?></span>
              <?= badge($t['priority'], severity_tone($t['priority'])) ?>
              <?php if ($canDelete): ?>
                <button type="button" class="tcard__del" data-delete-task="<?= (int) $t['id'] ?>"
                        title="Delete task" aria-label="Delete task">×</button>
              <?php endif; ?>
            </div>
            <p class="tcard__title"><?= e($t['title']) ?></p>
            <?php if ($t['issue_code']): ?>
              <a class="tcard__link" href="?page=issue&amp;id=<?= (int) $t['issue_id'] ?>" title="<?= e($t['issue_title']) ?>">↳ <?= e($t['issue_code']) ?></a>
            <?php endif; ?>
            <footer class="tcard__foot">
              <span class="avatar"><?= e($t['owner'] ? strtoupper(preg_replace('/\s+/', '', mb_substr($t['owner'], 0, 2))) : '–') ?></span>
              <span class="muted <?= $t['due_at'] && $t['due_at'] < $today && $t['column_key'] !== 'done' ? 'text-red' : '' ?>">
                <?= $t['due_at'] ? 'due ' . date('d M', strtotime($t['due_at'])) : 'no date' ?>
              </span>
            </footer>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<form id="delete-task-form" method="post" action="index.php" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_task">
  <input type="hidden" name="back" value="workflow">
  <input type="hidden" name="task_id" id="delete-task-id" value="">
</form>

<p class="footnote" id="board-status" role="status" aria-live="polite"><?= $canMove ? 'Changes are saved automatically.' : 'Your role has read-only access to the board.' ?></p>
