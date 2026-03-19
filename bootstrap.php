<?php
/**
 * Bootstrap file for the notification_course standalone app.
 *
 * This file must be included at the top of every entry-point script.
 * It bootstraps the Moodle environment, enforces authentication and
 * capability checks, and registers the PSR-0-style class autoloader
 * for classes living under src/.
 *
 * Direct access to other files in this app is blocked via the
 * NOTIFCOURSE_INTERNAL constant — every other file should check:
 *
 *   defined('NOTIFCOURSE_INTERNAL') || die('Direct access not allowed.');
 */

// ---------------------------------------------------------------------------
// 1. Mark the environment as internally bootstrapped.
// ---------------------------------------------------------------------------
define('NOTIFCOURSE_INTERNAL', true);

// ---------------------------------------------------------------------------
// 2. Path to Moodle's config.php.
//    Override MOODLE_CONFIG_PATH before including this file if your Moodle
//    root lives somewhere else.
// ---------------------------------------------------------------------------
if (!defined('MOODLE_CONFIG_PATH')) {
    define('MOODLE_CONFIG_PATH', __DIR__ . '/../config.php');
}

require_once(MOODLE_CONFIG_PATH);

// ---------------------------------------------------------------------------
// 3. Enforce authentication.
// ---------------------------------------------------------------------------
require_login();

// ---------------------------------------------------------------------------
// 4. Enforce site-administration capability.
// ---------------------------------------------------------------------------
require_capability('moodle/site:config', context_system::instance());

// ---------------------------------------------------------------------------
// 5. Set up the Moodle page context and URL.
// ---------------------------------------------------------------------------
$PAGE->set_context(context_system::instance());

// Build the URL from the current script path relative to $CFG->wwwroot.
// We derive the web path by stripping the server's document root from the
// current script filename.  Falls back gracefully when DOCUMENT_ROOT is
// unavailable (e.g. CLI).
(function () {
    global $CFG, $PAGE;

    $scriptFile  = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
    $docRoot     = isset($_SERVER['DOCUMENT_ROOT'])   ? realpath($_SERVER['DOCUMENT_ROOT'])   : '';

    if ($scriptFile && $docRoot && strpos($scriptFile, $docRoot) === 0) {
        // e.g. /var/www/html/moodle/notification_course/index.php
        //   -> /notification_course/index.php
        $relativePath = substr($scriptFile, strlen($docRoot));
        $pageUrl      = new moodle_url($relativePath);
    } else {
        // Fallback: just use the configured wwwroot.
        $pageUrl = new moodle_url('/');
    }

    $PAGE->set_url($pageUrl);
})();

// ---------------------------------------------------------------------------
// 6. Session-key helper — call this at the top of any POST handler.
// ---------------------------------------------------------------------------

/**
 * Verifies the Moodle session key when the current request is a POST.
 *
 * Wraps Moodle's require_sesskey() so callers don't have to repeat the
 * method-check boilerplate.
 */
function notifcourse_require_sesskey(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_sesskey();
    }
}

// ---------------------------------------------------------------------------
// 7. Autoloader for classes under src/.
//    Naming convention: Namespace\ClassName  ->  src/Namespace/ClassName.php
//    Simple (no-namespace) classes           ->  src/ClassName.php
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $className): void {
    $srcDir   = __DIR__ . '/src/';
    $filePath = $srcDir . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';

    if (is_file($filePath)) {
        require_once $filePath;
    }
});

// ---------------------------------------------------------------------------
// 8. Check if tables are installed — redirect to installer if not.
// ---------------------------------------------------------------------------
(function () {
    global $DB;

    // Skip check if we're already on the install page.
    $currentScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($currentScript === 'install.php') {
        return;
    }

    $dbman = $DB->get_manager();
    $required_tables = ['notifcourse_schedule', 'notifcourse_log', 'notifcourse_config', 'notifcourse_categories'];

    foreach ($required_tables as $tname) {
        $table = new xmldb_table($tname);
        if (!$dbman->table_exists($table)) {
            // Tables missing — redirect to installer.
            $installUrl = new moodle_url('/notification_course/db/install.php');
            redirect($installUrl);
        }
    }
})();
