(function () {
	'use strict';

	const cfg = window.ShiftDBAdmin || {};
	const ajaxUrl = cfg.ajaxUrl || '';
	const nonce = cfg.nonce || '';

	function qs(selector, root = document) {
		return root.querySelector(selector);
	}

	function qsa(selector, root = document) {
		return Array.from(root.querySelectorAll(selector));
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	async function post(action, data = {}) {
		const body = new URLSearchParams({ action, nonce, ...data });
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		});
		let json;
		try {
			json = await response.json();
		} catch (error) {
			throw new Error('ShiftDB received an invalid server response.');
		}
		if (!response.ok || !json.success) {
			throw new Error(json?.data?.message || 'The operation could not be completed.');
		}
		return json.data;
	}

	function selected(selector) {
		return qsa(selector + ':checked').map((item) => item.value);
	}

	function setResult(target, type, html) {
		const node = qs(target);
		if (!node) return;
		node.className = 'shiftdb-result is-visible is-' + type;
		node.innerHTML = html;
	}

	function setBusy(button, busy) {
		if (!button) return;
		if (busy) {
			button.dataset.label = button.textContent;
			button.textContent = cfg?.i18n?.working || 'Working...';
			button.disabled = true;
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
		}
	}

	qsa('.shiftdb-tabs button').forEach((button) => {
		button.addEventListener('click', () => {
			qsa('.shiftdb-tabs button').forEach((item) => item.classList.remove('is-active'));
			qsa('.shiftdb-panel').forEach((item) => item.classList.remove('is-active'));
			button.classList.add('is-active');
			qs('[data-panel="' + button.dataset.tab + '"]')?.classList.add('is-active');
		});
	});

	qsa('.shiftdb-check-all').forEach((checkAll) => {
		checkAll.addEventListener('change', () => {
			qsa('.' + checkAll.dataset.target).forEach((box) => {
				box.checked = checkAll.checked;
			});
		});
	});

	const dryRunButton = qs('#shiftdb-dry-run');
	const replaceButton = qs('#shiftdb-run-replace');
	let lastDrySignature = '';

	function searchPayload() {
		return {
			search: qs('#shiftdb-search')?.value || '',
			replace: qs('#shiftdb-replace')?.value || '',
			case_sensitive: qs('#shiftdb-case-sensitive')?.checked ? '1' : '',
			protect_guids: qs('#shiftdb-protect-guids')?.checked ? '1' : '',
			tables: selected('.shiftdb-sr-tables')
		};
	}

	function payloadSignature(payload) {
		return JSON.stringify(payload);
	}

	qsa('#shiftdb-search, #shiftdb-replace, #shiftdb-case-sensitive, #shiftdb-protect-guids, .shiftdb-sr-tables').forEach((control) => {
		control.addEventListener('change', () => {
			if (replaceButton) replaceButton.disabled = true;
		});
		control.addEventListener('input', () => {
			if (replaceButton) replaceButton.disabled = true;
		});
	});

	dryRunButton?.addEventListener('click', async () => {
		const payload = searchPayload();
		setBusy(dryRunButton, true);
		if (replaceButton) replaceButton.disabled = true;
		try {
			const data = await post('shiftdb_dry_run', payload);
			lastDrySignature = payloadSignature(payload);
			const rows = Object.entries(data.tables || {}).map(([table, info]) =>
				'<tr><td><code>' + escapeHtml(table) + '</code></td><td>' + Number(info.rows || 0).toLocaleString() + '</td><td>' + Number(info.replacements || 0).toLocaleString() + '</td></tr>'
			).join('');
			setResult('#shiftdb-sr-result', 'success',
				'<strong>Dry test completed.</strong><p>' + Number(data.total_replacements || 0).toLocaleString() + ' replacements across ' + Number(data.total_rows || 0).toLocaleString() + ' rows.</p>' +
				(rows ? '<div class="shiftdb-mini-table"><table><thead><tr><th>Table</th><th>Rows</th><th>Replacements</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '')
			);
			if (replaceButton) replaceButton.disabled = Number(data.total_replacements || 0) < 1;
		} catch (error) {
			setResult('#shiftdb-sr-result', 'error', '<strong>Dry test failed.</strong><p>' + escapeHtml(error.message) + '</p>');
		} finally {
			setBusy(dryRunButton, false);
		}
	});

	replaceButton?.addEventListener('click', async () => {
		const payload = searchPayload();
		if (payloadSignature(payload) !== lastDrySignature) {
			setResult('#shiftdb-sr-result', 'error', '<strong>Run a new dry test first.</strong><p>Your search settings changed after the previous dry test.</p>');
			replaceButton.disabled = true;
			return;
		}
		if (!window.confirm(cfg?.i18n?.confirmReplace || 'Continue?')) return;
		setBusy(replaceButton, true);
		try {
			const data = await post('shiftdb_replace', payload);
			setResult('#shiftdb-sr-result', 'success', '<strong>Replacement completed.</strong><p>' + Number(data.replacements || 0).toLocaleString() + ' replacements across ' + Number(data.rows || 0).toLocaleString() + ' rows. Recovery point: <code>' + escapeHtml(data.snapshot || 'none') + '</code></p>');
			lastDrySignature = '';
		} catch (error) {
			setResult('#shiftdb-sr-result', 'error', '<strong>Replacement failed.</strong><p>' + escapeHtml(error.message) + '</p>');
		} finally {
			setBusy(replaceButton, false);
			replaceButton.disabled = true;
		}
	});

	qs('#shiftdb-optimize')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const tables = selected('.db-table');
		setBusy(button, true);
		try {
			const data = await post('shiftdb_optimize', { tables });
			const list = Object.entries(data).map(([table, message]) => '<li><code>' + escapeHtml(table) + '</code>: ' + escapeHtml(message) + '</li>').join('');
			setResult('#shiftdb-db-result', 'success', '<strong>Optimization completed.</strong><ul>' + list + '</ul>');
		} catch (error) {
			setResult('#shiftdb-db-result', 'error', '<strong>Optimization failed.</strong><p>' + escapeHtml(error.message) + '</p>');
		} finally {
			setBusy(button, false);
		}
	});

	qs('#shiftdb-cleanup')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const types = selected('.shiftdb-clean-type');
		if (!types.length) {
			setResult('#shiftdb-clean-result', 'error', '<strong>Select at least one cleanup type.</strong>');
			return;
		}
		setBusy(button, true);
		try {
			const data = await post('shiftdb_cleanup', { types });
			const list = Object.entries(data).map(([type, count]) => '<li>' + escapeHtml(type.replace(/_/g, ' ')) + ': ' + Number(count || 0).toLocaleString() + '</li>').join('');
			setResult('#shiftdb-clean-result', 'success', '<strong>Cleanup completed.</strong><ul>' + list + '</ul>');
		} catch (error) {
			setResult('#shiftdb-clean-result', 'error', '<strong>Cleanup failed.</strong><p>' + escapeHtml(error.message) + '</p>');
		} finally {
			setBusy(button, false);
		}
	});

	qsa('.shiftdb-restore').forEach((button) => {
		button.addEventListener('click', async () => {
			if (!window.confirm(cfg?.i18n?.confirmRestore || 'Restore?')) return;
			setBusy(button, true);
			try {
				const data = await post('shiftdb_restore', { file: button.dataset.file || '' });
				setResult('#shiftdb-recovery-result', 'success', '<strong>Recovery completed.</strong><p>' + Number(data.rows || 0).toLocaleString() + ' rows restored.</p>');
			} catch (error) {
				setResult('#shiftdb-recovery-result', 'error', '<strong>Recovery failed.</strong><p>' + escapeHtml(error.message) + '</p>');
			} finally {
				setBusy(button, false);
			}
		});
	});

	qsa('.shiftdb-delete-snapshot').forEach((button) => {
		button.addEventListener('click', async () => {
			if (!window.confirm(cfg?.i18n?.confirmDelete || 'Delete?')) return;
			try {
				await post('shiftdb_delete_snapshot', { file: button.dataset.file || '' });
				button.closest('tr')?.remove();
				setResult('#shiftdb-recovery-result', 'success', '<strong>Recovery snapshot deleted.</strong>');
			} catch (error) {
				setResult('#shiftdb-recovery-result', 'error', '<strong>Delete failed.</strong><p>' + escapeHtml(error.message) + '</p>');
			}
		});
	});
})();
