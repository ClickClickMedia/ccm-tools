<?php
/**
 * Admin Dashboard
 * 
 * Overview stats: licensed sites, usage metrics, recent activity.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once dirname(__DIR__) . '/config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$currentNav = 'index';

// ─── Gather Stats ───────────────────────────────────────────
$totalSites = (int)dbFetchValue("SELECT COUNT(*) FROM licensed_sites");
$activeSites = (int)dbFetchValue("SELECT COUNT(*) FROM licensed_sites WHERE is_active = 1");
$totalAiCalls = (int)dbFetchValue("SELECT COUNT(*) FROM api_usage_log WHERE request_type = 'ai' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$totalPsCalls = (int)dbFetchValue("SELECT COUNT(*) FROM api_usage_log WHERE request_type = 'pagespeed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Token usage this month
$monthStart = date('Y-m-01 00:00:00');
$monthlyTokens = (int)dbFetchValue(
    "SELECT COALESCE(SUM(tokens_used), 0) FROM api_usage_log WHERE request_type = 'ai' AND created_at >= ?",
    [$monthStart]
);
$monthlyCost = (float)dbFetchValue(
    "SELECT COALESCE(SUM(cost_usd), 0) FROM api_usage_log WHERE request_type = 'ai' AND created_at >= ?",
    [$monthStart]
);

// Recent PageSpeed results (last 10)
$recentResults = dbFetchAll(
    "SELECT pr.*, ls.site_name, ls.site_url 
     FROM pagespeed_results pr 
     JOIN licensed_sites ls ON ls.id = pr.site_id 
     ORDER BY pr.created_at DESC LIMIT 10"
);

// Recent activity (last 15)
$recentActivity = dbFetchAll(
    "SELECT al.*, u.name as user_name 
     FROM activity_log al 
     LEFT JOIN users u ON u.id = al.user_id 
     ORDER BY al.created_at DESC LIMIT 15"
);

// Sites with recent activity
$activeSitesList = dbFetchAll(
    "SELECT ls.*, 
            (SELECT COUNT(*) FROM api_usage_log WHERE site_id = ls.id AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as calls_24h,
            (SELECT COALESCE(SUM(tokens_used), 0) FROM api_usage_log WHERE site_id = ls.id AND created_at >= ?) as monthly_tokens
     FROM licensed_sites ls 
     WHERE ls.is_active = 1 
     ORDER BY ls.last_seen DESC 
     LIMIT 10",
    [$monthStart]
);

include 'layout-header.php';
?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Licensed Sites</div>
        <div class="stat-value"><?= $activeSites ?><span class="text-muted" style="font-size: 0.9rem; font-weight: 400;"> / <?= $totalSites ?></span></div>
        <div class="stat-change neutral">active / total</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">AI Calls (30d)</div>
        <div class="stat-value"><?= formatNumber($totalAiCalls) ?></div>
        <div class="stat-change neutral"><?= formatNumber($monthlyTokens) ?> tokens this month</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">PageSpeed Tests (30d)</div>
        <div class="stat-value"><?= formatNumber($totalPsCalls) ?></div>
        <div class="stat-change neutral">mobile + desktop</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">AI Cost (Month)</div>
        <div class="stat-value"><?= formatCost($monthlyCost) ?></div>
        <div class="stat-change neutral">estimated USD</div>
    </div>
</div>

<!-- Two-column layout -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">

    <!-- Recent PageSpeed Results -->
    <div class="card">
        <div class="card-header">
            <h3>📊 Recent PageSpeed Results</h3>
        </div>
        <?php if (empty($recentResults)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🔍</div>
                <p>No PageSpeed tests yet</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Strategy</th>
                            <th>Score</th>
                            <th>LCP</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentResults as $r): ?>
                            <?php
                            $score = $r['performance_score'] ?? 0;
                            $scoreClass = $score >= 90 ? 'score-good' : ($score >= 50 ? 'score-average' : 'score-poor');
                            ?>
                            <tr>
                                <td class="truncate" style="max-width: 150px;" title="<?= h($r['site_url']) ?>"><?= h($r['site_name'] ?: $r['site_url']) ?></td>
                                <td><span class="badge badge-info"><?= h($r['strategy']) ?></span></td>
                                <td><span class="score-circle <?= $scoreClass ?>" style="width:36px;height:36px;font-size:0.8rem;"><?= $score ?></span></td>
                                <td class="mono"><?= $r['lcp_ms'] ? number_format($r['lcp_ms'] / 1000, 1) . 's' : '—' ?></td>
                                <td class="text-muted"><?= timeAgo($r['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Active Sites -->
    <div class="card">
        <div class="card-header">
            <h3>🌐 Active Sites</h3>
            <a href="sites.php" class="btn btn-ghost btn-sm">View All →</a>
        </div>
        <?php if (empty($activeSitesList)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🌐</div>
                <h3>No sites yet</h3>
                <p>Add a licensed site to get started</p>
                <a href="sites.php" class="btn btn-primary btn-sm">+ Add Site</a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>24h Calls</th>
                            <th>Monthly Tokens</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeSitesList as $site): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;"><?= h($site['site_name'] ?: parse_url($site['site_url'], PHP_URL_HOST)) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= h($site['site_url']) ?></div>
                                </td>
                                <td class="mono"><?= formatNumber($site['calls_24h']) ?></td>
                                <td class="mono"><?= formatNumber($site['monthly_tokens']) ?></td>
                                <td class="text-muted"><?= $site['last_seen'] ? timeAgo($site['last_seen']) : 'Never' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Activity -->
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header">
        <h3>📋 Recent Activity</h3>
    </div>
    <?php if (empty($recentActivity)): ?>
        <div class="empty-state">
            <p>No activity yet</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>User</th>
                        <th>Target</th>
                        <th>IP</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActivity as $a): ?>
                        <tr>
                            <td><span class="badge badge-info"><?= h($a['action']) ?></span></td>
                            <td><?= h($a['user_name'] ?? 'System') ?></td>
                            <td class="text-muted"><?= h(($a['target_type'] ?? '') . ($a['target_id'] ? ' #' . $a['target_id'] : '')) ?></td>
                            <td class="mono text-muted"><?= h($a['ip_address'] ?? '') ?></td>
                            <td class="text-muted"><?= timeAgo($a['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include 'layout-footer.php'; ?>
