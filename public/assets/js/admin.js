/* ==========================================================================
   Krishna Printer — admin panel
   Dependency-free. Adds the chart hover layer, confirmation guards, and the
   async actions (printer test, capability probe, update check/run).
   ========================================================================== */

(function () {
  'use strict';

  var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function post(url, body) {
    var isForm = body instanceof FormData;
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken };
    if (!isForm) { headers['Content-Type'] = 'application/json'; }

    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: isForm ? body : JSON.stringify(body || {})
    }).then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  // ------------------------------------------------------- chart hover layer

  // An HTML chart should be interrogable: hovering a day tells you its exact
  // value rather than making you estimate it against the axis.
  function bindCharts() {
    document.querySelectorAll('.a-chart').forEach(function (container) {
      var svg = container.querySelector('.js-chart');
      if (!svg) { return; }

      var hits = svg.querySelectorAll('.js-hit');
      if (!hits.length) { return; }

      var tip = document.createElement('div');
      tip.className = 'a-chart__tip';
      container.appendChild(tip);

      var cursor = document.createElement('div');
      cursor.className = 'a-chart__cursor';
      container.appendChild(cursor);

      var isArea = svg.getAttribute('data-chart') === 'area';

      function position(hit) {
        var box = svg.getBoundingClientRect();
        var viewBox = svg.viewBox.baseVal;
        var scaleX = box.width / viewBox.width;
        var scaleY = box.height / viewBox.height;

        var x = parseFloat(hit.getAttribute('data-x')) * scaleX;
        var y = parseFloat(hit.getAttribute('data-y')) * scaleY;

        tip.innerHTML = '<strong>' + hit.getAttribute('data-value') + '</strong>' +
          hit.getAttribute('data-label');

        // Keep the tooltip inside the panel at the edges.
        var clampedX = Math.max(48, Math.min(box.width - 48, x));
        tip.style.left = clampedX + 'px';
        tip.style.top = y + 'px';
        tip.classList.add('is-visible');

        if (isArea) {
          cursor.style.left = x + 'px';
          cursor.style.top = y + 'px';
          cursor.classList.add('is-visible');
        }
      }

      function hide() {
        tip.classList.remove('is-visible');
        cursor.classList.remove('is-visible');
      }

      hits.forEach(function (hit) {
        hit.addEventListener('mouseenter', function () { position(hit); });
        hit.addEventListener('touchstart', function (e) { position(hit); }, { passive: true });
      });

      svg.addEventListener('mouseleave', hide);
      container.addEventListener('touchend', function () { setTimeout(hide, 2500); });
    });
  }

  // ------------------------------------------------------------ confirmation

  // Destructive actions confirm before firing. Anything with
  // data-confirm-phrase requires the phrase typed exactly — used for the
  // actions that overwrite the database or swap the running code.
  function bindConfirmations() {
    document.querySelectorAll('[data-confirm]').forEach(function (element) {
      element.addEventListener('click', function (event) {
        var message = element.getAttribute('data-confirm');
        var phrase = element.getAttribute('data-confirm-phrase');

        if (phrase) {
          var typed = window.prompt(message + '\n\nType ' + phrase + ' to confirm:');
          if (typed !== phrase) {
            event.preventDefault();
            return false;
          }
          var input = element.form && element.form.querySelector('[name="confirm"]');
          if (input) { input.value = phrase; }
          return true;
        }

        if (!window.confirm(message)) {
          event.preventDefault();
          return false;
        }
        return true;
      });
    });
  }

  // ------------------------------------------------------------- copy buttons

  function bindCopy() {
    document.querySelectorAll('[data-copy]').forEach(function (button) {
      button.addEventListener('click', function () {
        var target = document.querySelector(button.getAttribute('data-copy'));
        if (!target) { return; }

        var text = target.textContent.trim();
        var original = button.textContent;

        function done() {
          button.textContent = 'Copied';
          setTimeout(function () { button.textContent = original; }, 1600);
        }

        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done).catch(fallback);
        } else {
          fallback();
        }

        function fallback() {
          // execCommand is deprecated but is the only option over plain HTTP,
          // which a first-run install may still be on.
          var field = document.createElement('textarea');
          field.value = text;
          field.style.position = 'fixed';
          field.style.opacity = '0';
          document.body.appendChild(field);
          field.select();
          try { document.execCommand('copy'); done(); } catch (e) { /* nothing to do */ }
          document.body.removeChild(field);
        }
      });
    });
  }

  // --------------------------------------------------------- printer actions

  function bindPrinterActions() {
    var testBtn = document.getElementById('testPrinterBtn');
    var probeBtn = document.getElementById('probeCapabilitiesBtn');
    var result = document.getElementById('printerActionResult');

    function show(tone, html) {
      if (!result) { return; }
      result.className = 'a-alert a-alert--' + tone;
      result.innerHTML = html;
      result.hidden = false;
    }

    if (testBtn) {
      testBtn.addEventListener('click', function () {
        var id = testBtn.getAttribute('data-printer');
        testBtn.disabled = true;
        testBtn.textContent = 'Testing…';
        show('info', 'Contacting the printer…');

        post('/admin/printers/' + id + '/test')
          .then(function (r) {
            var d = r.data;
            var tone = d.usable ? 'success' : (d.status === 'online' ? 'warning' : 'danger');
            var html = '<strong>' + (d.status || 'unknown').toUpperCase() + '</strong>' +
              escapeHtml(d.message || '');

            if (d.latency_ms != null) { html += '<br>Responded in ' + d.latency_ms + ' ms.'; }
            if (d.driver) { html += '<br>Transport: ' + escapeHtml(d.driver) + '.'; }
            if (d.state_reasons && d.state_reasons.length) {
              html += '<br>Printer reports: ' + escapeHtml(d.state_reasons.join(', ')) + '.';
            }
            if (d.status === 'online' && !d.usable) {
              html += '<br><em>Reachable, but not able to print right now — customers will not be offered it.</em>';
            }
            show(tone, html);
          })
          .catch(function () { show('danger', 'The test could not be run.'); })
          .finally(function () {
            testBtn.disabled = false;
            testBtn.textContent = 'Test connection';
          });
      });
    }

    if (probeBtn) {
      probeBtn.addEventListener('click', function () {
        var id = probeBtn.getAttribute('data-printer');
        probeBtn.disabled = true;
        probeBtn.textContent = 'Probing…';
        show('info', 'Asking the printer what it supports…');

        post('/admin/printers/' + id + '/probe')
          .then(function (r) {
            var d = r.data;
            if (!d.success) {
              show('warning', '<strong>Could not read capabilities</strong>' + escapeHtml(d.message || '') +
                '<br>You can still confirm each option by hand after a test print.');
              return;
            }
            var caps = d.capabilities || {};
            var html = '<strong>' + escapeHtml(d.message) + '</strong>';
            if (caps.paper_sizes) { html += 'Paper: ' + escapeHtml(caps.paper_sizes.join(', ')) + '<br>'; }
            if (caps.color_modes) { html += 'Colour modes: ' + escapeHtml(caps.color_modes.join(', ')) + '<br>'; }
            if (caps.duplex_modes) { html += 'Sides: ' + escapeHtml(caps.duplex_modes.join(', ')); }
            html += '<br><em>Reloading to show the updated matrix…</em>';
            show('success', html);
            setTimeout(function () { window.location.reload(); }, 2200);
          })
          .catch(function () { show('danger', 'The probe could not be run.'); })
          .finally(function () {
            probeBtn.disabled = false;
            probeBtn.textContent = 'Probe capabilities';
          });
      });
    }
  }

  // ---------------------------------------------------------- system update

  function bindSystemUpdate() {
    var checkBtn = document.getElementById('checkUpdateBtn');
    var runBtn = document.getElementById('runUpdateBtn');
    var panel = document.getElementById('updatePanel');
    var steps = document.getElementById('updateSteps');

    function panelHtml(tone, html) {
      if (!panel) { return; }
      panel.className = 'a-alert a-alert--' + tone;
      panel.innerHTML = html;
      panel.hidden = false;
    }

    if (checkBtn) {
      checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true;
        checkBtn.textContent = 'Checking…';
        panelHtml('info', 'Contacting GitHub…');

        post('/admin/system/check-update')
          .then(function (r) {
            var d = r.data;
            if (!d.success) {
              panelHtml('danger', '<strong>Check failed</strong>' + escapeHtml(d.error || ''));
              return;
            }
            if (!d.update_available) {
              panelHtml('success', '<strong>Up to date</strong>Running ' +
                escapeHtml(d.current.short_sha) + ' on ' + escapeHtml(d.branch) + '.');
              if (runBtn) { runBtn.hidden = true; }
              return;
            }

            var html = '<strong>Update available</strong>' +
              '<div class="a-kv" style="margin-top:.5rem">' +
              '<dt>Version</dt><dd>' + escapeHtml(d.current.short_sha) + ' → ' + escapeHtml(d.latest.short_sha) + '</dd>' +
              '<dt>Commit</dt><dd>' + escapeHtml((d.latest.message || '').split('\n')[0]) + '</dd>' +
              '<dt>Author</dt><dd>' + escapeHtml(d.latest.author) + '</dd>' +
              '<dt>Date</dt><dd>' + escapeHtml(d.latest.date) + '</dd>' +
              '<dt>Commits behind</dt><dd>' + d.commits_behind + '</dd>' +
              '<dt>Files changed</dt><dd>' + d.changed_file_count + '</dd>' +
              '</div>';

            if (d.touches_migrations) {
              html += '<p style="margin:.6rem 0 0"><strong>This update includes database migrations.</strong> ' +
                'A database backup is taken automatically before they run.</p>';
            }

            if (d.changed_files && d.changed_files.length) {
              html += '<details style="margin-top:.5rem"><summary>Changed files</summary><ul class="a-small">';
              d.changed_files.slice(0, 60).forEach(function (f) {
                html += '<li>' + escapeHtml(f.status) + ' — ' + escapeHtml(f.filename) + '</li>';
              });
              if (d.changed_file_count > 60) {
                html += '<li>… and ' + (d.changed_file_count - 60) + ' more</li>';
              }
              html += '</ul></details>';
            }

            panelHtml('warning', html);
            if (runBtn) { runBtn.hidden = false; }
          })
          .catch(function () { panelHtml('danger', 'Could not reach the server.'); })
          .finally(function () {
            checkBtn.disabled = false;
            checkBtn.textContent = 'Check for update';
          });
      });
    }

    if (runBtn) {
      runBtn.addEventListener('click', function () {
        var typed = window.prompt(
          'This will back up, download, swap the application files and run migrations.\n' +
          'The site enters maintenance mode while it runs, and rolls back automatically if any step fails.\n\n' +
          'Type UPDATE to confirm:'
        );
        if (typed !== 'UPDATE') { return; }

        runBtn.disabled = true;
        runBtn.textContent = 'Updating…';
        if (steps) {
          steps.hidden = false;
          steps.innerHTML = '<li class="a-step-row"><span class="a-step-row__icon a-step-row__icon--running">•</span>' +
            '<span class="a-step-row__body"><span class="a-step-row__label">Update in progress</span>' +
            '<span class="a-step-row__msg">Do not close this page or navigate away.</span></span></li>';
        }

        post('/admin/system/update', { confirm: 'UPDATE' })
          .then(function (r) {
            var d = r.data;
            if (steps) { steps.innerHTML = renderSteps(d.steps || []); }

            if (d.success) {
              panelHtml('success', '<strong>Update complete</strong>' + escapeHtml(d.message) +
                '<br>Reloading…');
              setTimeout(function () { window.location.reload(); }, 2500);
            } else {
              panelHtml('danger', '<strong>Update failed' + (d.rolled_back ? ' — rolled back' : '') + '</strong>' +
                escapeHtml(d.message) +
                (d.rolled_back
                  ? '<br>The previous version was restored and the site is live again.'
                  : '<br>Check the step trace below and the update log.'));
              runBtn.disabled = false;
              runBtn.textContent = 'Retry update';
            }
          })
          .catch(function () {
            panelHtml('danger',
              '<strong>The connection dropped during the update</strong>' +
              'The update continues on the server and rolls back on failure. ' +
              'Reload this page in a minute to see the result.');
            runBtn.disabled = false;
            runBtn.textContent = 'Update now';
          });
      });
    }

    function renderSteps(list) {
      return list.map(function (step) {
        var icon = step.status === 'ok' ? 'ok' : (step.status === 'failed' ? 'failed' : 'running');
        var glyph = step.status === 'ok' ? '✓' : (step.status === 'failed' ? '!' : '•');
        return '<li class="a-step-row">' +
          '<span class="a-step-row__icon a-step-row__icon--' + icon + '">' + glyph + '</span>' +
          '<span class="a-step-row__body">' +
            '<span class="a-step-row__label">' + escapeHtml(step.label || step.key) + '</span>' +
            '<span class="a-step-row__msg">' + escapeHtml(step.message || '') + '</span>' +
          '</span>' +
          (step.duration_ms != null
            ? '<span class="a-step-row__time">' + step.duration_ms + ' ms</span>'
            : '') +
          '</li>';
      }).join('');
    }
  }

  // -------------------------------------------------------- pricing preview

  function bindPricingPreview() {
    var form = document.getElementById('pricePreviewForm');
    var output = document.getElementById('pricePreviewResult');
    if (!form || !output) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var body = new FormData(form);
      body.append('_csrf_token', csrfToken);

      output.innerHTML = '<p class="a-muted a-small">Calculating…</p>';

      post('/admin/pricing/preview', body)
        .then(function (r) {
          var p = r.data.pricing;
          if (!r.data.success || !p || !p.priced) {
            output.innerHTML = '<div class="a-alert a-alert--warning">' +
              escapeHtml((p && p.error) || r.data.error || 'No price is configured for that combination.') +
              '</div>';
            return;
          }

          output.innerHTML =
            '<div class="a-alert a-alert--success">' +
              '<strong>' + escapeHtml(p.total_display) + '</strong>' +
              escapeHtml(p.explanation) +
              '<div class="a-kv" style="margin-top:.6rem">' +
                '<dt>Rule</dt><dd>' + escapeHtml(p.rule_name) + ' (' + escapeHtml(p.rule_scope) + ')</dd>' +
                '<dt>Pages billed</dt><dd>' + p.billable_pages + '</dd>' +
                '<dt>Sheets</dt><dd>' + p.sheets + '</dd>' +
                '<dt>Subtotal</dt><dd>' + escapeHtml(p.subtotal) + '</dd>' +
                (p.tax_paise > 0 ? '<dt>' + escapeHtml(p.tax_label) + '</dt><dd>' + escapeHtml(p.tax) + '</dd>' : '') +
                (p.gateway_fee_paise > 0 ? '<dt>Gateway fee</dt><dd>' + escapeHtml(p.gateway_fee) + '</dd>' : '') +
              '</div>' +
            '</div>';
        })
        .catch(function () {
          output.innerHTML = '<div class="a-alert a-alert--danger">Could not reach the server.</div>';
        });
    });
  }

  // ----------------------------------------------------------------- helpers

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Filter forms submit on change, so an operator does not hunt for a button.
  function bindAutoSubmit() {
    document.querySelectorAll('[data-auto-submit] select, [data-auto-submit] input[type="date"]')
      .forEach(function (field) {
        field.addEventListener('change', function () {
          if (field.form) { field.form.submit(); }
        });
      });
  }

  bindCharts();
  bindConfirmations();
  bindCopy();
  bindPrinterActions();
  bindSystemUpdate();
  bindPricingPreview();
  bindAutoSubmit();
})();
