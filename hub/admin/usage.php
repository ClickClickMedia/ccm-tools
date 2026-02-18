<?php
/**
 * Usage Analytics
 * 
 * Detailed API usage stats per site: AI tokens, PageSpeed tests, costs.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once dirname(__DIR__) . '/config/config.php';
requireLogin();

$pageTitle = 'Usage Analytics';
$currentNav = 'usage';

// Filters
$siteId = (int)($_GET['site_id'] ?? 0);
$period = $_GET['period'] ?? '30d';
$type = $_GET['type'] ?? 'all';

// Calculate date range
$dateStart = match ($period) {
    '24h' => date('Y-m-d H:i:s', strtotime('-24 hours')),
    '7d'  => date('Y-m-d H:i:s', strtotime('-7 days')),
    '30d' => date('Y-m-d H:i:s', strtotime('-30 days')),
    '90d' => date('Y-m-d H:i:s', strtotime('-90 days')),
    default => date('Y-m-d H:i:s', strtotime('-30 days')),
};

// Build query conditions
$where = "created_at >= ?";
$params = [$dateStart];

if ($siteId > 0) {
    $where .= " AND site_id = ?";
    $params[] = $siteId;
}

if ($type !== 'all') {
    $where .= " AND request_type = ?";
    $params[] = $type;
}

// Aggregate stats
$totalRequests = (int)dbFetchValue("SELECT COUNT(*) FROM api_usage_log WHERE {$where}", $params);
$totalTokens = (int)dbFetchValue("SELECT COALESCE(SUM(tokens_used), 0) FROM api_usage_log WHERE {$where}", $params);
$totalCost = (float)dbFetchValue("SELECT COALESCE(SUM(cost_usd), 0) FROM api_usage_log WHERE {$where}", $params);
$avgResponseTime = (int)dbFetchValue("SELECT COALESCE(AVG(response_time_ms), 0) FROM api_usage_log WHERE {$where}", $params);
$errorCount = (int)dbFetchValue("SELECT COUNT(*) FROM api_usage_log WHERE {$where} AND status_code >= 400", $params);

// Per-site breakdown
$siteBreakdown = dbFetchAll(
    "SELECT ls.site_name, ls.site_url, ls.id as site_id,
            COUNT(*) as total_calls,
            SUM(CASE WHEN aul.request_type = 'ai' THEN 1 ELSE 0 END) as ai_calls,
            SUM(CASE WHEN aul.request_type = 'pagespeed' THEN 1 ELSE 0 END) as ps_calls,
            COALESCE(SUM(aul.tokens_used), 0) as total_tokens,
            COALESCE(SUM(aul.cost_usd), 0) as total_cost,
            COALESCE(AVG(aul.response_time_ms), 0) as avg_response
     FROM api_usage_log aul
     JOIN licensed_sites ls ON ls.id = aul.site_id
     WHERE aul.created_at >= ?
     GROUP BY ls.id
     ORDER BY total_tokens DESC",
    [$dateStart]
);

// Daily usage chart data (last 30 days)
$dailyUsage = dbFetchAll(
    "SELECT DATE(created_at) as day,
            SUM(CASE WHEN request_type = 'ai' THEN tokens_used ELSE 0 END) as ai_tokens,
            SUM(CASE WHEN request_type = 'pagespeed' THEN 1 ELSE 0 END) as ps_tests,
            COALESCE(SUM(cost_usd), 0) as daily_cost
     FROM api_usage_log
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY DATE(created_at)
     ORDER BY day ASC"
);

// Recent log entries
$recentLogs = dbFetchAll(
    "SELECT aul.*, ls.site_name, ls.site_url
     FROM api_usage_log aul
     JOIN licensed_sites ls ON ls.id = aul.site_id
     WHERE aul.{$where}
     ORDER BY aul.created_at DESC
     LIMIT 50",
    $params
);

// Sites for filter dropdown
$allSites = dbFetchAll("SELECT id, site_name, site_url FROM licensed_sites ORDER BY site_name ASC");

include 'layout-header.php';
?>

<div class="page-header">
    <div>
        <h1>📈 Usage Analytics</h1>
        <p class="text-muted">API usage, token consumption, and costs</p>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>Period</label>
                <select name="period" onchange="this.form.submit()">
                    <option value="24h" <?= $period === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90d" <?= $period === '90d' ? 'selected' : '' ?>>Last 90 Days</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Site</label>
                <select name="site_id" onchange="this.form.submit()">
                    <option value="0">All Sites</option>
                    <?php foreach ($allSites as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $siteId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['site_name'] ?: $s['site_url']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Type</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="ai" <?= $type === 'ai' ? 'selected' : '' ?>>AI Only</option>
                    <option value="pagespeed" <?= $type === 'pagespeed' ? 'selected' : '' ?>>PageSpeed Only</option>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Requests</div>
        <div class="stat-value"><?= formatNumber($totalRequests) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Tokens Used</div>
        <div class="stat-value"><?= formatNumber($totalTokens) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Estimated Cost</div>
        <div class="stat-value"><?= formatCost($totalCost) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg Response</div>
        <div class="stat-value"><?= formatNumber($avgResponseTime) ?><span style="font-size: 0.9rem; font-weight: 400;">ms</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Errors</div>
        <div class="stat-value <?= $errorCount > 0 ? 'text-danger' : '' ?>"><?= formatNumber($errorCount) ?></div>
    </div>
</div>

<!-- Per-Site Breakdown -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <h3>Per-Site Breakdown</h3>
    </div>
    <?php if (empty($siteBreakdown)): ?>
        <div class="empty-state"><p>No usage data for this period</p></div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Total Calls</th>
                        <th>AI Calls</th>
                        <th>PS Tests</th>
                        <th>Tokens</th>
                        <th>Cost</th>
                        <th>Avg Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($siteBreakdown as $sb): ?>
                        <tr>
                            <td>
                                <a href="?site_id=<?= $sb['site_id'] ?>&period=<?= h($period) ?>" style="font-weight: 500;">
                                    <?= h($sb['site_name'] ?: parse_url($sb['site_url'], PHP_URL_HOST)) ?>
                                </a>
                            </td>
                            <td class="mono"><?= formatNumber($sb['total_calls']) ?></td>
                            <td class="mono"><?= formatNumber($sb['ai_calls']) ?></td>
                            <td class="mono"><?= formatNumber($sb['ps_calls']) ?></td>
                            <td class="mono"><?= formatNumber($sb['total_tokens']) ?></td>
                            <td class="mono"><?= formatCost($sb['total_cost']) ?></td>
                            <td class="mono"><?= formatNumber($sb['avg_response']) ?>ms</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Recent API Calls -->
<div class="card">
    <div class="card-header">
        <h3>Recent API Calls</h3>
        <span class="text-muted"><?= $totalRequests ?> total</span>
    </div>
    <?php if (empty($recentLogs)): ?>
        <div class="empty-state"><p>No API calls for this period</p></div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Site</th>
                        <th>Endpoint</th>
                        <th>Type</th>
                        <th>Tokens</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td class="text-muted" style="white-space: nowrap;"><?= date('M j H:i', strtotime($log['created_at'])) ?></td>
                            <td class="truncate" style="max-width: 120px;" title="<?= h($log['site_url']) ?>"><?= h($log['site_name'] ?: '') ?></td>
                            <td class="mono"><?= h($log['endpoint']) ?></td>
                            <td>
                                <?php if ($log['request_type'] === 'ai'): ?>
                                    <span class="badge badge-success">AI</span>
                                <?php elseif ($log['request_type'] === 'pagespeed'): ?>
                                    <span class="badge badge-info">PS</span>
                                <?php else: ?>
                                    <span class="badge"><?= h($log['request_type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= $log['tokens_used'] ? formatNumber($log['tokens_used']) : '—' ?></td>
                            <td class="mono"><?= $log['cost_usd'] > 0 ? formatCost($log['cost_usd']) : '—' ?></td>
                            <td>
                                <?php
                                $sc = (int)$log['status_code'];
                                $scClass = $sc >= 200 && $sc < 300 ? 'text-success' : ($sc >= 400 ? 'text-danger' : 'text-warning');
                                ?>
                                <span class="mono <?= $scClass ?>"><?= $sc ?: '—' ?></span>
                            </td>
                            <td class="mono text-muted"><?= $log['response_time_ms'] ? $log['response_time_ms'] . 'ms' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include 'layout-footer.php'; ?>
