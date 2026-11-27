/* SecOps CRM — board drag & drop, confirmations, upload + risk widgets.
 *
 * One IIFE, delegated listeners: the board, the delete buttons, the
 * auto-submitting selects, the editing dialog and every form are handled from
 * a few listeners on the document instead of one per element. Drag-over is
 * throttled to one layout read per animation frame. Every write path checks
 * the connection first, because a failed POST cannot be queued safely (one-shot
 * CSRF token).
 */

(function () {
  'use strict';

  var doc = document;

  /* ------------------------------------------------------- connectivity */

  var bar = null;
  var offlineAt = 0; // set when a request fails, not only when the OS says so

  function netbar(show, text) {
    if (!bar) {
      bar = doc.createElement('div');
      bar.className = 'netbar';
      bar.setAttribute('role', 'status');
      bar.innerHTML = '<span class="netbar__text"></span>'
        + '<button type="button" class="btn btn--xs netbar__retry">Try again</button>';
      bar.querySelector('.netbar__retry').addEventListener('click', function () { window.location.reload(); });
      // First child: a sticky last child would sit at the end of the document.
      doc.body.insertBefore(bar, doc.body.firstChild);
    }
    bar.querySelector('.netbar__text').textContent = text
      || 'Offline — the server is not reachable, so changes cannot be saved.';
    bar.hidden = !show;
  }

  function isOffline() {
    return navigator.onLine === false || offlineAt !== 0;
  }

  function markOffline() {
    offlineAt = Date.now();
    netbar(true);
  }

  function markOnline() {
    offlineAt = 0;
    netbar(false);
  }

  window.addEventListener('online', markOnline);
  window.addEventListener('offline', markOffline);
  netbar(isOffline());

  /* ------------------------------------------------------------- board */

  var board = doc.querySelector('.board');
  var status = doc.getElementById('board-status');
  var columns = board ? Array.prototype.slice.call(board.querySelectorAll('.col')) : [];
  var dragging = null;
  var raf = null;
  var lastY = 0;

  function say(msg, isError) {
    if (!status) return;
    status.textContent = msg;
    status.classList.toggle('is-error', !!isError);
  }

  function countCards() {
    columns.forEach(function (col) {
      var counter = col.querySelector('.col__count');
      if (counter) counter.textContent = String(col.querySelectorAll('.tcard').length);
    });
  }

  function placeCard(zone) {
    if (!dragging) return;
    var cards = zone.querySelectorAll('.tcard:not(.dragging)');
    var after = null;
    for (var i = 0; i < cards.length; i++) {
      var box = cards[i].getBoundingClientRect();
      if (lastY < box.top + box.height / 2) { after = cards[i]; break; }
    }
    if (after) zone.insertBefore(dragging, after);
    else zone.appendChild(dragging);
  }

  if (board) {
    board.addEventListener('dragstart', function (e) {
      var card = e.target.closest('.tcard');
      if (!card || card.classList.contains('tcard--static')) return;
      dragging = card;
      card.classList.add('dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', card.dataset.id || ''); } catch (err) { /* old browsers */ }
      }
    });

    board.addEventListener('dragend', function () {
      if (dragging) dragging.classList.remove('dragging');
      dragging = null;
      board.querySelectorAll('.col__body.is-over').forEach(function (zone) {
        zone.classList.remove('is-over');
      });
    });

    board.addEventListener('dragover', function (e) {
      if (!dragging) return;
      var zone = e.target.closest('.col__body');
      if (!zone) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      if (!zone.classList.contains('is-over')) {
        board.querySelectorAll('.col__body.is-over').forEach(function (other) {
          if (other !== zone) other.classList.remove('is-over');
        });
        zone.classList.add('is-over');
      }
      lastY = e.clientY;
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = null;
        placeCard(zone);
      });
    });

    board.addEventListener('dragleave', function (e) {
      var zone = e.target.closest('.col__body');
      if (zone && !zone.contains(e.relatedTarget)) zone.classList.remove('is-over');
    });

    board.addEventListener('drop', function (e) {
      var zone = e.target.closest('.col__body');
      if (!zone || !dragging) return;
      e.preventDefault();
      zone.classList.remove('is-over');

      if (isOffline()) {
        markOffline();
        say('Offline — that move was not saved.', true);
        return;
      }

      var card = dragging;
      var index = Array.prototype.indexOf.call(zone.querySelectorAll('.tcard'), card);
      var data = new FormData();
      data.append('action', 'move_task');
      data.append('csrf', window.CSRF_TOKEN || '');
      data.append('task_id', card.dataset.id || '');
      data.append('column', zone.dataset.drop || '');
      data.append('index', String(index < 0 ? 0 : index));

      say('Saving…');
      fetch('index.php?page=workflow', { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
        .then(function (json) {
          if (!json.ok) throw new Error(json.error || 'save failed');
          countCards();
          say('Saved.');
        })
        .catch(function (err) {
          say('Could not save that move — reload the board.', true);
          // A rejected fetch means the server is gone; an HTTP error does not.
          if (err instanceof TypeError) markOffline();
        });
    });
  }

  /* ------------------------------------------------ delete a task (✕) */

  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-delete-task]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    var form = doc.getElementById('delete-task-form');
    var id = doc.getElementById('delete-task-id');
    if (!form || !id) return;
    if (!window.confirm('Delete this task permanently?')) return;
    id.value = btn.getAttribute('data-delete-task') || '';
    form.submit();
  });

  /* ------------------------------------- forms: confirm, offline, lock */

  function unlock(form) {
    form.querySelectorAll('button[type=submit]').forEach(function (b) {
      if (b.dataset.label) b.textContent = b.dataset.label;
      b.disabled = false;
    });
  }

  doc.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;

    var ask = form.getAttribute('data-confirm');
    if (ask !== null && !window.confirm(ask)) {
      e.preventDefault();
      unlock(form);
      return;
    }

    // Nothing is queued: a POST carries a one-shot CSRF token, so replaying it
    // later would be refused anyway. Block it and say so.
    if (isOffline()) {
      e.preventDefault();
      markOffline();
      say('Offline — nothing was submitted. Reconnect and try again.', true);
      return;
    }

    if (e.defaultPrevented) return;
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

    // One click = one request: lock the button while the form posts.
    form.querySelectorAll('button[type=submit]').forEach(function (b) {
      b.dataset.label = b.textContent;
      b.textContent = 'Working…';
      b.disabled = true;
    });
  });

  /* ------------------------------- catalogue "Other (direct input)" fields */

  // A catalogue dropdown can end with "Other (direct input)". The companion
  // text input is disabled until that option is chosen, so a normal submit
  // never carries two locations or two vulnerability types.
  function syncOther(select) {
    var input = doc.getElementById(select.getAttribute('data-other') || '');
    if (!input) return;
    var on = select.value === '__other';
    input.hidden = !on;
    input.disabled = !on;
    return on ? input : null;
  }

  doc.querySelectorAll('select[data-other]').forEach(syncOther);

  doc.addEventListener('change', function (e) {
    var other = e.target.closest('select[data-other]');
    if (other) {
      var box = syncOther(other);
      if (box) box.focus();
    }

    var select = e.target.closest('select[data-autosubmit]');
    if (!select || !select.form) return;
    if (isOffline()) {
      markOffline();
      say('Offline — that change was not saved.', true);
      return;
    }
    select.form.submit();
  });

  /* -------------------------------------------------- evidence dropzone */

  var zone = doc.querySelector('.dropzone');
  if (zone) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.remove('is-over'); });
    });

    var input = zone.querySelector('input[type=file]');
    var name = doc.getElementById('dropzone-name');

    function bytes(n) {
      return n > 1048576
        ? (n / 1048576).toFixed(1) + ' MB'
        : Math.max(1, Math.round(n / 1024)) + ' KB';
    }

    // PHP drops files past max_file_uploads before the app sees them, so the
    // only place to say so is here, as the selection is made.
    var maxFiles = parseInt(zone.getAttribute('data-max-files') || '0', 10) || 0;

    if (input && name) {
      input.addEventListener('change', function () {
        var list = input.files ? Array.prototype.slice.call(input.files) : [];
        if (!list.length) {
          name.textContent = 'No file selected';
          return;
        }
        if (list.length === 1) {
          name.textContent = list[0].name + ' · ' + bytes(list[0].size);
          return;
        }
        var total = list.reduce(function (sum, f) { return sum + f.size; }, 0);
        name.textContent = list.length + ' files · ' + bytes(total) + ' in total'
          + (maxFiles > 0 && list.length > maxFiles
            ? ' — only the first ' + maxFiles + ' are uploaded'
            : '');
      });
    }
  }

  /* ------------------------------------------------- risk score widget */

  var likeSel = doc.querySelector('.js-risk[data-axis="like"]');
  var impSel = doc.querySelector('.js-risk[data-axis="imp"]');
  var out = doc.getElementById('risk-out');
  var heat = doc.getElementById('risk-heatmap');

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

  /* --------------------------------------- theme + density + panels */

  var root = doc.documentElement;

  function remember(key, value) {
    try { localStorage.setItem(key, value); } catch (err) { /* private mode */ }
  }

  function recall(key) {
    try { return localStorage.getItem(key); } catch (err) { return null; }
  }

  var themeBtn = doc.querySelector('[data-theme-toggle]');
  var densityBtn = doc.querySelector('[data-density-toggle]');

  function paintToggles() {
    var dark = root.dataset.theme === 'dark';
    var compact = root.dataset.density === 'compact';
    if (themeBtn) {
      themeBtn.textContent = dark ? 'Bright' : 'Dim';
      themeBtn.setAttribute('aria-pressed', dark ? 'false' : 'true');
    }
    if (densityBtn) {
      densityBtn.textContent = compact ? 'Comfort' : 'Compact';
      densityBtn.setAttribute('aria-pressed', compact ? 'true' : 'false');
    }
  }

  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      remember('secops-theme', root.dataset.theme);
      paintToggles();
    });
  }

  if (densityBtn) {
    densityBtn.addEventListener('click', function () {
      root.dataset.density = root.dataset.density === 'compact' ? 'comfortable' : 'compact';
      remember('secops-density', root.dataset.density);
      paintToggles();
    });
  }

  /* Simple / Detailed display.
   *
   * Simple is the default: the second layer of a page (filter clusters,
   * explainers, extra counters, coverage charts) sits in a closed <details>.
   * Detailed opens every block tagged data-more. */
  var detailBtn = doc.querySelector('[data-detail-toggle]');
  /* Create panels are controls, not the second layer of the page: Detailed
     must not force a form open. */
  var moreBlocks = doc.querySelectorAll('details[data-more]:not(.panel--create)');

  function isDetailed() {
    return root.dataset.detail === 'detailed';
  }

  function paintDetail() {
    if (!detailBtn) return;
    detailBtn.textContent = isDetailed() ? 'Simple view' : 'Detailed view';
    detailBtn.setAttribute('aria-pressed', isDetailed() ? 'true' : 'false');
    detailBtn.title = isDetailed()
      ? 'Hide the second layer again — one detail block per page'
      : 'Open every filter, column and explainer at once';
  }

  function applyDetail() {
    var open = isDetailed();
    moreBlocks.forEach(function (box) {
      box.open = open;
    });
  }

  if (detailBtn) {
    detailBtn.addEventListener('click', function () {
      root.dataset.detail = isDetailed() ? 'simple' : 'detailed';
      remember('secops-detail', root.dataset.detail);
      paintDetail();
      applyDetail();
    });
  }

  paintDetail();
  applyDetail();

  paintToggles();

  // Collapsible panels remember their state per browser, per panel.
  doc.querySelectorAll('details[data-collapse]').forEach(function (box) {
    var key = 'secops-open:' + box.getAttribute('data-collapse');
    var saved = recall(key);
    if (saved === '1') box.open = true;
    else if (saved === '0') box.open = false;
    box.addEventListener('toggle', function () {
      remember(key, box.open ? '1' : '0');
    });
  });

  /* ------------------------------------------------------ editing dialog */

  // A register row's Edit button opens a dialog, not a panel below the row:
  // the form sits centred over the page, so the table never moves and a long
  // form scrolls inside the dialog instead of pushing the list down. The
  // markup comes either from a panel URL (data-modal-url, fetched on demand)
  // or from a hidden block already on the page (data-modal-node), so no form
  // is duplicated and the trigger stays a plain link without JavaScript.
  var modal, modalBody, modalTitle, modalTrigger, modalNode, modalHome;

  function buildModal() {
    if (modal) return modal;

    modal = doc.createElement('div');
    modal.className = 'modal';
    modal.hidden = true;
    modal.innerHTML = '<div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="modal-title">'
      + '<header class="modal__head">'
      + '<h2 class="modal__title" id="modal-title"></h2>'
      + '<button type="button" class="modal__close" data-modal-close aria-label="Close">&times;</button>'
      + '</header>'
      + '<div class="modal__body"></div>'
      + '</div>';
    doc.body.appendChild(modal);

    modalBody = modal.querySelector('.modal__body');
    modalTitle = modal.querySelector('.modal__title');

    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('[data-modal-close]')) closeModal();
    });

    // Keep Tab inside the dialog: a modal that lets focus escape to the page
    // behind it is worse than no modal at all.
    modal.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var items = Array.prototype.filter.call(
        modal.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]),'
          + ' select:not([disabled]), textarea:not([disabled])'),
        function (el) { return el.offsetParent !== null; }
      );
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    return modal;
  }

  function focusFirstField() {
    var first = modalBody.querySelector(
      'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])'
    );
    if (first) first.focus({ preventScroll: true });
    else modal.querySelector('[data-modal-close]').focus();
  }

  function closeModal(silent) {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    doc.documentElement.classList.remove('is-modal');
    modalBody.innerHTML = '';

    // A form borrowed from the page goes back where it came from, so the row's
    // next open shows the same markup and the page is unchanged afterwards.
    if (modalNode && modalHome && modalHome.parentNode) {
      modalHome.parentNode.insertBefore(modalNode, modalHome);
      modalHome.parentNode.removeChild(modalHome);
      modalNode.hidden = true;
    }
    modalNode = modalHome = null;

    var trigger = modalTrigger;
    modalTrigger = null;
    if (!silent && trigger) trigger.focus();
  }

  function openModal(trigger) {
    buildModal();
    closeModal(true);

    modalTrigger = trigger;
    modalTitle.textContent = trigger.getAttribute('data-modal-title') || 'Edit';
    modal.hidden = false;
    doc.documentElement.classList.add('is-modal');
    modal.classList.remove('modal--wide', 'modal--drawer');
    if (trigger.hasAttribute('data-modal-drawer')) modal.classList.add('modal--drawer');

    var nodeId = trigger.getAttribute('data-modal-node');
    var url = trigger.getAttribute('data-modal-url');

    if (nodeId) {
      var src = doc.getElementById(nodeId);
      if (!src) { closeModal(); return; }
      modalNode = src;
      modalHome = doc.createComment('modal-node');
      src.parentNode.insertBefore(modalHome, src);
      src.hidden = false;
      modalBody.appendChild(src);
      if (!modal.classList.contains('modal--drawer')) modal.classList.add('modal--wide');
      focusFirstField();
      return;
    }

    modalBody.innerHTML = '<p class="muted">Loading the form…</p>';

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status)); })
      .then(function (html) {
        if (modalTrigger !== trigger) return; // closed while it was loading
        modalBody.innerHTML = html;
        focusFirstField();
      })
      .catch(function (err) {
        if (modalTrigger !== trigger) return;
        modalBody.innerHTML = '<p class="muted">Could not load that form. '
          + '<a class="link" href="' + url + '">Open it on its own page</a>, or reload and try again.</p>';
        if (err instanceof TypeError) markOffline();
      });
  }

  doc.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-modal-url], [data-modal-node]');
    if (!trigger) return;
    e.preventDefault();
    openModal(trigger);
  });

  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hidden) {
      e.preventDefault();
      closeModal();
    }
  });
})();
