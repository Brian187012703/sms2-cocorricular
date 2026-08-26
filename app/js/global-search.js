// ============================================================
//  GLOBAL-SEARCH.JS
//  Role-Based Access Control (RBAC) Search Panel
//  Strictly searches authorized pages, modules, and events.
// ============================================================

(function () {
  'use strict';

  let activeWrap     = null;
  let activeDropdown = null;
  let debounceTimer  = null;
  let selectedIndex  = -1;
  let visibleItems   = [];

  function initSearchInputs() {
    document.querySelectorAll('.search-wrap').forEach(wrap => {
      const input = wrap.querySelector('input');
      if (!input || wrap.dataset.searchBound) return;
      wrap.dataset.searchBound = 'true';

      // Create dropdown container for this search wrap
      let dropdown = wrap.querySelector('.search-dropdown-menu');
      if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.className = 'search-dropdown-menu';
        dropdown.innerHTML = '<div class="search-dropdown-body"></div>';
        wrap.appendChild(dropdown);
      }

      input.addEventListener('focus', function () {
        activeWrap = wrap;
        activeDropdown = dropdown;
        openDropdown(wrap, dropdown, input.value.trim());
      });

      input.addEventListener('input', function (e) {
        activeWrap = wrap;
        activeDropdown = dropdown;
        const q = e.target.value.trim();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          openDropdown(wrap, dropdown, q);
        }, 120);
      });

      input.addEventListener('keydown', function (e) {
        handleKeyboardNav(e, dropdown);
      });
    });

    // Close on click outside
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.search-wrap')) {
        closeAllDropdowns();
      }
    });
  }

  function openDropdown(wrap, dropdown, query) {
    dropdown.classList.add('active');
    const body = dropdown.querySelector('.search-dropdown-body');
    if (!body) return;

    body.innerHTML = `
      <div class="search-dropdown-empty">
        <i class="fa-solid fa-spinner fa-spin" style="color:#2563eb;"></i>
        Searching...
      </div>
    `;

    fetch('../shared/search_actions.php?q=' + encodeURIComponent(query))
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          body.innerHTML = '<div class="search-dropdown-empty">No results available.</div>';
          return;
        }
        renderDropdownResults(body, data.results, query);
      })
      .catch(() => {
        body.innerHTML = '<div class="search-dropdown-empty">Error searching.</div>';
      });
  }

  function renderDropdownResults(body, results, query) {
    const { modules = [], events = [], clubs = [] } = results;
    const total = modules.length + events.length + clubs.length;

    if (total === 0) {
      body.innerHTML = `
        <div class="search-dropdown-empty">
          <i class="fa-solid fa-folder-open"></i>
          <div>No authorized pages or events found for "<strong>${escapeHtml(query)}</strong>"</div>
        </div>
      `;
      visibleItems = [];
      selectedIndex = -1;
      return;
    }

    let html = '';

    // 1. Pages & Modules (RBAC filtered)
    if (modules.length > 0) {
      html += `<div class="search-dropdown-group-title"><i class="fa-solid fa-layer-group" style="color:#2563eb;"></i> Pages &amp; Modules</div>`;
      modules.forEach(item => {
        html += `
          <a href="${item.url}" class="search-dropdown-item">
            <div class="search-dropdown-icon">
              <i class="${item.icon || 'fa-solid fa-file'}"></i>
            </div>
            <div class="search-dropdown-info">
              <div class="search-dropdown-title">${escapeHtml(item.title)}</div>
              <div class="search-dropdown-desc">${escapeHtml(item.description || '')}</div>
            </div>
            <span class="search-dropdown-badge">Page</span>
          </a>
        `;
      });
    }

    // 2. Events & Activities
    if (events.length > 0) {
      html += `<div class="search-dropdown-group-title"><i class="fa-solid fa-calendar-days" style="color:#16a34a;"></i> Campus Events</div>`;
      events.forEach(item => {
        html += `
          <a href="${item.url}" class="search-dropdown-item">
            <div class="search-dropdown-icon" style="color:#16a34a; background:#f0fdf4;">
              <i class="${item.icon || 'fa-solid fa-calendar-check'}"></i>
            </div>
            <div class="search-dropdown-info">
              <div class="search-dropdown-title">${escapeHtml(item.title)}</div>
              <div class="search-dropdown-desc">${item.description || ''}</div>
            </div>
            <span class="search-dropdown-badge" style="background:#dcfce7; color:#15803d;">Event</span>
          </a>
        `;
      });
    }

    // 3. Organizations (If authorized)
    if (clubs.length > 0) {
      html += `<div class="search-dropdown-group-title"><i class="fa-solid fa-sitemap" style="color:#9333ea;"></i> Organizations</div>`;
      clubs.forEach(item => {
        html += `
          <a href="${item.url}" class="search-dropdown-item">
            <div class="search-dropdown-icon" style="color:#9333ea; background:#faf5ff;">
              <i class="${item.icon || 'fa-solid fa-sitemap'}"></i>
            </div>
            <div class="search-dropdown-info">
              <div class="search-dropdown-title">${escapeHtml(item.title)}</div>
              <div class="search-dropdown-desc">${escapeHtml(item.description || '')}</div>
            </div>
            <span class="search-dropdown-badge" style="background:#f3e8ff; color:#7e22ce;">Club</span>
          </a>
        `;
      });
    }

    body.innerHTML = html;

    visibleItems = Array.from(body.querySelectorAll('.search-dropdown-item'));
    selectedIndex = visibleItems.length > 0 ? 0 : -1;
    updateSelection();
  }

  function handleKeyboardNav(e, dropdown) {
    if (!dropdown.classList.contains('active')) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!visibleItems.length) return;
      selectedIndex = (selectedIndex + 1) % visibleItems.length;
      updateSelection();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!visibleItems.length) return;
      selectedIndex = (selectedIndex - 1 + visibleItems.length) % visibleItems.length;
      updateSelection();
    } else if (e.key === 'Enter') {
      if (selectedIndex >= 0 && visibleItems[selectedIndex]) {
        e.preventDefault();
        visibleItems[selectedIndex].click();
      }
    } else if (e.key === 'Escape') {
      closeAllDropdowns();
    }
  }

  function updateSelection() {
    visibleItems.forEach((el, idx) => {
      el.classList.toggle('selected', idx === selectedIndex);
    });
  }

  function closeAllDropdowns() {
    document.querySelectorAll('.search-dropdown-menu.active').forEach(d => {
      d.classList.remove('active');
    });
    selectedIndex = -1;
    visibleItems = [];
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSearchInputs);
  } else {
    initSearchInputs();
  }
})();
