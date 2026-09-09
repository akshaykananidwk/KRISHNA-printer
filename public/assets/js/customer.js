/* ==========================================================================
   Krishna Printer — customer flow
   Dependency-free. Drives upload → options → live price → submit.

   Two rules this file follows without exception:
     1. It never computes a price. Every figure shown comes from the server's
        /print/quote response, so the amount on screen is the amount charged.
     2. It never assumes the printer is up. A background poll blocks the flow
        the moment the printer stops being usable.
   ========================================================================== */

(function () {
  'use strict';

  var config = window.KPMS || {};
  var files = [];        // { id, name, size_bytes, pages, page_source, options }
  var quote = null;
  var quoteTimer = null;
  var printerOnline = true;

  var el = {
    dropZone: document.getElementById('dropZone'),
    fileInput: document.getElementById('fileInput'),
    fileList: document.getElementById('fileList'),
    progress: document.getElementById('uploadProgress'),
    progressBar: document.getElementById('uploadBar'),
    progressText: document.getElementById('uploadText'),
    stepOptions: document.getElementById('stepOptions'),
    optionCards: document.getElementById('optionCards'),
    stepPrice: document.getElementById('stepPrice'),
    priceSummary: document.getElementById('priceSummary'),
    submitBtn: document.getElementById('submitBtn'),
    submitHelp: document.getElementById('submitHelp'),
    errorBanner: document.getElementById('errorBanner'),
    printerChip: document.getElementById('printerChip')
  };

  // ---------------------------------------------------------------- utils

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function humanBytes(bytes) {
    if (bytes < 1024) { return bytes + ' B'; }
    var units = ['KB', 'MB', 'GB'];
    var value = bytes / 1024;
    var i = 0;
    while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
    return (value < 10 ? value.toFixed(1) : Math.round(value)) + ' ' + units[i];
  }

  function showError(message) {
    el.errorBanner.textContent = message;
    el.errorBanner.hidden = false;
    el.errorBanner.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function clearError() {
    el.errorBanner.hidden = true;
    el.errorBanner.textContent = '';
  }

  function post(url, body, isForm) {
    var headers = {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': config.csrfToken
    };
    if (!isForm) { headers['Content-Type'] = 'application/json'; }

    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: isForm ? body : JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  // ---------------------------------------------------------------- upload

  function handleFiles(fileList) {
    clearError();

    if (!printerOnline) {
      showError('The printer is not available right now, so files cannot be uploaded.');
      return;
    }

    var selected = Array.prototype.slice.call(fileList);
    if (!selected.length) { return; }

    if (files.length + selected.length > config.maxFiles) {
      showError('You can print up to ' + config.maxFiles + ' documents in one order.');
      return;
    }

    // Filter client-side for immediate feedback. The server checks again —
    // this only saves the customer a pointless upload on a slow connection.
    var accepted = [];
    for (var i = 0; i < selected.length; i++) {
      var file = selected[i];
      var extension = (file.name.split('.').pop() || '').toLowerCase();

      if (config.allowedExtensions.indexOf(extension) === -1) {
        showError('"' + file.name + '" is not a supported file type.');
        continue;
      }
      if (file.size > config.maxFileBytes) {
        showError('"' + file.name + '" is ' + humanBytes(file.size) + ', over the '
          + humanBytes(config.maxFileBytes) + ' limit.');
        continue;
      }
      if (file.size === 0) {
        showError('"' + file.name + '" is empty.');
        continue;
      }
      accepted.push(file);
    }

    if (!accepted.length) { return; }

    var form = new FormData();
    accepted.forEach(function (file) { form.append('files[]', file); });
    form.append('_csrf_token', config.csrfToken);

    uploadWithProgress(form, accepted.length);
  }

  function uploadWithProgress(form, count) {
    el.progress.hidden = false;
    el.progressBar.style.width = '0%';
    el.progressText.textContent = 'Uploading ' + count + ' file' + (count === 1 ? '' : 's') + '…';

    var request = new XMLHttpRequest();
    request.open('POST', '/print/upload');
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.setRequestHeader('X-CSRF-Token', config.csrfToken);

    request.upload.addEventListener('progress', function (event) {
      if (event.lengthComputable) {
        var percent = Math.round((event.loaded / event.total) * 100);
        el.progressBar.style.width = percent + '%';
        if (percent >= 100) { el.progressText.textContent = 'Checking your file…'; }
      }
    });

    request.addEventListener('load', function () {
      el.progress.hidden = true;

      var data;
      try { data = JSON.parse(request.responseText); }
      catch (e) { showError('The upload failed. Please try again.'); return; }

      if (!data.success) {
        showError(data.error || 'The upload failed.');
        if (data.printer_unavailable) { markPrinterOffline(data.error); }
        return;
      }

      (data.files || []).forEach(function (file) {
        files.push({
          id: file.id,
          name: file.name,
          size_bytes: file.size_bytes,
          pages: file.pages,
          page_source: file.page_source,
          page_detail: file.page_detail,
          options: defaultOptions()
        });
      });

      (data.errors || []).forEach(function (message) { showError(message); });
      (data.warnings || []).forEach(function (message) { addNotice(message); });

      renderFiles();
      renderOptions();
      requestQuote();
    });

    request.addEventListener('error', function () {
      el.progress.hidden = true;
      showError('The upload could not be completed. Check your connection and try again.');
    });

    request.send(form);
  }

  function defaultOptions() {
    var defaults = config.options.defaults || {};
    return {
      copies: 1,
      color_mode: defaults.color_mode || 'bw',
      paper_size: defaults.paper_size || 'A4',
      orientation: defaults.orientation || 'portrait',
      duplex: defaults.duplex || 'single',
      page_range: 'all',
      custom_range: ''
    };
  }

  function removeFile(fileId) {
    var body = new FormData();
    body.append('_method', 'DELETE');
    body.append('_csrf_token', config.csrfToken);

    fetch('/print/upload/' + encodeURIComponent(fileId), {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': config.csrfToken },
      body: body
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) { showError(data.error || 'That file could not be removed.'); return; }
        files = files.filter(function (f) { return f.id !== fileId; });
        renderFiles();
        renderOptions();
        requestQuote();
      })
      .catch(function () { showError('That file could not be removed.'); });
  }

  // ---------------------------------------------------------------- render

  function renderFiles() {
    el.fileList.innerHTML = '';

    files.forEach(function (file) {
      var item = document.createElement('li');
      item.className = 'c-file';

      var extension = (file.name.split('.').pop() || '?').toUpperCase().slice(0, 4);
      var pagesText = file.pages + ' page' + (file.pages === 1 ? '' : 's');
      if (file.page_source === 'estimated') { pagesText = '~' + pagesText + ' (estimated)'; }

      item.innerHTML =
        '<span class="c-file__icon">' + escapeHtml(extension) + '</span>' +
        '<span class="c-file__body">' +
          '<span class="c-file__name">' + escapeHtml(file.name) + '</span>' +
          '<span class="c-file__meta">' + escapeHtml(pagesText) + ' · ' + humanBytes(file.size_bytes) + '</span>' +
          (file.page_source === 'estimated'
            ? '<span class="c-file__note">Page count is an estimate; you are billed on the exact count.</span>'
            : '') +
        '</span>';

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'c-file__remove';
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.textContent = '×';
      remove.addEventListener('click', function () { removeFile(file.id); });

      item.appendChild(remove);
      el.fileList.appendChild(item);
    });
  }

  function choiceGroup(name, fileId, values, current, disabledValues) {
    var html = '<div class="c-choices">';
    values.forEach(function (choice) {
      var disabled = disabledValues && disabledValues.indexOf(choice.value) !== -1;
      var id = name + '-' + fileId + '-' + choice.value;
      html +=
        '<label class="c-choice">' +
          '<input type="radio" name="' + escapeHtml(name + '-' + fileId) + '"' +
            ' id="' + escapeHtml(id) + '"' +
            ' value="' + escapeHtml(choice.value) + '"' +
            ' data-file="' + escapeHtml(fileId) + '"' +
            ' data-field="' + escapeHtml(name) + '"' +
            (current === choice.value ? ' checked' : '') +
            (disabled ? ' disabled' : '') + '>' +
          '<span>' + escapeHtml(choice.label) + '</span>' +
        '</label>';
    });
    return html + '</div>';
  }

  function renderOptions() {
    if (!files.length) {
      el.stepOptions.hidden = true;
      el.stepPrice.hidden = true;
      return;
    }

    el.stepOptions.hidden = false;
    el.optionCards.innerHTML = '';

    var options = config.options;

    files.forEach(function (file) {
      var card = document.createElement('div');
      card.className = 'c-opt';

      // Duplex is only offered on paper sizes the printer is verified to
      // support it on — the profile records that restriction.
      var duplexAllowed = options.duplex_paper_sizes || [];
      var duplexDisabled = duplexAllowed.indexOf(file.options.paper_size) === -1 ? ['double'] : [];

      var copyChoices = config.copyPresets.map(function (n) {
        return { value: String(n), label: String(n) };
      });
      copyChoices.push({ value: 'custom', label: 'Other' });

      var currentCopies = config.copyPresets.indexOf(file.options.copies) !== -1
        ? String(file.options.copies)
        : 'custom';

      var pagesText = file.pages + ' page' + (file.pages === 1 ? '' : 's');

      var html =
        '<p class="c-opt__file">' + escapeHtml(file.name) + '</p>' +
        '<p class="c-opt__pages">' + escapeHtml(pagesText) +
          (file.page_source === 'estimated' ? ' (estimated)' : '') + '</p>';

      html += '<div class="c-field"><span class="c-field__label">Copies</span>' +
        choiceGroup('copies', file.id, copyChoices, currentCopies) +
        '<input type="number" class="c-input" style="margin-top:.5rem"' +
          ' data-file="' + file.id + '" data-field="copies_custom"' +
          ' min="1" max="' + config.maxCopies + '" value="' + file.options.copies + '"' +
          ' aria-label="Number of copies"' +
          (currentCopies === 'custom' ? '' : ' hidden') + '>' +
        '</div>';

      if (options.color_modes.length > 1) {
        html += '<div class="c-field"><span class="c-field__label">Colour</span>' +
          choiceGroup('color_mode', file.id, options.color_modes, file.options.color_mode) + '</div>';
      } else if (options.color_modes.length === 1) {
        html += '<div class="c-field"><span class="c-field__label">Colour</span>' +
          '<p class="c-field__hint">This printer prints ' +
          escapeHtml(options.color_modes[0].label.toLowerCase()) + ' only.</p></div>';
      }

      if (options.paper_sizes.length > 1) {
        html += '<div class="c-field"><span class="c-field__label">Paper size</span>' +
          choiceGroup('paper_size', file.id, options.paper_sizes, file.options.paper_size) + '</div>';
      } else if (options.paper_sizes.length === 1) {
        html += '<div class="c-field"><span class="c-field__label">Paper size</span>' +
          '<p class="c-field__hint">' + escapeHtml(options.paper_sizes[0].label) + '</p></div>';
      }

      if (options.orientations.length > 1) {
        html += '<div class="c-field"><span class="c-field__label">Orientation</span>' +
          choiceGroup('orientation', file.id, options.orientations, file.options.orientation) + '</div>';
      }

      if (options.duplex_modes.length > 1) {
        html += '<div class="c-field"><span class="c-field__label">Sides</span>' +
          choiceGroup('duplex', file.id, options.duplex_modes, file.options.duplex, duplexDisabled);
        if (duplexDisabled.length) {
          html += '<p class="c-field__hint">Double-sided is not available on ' +
            escapeHtml(file.options.paper_size) + ' on this printer.</p>';
        }
        html += '</div>';
      }

      html += '<div class="c-field"><span class="c-field__label">Pages</span>' +
        choiceGroup('page_range', file.id, [
          { value: 'all', label: 'All pages' },
          { value: 'custom', label: 'Choose pages' }
        ], file.options.page_range === 'all' ? 'all' : 'custom') +
        '<input type="text" class="c-input" style="margin-top:.5rem"' +
          ' data-file="' + file.id + '" data-field="custom_range"' +
          ' placeholder="e.g. 1-5, 8-10"' +
          ' value="' + escapeHtml(file.options.custom_range) + '"' +
          ' inputmode="numeric" aria-label="Page range"' +
          (file.options.page_range === 'all' ? ' hidden' : '') + '>' +
        '<p class="c-field__hint"' + (file.options.page_range === 'all' ? ' hidden' : '') +
          ' data-hint="' + file.id + '">Separate ranges with commas, for example 1-5, 8-10.</p>' +
        '</div>';

      card.innerHTML = html;
      el.optionCards.appendChild(card);
    });

    bindOptionEvents();
  }

  function bindOptionEvents() {
    el.optionCards.querySelectorAll('input[type="radio"]').forEach(function (input) {
      input.addEventListener('change', function () {
        var file = files.filter(function (f) { return String(f.id) === input.dataset.file; })[0];
        if (!file) { return; }

        var field = input.dataset.field;
        var value = input.value;

        if (field === 'copies') {
          var customInput = el.optionCards.querySelector(
            '[data-file="' + file.id + '"][data-field="copies_custom"]'
          );
          if (value === 'custom') {
            if (customInput) { customInput.hidden = false; customInput.focus(); }
          } else {
            file.options.copies = parseInt(value, 10);
            if (customInput) { customInput.hidden = true; customInput.value = file.options.copies; }
          }
        } else if (field === 'page_range') {
          var rangeInput = el.optionCards.querySelector(
            '[data-file="' + file.id + '"][data-field="custom_range"]'
          );
          var hint = el.optionCards.querySelector('[data-hint="' + file.id + '"]');
          if (value === 'custom') {
            if (rangeInput) { rangeInput.hidden = false; rangeInput.focus(); }
            if (hint) { hint.hidden = false; }
            file.options.page_range = file.options.custom_range || '';
          } else {
            file.options.page_range = 'all';
            if (rangeInput) { rangeInput.hidden = true; }
            if (hint) { hint.hidden = true; }
          }
        } else {
          file.options[field] = value;
          // Changing paper size can invalidate a duplex choice, so re-render.
          if (field === 'paper_size') { renderOptions(); }
        }

        requestQuote();
      });
    });

    el.optionCards.querySelectorAll('input[type="number"], input[type="text"]').forEach(function (input) {
      input.addEventListener('input', function () {
        var file = files.filter(function (f) { return String(f.id) === input.dataset.file; })[0];
        if (!file) { return; }

        if (input.dataset.field === 'copies_custom') {
          var copies = parseInt(input.value, 10);
          if (!isNaN(copies)) {
            file.options.copies = Math.max(1, Math.min(config.maxCopies, copies));
          }
        } else if (input.dataset.field === 'custom_range') {
          file.options.custom_range = input.value;
          file.options.page_range = input.value.trim() === '' ? 'all' : input.value.trim();
        }

        requestQuote();
      });
    });
  }

  // ---------------------------------------------------------------- pricing

  function requestQuote() {
    if (!files.length) {
      el.stepPrice.hidden = true;
      el.submitBtn.disabled = true;
      quote = null;
      return;
    }

    // Debounced: typing a page range should not fire a request per keystroke.
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(function () {
      var items = files.map(function (file) {
        return {
          file_id: file.id,
          copies: file.options.copies,
          color_mode: file.options.color_mode,
          paper_size: file.options.paper_size,
          orientation: file.options.orientation,
          duplex: file.options.duplex,
          page_range: file.options.page_range
        };
      });

      el.stepPrice.hidden = false;
      el.priceSummary.innerHTML = '<p class="c-summary__sub">Calculating…</p>';
      el.submitBtn.disabled = true;

      post('/print/quote', { items: items })
        .then(function (result) {
          if (!result.data.success) {
            quote = null;
            el.priceSummary.innerHTML =
              '<p class="c-field__error">' + escapeHtml(result.data.error || 'This order could not be priced.') + '</p>';
            el.submitBtn.disabled = true;
            return;
          }

          quote = result.data;
          renderQuote(result.data);
          el.submitBtn.disabled = !printerOnline;
        })
        .catch(function () {
          el.priceSummary.innerHTML =
            '<p class="c-field__error">Could not reach the server to calculate the price.</p>';
        });
    }, 350);
  }

  function renderQuote(data) {
    var totals = data.totals;
    var symbol = config.currencySymbol;
    var html = '';

    data.items.forEach(function (item) {
      var meta = item.selected_pages + ' page' + (item.selected_pages === 1 ? '' : 's') +
        ' × ' + item.copies + ' cop' + (item.copies === 1 ? 'y' : 'ies') +
        ' · ' + (item.color_mode === 'color' ? 'Colour' : 'B&W') +
        ' · ' + item.paper_size +
        ' · ' + (item.duplex === 'double' ? 'Double' : 'Single') + '-sided';

      html +=
        '<div class="c-summary__line">' +
          '<span class="c-summary__label">' +
            escapeHtml(item.file_name || 'Document') +
            '<br><span class="c-summary__sub">' + escapeHtml(meta) + '</span>' +
          '</span>' +
          '<span class="c-summary__value">' + escapeHtml(symbol + item.subtotal) + '</span>' +
        '</div>';
    });

    if (totals.tax_paise > 0) {
      var taxLabel = (data.items[0] && data.items[0].tax_label) || 'Tax';
      html += '<div class="c-summary__line">' +
        '<span class="c-summary__label">' + escapeHtml(taxLabel) + '</span>' +
        '<span class="c-summary__value">' + escapeHtml(symbol + totals.tax) + '</span></div>';
    }

    if (totals.gateway_fee_paise > 0) {
      html += '<div class="c-summary__line">' +
        '<span class="c-summary__label">Payment charges</span>' +
        '<span class="c-summary__value">' + escapeHtml(symbol + totals.gateway_fee) + '</span></div>';
    }

    html += '<div class="c-summary__total"><span>Grand total</span><strong>' +
      escapeHtml(totals.total_display) + '</strong></div>';

    el.priceSummary.innerHTML = html;

    el.submitBtn.textContent = data.payment_required
      ? 'Pay ' + totals.total_display
      : 'Send to printer';
  }

  // ---------------------------------------------------------------- submit

  function submitOrder() {
    if (!quote || !files.length) { return; }

    clearError();
    el.submitBtn.disabled = true;
    el.submitBtn.textContent = 'Submitting…';

    var items = files.map(function (file) {
      return {
        file_id: file.id,
        copies: file.options.copies,
        color_mode: file.options.color_mode,
        paper_size: file.options.paper_size,
        orientation: file.options.orientation,
        duplex: file.options.duplex,
        page_range: file.options.page_range
      };
    });

    post('/print/submit', { items: items })
      .then(function (result) {
        if (!result.data.success) {
          showError(result.data.error || 'Your order could not be submitted.');
          if (result.data.printer_unavailable) { markPrinterOffline(result.data.error); }
          el.submitBtn.disabled = false;
          renderQuote(quote);
          return;
        }
        window.location.href = result.data.redirect;
      })
      .catch(function () {
        showError('Could not reach the server. Please check your connection and try again.');
        el.submitBtn.disabled = false;
        renderQuote(quote);
      });
  }

  // ------------------------------------------------------- printer watchdog

  function markPrinterOffline(reason) {
    printerOnline = false;
    el.submitBtn.disabled = true;

    if (el.printerChip) {
      el.printerChip.classList.add('is-offline');
      el.printerChip.querySelector('.c-printer-chip__text').innerHTML =
        '<strong>Printer unavailable</strong>';
    }
    if (el.fileInput) { el.fileInput.disabled = true; }

    showError(reason || 'Printing Service Currently Unavailable. Please try again later.');
  }

  function markPrinterOnline() {
    if (printerOnline) { return; }
    printerOnline = true;

    if (el.printerChip) {
      el.printerChip.classList.remove('is-offline');
      el.printerChip.querySelector('.c-printer-chip__text').innerHTML =
        '<strong>Printer</strong> is back online';
    }
    if (el.fileInput) { el.fileInput.disabled = false; }
    if (quote) { el.submitBtn.disabled = false; }
    clearError();
  }

  function watchPrinter() {
    setInterval(function () {
      fetch('/print/status/' + encodeURIComponent(config.printerId), {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.success) { return; }
          if (data.available) { markPrinterOnline(); }
          else if (printerOnline) { markPrinterOffline(data.reason); }
        })
        .catch(function () { /* transient network trouble; do not alarm the customer */ });
    }, 20000);
  }

  function addNotice(message) {
    var notice = document.createElement('p');
    notice.className = 'c-help';
    notice.textContent = message;
    el.fileList.parentNode.insertBefore(notice, el.fileList.nextSibling);
  }

  // ---------------------------------------------------------------- wiring

  if (el.fileInput) {
    el.fileInput.addEventListener('change', function () {
      handleFiles(el.fileInput.files);
      el.fileInput.value = '';   // allow re-selecting the same file
    });
  }

  if (el.dropZone) {
    ['dragenter', 'dragover'].forEach(function (event) {
      el.dropZone.addEventListener(event, function (e) {
        e.preventDefault();
        el.dropZone.classList.add('is-dragging');
      });
    });
    ['dragleave', 'drop'].forEach(function (event) {
      el.dropZone.addEventListener(event, function (e) {
        e.preventDefault();
        el.dropZone.classList.remove('is-dragging');
      });
    });
    el.dropZone.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) { handleFiles(e.dataTransfer.files); }
    });
  }

  if (el.submitBtn) {
    el.submitBtn.addEventListener('click', submitOrder);
  }

  // Restore any files already in this session's basket (e.g. after a reload).
  if (config.existingFiles && config.existingFiles.length) {
    config.existingFiles.forEach(function (file) {
      files.push({
        id: file.id,
        name: file.name,
        size_bytes: file.size_bytes,
        pages: file.pages,
        page_source: file.page_source,
        options: defaultOptions()
      });
    });
    renderFiles();
    renderOptions();
    requestQuote();
  }

  watchPrinter();
})();
