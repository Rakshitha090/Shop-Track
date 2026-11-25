/**
 * ShopTrack - Global Client Application JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Lucide icons if available
    if (window.lucide) {
        lucide.createIcons();
    }

    // Mobile sidebar toggle handler
    const sidebar = document.getElementById('app-sidebar');
    const toggleBtn = document.getElementById('menu-toggle-btn');
    const overlay = document.getElementById('sidebar-overlay');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('show-mobile');
            if (overlay) overlay.classList.toggle('active');
        });
    }

    if (overlay && sidebar) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show-mobile');
            overlay.classList.remove('active');
        });
    }

    // Global modal close on ESC and backdrop click
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.show').forEach(m => closeModal(m.id));
        }
    });

    document.querySelectorAll('.modal-backdrop').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal(modal.id);
            }
        });
    });
});

/**
 * Show a toast notification
 * @param {string} message 
 * @param {'success'|'error'|'warning'|'info'} type 
 */
function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let icon = 'check-circle';
    let color = '#10b981';
    if (type === 'error') { icon = 'alert-triangle'; color = '#ef4444'; }
    else if (type === 'warning') { icon = 'alert-circle'; color = '#f59e0b'; }
    else if (type === 'info') { icon = 'info'; color = '#0284c7'; }

    toast.innerHTML = `
        <span style="color: ${color}; display: flex; align-items: center;">
            <i data-lucide="${icon}" style="width: 18px; height: 18px;"></i>
        </span>
        <div style="flex: 1; font-weight: 500;">${escapeHtml(message)}</div>
    `;

    container.appendChild(toast);
    if (window.lucide) {
        lucide.createIcons({ root: toast });
    }

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease-out';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

/**
 * Open Modal Dialog
 */
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
        if (window.lucide) {
            lucide.createIcons({ root: modal });
        }
    }
}

/**
 * Close Modal Dialog
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

/**
 * Generic Confirmation Modal Dialog
 */
function confirmDialog(title, message, onConfirmCallback) {
    let confirmModal = document.getElementById('global-confirm-modal');
    if (!confirmModal) {
        confirmModal = document.createElement('div');
        confirmModal.id = 'global-confirm-modal';
        confirmModal.className = 'modal-backdrop';
        confirmModal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title" id="confirm-modal-title">Confirm Action</h3>
                    <button type="button" class="modal-close-btn" onclick="closeModal('global-confirm-modal')">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <div class="modal-body" id="confirm-modal-body">
                    Are you sure you want to proceed?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('global-confirm-modal')">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirm-modal-btn">Confirm</button>
                </div>
            </div>
        `;
        document.body.appendChild(confirmModal);
    }

    document.getElementById('confirm-modal-title').textContent = title;
    document.getElementById('confirm-modal-body').textContent = message;

    const btn = document.getElementById('confirm-modal-btn');
    // Clear old listener
    const newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);

    newBtn.addEventListener('click', () => {
        closeModal('global-confirm-modal');
        if (typeof onConfirmCallback === 'function') {
            onConfirmCallback();
        }
    });

    openModal('global-confirm-modal');
}

/**
 * Safe AJAX Fetch Wrapper with CSRF Header Injection
 */
async function apiRequest(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    options.headers = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken,
        ...(options.headers || {})
    };

    if (options.body && options.body instanceof FormData) {
        if (!options.body.has('csrf_token') && csrfToken) {
            options.body.append('csrf_token', csrfToken);
        }
    } else if (options.body && typeof options.body === 'object') {
        if (!options.body.csrf_token && csrfToken) {
            options.body.csrf_token = csrfToken;
        }
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    try {
        const response = await fetch(url, options);
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || `Request failed with status ${response.status}`);
        }
        return data;
    } catch (err) {
        console.error('API Error:', err);
        throw err;
    }
}

/**
 * Helper to escape HTML in JS
 */
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Format currency in INR
 */
function formatMoney(amount, currency = '₹') {
    const val = parseFloat(amount || 0);
    return `${currency}${val.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
