<?php
/**
 * Setup Wizard
 * 
 * First-run configuration: sets up Google OAuth, API keys, and admin account.
 * Only accessible when setup_complete = 0 in database.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

define('SETUP_MODE', true);
require_once __DIR__ . '/config/config.php';

// If setup is already complete, redirect to admin
if (Settings::isLoaded() && Settings::get('setup_complete', '0') === '1') {
    redirect(APP_URL . '/admin/');
}

$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_general':
            try {
                Settings::save('app_name', trim($_POST['app_name'] ?? 'CCM API Hub'), false, 'general');
                Settings::save('app_url', rtrim(trim($_POST['app_url'] ?? ''), '/'), false, 'general');
                Settings::save('app_secret_key', generateToken(32), true, 'general');
                redirect(APP_URL . '/setup.php?step=2');
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_oauth':
            try {
                $clientId = trim($_POST['google_client_id'] ?? '');
                $clientSecret = trim($_POST['google_client_secret'] ?? '');
                $wizardEmails = trim($_POST['wizard_emails'] ?? '');

                Settings::save('google_client_id', $clientId, true, 'oauth');
                Settings::save('google_client_secret', $clientSecret, true, 'oauth');
                Settings::save('google_redirect_uri', rtrim(Settings::get('app_url'), '/') . '/auth/google-callback.php', false, 'oauth');
                Settings::save('google_allowed_domain', 'clickclickmedia.com.au', false, 'oauth');
                Settings::save('wizard_emails', $wizardEmails, false, 'oauth');

                redirect(APP_URL . '/setup.php?step=3');
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'save_apis':
            try {
                $claudeKey = trim($_POST['claude_api_key'] ?? '');
                $pagespeedKey = trim($_POST['pagespeed_api_key'] ?? '');

                if (!empty($claudeKey)) {
                    Settings::save('claude_api_key', $claudeKey, true, 'ai');
                }
                if (!empty($pagespeedKey)) {
                    Settings::save('pagespeed_api_key', $pagespeedKey, true, 'pagespeed');
                }

                redirect(APP_URL . '/setup.php?step=4');
            } catch (Exception $e) {
                $error = 'Failed to save: ' . $e->getMessage();
            }
            break;

        case 'complete_setup':
            try {
                Settings::save('setup_complete', '1', false, 'general');
                logActivity('setup_completed', null, null, ['version' => APP_VERSION]);
                redirect(APP_URL . '/login.php');
            } catch (Exception $e) {
                $error = 'Failed to complete setup: ' . $e->getMessage();
            }
            break;
    }
}

$steps = [
    1 => 'General',
    2 => 'Google OAuth',
    3 => 'API Keys',
    4 => 'Complete',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — CCM API Hub</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 2rem;
        }
        .setup-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 2.5rem;
            max-width: 600px;
            width: 100%;
            box-shadow: var(--shadow-lg);
        }
        .setup-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .setup-header h1 {
            color: var(--text-primary);
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }
        .setup-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        .setup-steps {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }
        .setup-step {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .setup-step.active { color: var(--brand-primary); }
        .setup-step.done { color: var(--accent-success); }
        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--bg-hover);
            border: 1px solid var(--border-light);
        }
        .setup-step.active .step-num { background: var(--brand-primary); color: var(--text-inverse); border-color: var(--brand-primary); }
        .setup-step.done .step-num { background: var(--accent-success); color: #fff; border-color: var(--accent-success); }
        .step-divider { width: 24px; height: 1px; background: var(--border-light); margin: 0 0.25rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.4rem;
        }
        .form-group .hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .form-group input[type="text"],
        .form-group input[type="url"],
        .form-group input[type="password"],
        .form-group textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            background: var(--bg-input);
            border: 1px solid var(--border-light);
            border-radius: var(--border-radius-md);
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: var(--font-mono);
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 2px rgba(148, 200, 62, 0.15);
        }
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-light);
        }
        .btn {
            padding: 0.6rem 1.5rem;
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all var(--transition-normal);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .btn-primary { background: var(--brand-primary); color: var(--text-inverse); }
        .btn-primary:hover { background: var(--brand-secondary); }
        .btn-outline { background: transparent; border-color: var(--border-light); color: var(--text-secondary); }
        .btn-outline:hover { border-color: var(--text-secondary); }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius-md);
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: var(--accent-danger); }
        .alert-success { background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: var(--accent-success); }
        .alert-info { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: var(--accent-info); }
        .complete-icon { font-size: 3rem; text-align: center; margin-bottom: 1rem; }
        .complete-text { text-align: center; color: var(--text-secondary); margin-bottom: 2rem; }
        .complete-text h2 { color: var(--accent-success); margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1>🔧 CCM API Hub Setup</h1>
            <p>Configure your centralized API management server</p>
        </div>

        <!-- Step Indicators -->
        <div class="setup-steps">
            <?php foreach ($steps as $num => $label): ?>
                <?php if ($num > 1): ?><div class="step-divider"></div><?php endif; ?>
                <div class="setup-step <?= $num < $step ? 'done' : ($num === $step ? 'active' : '') ?>">
                    <span class="step-num"><?= $num < $step ? '✓' : $num ?></span>
                    <span><?= $label ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <!-- Step 1: General Settings -->
        <?php if ($step === 1): ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_general">
                <div class="form-group">
                    <label>Application Name</label>
                    <input type="text" name="app_name" value="<?= h(Settings::get('app_name', 'CCM API Hub')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Application URL</label>
                    <input type="url" name="app_url" value="<?= h(Settings::get('app_url', 'https://api.tools.clickclick.media')) ?>" required>
                    <div class="hint">The full URL of this installation (no trailing slash)</div>
                </div>
                <div class="alert alert-info">
                    A secure secret key will be generated automatically.
                </div>
                <div class="form-actions">
                    <span></span>
                    <button type="submit" class="btn btn-primary">Next →</button>
                </div>
            </form>

        <!-- Step 2: Google OAuth -->
        <?php elseif ($step === 2): ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_oauth">
                <div class="alert alert-info">
                    Create OAuth credentials at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color: var(--brand-primary);">Google Cloud Console</a>.
                    Set the redirect URI to: <code style="color: var(--brand-primary);"><?= h(rtrim(Settings::get('app_url', 'https://api.tools.clickclick.media'), '/') . '/auth/google-callback.php') ?></code>
                </div>
                <div class="form-group">
                    <label>Google Client ID</label>
                    <input type="text" name="google_client_id" placeholder="xxxx.apps.googleusercontent.com" required>
                </div>
                <div class="form-group">
                    <label>Google Client Secret</label>
                    <input type="password" name="google_client_secret" placeholder="GOCSPX-xxxx" required>
                </div>
                <div class="form-group">
                    <label>Admin Emails (auto-promoted to Admin role)</label>
                    <input type="text" name="wizard_emails" value="rik@clickclickmedia.com.au" placeholder="admin@clickclickmedia.com.au">
                    <div class="hint">Comma-separated. These emails get Admin role on first login.</div>
                </div>
                <div class="form-actions">
                    <a href="?step=1" class="btn btn-outline">← Back</a>
                    <button type="submit" class="btn btn-primary">Next →</button>
                </div>
            </form>

        <!-- Step 3: API Keys -->
        <?php elseif ($step === 3): ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_apis">
                <div class="alert alert-info">
                    API keys are encrypted before storage. You can add or change these later from the admin dashboard.
                </div>
                <div class="form-group">
                    <label>Claude API Key</label>
                    <input type="password" name="claude_api_key" placeholder="sk-ant-xxxx">
                    <div class="hint">From <a href="https://console.anthropic.com/" target="_blank" style="color: var(--brand-primary);">Anthropic Console</a>. Powers AI performance analysis.</div>
                </div>
                <div class="form-group">
                    <label>Google PageSpeed API Key</label>
                    <input type="password" name="pagespeed_api_key" placeholder="AIzaSyxxxx">
                    <div class="hint">From <a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" style="color: var(--brand-primary);">Google Cloud Console</a>. Powers PageSpeed Insights tests.</div>
                </div>
                <div class="form-actions">
                    <a href="?step=2" class="btn btn-outline">← Back</a>
                    <button type="submit" class="btn btn-primary">Next →</button>
                </div>
            </form>

        <!-- Step 4: Complete -->
        <?php elseif ($step === 4): ?>
            <div class="complete-icon">✅</div>
            <div class="complete-text">
                <h2>Setup Complete!</h2>
                <p>Your CCM API Hub is configured and ready. You can now sign in with your Google account and start managing licensed sites.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="complete_setup">
                <div class="form-actions" style="justify-content: center;">
                    <button type="submit" class="btn btn-primary">🚀 Launch Dashboard</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
