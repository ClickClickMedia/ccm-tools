<?php
/**
 * Sites Management
 * 
 * Add, edit, enable/disable licensed sites and generate API keys.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once dirname(__DIR__) . '/config/config.php';
requireLogin();
requireManager();

$pageTitle = 'Licensed Sites';
$currentNav = 'sites';

$success = '';
$error = '';

// ─── Handle Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        // ── Add New Site ────────────────────────────────────
        case 'add_site':
            $siteUrl = normalizeSiteUrl(trim($_POST['site_url'] ?? ''));
            $siteName = trim($_POST['site_name'] ?? '');

            if (!isValidUrl($siteUrl)) {
                $error = 'Invalid URL format.';
                break;
            }

            // Check for duplicate
            $existing = dbFetchOne("SELECT id FROM licensed_sites WHERE site_url = ?", [$siteUrl]);
            if ($existing) {
                $error = 'This URL is already registered.';
                break;
            }

            // Generate API key
            $keyData = generateSiteApiKey();

            try {
                $siteId = dbInsert('licensed_sites', [
                    'site_url'              => $siteUrl,
                    'site_name'             => $siteName ?: parse_url($siteUrl, PHP_URL_HOST),
                    'api_key_hash'          => $keyData['hash'],
                    'api_key_prefix'        => $keyData['prefix'],
                    'is_active'             => 1,
                    'ai_enabled'            => (int)($_POST['ai_enabled'] ?? 1),
                    'pagespeed_enabled'     => (int)($_POST['pagespeed_enabled'] ?? 1),
                    'ai_monthly_limit'      => (int)($_POST['ai_monthly_limit'] ?? Settings::int('default_ai_monthly_limit', 1000)),
                    'pagespeed_daily_limit' => (int)($_POST['pagespeed_daily_limit'] ?? Settings::int('default_pagespeed_daily_limit', 100)),
                    'notes'                 => trim($_POST['notes'] ?? '') ?: null,
                    'created_by'            => $_SESSION['user_id'],
                ]);

                logActivity('site_created', 'licensed_site', (string)$siteId, [
                    'url'  => $siteUrl,
                    'name' => $siteName,
                ]);

                // Flash the API key (show once)
                $_SESSION['flash_api_key'] = $keyData['key'];
                $_SESSION['flash_site_id'] = $siteId;

                redirect('sites.php?created=1');
            } catch (Exception $e) {
                $error = 'Failed to create site: ' . $e->getMessage();
            }
            break;

        // ── Toggle Active Status ────────────────────────────
        case 'toggle_status':
            $siteId = (int)($_POST['site_id'] ?? 0);
            $site = dbFetchOne("SELECT * FROM licensed_sites WHERE id = ?", [$siteId]);

            if ($site) {
                $newStatus = $site['is_active'] ? 0 : 1;
                dbUpdate('licensed_sites', ['is_active' => $newStatus], 'id = ?', [$siteId]);
                logActivity($newStatus ? 'site_enabled' : 'site_disabled', 'licensed_site', (string)$siteId);
                $success = 'Site ' . ($newStatus ? 'enabled' : 'disabled') . '.';
            }
            break;

        // ── Regenerate API Key ──────────────────────────────
        case 'regenerate_key':
            $siteId = (int)($_POST['site_id'] ?? 0);
            $site = dbFetchOne("SELECT * FROM licensed_sites WHERE id = ?", [$siteId]);

            if ($site) {
                $keyData = generateSiteApiKey();
                dbUpdate('licensed_sites', [
                    'api_key_hash'   => $keyData['hash'],
                    'api_key_prefix' => $keyData['prefix'],
                ], 'id = ?', [$siteId]);

                logActivity('site_key_regenerated', 'licensed_site', (string)$siteId);

                $_SESSION['flash_api_key'] = $keyData['key'];
                $_SESSION['flash_site_id'] = $siteId;
                redirect('sites.php?regenerated=1');
            }
            break;

        // ── Update Site Settings ────────────────────────────
        case 'update_site':
            $siteId = (int)($_POST['site_id'] ?? 0);

            try {
                dbUpdate('licensed_sites', [
                    'site_name'             => trim($_POST['site_name'] ?? ''),
                    'ai_enabled'            => (int)($_POST['ai_enabled'] ?? 0),
                    'pagespeed_enabled'     => (int)($_POST['pagespeed_enabled'] ?? 0),
                    'ai_monthly_limit'      => (int)($_POST['ai_monthly_limit'] ?? 1000),
                    'pagespeed_daily_limit' => (int)($_POST['pagespeed_daily_limit'] ?? 100),
                    'notes'                 => trim($_POST['notes'] ?? '') ?: null,
                    'expires_at'            => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
                ], 'id = ?', [$siteId]);

                logActivity('site_updated', 'licensed_site', (string)$siteId);
                $success = 'Site updated.';
            } catch (Exception $e) {
                $error = 'Failed to update: ' . $e->getMessage();
            }
            break;

        // ── Delete Site ─────────────────────────────────────
        case 'delete_site':
            if (!hasRole(ROLE_ADMIN)) {
                $error = 'Admin role required to delete sites.';
                break;
            }

            $siteId = (int)($_POST['site_id'] ?? 0);
            $site = dbFetchOne("SELECT site_url FROM licensed_sites WHERE id = ?", [$siteId]);

            if ($site) {
                dbDelete('licensed_sites', 'id = ?', [$siteId]);
                logActivity('site_deleted', 'licensed_site', (string)$siteId, ['url' => $site['site_url']]);
                $success = 'Site deleted.';
            }
            break;
    }
}

// ─── Flash Messages ─────────────────────────────────────────
$flashApiKey = $_SESSION['flash_api_key'] ?? null;
$flashSiteId = $_SESSION['flash_site_id'] ?? null;
unset($_SESSION['flash_api_key'], $_SESSION['flash_site_id']);

if (isset($_GET['created'])) $success = 'Site created successfully.';
if (isset($_GET['regenerated'])) $success = 'API key regenerated.';

// ─── Load Sites ─────────────────────────────────────────────
$sites = dbFetchAll(
    "SELECT ls.*, 
            u.name as created_by_name,
            (SELECT COALESCE(SUM(tokens_used), 0) FROM api_usage_log WHERE site_id = ls.id AND created_at >= ?) as monthly_tokens,
            (SELECT COUNT(*) FROM api_usage_log WHERE site_id = ls.id AND request_type = 'pagespeed' AND created_at >= CURDATE()) as today_ps
     FROM licensed_sites ls 
     LEFT JOIN users u ON u.id = ls.created_by 
     ORDER BY ls.created_at DESC",
    [date('Y-m-01 00:00:00')]
);

include 'layout-header.php';
?>

<div class="page-header">
    <div>
        <h1>🌐 Licensed Sites</h1>
        <p class="text-muted">Manage which sites can access the AI and PageSpeed APIs</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('add-site-modal').classList.add('active')">+ Add Site</button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<!-- Flash: Show API Key (once only) -->
<?php if ($flashApiKey): ?>
    <div class="alert alert-warning" style="border: 2px solid var(--accent-warning);">
        <strong>⚠️ Copy this API key now — it will not be shown again!</strong><br>
        <code style="font-size: 1rem; word-break: break-all; display: block; margin-top: 0.5rem; padding: 0.5rem; background: var(--bg-primary); border-radius: var(--border-radius-md);" id="new-api-key"><?= h($flashApiKey) ?></code>
        <button class="btn btn-outline btn-sm" style="margin-top: 0.5rem;" onclick="navigator.clipboard.writeText(document.getElementById('new-api-key').textContent); showToast('API key copied!', 'success');">📋 Copy to Clipboard</button>
    </div>
<?php endif; ?>

<!-- Sites Table -->
<div class="card">
    <?php if (empty($sites)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🌐</div>
            <h3>No licensed sites</h3>
            <p>Add a site to generate an API key and grant access to AI and PageSpeed features.</p>
            <button class="btn btn-primary" onclick="document.getElementById('add-site-modal').classList.add('active')">+ Add Site</button>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Key Prefix</th>
                        <th>Features</th>
                        <th>Monthly Tokens</th>
                        <th>Today PS</th>
                        <th>Last Seen</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500;"><?= h($site['site_name'] ?: parse_url($site['site_url'], PHP_URL_HOST)) ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?= h($site['site_url']) ?></div>
                            </td>
                            <td><code><?= h($site['api_key_prefix']) ?></code></td>
                            <td>
                                <?php if ($site['ai_enabled']): ?><span class="badge badge-success">AI</span> <?php endif; ?>
                                <?php if ($site['pagespeed_enabled']): ?><span class="badge badge-info">PS</span><?php endif; ?>
                            </td>
                            <td class="mono"><?= formatNumber($site['monthly_tokens']) ?> <span class="text-muted">/ <?= formatNumber($site['ai_monthly_limit'] * 1000) ?></span></td>
                            <td class="mono"><?= $site['today_ps'] ?> <span class="text-muted">/ <?= $site['pagespeed_daily_limit'] ?></span></td>
                            <td class="text-muted"><?= $site['last_seen'] ? timeAgo($site['last_seen']) : 'Never' ?></td>
                            <td>
                                <?php if ($site['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.25rem;">
                                    <button class="btn btn-ghost btn-sm" onclick="editSite(<?= h(json_encode($site)) ?>)" title="Edit">✏️</button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" title="<?= $site['is_active'] ? 'Disable' : 'Enable' ?>"><?= $site['is_active'] ? '⏸️' : '▶️' ?></button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Regenerate API key? The old key will stop working immediately.')">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="action" value="regenerate_key">
                                        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" title="Regenerate Key">🔑</button>
                                    </form>
                                    <?php if (hasRole(ROLE_ADMIN)): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this site and all its data?')">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="delete_site">
                                            <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm text-danger" title="Delete">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Add Site Modal -->
<div class="modal-overlay" id="add-site-modal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Add Licensed Site</h3>
            <button class="btn btn-ghost btn-sm" onclick="this.closest('.modal-overlay').classList.remove('active')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="add_site">

                <div class="form-group">
                    <label>Site URL</label>
                    <input type="url" name="site_url" placeholder="https://example.com" required>
                    <div class="hint">The WordPress site URL (no trailing slash)</div>
                </div>

                <div class="form-group">
                    <label>Site Name (optional)</label>
                    <input type="text" name="site_name" placeholder="My Client Site">
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>AI Monthly Limit (K tokens)</label>
                        <input type="number" name="ai_monthly_limit" value="<?= Settings::int('default_ai_monthly_limit', 1000) ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>PageSpeed Daily Limit</label>
                        <input type="number" name="pagespeed_daily_limit" value="<?= Settings::int('default_pagespeed_daily_limit', 100) ?>" min="0">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label><input type="checkbox" name="ai_enabled" value="1" checked> Enable AI Features</label>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="pagespeed_enabled" value="1" checked> Enable PageSpeed</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Internal notes about this site..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Site & Generate Key</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Site Modal -->
<div class="modal-overlay" id="edit-site-modal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Edit Site</h3>
            <button class="btn btn-ghost btn-sm" onclick="this.closest('.modal-overlay').classList.remove('active')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="update_site">
                <input type="hidden" name="site_id" id="edit-site-id">

                <div class="form-group">
                    <label>Site URL</label>
                    <input type="url" id="edit-site-url" disabled style="opacity: 0.6;">
                    <div class="hint">URL cannot be changed. Delete and recreate if needed.</div>
                </div>

                <div class="form-group">
                    <label>Site Name</label>
                    <input type="text" name="site_name" id="edit-site-name">
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>AI Monthly Limit (K tokens)</label>
                        <input type="number" name="ai_monthly_limit" id="edit-ai-limit" min="0">
                    </div>
                    <div class="form-group">
                        <label>PageSpeed Daily Limit</label>
                        <input type="number" name="pagespeed_daily_limit" id="edit-ps-limit" min="0">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label><input type="checkbox" name="ai_enabled" value="1" id="edit-ai-enabled"> Enable AI</label>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="pagespeed_enabled" value="1" id="edit-ps-enabled"> Enable PageSpeed</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Expires At (optional)</label>
                    <input type="datetime-local" name="expires_at" id="edit-expires">
                    <div class="hint">Leave blank for no expiry</div>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="edit-notes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editSite(site) {
    document.getElementById('edit-site-id').value = site.id;
    document.getElementById('edit-site-url').value = site.site_url;
    document.getElementById('edit-site-name').value = site.site_name || '';
    document.getElementById('edit-ai-limit').value = site.ai_monthly_limit;
    document.getElementById('edit-ps-limit').value = site.pagespeed_daily_limit;
    document.getElementById('edit-ai-enabled').checked = !!parseInt(site.ai_enabled);
    document.getElementById('edit-ps-enabled').checked = !!parseInt(site.pagespeed_enabled);
    document.getElementById('edit-expires').value = site.expires_at ? site.expires_at.replace(' ', 'T') : '';
    document.getElementById('edit-notes').value = site.notes || '';
    document.getElementById('edit-site-modal').classList.add('active');
}
</script>

<?php include 'layout-footer.php'; ?>
