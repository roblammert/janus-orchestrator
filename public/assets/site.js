(function () {
  const PREF_THEME_KEY = 'janus.theme';
  const PREF_FONT_KEY = 'janus.fontPair';

  function getPreference(key, fallback) {
    try {
      return localStorage.getItem(key) || fallback;
    } catch (_) {
      return fallback;
    }
  }

  function setPreference(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (_) {
      // Ignore storage failures in restricted environments.
    }
  }

  function applyTheme(theme) {
    const normalized = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', normalized);
    setPreference(PREF_THEME_KEY, normalized);

    const themeToggle = document.getElementById('theme-toggle-btn');
    if (themeToggle) {
      themeToggle.textContent = normalized === 'dark' ? 'Theme: Dark' : 'Theme: Light';
    }
  }

  function applyFontPair(fontPair) {
    const normalized = ['plex', 'source', 'nunito'].includes(fontPair) ? fontPair : 'plex';
    document.documentElement.setAttribute('data-font-pair', normalized);
    setPreference(PREF_FONT_KEY, normalized);
  }

  function initThemeAndFont() {
    applyTheme(getPreference(PREF_THEME_KEY, 'light'));
    applyFontPair(getPreference(PREF_FONT_KEY, 'plex'));

    const themeToggle = document.getElementById('theme-toggle-btn');
    if (themeToggle) {
      themeToggle.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        syncSettingsControls();
      });
    }

    const fontSelector = document.getElementById('font-pair-selector');
    if (fontSelector) {
      fontSelector.value = getPreference(PREF_FONT_KEY, 'plex');
      fontSelector.addEventListener('change', () => {
        applyFontPair(fontSelector.value);
        syncSettingsControls();
      });
    }
  }

  function syncSettingsControls() {
    const themeSelector = document.getElementById('theme-selector');
    if (themeSelector) {
      themeSelector.value = getPreference(PREF_THEME_KEY, 'light');
    }

    const fontSelector = document.getElementById('font-selector');
    if (fontSelector) {
      fontSelector.value = getPreference(PREF_FONT_KEY, 'plex');
    }

    const shellFontSelector = document.getElementById('font-pair-selector');
    if (shellFontSelector) {
      shellFontSelector.value = getPreference(PREF_FONT_KEY, 'plex');
    }
  }

  function bindPreferencesForm() {
    const form = document.getElementById('ui-preferences-form');
    if (!form) {
      return;
    }

    syncSettingsControls();
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const themeControl = document.getElementById('theme-selector');
      const fontControl = document.getElementById('font-selector');
      const theme = themeControl ? themeControl.value : getPreference(PREF_THEME_KEY, 'light');
      const font = fontControl ? fontControl.value : getPreference(PREF_FONT_KEY, 'plex');
      applyTheme(theme);
      applyFontPair(font);
      syncSettingsControls();
      showToast('Preferences saved', 'success');
    });
  }

  function showToast(message, kind) {
    const region = document.getElementById('toast-region');
    if (!region) {
      return;
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${kind || 'info'}`;
    toast.textContent = message;
    region.appendChild(toast);

    window.setTimeout(() => {
      toast.classList.add('toast-out');
      window.setTimeout(() => {
        toast.remove();
      }, 220);
    }, 2400);
  }

  async function api(url, method, body) {
    const started = performance.now();
    const response = await fetch(url, {
      method: method || 'GET',
      headers: {
        'Content-Type': 'application/json',
      },
      body: body ? JSON.stringify(body) : null,
    });

    const elapsed = Math.round(performance.now() - started);
    const latencyNode = document.getElementById('footer-latency');
    if (latencyNode) {
      latencyNode.textContent = `Latency: ${elapsed}ms`;
    }

    const text = await response.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch (_) {
      data = { raw: text };
    }

    const isEnvelope = typeof data === 'object' && data !== null && Object.prototype.hasOwnProperty.call(data, 'success');

    if (!response.ok || (isEnvelope && data.success === false)) {
      const errorMessage = isEnvelope
        ? (data.error?.message || `Request failed (${response.status})`)
        : (data.error || `Request failed (${response.status})`);
      showToast(errorMessage, 'error');
      throw new Error(errorMessage);
    }

    if (isEnvelope) {
      return data.data;
    }

    return data;
  }

  async function refreshFooterStatus() {
    const statusNode = document.getElementById('footer-service-status');
    if (!statusNode) {
      return;
    }

    try {
      await api('/api/metrics/overview');
      statusNode.textContent = 'Services: api ok';
    } catch (_) {
      statusNode.textContent = 'Services: degraded';
    }
  }

  function init() {
    initThemeAndFont();
    bindPreferencesForm();
    refreshFooterStatus();

    if (document.getElementById('footer-service-status')) {
      window.setInterval(refreshFooterStatus, 15000);
    }
  }

  window.JanusUI = {
    api,
    showToast,
    applyTheme,
    applyFontPair,
    syncSettingsControls,
  };

  document.addEventListener('DOMContentLoaded', init);
})();
