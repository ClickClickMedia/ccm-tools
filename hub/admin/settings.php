<?php
/**
 * Admin Settings
 * 
 * Manage all application settings stored in the database.
 * Only Admins can access this page.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once dirname(__DIR__) . '/config/config.php';
requireAdmin();

$pageTitle = 'Settings';
$currentNav = 'settings';

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_general':
            try {
                Settings::save('app_name', trim($_POST['app_name'] ?? ''), false, 'general');
                Settings::save('app_url', rtrim(trim($_POST['app_url'] ?? ''), '/'), false, 'general');
                Settings::save('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0', false, 'general');
                logActivity('settings_updated', null, null, ['category' => 'general']);
                $success = 'General settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_oauth':
            try {
                $clientId = trim($_POST['google_client_id'] ?? '');
                $clientSecret = trim($_POST['google_client_secret'] ?? '');
                $wizardEmails = trim($_POST['wizard_emails'] ?? '');

                // Only update if new values provided (don't overwrite with blank)
                if (!empty($clientId)) {
                    Settings::save('google_client_id', $clientId, true, 'oauth');
                }
                if (!empty($clientSecret)) {
                    Settings::save('google_client_secret', $clientSecret, true, 'oauth');
                }
                Settings::save('google_redirect_uri', rtrim(Settings::get('app_url'), '/') . '/auth/google-callback.php', false, 'oauth');
                Settings::save('google_allowed_domain', trim($_POST['google_allowed_domain'] ?? 'clickclickmedia.com.au'), false, 'oauth');
                Settings::save('wizard_emails', $wizardEmails, false, 'oauth');

                logActivity('settings_updated', null, null, ['category' => 'oauth']);
                $success = 'OAuth settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_ai':
            try {
                $claudeKey = trim($_POST['claude_api_key'] ?? '');
                if (!empty($claudeKey)) {
                    Settings::save('claude_api_key', $claudeKey, true, 'ai');
                }
                Settings::save('claude_model', trim($_POST['claude_model'] ?? 'claude-sonnet-4-20250514'), false, 'ai');
                Settings::save('claude_max_tokens', (string)(int)($_POST['claude_max_tokens'] ?? 4096), false, 'ai');
                Settings::save('max_optimization_iterations', (string)(int)($_POST['max_optimization_iterations'] ?? 5), false, 'ai');

                logActivity('settings_updated', null, null, ['category' => 'ai']);
                $success = 'AI settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_pagespeed':
            try {
                $psKey = trim($_POST['pagespeed_api_key'] ?? '');
                if (!empty($psKey)) {
                    Settings::save('pagespeed_api_key', $psKey, true, 'pagespeed');
                }
                Settings::save('pagespeed_cache_hours', (string)(int)($_POST['pagespeed_cache_hours'] ?? 24), false, 'pagespeed');

                logActivity('settings_updated', null, null, ['category' => 'pagespeed']);
                $success = 'PageSpeed settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_limits':
            try {
                Settings::save('rate_limit_per_minute', (string)(int)($_POST['rate_limit_per_minute'] ?? 30), false, 'limits');
                Settings::save('rate_limit_ai_per_hour', (string)(int)($_POST['rate_limit_ai_per_hour'] ?? 50), false, 'limits');
                Settings::save('default_ai_monthly_limit', (string)(int)($_POST['default_ai_monthly_limit'] ?? 1000), false, 'limits');
                Settings::save('default_pagespeed_daily_limit', (string)(int)($_POST['default_pagespeed_daily_limit'] ?? 100), false, 'limits');

                logActivity('settings_updated', null, null, ['category' => 'limits']);
                $success = 'Rate limit settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_logging':
            try {
                Settings::save('log_level', $_POST['log_level'] ?? 'info', false, 'logging');
                Settings::save('log_retention_days', (string)(int)($_POST['log_retention_days'] ?? 90), false, 'logging');

                logActivity('settings_updated', null, null, ['category' => 'logging']);
                $success = 'Logging settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_session':
            try {
                Settings::save('session_secure', isset($_POST['session_secure']) ? '1' : '0', false, 'session');
                Settings::save('session_lifetime', (string)(int)($_POST['session_lifetime'] ?? 7200), false, 'session');

                logActivity('settings_updated', null, null, ['category' => 'session']);
                $success = 'Session settings saved.';
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'regenerate_app_secret':
            try {
                Settings::save('app_secret_key', generateToken(32), true, 'general');
                logActivity('app_secret_regenerated');
                $success = 'Application secret key regenerated. All existing transit tokens are now invalid.';
            } catch (Exception $e) {
                $error = 'Failed to regenerate: ' . $e->getMessage();
            }
            break;

        case 'purge_logs':
            try {
                $days = (int)($_POST['purge_days'] ?? 90);
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                $deleted = dbExecute("DELETE FROM activity_log WHERE created_at < ?", [$cutoff]);
                $deleted2 = dbExecute("DELETE FROM api_usage_log WHERE created_at < ?", [$cutoff]);
                logActivity('logs_purged', null, null, ['days' => $days]);
                $success = "Purged logs older than {$days} days.";
            } catch (Exception $e) {
                $error = 'Failed to purge: ' . $e->getMessage();
            }
            break;
    }

    // Reload settings after save
    Settings::invalidate();
    Settings::load();
}

// Current values (after any saves)
$tab = $_GET['tab'] ?? 'general';

include 'layout-header.php';
?>

<div class="page-header">
    <div>
        <h1>⚙️ Settings</h1>
        <p class="text-muted">Manage application configuration</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<!-- Tab Navigation -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <?php
    $tabs = [
        'general' => '🏠 General',
        'oauth' => '🔐 OAuth',
        'ai' => '🤖 AI',
        'pagespeed' => '📊 PageSpeed',
        'limits' => '🚦 Rate Limits',
        'session' => '🔒 Session',
        'logging' => '📝 Logging',
        'maintenance' => '🔧 Maintenance',
    ];
    foreach ($tabs as $key => $label): ?>
        <a href="?tab=<?= $key ?>"
           class="btn <?= $tab === $key ? 'btn-primary' : 'btn-outline' ?>"
           style="font-size: 0.85rem;">
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- General Settings -->
<?php if ($tab === 'general'): ?>
<div class="card">
    <div class="card-header"><h3>General Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_general">

            <div class="form-group">
                <label>Application Name</label>
                <input type="text" name="app_name" value="<?= h(Settings::get('app_name', 'CCM API Hub')) ?>" required>
            </div>
            <div class="form-group">
                <label>Application URL</label>
                <input type="url" name="app_url" value="<?= h(Settings::get('app_url')) ?>" required placeholder="https://api.tools.clickclick.media">
                <small class="text-muted">No trailing slash. Used for OAuth redirects and link generation.</small>
            </div>
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= Settings::bool('maintenance_mode') ? 'checked' : '' ?>>
                    Maintenance Mode
                </label>
                <small class="text-muted">Disables all API endpoints. Sites will receive a 503 response.</small>
            </div>

            <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary">Save General Settings</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header"><h3>Application Secret Key</h3></div>
    <div class="card-body">
        <p class="text-muted">Used for signing transit payloads between the hub and WordPress plugins. Regenerating this key will invalidate all current plugin connections until they re-authenticate.</p>
        <form method="POST" onsubmit="return confirm('Are you sure? All existing plugin connections will need to re-authenticate.')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="regenerate_app_secret">
            <button type="submit" class="btn btn-danger">Regenerate Secret Key</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- OAuth Settings -->
<?php if ($tab === 'oauth'): ?>
<div class="card">
    <div class="card-header"><h3>Google OAuth Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_oauth">

            <div class="form-group">
                <label>Google Client ID</label>
                <input type="text" name="google_client_id" placeholder="<?= Settings::has('google_client_id') ? '••••••••••• (saved, leave blank to keep)' : 'Enter Client ID' ?>">
                <small class="text-muted">Leave blank to keep the existing value.</small>
            </div>
            <div class="form-group">
                <label>Google Client Secret</label>
                <input type="password" name="google_client_secret" placeholder="<?= Settings::has('google_client_secret') ? '••••••••••• (saved, leave blank to keep)' : 'Enter Client Secret' ?>">
                <small class="text-muted">Leave blank to keep the existing value.</small>
            </div>
            <div class="form-group">
                <label>Redirect URI</label>
                <input type="text" value="<?= h(Settings::get('google_redirect_uri', rtrim(Settings::get('app_url'), '/') . '/auth/google-callback.php')) ?>" readonly style="opacity: 0.7;">
                <small class="text-muted">Automatically set from App URL. Add this to your Google Cloud Console.</small>
            </div>
            <div class="form-group">
                <label>Allowed Email Domain</label>
                <input type="text" name="google_allowed_domain" value="<?= h(Settings::get('google_allowed_domain', 'clickclickmedia.com.au')) ?>">
                <small class="text-muted">Only emails from this domain can log in.</small>
            </div>
            <div class="form-group">
                <label>Wizard (Super-Admin) Emails</label>
                <textarea name="wizard_emails" rows="3"><?= h(Settings::get('wizard_emails', 'rik@clickclickmedia.com.au')) ?></textarea>
                <small class="text-muted">One email per line. These users are auto-promoted to Admin on first login.</small>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save OAuth Settings</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- AI Settings -->
<?php if ($tab === 'ai'): ?>
<div class="card">
    <div class="card-header"><h3>Claude AI Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_ai">

            <div class="form-group">
                <label>Claude API Key</label>
                <input type="password" name="claude_api_key" placeholder="<?= Settings::has('claude_api_key') ? '••••••••••• (saved, leave blank to keep)' : 'sk-ant-...' ?>">
                <small class="text-muted">Leave blank to keep the existing value.</small>
            </div>
            <div class="form-group">
                <label>Model</label>
                <select name="claude_model">
                    <?php
                    $currentModel = Settings::get('claude_model', 'claude-sonnet-4-20250514');
                    $models = [
                        'claude-sonnet-4-20250514' => 'Claude Sonnet 4 (Recommended)',
                        'claude-opus-4-20250514' => 'Claude Opus 4 (Most Capable)',
                        'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (Fastest)',
                    ];
                    foreach ($models as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $currentModel === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Max Tokens per Response</label>
                    <input type="number" name="claude_max_tokens" value="<?= h(Settings::get('claude_max_tokens', '4096')) ?>" min="256" max="32768">
                </div>
                <div class="form-group">
                    <label>Max Optimization Iterations</label>
                    <input type="number" name="max_optimization_iterations" value="<?= h(Settings::get('max_optimization_iterations', '5')) ?>" min="1" max="20">
                    <small class="text-muted">Max PageSpeed→AI→Retest loops per session.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save AI Settings</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- PageSpeed Settings -->
<?php if ($tab === 'pagespeed'): ?>
<div class="card">
    <div class="card-header"><h3>PageSpeed API Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_pagespeed">

            <div class="form-group">
                <label>Google PageSpeed API Key</label>
                <input type="password" name="pagespeed_api_key" placeholder="<?= Settings::has('pagespeed_api_key') ? '••••••••••• (saved, leave blank to keep)' : 'AIza...' ?>">
                <small class="text-muted">From Google Cloud Console → PageSpeed Insights API. Leave blank to keep existing.</small>
            </div>
            <div class="form-group">
                <label>Cache Duration (Hours)</label>
                <input type="number" name="pagespeed_cache_hours" value="<?= h(Settings::get('pagespeed_cache_hours', '24')) ?>" min="1" max="720">
                <small class="text-muted">How long PageSpeed results are cached before re-testing.</small>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save PageSpeed Settings</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Rate Limits -->
<?php if ($tab === 'limits'): ?>
<div class="card">
    <div class="card-header"><h3>Rate Limits &amp; Quotas</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_limits">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>API Requests per Minute</label>
                    <input type="number" name="rate_limit_per_minute" value="<?= h(Settings::get('rate_limit_per_minute', '30')) ?>" min="1" max="1000">
                    <small class="text-muted">Per-site rate limit for all API calls.</small>
                </div>
                <div class="form-group">
                    <label>AI Calls per Hour</label>
                    <input type="number" name="rate_limit_ai_per_hour" value="<?= h(Settings::get('rate_limit_ai_per_hour', '50')) ?>" min="1" max="500">
                    <small class="text-muted">Per-site AI request limit.</small>
                </div>
                <div class="form-group">
                    <label>Default AI Monthly Limit</label>
                    <input type="number" name="default_ai_monthly_limit" value="<?= h(Settings::get('default_ai_monthly_limit', '1000')) ?>" min="0" max="100000">
                    <small class="text-muted">Assigned to new sites. Override per-site on Sites page.</small>
                </div>
                <div class="form-group">
                    <label>Default PageSpeed Daily Limit</label>
                    <input type="number" name="default_pagespeed_daily_limit" value="<?= h(Settings::get('default_pagespeed_daily_limit', '100')) ?>" min="0" max="10000">
                    <small class="text-muted">Assigned to new sites. Override per-site on Sites page.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save Rate Limits</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Session Settings -->
<?php if ($tab === 'session'): ?>
<div class="card">
    <div class="card-header"><h3>Session Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_session">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Session Lifetime (seconds)</label>
                    <input type="number" name="session_lifetime" value="<?= h(Settings::get('session_lifetime', '7200')) ?>" min="300" max="86400">
                    <small class="text-muted">Default: 7200 (2 hours)</small>
                </div>
                <div class="form-group" style="display: flex; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="session_secure" value="1" <?= Settings::bool('session_secure', true) ? 'checked' : '' ?>>
                        Secure Cookies (HTTPS only)
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save Session Settings</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Logging Settings -->
<?php if ($tab === 'logging'): ?>
<div class="card">
    <div class="card-header"><h3>Logging Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_logging">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Log Level</label>
                    <select name="log_level">
                        <?php $current = Settings::get('log_level', 'info'); ?>
                        <option value="error" <?= $current === 'error' ? 'selected' : '' ?>>Error — Only errors</option>
                        <option value="warning" <?= $current === 'warning' ? 'selected' : '' ?>>Warning — Errors + warnings</option>
                        <option value="info" <?= $current === 'info' ? 'selected' : '' ?>>Info — Standard (recommended)</option>
                        <option value="debug" <?= $current === 'debug' ? 'selected' : '' ?>>Debug — Verbose (dev only)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Log Retention (days)</label>
                    <input type="number" name="log_retention_days" value="<?= h(Settings::get('log_retention_days', '90')) ?>" min="7" max="365">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Save Logging Settings</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Maintenance Actions -->
<?php if ($tab === 'maintenance'): ?>
<div class="card">
    <div class="card-header"><h3>Purge Old Logs</h3></div>
    <div class="card-body">
        <p class="text-muted">Remove API usage logs and activity logs older than the specified number of days.</p>
        <form method="POST" onsubmit="return confirm('This will permanently delete old log entries. Continue?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="purge_logs">
            <div style="display: flex; gap: 1rem; align-items: flex-end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Older than (days)</label>
                    <input type="number" name="purge_days" value="90" min="7" max="365" style="width: 120px;">
                </div>
                <button type="submit" class="btn btn-danger">Purge Logs</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header"><h3>Database Info</h3></div>
    <div class="card-body">
        <?php
        $tableStats = dbFetchAll(
            "SELECT table_name, table_rows, ROUND(data_length / 1024, 1) as data_kb, ROUND(index_length / 1024, 1) as index_kb
             FROM information_schema.tables
             WHERE table_schema = ? AND table_name LIKE 'ccm_%'
             ORDER BY table_name",
            [DB_NAME]
        );
        ?>
        <?php if (!empty($tableStats)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Table</th><th>Rows</th><th>Data</th><th>Index</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStats as $ts): ?>
                        <tr>
                            <td class="mono"><?= h($ts['table_name']) ?></td>
                            <td class="mono"><?= formatNumber($ts['table_rows']) ?></td>
                            <td class="mono"><?= $ts['data_kb'] ?> KB</td>
                            <td class="mono"><?= $ts['index_kb'] ?> KB</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="text-muted">No tables found. Run the setup wizard or import schema.sql.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header"><h3>Environment Check</h3></div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <tbody>
                    <tr><td>PHP Version</td><td class="mono"><?= PHP_VERSION ?></td><td><?= version_compare(PHP_VERSION, '8.0', '>=') ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗ Requires 8.0+</span>' ?></td></tr>
                    <tr><td>OpenSSL</td><td class="mono"><?= extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'Not loaded' ?></td><td><?= extension_loaded('openssl') ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗ Required</span>' ?></td></tr>
                    <tr><td>cURL</td><td class="mono"><?= extension_loaded('curl') ? curl_version()['version'] : 'Not loaded' ?></td><td><?= extension_loaded('curl') ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗ Required</span>' ?></td></tr>
                    <tr><td>PDO MySQL</td><td class="mono"><?= extension_loaded('pdo_mysql') ? 'Loaded' : 'Not loaded' ?></td><td><?= extension_loaded('pdo_mysql') ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗ Required</span>' ?></td></tr>
                    <tr><td>Google OAuth</td><td class="mono"><?= Settings::has('google_client_id') ? 'Configured' : 'Not set' ?></td><td><?= Settings::has('google_client_id') ? '<span class="text-success">✓</span>' : '<span class="text-warning">⚠ Setup required</span>' ?></td></tr>
                    <tr><td>Claude API</td><td class="mono"><?= Settings::has('claude_api_key') ? 'Configured' : 'Not set' ?></td><td><?= Settings::has('claude_api_key') ? '<span class="text-success">✓</span>' : '<span class="text-warning">⚠ Setup required</span>' ?></td></tr>
                    <tr><td>PageSpeed API</td><td class="mono"><?= Settings::has('pagespeed_api_key') ? 'Configured' : 'Not set' ?></td><td><?= Settings::has('pagespeed_api_key') ? '<span class="text-success">✓</span>' : '<span class="text-warning">⚠ Setup required</span>' ?></td></tr>
                    <tr><td>Debug Mode</td><td class="mono"><?= APP_DEBUG ? 'ON' : 'OFF' ?></td><td><?= APP_DEBUG ? '<span class="text-warning">⚠ Disable in production</span>' : '<span class="text-success">✓</span>' ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'layout-footer.php'; ?>
