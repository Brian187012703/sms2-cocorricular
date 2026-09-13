/**
 * ============================================================
 *  TABLE-PAGINATION.JS — Universal Responsive Table Paginator
 *  BCP Co-Curricular Portal
 * ============================================================
 */

(function () {
  'use strict';

  class TablePaginator {
    constructor(tableOrSelector, options = {}) {
      this.table = typeof tableOrSelector === 'string' ? document.querySelector(tableOrSelector) : tableOrSelector;
      if (!this.table) return;

      // Prevent double init
      if (this.table._paginator) {
        this.table._paginator.refresh();
        return this.table._paginator;
      }

      this.options = Object.assign({
        pageSize: 5,
        pageSizeOptions: [5, 10, 25, 50, 0], // 0 means 'All'
        showPageSizeSelector: true,
        showInfo: false,
        showTopBar: false,
        showBottomBar: true,
        searchFilterSelector: null,
        className: 'pagination-wrapper'
      }, options);

      this.currentPage = 1;
      this.pageSize = this.options.pageSize;
      this.table._paginator = this;

      this.init();
    }

    init() {
      // Ensure table is inside a responsive overflow wrapper
      if (!this.table.parentElement.classList.contains('table-responsive') && !this.table.parentElement.classList.contains('resp-table-wrap')) {
        const respWrap = document.createElement('div');
        respWrap.className = 'table-responsive';
        this.table.parentNode.insertBefore(respWrap, this.table);
        respWrap.appendChild(this.table);
      }

      // Create bottom pagination toolbar
      this.toolbar = document.createElement('div');
      this.toolbar.className = 'pagination-toolbar';
      
      // Info section (left)
      if (this.options.showInfo) {
        this.infoEl = document.createElement('div');
        this.infoEl.className = 'pagination-info';
        this.toolbar.appendChild(this.infoEl);
      }
      
      // Controls section (right)
      this.controlsEl = document.createElement('div');
      this.controlsEl.className = 'pagination-controls';

      // Page size selector
      if (this.options.showPageSizeSelector) {
        const sizeWrap = document.createElement('div');
        sizeWrap.className = 'pagination-size-wrap';
        sizeWrap.innerHTML = `
          <label class="pagination-size-label">
            <span>Show</span>
            <select class="pagination-size-select" aria-label="Entries per page">
              ${this.options.pageSizeOptions.map(size => `<option value="${size}" ${size === this.pageSize ? 'selected' : ''}>${size === 0 ? 'All' : size}</option>`).join('')}
            </select>
            <span>entries</span>
          </label>
        `;
        const selectEl = sizeWrap.querySelector('select');
        selectEl.addEventListener('change', (e) => {
          this.pageSize = parseInt(e.target.value, 10);
          this.currentPage = 1;
          this.render();
        });
        this.controlsEl.appendChild(sizeWrap);
      }

      // Buttons container
      this.buttonsEl = document.createElement('div');
      this.buttonsEl.className = 'pagination-buttons';
      this.controlsEl.appendChild(this.buttonsEl);

      this.toolbar.appendChild(this.controlsEl);

      // Insert toolbar after responsive container
      const container = this.table.closest('.table-responsive') || this.table.closest('.resp-table-wrap') || this.table;
      container.parentNode.insertBefore(this.toolbar, container.nextSibling);

      // Bind search filter if provided
      if (this.options.searchFilterSelector) {
        const searchInput = document.querySelector(this.options.searchFilterSelector);
        if (searchInput) {
          searchInput.addEventListener('input', () => {
            setTimeout(() => {
              this.currentPage = 1;
              this.render();
            }, 50);
          });
        }
      }

      this.render();
    }

    getAllRows() {
      const tbody = this.table.querySelector('tbody');
      if (!tbody) return [];
      // Get all non-empty / non-notice rows
      return Array.from(tbody.querySelectorAll('tr')).filter(tr => {
        // Exclude system empty placeholders if they span 3+ cols
        const singleTd = tr.querySelector('td[colspan]');
        return !(singleTd && parseInt(singleTd.getAttribute('colspan') || '1', 10) >= 3);
      });
    }

    getVisibleRows() {
      return this.getAllRows().filter(tr => {
        return tr.style.display !== 'none' && !tr.classList.contains('filtered-out');
      });
    }

    render() {
      const allRows = this.getAllRows();
      // Only paginate rows that aren't hidden by search filters
      const visibleRows = allRows.filter(tr => {
        // Check if filtered out by search input
        return tr.getAttribute('data-search-hidden') !== 'true';
      });

      const totalEntries = visibleRows.length;
      if (totalEntries === 0) {
        this.toolbar.style.display = 'none';
        return;
      }
      this.toolbar.style.display = 'flex';

      const effectivePageSize = this.pageSize === 0 ? totalEntries : this.pageSize;
      const totalPages = Math.max(1, Math.ceil(totalEntries / (effectivePageSize || 1)));

      if (this.currentPage > totalPages) this.currentPage = totalPages;
      if (this.currentPage < 1) this.currentPage = 1;

      const startIndex = (this.currentPage - 1) * effectivePageSize;
      const endIndex = this.pageSize === 0 ? totalEntries : Math.min(startIndex + effectivePageSize, totalEntries);

      // Apply visibility to rows
      visibleRows.forEach((tr, index) => {
        if (index >= startIndex && index < endIndex) {
          tr.style.display = '';
        } else {
          tr.style.display = 'none';
        }
      });

      // Update info text
      if (this.options.showInfo && this.infoEl) {
        this.infoEl.innerHTML = `Showing <strong>${startIndex + 1}</strong> to <strong>${endIndex}</strong> of <strong>${totalEntries}</strong> entries`;
      }

      // Render pagination buttons
      this.renderButtons(totalPages);
    }

    renderButtons(totalPages) {
      this.buttonsEl.innerHTML = '';
      this.buttonsEl.style.display = 'inline-flex';

      // Prev Button
      const prevBtn = document.createElement('button');
      prevBtn.type = 'button';
      prevBtn.className = `pagination-btn prev-btn ${this.currentPage <= 1 ? 'disabled' : ''}`;
      prevBtn.innerHTML = '&laquo; Previous';
      prevBtn.disabled = (this.currentPage <= 1);
      prevBtn.addEventListener('click', () => {
        if (this.currentPage > 1) {
          this.currentPage--;
          this.render();
        }
      });
      this.buttonsEl.appendChild(prevBtn);

      // Page numbers (smart window)
      const pageNumbers = this.getPageNumbers(totalPages);
      pageNumbers.forEach(p => {
        if (p === '...') {
          const ellipsis = document.createElement('span');
          ellipsis.className = 'pagination-ellipsis';
          ellipsis.textContent = '…';
          this.buttonsEl.appendChild(ellipsis);
        } else {
          const pageBtn = document.createElement('button');
          pageBtn.type = 'button';
          pageBtn.className = `pagination-btn page-num-btn ${p === this.currentPage ? 'active' : ''}`;
          pageBtn.textContent = p;
          pageBtn.addEventListener('click', () => {
            this.currentPage = p;
            this.render();
          });
          this.buttonsEl.appendChild(pageBtn);
        }
      });

      // Next Button
      const nextBtn = document.createElement('button');
      nextBtn.type = 'button';
      nextBtn.className = `pagination-btn next-btn ${this.currentPage >= totalPages ? 'disabled' : ''}`;
      nextBtn.innerHTML = 'Next &raquo;';
      nextBtn.disabled = (this.currentPage >= totalPages);
      nextBtn.addEventListener('click', () => {
        if (this.currentPage < totalPages) {
          this.currentPage++;
          this.render();
        }
      });
      this.buttonsEl.appendChild(nextBtn);
    }

    getPageNumbers(totalPages) {
      if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, i) => i + 1);
      }
      const current = this.currentPage;
      if (current <= 3) {
        return [1, 2, 3, 4, '...', totalPages];
      }
      if (current >= totalPages - 2) {
        return [1, '...', totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
      }
      return [1, '...', current - 1, current, current + 1, '...', totalPages];
    }

    refresh() {
      this.render();
    }
  }

  // Global helper to initialize pagination on a table
  window.initTablePagination = function (tableOrSelector, options = {}) {
    return new TablePaginator(tableOrSelector, options);
  };

  function autoInit() {
    document.querySelectorAll('table.data-table, table.ledger-table, table.resp-table, table.matrix-table').forEach(tbl => {
      // Don't auto-paginate modal tables without explicit id or meta tables
      if (tbl.closest('.modal-card') || tbl.closest('.modal-overlay')) return;
      if (tbl.classList.contains('meta-table') || tbl.classList.contains('no-auto-paginate')) return;
      if (!tbl._paginator) {
        window.initTablePagination(tbl, { pageSize: 5 });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

})();
