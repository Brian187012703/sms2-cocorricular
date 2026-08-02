// ============================================================
//  DASHBOARD.JS
//  All the interactive behaviour for dashboard.php.
//
//  SECTIONS (use Ctrl+F to jump):
//    1. SIDEBAR TOGGLE
//    2. SIDEBAR DROPDOWNS
//    3. BIRTHDAY DROPDOWNS
//    4. CHART DATA   ← edit chart labels and numbers here
//    5. QUICK-ADD FORM
//    6. MODAL HELPERS
//    7. TOAST NOTIFICATIONS
//    8. BULK SELECTION
//    9. VIEW MODAL
//   10. ADD MODAL
//   11. EDIT MODAL
//   12. DELETE (single)
// ============================================================


// ============================================================
//  1. SIDEBAR TOGGLE
//  Clicking the hamburger icon collapses/expands the sidebar.
// ============================================================
const hamburgerBtn   = document.getElementById('hamburgerBtn');
const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

// On mobile (<= 900px) the sidebar is collapsed by default
if (window.innerWidth <= 900) {
    sidebar.classList.add('collapsed');
}

hamburgerBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    if (sidebarOverlay) {
        sidebarOverlay.classList.toggle('active', !sidebar.classList.contains('collapsed'));
    }
});

// Tap the overlay to close sidebar on mobile
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.add('collapsed');
        sidebarOverlay.classList.remove('active');
    });
}


// ============================================================
//  2. SIDEBAR DROPDOWNS
//  Each nav item expands a dropdown when clicked.
// ============================================================
document.querySelectorAll('.dropdown-trigger').forEach(button => {
    button.addEventListener('click', function () {
        const targetMenu = document.getElementById(this.dataset.target);
        const isOpen     = targetMenu.classList.contains('open');

        // Close all open dropdowns first
        document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.sidebar-item.open').forEach(b => b.classList.remove('open'));

        // Then open the clicked one (if it was closed)
        if (!isOpen) {
            targetMenu.classList.add('open');
            this.classList.add('open');
        }
    });
});


// ============================================================
//  3. BIRTHDAY DROPDOWNS
//  Fills the Day and Year <select> options with JavaScript
//  so we don't have to write them all out in HTML.
// ============================================================
const daySelect  = document.getElementById('bdDay');
const yearSelect = document.getElementById('bdYear');

if (daySelect) {
    for (let day = 1; day <= 31; day++) {
        const option = new Option(day, day);
        if (day === 20) option.selected = true; // default selected day
        daySelect.appendChild(option);
    }
}

if (yearSelect) {
    const currentYear = new Date().getFullYear();
    for (let year = currentYear; year >= 1950; year--) {
        const option = new Option(year, year);
        if (year === 2006) option.selected = true; // default selected year
        yearSelect.appendChild(option);
    }
}


// ============================================================
//  4. CHART DATA
//  Edit the labels array and the data arrays to change the chart.
//  Each dataset is one coloured bar group.
// ============================================================

// Dashboard Chart (Co-Curricular Engagement Metrics)
const dashboardChart = document.getElementById('dashboardChart');

if (dashboardChart) {
    const engagementLabels = [
        'Club Participation', 'Event Attendance', 'Achievement Submissions',
        'Community Service', 'Leadership Roles', 'Award Recognitions'
    ];

    const engagementData = [
        {
            label: 'Current Year',
            data: [78, 85, 42, 65, 55, 38],
            backgroundColor: '#2563eb'
        },
        {
            label: 'Previous Year',
            data: [65, 72, 35, 58, 48, 30],
            backgroundColor: '#10b981'
        }
    ];

    new Chart(dashboardChart.getContext('2d'), {
        type: 'bar',
        data: { labels: engagementLabels, datasets: engagementData },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 }, padding: 12 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { font: { size: 10 } },
                    grid:  { color: '#f0f0f0' }
                },
                x: {
                    ticks: { font: { size: 10 } },
                    grid:  { display: false }
                }
            }
        }
    });
}

// Report Chart (Legacy - old enrollment system data)
const reportChart = document.getElementById('reportChart');

if (reportChart) {
    // ── EDIT CHART LABELS HERE (x-axis categories) ──────────
    const chartLabels = [
        'English', 'Science', 'ICT', 'PE',
        'Constitution', 'Humanity', 'Center',
        'Core1', 'Core2', 'Elective', 'Final'
    ];

    // ── EDIT CHART DATA HERE (one object per year/group) ────
    const chartDatasets = [
        {
            label: '2020',
            data: [75, 68, 82, 55, 15, 62, 78, 45, 50, 38, 72],
            backgroundColor: '#a78bfa'  // purple
        },
        {
            label: '2021',
            data: [60, 80, 70, 65, 30, 55, 85, 35, 60, 25, 80],
            backgroundColor: '#38bdf8'  // blue
        },
        {
            label: '2022',
            data: [50, 72, 60, 78, 45, 48, 55, 68, 42, 55, 45],
            backgroundColor: '#f9a8d4'  // pink
        }
    ];

    new Chart(reportChart.getContext('2d'), {
        type: 'bar',
        data: { labels: chartLabels, datasets: chartDatasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 } }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { font: { size: 10 } },
                    grid:  { color: '#f0f0f0' }
                },
                x: {
                    ticks: { font: { size: 10 } },
                    grid:  { display: false }
                }
            }
        }
    });
}


// ============================================================
//  5. QUICK-ADD FORM [DEAD CODE - From old enrollment system]
//  This code is preserved for reference but is not used in the
//  Co-Curricular Management System.
// ============================================================
/*

// ── Shared form validation ───────────────────────────────────
// Pass a <form> element and a map of { inputName: 'Label text' }.
// Returns true if all fields are filled, false otherwise.
// Also highlights empty fields red and shows an error message.
function validateForm(form, requiredFields) {
    let valid = true;

    Object.entries(requiredFields).forEach(([name, label]) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (!input) return;

        const field = input.closest('.form-field');
        const value = input.value.trim();

        // Ensure the error <span> exists below the input
        let errorEl = field?.querySelector('.field-error');
        if (field && !errorEl) {
            errorEl = document.createElement('span');
            errorEl.className = 'field-error';
            field.appendChild(errorEl);
        }

        if (!value) {
            input.classList.add('input-error');
            field?.classList.add('has-error');
            if (errorEl) errorEl.textContent = `${label} is required.`;
            valid = false;
        } else {
            input.classList.remove('input-error');
            field?.classList.remove('has-error');
            if (errorEl) errorEl.textContent = '';
        }
    });

    return valid;
}

// ── Live input listeners — clears error as soon as user types ─
function attachLiveValidation(form) {
    form.querySelectorAll('input, select').forEach(input => {
        // Blue ring on focus
        input.addEventListener('focus', () => {
            if (!input.classList.contains('input-error')) {
                input.style.borderColor = '#2563eb';
            }
        });

        // Reset border on blur (CSS handles :focus, this covers the gap)
        input.addEventListener('blur', () => {
            if (!input.classList.contains('input-error')) {
                input.style.borderColor = '';
            }
        });

        // Clear error the moment the user starts typing / changing
        input.addEventListener('input', () => clearFieldError(input));
        input.addEventListener('change', () => clearFieldError(input));
    });
}

function clearFieldError(input) {
    if (input.value.trim()) {
        input.classList.remove('input-error');
        const field = input.closest('.form-field');
        field?.classList.remove('has-error');
        const errorEl = field?.querySelector('.field-error');
        if (errorEl) errorEl.textContent = '';
    }
}

const quickAddForm = document.getElementById('quickAddForm');

// Required fields for the quick-add form
const quickAddRequired = {
    first_name : 'First Name',
    last_name  : 'Last Name',
    course     : 'Course',
    year_level : 'Year Level',
    section    : 'Section',
    phone      : 'Phone'
};

if (quickAddForm) {
    attachLiveValidation(quickAddForm);

    quickAddForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!validateForm(this, quickAddRequired)) return; // stop if invalid

        const formData = new FormData(this);

        // Convert the 3 birthday dropdowns into one "YYYY-MM-DD" value
        const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const monthNum   = String(monthNames.indexOf(formData.get('bday_month')) + 1).padStart(2, '0');
        const day        = String(formData.get('bday_day')).padStart(2, '0');
        const year       = formData.get('bday_year');
        formData.set('birthday', `${year}-${monthNum}-${day}`);
        formData.set('action', 'add');

        fetch(typeof STUDENT_API !== 'undefined' ? STUDENT_API : 'student_actions.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    quickAddForm.reset();
                    reloadWithToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => showToast('Request failed.', 'error'));
    });
}

*/


// ============================================================
//  6. MODAL HELPERS
//  openModal / closeModal show or hide a popup dialog.
//  Clicking outside the modal box or the × button also closes it.
// ============================================================

// Opens a modal by its HTML id
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

// Closes a modal by its HTML id
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Listen for any click on a [data-close] button or the overlay background
document.addEventListener('click', event => {
    const closeButton = event.target.closest('[data-close]');
    if (closeButton) {
        closeModal(closeButton.dataset.close);
        return;
    }
    // Click directly on the dark overlay (not the modal box)
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
    }
});


// ============================================================
//  7. TOAST NOTIFICATIONS
//  Shows a card-style popup in the top-right corner.
//
//  Types:
//    'success'  → green  "Submitted"
//    'updated'  → blue   "Updated"
//    'warning'  → yellow "Warning"
//    'error'    → red    "Error"
//
//  Usage: showToast('Your message here', 'success')
// ============================================================

// Maps each type to the bold label shown at the top of the card
const TOAST_LABELS = {
    success : 'Submitted',
    updated : 'Updated',
    warning : 'Warning',
    error   : 'Error'
};

function showToast(message, type = 'success') {
    // Create the toast element once and reuse it
    let toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.innerHTML = `
            <div class="toast-label">
                <span class="toast-dot"></span>
                <span class="toast-title"></span>
            </div>
            <div class="toast-msg"></div>`;
        document.body.appendChild(toast);
    }

    // Set the label and message
    toast.querySelector('.toast-title').textContent = TOAST_LABELS[type] ?? type;
    toast.querySelector('.toast-msg').textContent   = message;

    // Swap the colour class
    toast.className = `toast ${type}`;

    // Trigger animation (force reflow so class change restarts transition)
    void toast.offsetWidth;
    toast.classList.add('show');

    // Auto-hide after 3.5 seconds
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('show'), 3500);
}

// ── Save a toast to show AFTER a page reload ─────────────────
// Because location.reload() happens before the toast is visible,
// we store the message in sessionStorage and read it back on load.
function reloadWithToast(message, type) {
    sessionStorage.setItem('pending_toast', JSON.stringify({ message, type }));
    location.reload();
}

// On every page load: check if there's a pending toast and show it
window.addEventListener('DOMContentLoaded', () => {
    const pending = sessionStorage.getItem('pending_toast');
    if (pending) {
        sessionStorage.removeItem('pending_toast');
        const { message, type } = JSON.parse(pending);
        // Small delay so the DOM is fully painted before the toast appears
        setTimeout(() => showToast(message, type), 150);
    }
});

// Avatar initial is rendered server-side from the PHP session — no JS needed.


// ============================================================
//  BELL / NOTIFICATION PANEL
//  Sidebar bell icon toggles the notification panel.
//  Clicking an unread item or "Mark all as read" clears the badge.
// ============================================================
const bellBtn      = document.getElementById('bellBtn');
const bellBadge    = document.getElementById('bellBadge');
const notifPanel   = document.getElementById('notifPanel');
const notifOverlay = document.getElementById('notifOverlay');
const notifClose   = document.getElementById('notifClose');
const notifMarkAll = document.getElementById('notifMarkAll');

function openNotifPanel() {
    notifPanel.classList.add('active');
    notifOverlay.classList.add('active');
}

function closeNotifPanel() {
    notifPanel.classList.remove('active');
    notifOverlay.classList.remove('active');
}

function updateBellBadge() {
    const hasUnread = document.querySelector('#notifList .notif-item.unread');
    bellBadge?.classList.toggle('has-notif', !!hasUnread);
}

// Set initial badge state on page load
updateBellBadge();

bellBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = notifPanel.classList.contains('active');
    isOpen ? closeNotifPanel() : openNotifPanel();
});

// Close when clicking the × button or the overlay
notifClose?.addEventListener('click', closeNotifPanel);
notifOverlay?.addEventListener('click', closeNotifPanel);

// Mark individual item as read on click
document.getElementById('notifList')?.addEventListener('click', (e) => {
    const item = e.target.closest('.notif-item');
    if (item) {
        item.classList.remove('unread');
        updateBellBadge();
    }
});

// Mark all as read
notifMarkAll?.addEventListener('click', () => {
    document.querySelectorAll('#notifList .notif-item.unread')
        .forEach(el => el.classList.remove('unread'));
    updateBellBadge();
});


// ============================================================
//  8. BULK SELECTION [DEAD CODE - From old enrollment system]
//  Bulk action checkboxes and toolbar are not used in the
//  Co-Curricular Management System.
// ============================================================
/*

    const formData = new FormData();
    formData.set('action', 'bulk_status');
    formData.set('ids', ids.join(','));
    formData.set('status', 'Inactive');

    fetch(typeof STUDENT_API !== 'undefined' ? STUDENT_API : 'student_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) reloadWithToast(data.message, 'updated');
            else showToast(data.message, 'error');
        })
        .catch(() => showToast('Request failed.', 'error'));
});

*/

// ============================================================
//  9. VIEW/EDIT/DELETE MODALS [DEAD CODE - From old enrollment system]
//  Student CRUD operations are not used in the Co-Curricular system.
// ============================================================
/*
document.querySelectorAll('.btn-view').forEach(button => {
    button.addEventListener('click', function () {
        const studentId = this.dataset.id;

        fetch(`${typeof STUDENT_API !== 'undefined' ? STUDENT_API : 'student_actions.php'}?action=get&id=${studentId}`)
            .then(response => response.json())
            .then(data => {
                if (!data.success) { showToast(data.message, 'error'); return; }

                const s = data.student;
                // Fill in the modal fields
                document.getElementById('vName').textContent    = `${s.first_name} ${s.last_name}`;
                document.getElementById('vBirthday').textContent = s.birthday;
                document.getElementById('vPhone').textContent   = s.phone;
                document.getElementById('vCourse').textContent  = s.course;
                document.getElementById('vYear').textContent    = s.year_level;
                document.getElementById('vSection').textContent = s.section;

                openModal('viewModal');
            })
            .catch(() => showToast('Failed to load student.', 'error'));
    });
});


// ============================================================
//  10. ADD MODAL
//  Clicking "Add" clears the form and opens the modal.
// ============================================================

// Required fields for the Add/Edit modal form
const modalRequired = {
    first_name : 'First Name',
    last_name  : 'Last Name',
    birthday   : 'Birthday',
    course     : 'Course',
    year_level : 'Year Level',
    section    : 'Section',
    phone      : 'Phone'
};

document.getElementById('btnAddStudent')?.addEventListener('click', () => {
    const crudForm = document.getElementById('studentCrudForm');
    document.getElementById('formModalTitle').textContent = 'Add Student';
    crudForm.reset();
    // Clear any leftover error states from a previous open
    crudForm.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
    crudForm.querySelectorAll('.form-field.has-error').forEach(el => el.classList.remove('has-error'));
    crudForm.querySelectorAll('.field-error').forEach(el => { el.textContent = ''; });
    document.getElementById('crudId').value     = '';
    document.getElementById('crudAction').value = 'add';
    attachLiveValidation(crudForm);
    openModal('formModal');
});


// ============================================================
//  11. EDIT MODAL
//  Clicking the pencil icon pre-fills the form with the student's data.
// ============================================================
document.querySelectorAll('.btn-edit').forEach(button => {
    button.addEventListener('click', function () {
        const studentId = this.dataset.id;

        fetch(`${typeof STUDENT_API !== 'undefined' ? STUDENT_API : 'student_actions.php'}?action=get&id=${studentId}`)
            .then(response => response.json())
            .then(data => {
                if (!data.success) { showToast(data.message, 'error'); return; }

                const s        = data.student;
                const crudForm = document.getElementById('studentCrudForm');

                // Set the hidden fields so student_actions.php knows this is an edit
                document.getElementById('formModalTitle').textContent = 'Edit Student';
                document.getElementById('crudId').value     = s.id;
                document.getElementById('crudAction').value = 'edit';

                // Pre-fill each form field with the student's current values
                document.getElementById('cFirst').value   = s.first_name;
                document.getElementById('cLast').value    = s.last_name;
                document.getElementById('cBday').value    = s.birthday;
                document.getElementById('cCourse').value  = s.course;
                document.getElementById('cYear').value    = s.year_level;
                document.getElementById('cSection').value = s.section;
                document.getElementById('cPhone').value   = s.phone;
                document.getElementById('cStatus').value  = s.status;

                // Clear any leftover error states
                crudForm.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
                crudForm.querySelectorAll('.form-field.has-error').forEach(el => el.classList.remove('has-error'));
                crudForm.querySelectorAll('.field-error').forEach(el => { el.textContent = ''; });

                attachLiveValidation(crudForm);
                openModal('formModal');
            })
            .catch(() => showToast('Failed to load student.', 'error'));
    });
});

// The Submit button inside the Add/Edit modal sends the form data
document.getElementById('btnCrudSubmit')?.addEventListener('click', () => {
    const crudForm = document.getElementById('studentCrudForm');
    const isEdit   = document.getElementById('crudAction').value === 'edit';

    if (!validateForm(crudForm, modalRequired)) return; // stop if invalid

    const formData = new FormData(crudForm);

    fetch(typeof STUDENT_API !== 'undefined' ? STUDENT_API : 'student_actions.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeModal('formModal');
                reloadWithToast(data.message, isEdit ? 'updated' : 'success');
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => showToast('Request failed.', 'error'));
});

*/

// ============================================================
//  12. DELETE (single student)
//  Clicking the trash icon asks for confirmation, then deletes.
// ============================================================
let pendingDeleteId = null; // stores the ID of the student to delete

document.querySelectorAll('.btn-delete').forEach(button => {
    button.addEventListener('click', function () {
        pendingDeleteId = this.dataset.id;
        document.getElementById('deleteStudentName').textContent = this.dataset.name;
        openModal('deleteModal');
    });
});

document.getElementById('btnConfirmDelete')?.addEventListener('click', () => {
    if (!pendingDeleteId) return;

    const formData = new FormData();
    formData.set('action', 'delete');
    formData.set('id', pendingDeleteId);

    fetch(typeof STUDENT_API !== 'undefined' ? STUDENT_API : 'student_actions.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeModal('deleteModal');
                reloadWithToast(data.message, 'warning');
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => showToast('Request failed.', 'error'));
});
