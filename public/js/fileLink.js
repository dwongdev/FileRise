import { t } from './i18n.js?v={{APP_QVER}}';
import { withBase } from './basePath.js?v={{APP_QVER}}';
import { escapeHTML, showToast } from './domUtils.js?v={{APP_QVER}}';

async function copyTextToClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return true;
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'absolute';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch (e) {
    ok = false;
  } finally {
    ta.remove();
  }
  return ok;
}

function buildInternalLink(token) {
  return `${window.location.origin}${withBase(`/index.html?fileLink=${encodeURIComponent(token)}`)}`;
}

function openFileLinkResultModal(fileName, link) {
  const existing = document.getElementById('fileLinkResultModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'fileLinkResultModal';
  modal.className = 'modal';
  modal.innerHTML = `
    <div class="modal-content share-modal-content" style="max-width:560px;">
      <div class="modal-header">
        <h3>${t('link_file')}: ${escapeHTML(fileName)}</h3>
        <span id="closeFileLinkResultModalX" title="${t('close')}" class="close-image-modal">&times;</span>
      </div>
      <div class="modal-body">
        <p style="margin-bottom:6px;">${t('shareable_link')}</p>
        <input id="fileLinkResultInput" type="text" readonly style="width:100%;padding:6px;" />
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
          <button id="copyFileLinkResultBtn" class="btn btn-primary">${t('copy_link')}</button>
          <button id="closeFileLinkResultBtn" class="btn btn-secondary">${t('close')}</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  modal.style.display = 'block';

  const inputEl = document.getElementById('fileLinkResultInput');
  if (inputEl) {
    inputEl.value = link;
    inputEl.focus();
    inputEl.select();
  }

  const close = () => modal.remove();
  const closeX = document.getElementById('closeFileLinkResultModalX');
  const closeBtn = document.getElementById('closeFileLinkResultBtn');
  const copyBtn = document.getElementById('copyFileLinkResultBtn');

  if (closeX) closeX.addEventListener('click', close);
  if (closeBtn) closeBtn.addEventListener('click', close);
  modal.addEventListener('click', (e) => {
    if (e.target === modal) close();
  });

  if (copyBtn && inputEl) {
    copyBtn.addEventListener('click', async () => {
      const ok = await copyTextToClipboard(inputEl.value);
      showToast(ok ? t('link_copied') : t('unknown_error'));
    });
  }
}

async function createAuthFileLink(folder, file, sourceId = '') {
  const resp = await fetch(withBase('/api/file/createAuthFileLink.php'), {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken || '',
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      folder,
      file,
      sourceId: String(sourceId || '').trim(),
    }),
  });

  const data = await resp.json().catch(() => ({}));
  if (!resp.ok || !data || data.ok !== true) {
    throw new Error((data && (data.error || data.message)) || `HTTP ${resp.status}`);
  }
  return data;
}

export async function openFileLinkModal(fileObj, folder) {
  const fileName = String(fileObj?.name || '').trim();
  if (!fileName) {
    showToast(t('unknown_error'), 'error');
    return;
  }
  const targetFolder = String(folder || fileObj?.folder || window.currentFolder || 'root');
  const sourceId = String(fileObj?.sourceId || (window.__frGetActiveSourceId ? window.__frGetActiveSourceId() : '') || '').trim();

  try {
    const data = await createAuthFileLink(targetFolder, fileName, sourceId);
    const token = String(data.token || '').trim();
    if (!token) {
      throw new Error(t('file_link_create_failed'));
    }
    const link = buildInternalLink(token);
    openFileLinkResultModal(fileName, link);
  } catch (e) {
    const msg = (e && e.message) ? e.message : t('file_link_create_failed');
    showToast(t('file_link_create_failed_detail', { error: msg }), 'error');
  }
}
