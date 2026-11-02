/* SecOps CRM — board drag & drop, confirmations, upload + risk widgets. */

(function () {
  var status = document.getElementById('board-status');
  var dragging = null;

  function say(msg, isError) {
    if (!status) return;
    status.textContent = msg;
    status.style.color = isError ? '#e5484d' : '';
  }

  function indexOfCard(card) {
    var siblings = Array.prototype.slice.call(card.parentNode.querySelectorAll('.tcard'));
    return siblings.indexOf(card);
  }

  /* ------------------------------------------------- board drag & drop */

  document.querySelectorAll('.tcard[draggable="true"]').forEach(function (card) {
    card.addEventListener('dragstart', function (e) {
      dragging = card;
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', card.dataset.id); } catch (err) {}
    });

    card.addEventListener('dragend', function () {
      card.classList.remove('dragging');
      dragging = null;
      document.querySelectorAll('.col__body').forEach(function (b) { b.classList.remove('is-over'); });
    });
  });

  document.querySelectorAll('.col__body').forEach(function (zone) {
    zone.addEventListener('dragover', function (e) {
      if (!dragging) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      zone.classList.add('is-over');

      var after = null;
      var cards = Array.prototype.slice.call(zone.querySelectorAll('.tcard:not(.dragging)'));
      for (var i = 0; i < cards.length; i++) {
        var box = cards[i].getBoundingClientRect();
        if (e.clientY < box.top + box.height / 2) { after = cards[i]; break; }
      }
      if (after) zone.insertBefore(dragging, after);
      else zone.appendChild(dragging);
    });

    zone.addEventListener('dragleave', function (e) {
      if (!zone.contains(e.relatedTarget)) zone.classList.remove('is-over');
    });

    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('is-over');
      if (!dragging) return;

      var data = new FormData();
      data.append('action', 'move_task');
      data.append('csrf', window.CSRF_TOKEN || '');
      data.append('task_id', dragging.dataset.id);
      data.append('column', zone.dataset.drop);
      data.append('index', String(indexOfCard(dragging)));

      say('Saving…');
      fetch('index.php?page=workflow', { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json.ok) throw new Error(json.error || 'save failed');
          document.querySelectorAll('.col').forEach(function (col) {
            var n = col.querySelectorAll('.tcard').length;
            var counter = col.querySelector('.col__count');
            if (counter) counter.textContent = String(n);
          });
          say('Saved.');
        })
        .catch(function () {
          say('Could not save that move — reload the board.', true);
        });
    });
  });

  /* -------------------------------------------------- delete a task ✕ */

  var deleteForm = document.getElementById('delete-task-form');
  var deleteId = document.getElementById('delete-task-id');
  document.querySelectorAll('[data-delete-task]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (!deleteForm || !deleteId) return;
      if (!window.confirm('Delete this task permanently?')) return;
      deleteId.value = btn.getAttribute('data-delete-task');
      deleteForm.submit();
    });
  });

  /* -------------------------------------------- confirm dialogs (any) */

  function unlock(form) {
    form.querySelectorAll('button[type=submit]').forEach(function (b) {
      if (b.dataset.label) b.textContent = b.dataset.label;
      b.disabled = false;
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
        unlock(form);
      }
    });
  });

  /* ---------------------------------- auto-submit selects + submit lock */

  document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
  });

  // One click = one request: the button locks itself while the form posts, so
  // a double click cannot create the same record twice.
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
      form.querySelectorAll('button[type=submit]').forEach(function (b) {
        b.dataset.label = b.textContent;
        b.textContent = 'Working…';
        b.disabled = true;
      });
    });
  });

  /* -------------------------------------------------- evidence dropzone */

  var zone = document.querySelector('.dropzone');
  if (zone) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.remove('is-over'); });
    });

    var input = zone.querySelector('input[type=file]');
    var name = document.getElementById('dropzone-name');
    if (input && name) {
      input.addEventListener('change', function () {
        if (input.files && input.files[0]) {
          var f = input.files[0];
          var kb = f.size > 1048576
            ? (f.size / 1048576).toFixed(1) + ' MB'
            : Math.max(1, Math.round(f.size / 1024)) + ' KB';
          name.textContent = f.name + ' · ' + kb;
        } else {
          name.textContent = 'No file selected';
        }
      });
    }
  }

  /* ------------------------------------------------- risk score widget */

  var likeSel = document.querySelector('.js-risk[data-axis="like"]');
  var impSel = document.querySelector('.js-risk[data-axis="imp"]');
  var out = document.getElementById('risk-out');
  var heat = document.getElementById('risk-heatmap');

  function rating(score) {
    if (score >= 16) return ['Critical', 'red'];
    if (score >= 10) return ['High', 'orange'];
    if (score >= 5) return ['Moderate', 'blue'];
    return ['Low', 'green'];
  }

  function refreshRisk() {
    if (!likeSel || !impSel) return;
    var l = parseInt(likeSel.value, 10) || 3;
    var i = parseInt(impSel.value, 10) || 3;
    var score = l * i;
    var r = rating(score);

    if (out) {
      out.textContent = score + ' · ' + r[0];
      out.className = 'riskscore riskscore--' + r[1];
    }
    if (heat) {
      heat.querySelectorAll('.heat__cell').forEach(function (cell) {
        var on = parseInt(cell.dataset.x, 10) === l && parseInt(cell.dataset.y, 10) === i;
        cell.classList.toggle('is-on', on);
      });
    }
  }

  if (likeSel && impSel) {
    likeSel.addEventListener('change', refreshRisk);
    impSel.addEventListener('change', refreshRisk);
    refreshRisk();
  }
})();
