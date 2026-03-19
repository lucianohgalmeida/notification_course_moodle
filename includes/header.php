<?php
/**
 * HTML header / navigation template.
 *
 * Uses Moodle's standard $OUTPUT->header() so the page inherits the active
 * theme (navbar, accessibility bar, footer, etc.).
 *
 * Expected to be included AFTER bootstrap.php so that $USER is available.
 */

defined('NOTIFCOURSE_INTERNAL') || die('Acesso direto não permitido.');

global $USER, $PAGE, $OUTPUT, $CFG;

// ---------------------------------------------------------------------------
// Configure the Moodle page.
// ---------------------------------------------------------------------------
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Notificações de Curso');
$PAGE->set_heading('Notificações de Curso');

// Load the notification_course app stylesheet.
$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// ---------------------------------------------------------------------------
// Navigation helper.
// ---------------------------------------------------------------------------
/**
 * Returns 'aria-current="page"' + active CSS classes when $page matches the
 * current script's basename, or empty string otherwise.
 */
function notifcourse_nav_attrs(string $page): string {
    $current = basename($_SERVER['SCRIPT_NAME']);
    if ($current === $page) {
        return ' aria-current="page" class="nc-nav-link nc-nav-link--active"';
    }
    return ' class="nc-nav-link"';
}

$loggedInUserName = fullname($USER);

// ---------------------------------------------------------------------------
// Output the Moodle header (inherits theme navbar, a11y bar, etc.).
// ---------------------------------------------------------------------------
echo $OUTPUT->header();
?>

<!-- Notification Course app styles (scoped) -->
<link rel="stylesheet" href="<?= htmlspecialchars($appBase, ENT_QUOTES) ?>/assets/app.css">
<style>
    /* Expand Moodle's container to full width for this page */
    .main-inner,
    #topofscroll {
        max-width: 100% !important;
        margin-left: auto !important;
        margin-right: auto !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
    #page-content,
    #region-main-box,
    #region-main {
        max-width: 100% !important;
        width: 100% !important;
    }
    #region-main {
        overflow: visible !important;
    }

    /* Scope all notification_course styles inside .nc-app */
    .nc-app {
        display: flex;
        min-height: 70vh;
        font-family: 'Barlow', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        width: 100%;
    }

    /* Sidebar */
    .nc-sidebar {
        width: 200px;
        min-width: 200px;
        flex-shrink: 0;
        background: linear-gradient(180deg, #0D0B3E 0%, #161051 100%);
        color: #e2e8f0;
        border-radius: 0.75rem;
        padding: 1.25rem 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        margin-right: 1.5rem;
        align-self: flex-start;
        position: sticky;
        top: 80px;
    }

    .nc-sidebar__brand {
        padding: 0.5rem 0.75rem 1rem;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        border-bottom: 1px solid rgba(255,255,255,.12);
        margin-bottom: 0.5rem;
        color: #BFFF03;
    }

    .nc-sidebar__footer {
        padding: 0.75rem 0.75rem 0.25rem;
        border-top: 1px solid rgba(255,255,255,.12);
        margin-top: auto;
        font-size: 0.72rem;
        color: rgba(255,255,255,.5);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Navigation links */
    .nc-nav-link {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.4rem;
        font-size: 0.85rem;
        font-weight: 500;
        color: rgba(255,255,255,.75);
        text-decoration: none !important;
        transition: background-color 150ms ease, color 150ms ease;
    }

    .nc-nav-link:hover {
        background-color: rgba(255,255,255,.1);
        color: #fff;
    }

    .nc-nav-link--active {
        background-color: #BFFF03 !important;
        color: #0D0B3E !important;
        font-weight: 700;
    }

    .nc-nav-link--active:hover {
        background-color: #d4ff33 !important;
        color: #0D0B3E !important;
    }

    /* Main content */
    .nc-main {
        flex: 1;
        min-width: 0;
        overflow-x: auto;
    }

    /* Ensure tables inside nc-main scroll horizontally */
    .nc-main .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* Responsive: dashboard stat cards */
    @media (max-width: 1200px) {
        .nc-main [style*="grid-template-columns: repeat(4"] {
            grid-template-columns: repeat(2, 1fr) !important;
        }
    }

    @media (max-width: 768px) {
        .nc-app { flex-direction: column; }
        .nc-sidebar {
            width: 100%;
            flex-direction: column;
            position: static;
            margin-right: 0;
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 0.5rem;
            gap: 0.5rem;
        }
        .nc-sidebar__brand {
            border-bottom: none;
            padding: 0.25rem 0.5rem;
            margin-bottom: 0;
            font-size: 0.85rem;
        }
        .nc-sidebar nav {
            display: flex;
            flex-direction: row;
            overflow-x: auto;
            gap: 0.25rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .nc-sidebar nav::-webkit-scrollbar { display: none; }
        .nc-sidebar nav .nc-nav-link {
            white-space: nowrap;
            padding: 0.4rem 0.65rem;
            font-size: 0.78rem;
            border-radius: 2rem;
        }
        .nc-sidebar nav .nc-nav-link svg { width: 14px; height: 14px; }
        .nc-sidebar__footer { display: none; }

        .nc-main [style*="grid-template-columns"] {
            grid-template-columns: repeat(2, 1fr) !important;
        }

        .nc-main .section-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .nc-main .card {
            padding: 0.75rem !important;
        }
        .nc-main .card p[style*="font-size:2.25rem"] {
            font-size: 1.75rem !important;
        }

        .nc-main .table { font-size: 0.8rem; }
        .nc-main .table th,
        .nc-main .table td { padding: 0.5rem 0.4rem; }
    }

    @media (max-width: 420px) {
        .nc-main [style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }

    /* ---- Form responsiveness ---- */
    @media (max-width: 768px) {
        .nc-main .card {
            max-width: 100% !important;
        }
        .nc-main .form-input,
        .nc-main .form-textarea,
        .nc-main .form-select {
            font-size: 1rem;
            padding: 0.625rem 0.75rem;
        }
        .nc-main .form-input[style*="max-width"] {
            max-width: 100% !important;
        }
        .nc-main .nc-form-actions {
            flex-direction: column-reverse !important;
            gap: 0.5rem !important;
        }
        .nc-main .nc-form-actions .btn {
            width: 100% !important;
            justify-content: center;
        }
        .nc-main .form-hint code {
            word-break: break-all;
        }
    }
</style>

<div class="nc-app">

    <!-- Sidebar -->
    <aside class="nc-sidebar">
        <div class="nc-sidebar__brand">Notificações</div>

        <nav aria-label="Menu principal">
            <a href="<?= htmlspecialchars($appBase, ENT_QUOTES) ?>/index.php"
               <?= notifcourse_nav_attrs('index.php') ?>>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="7" height="9" x="3" y="3" rx="1"/>
                    <rect width="7" height="5" x="14" y="3" rx="1"/>
                    <rect width="7" height="9" x="14" y="12" rx="1"/>
                    <rect width="7" height="5" x="3" y="16" rx="1"/>
                </svg>
                Dashboard
            </a>

            <a href="<?= htmlspecialchars($appBase, ENT_QUOTES) ?>/schedules.php"
               <?= notifcourse_nav_attrs('schedules.php') ?>>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="4" rx="2" ry="2"/>
                    <line x1="16" x2="16" y1="2" y2="6"/>
                    <line x1="8"  x2="8"  y1="2" y2="6"/>
                    <line x1="3"  x2="21" y1="10" y2="10"/>
                </svg>
                Agendas
            </a>

            <a href="<?= htmlspecialchars($appBase, ENT_QUOTES) ?>/settings.php"
               <?= notifcourse_nav_attrs('settings.php') ?>>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                Configurações
            </a>

            <a href="<?= htmlspecialchars($appBase, ENT_QUOTES) ?>/history.php"
               <?= notifcourse_nav_attrs('history.php') ?>>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M12 7v5l4 2"/>
                </svg>
                Histórico
            </a>

        </nav>

        <div class="nc-sidebar__footer" title="<?= htmlspecialchars($loggedInUserName, ENT_QUOTES) ?>">
            Conectado como <?= htmlspecialchars($loggedInUserName, ENT_QUOTES) ?>
        </div>
    </aside>

    <!-- Main content area -->
    <div class="nc-main">
