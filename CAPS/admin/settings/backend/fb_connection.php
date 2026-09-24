<?php
/**
 * ADMIN/settings/backend/fb_connection.php
 * Facebook Page connection for Settings → Facebook (admin only).
 *
 *  GET  ?action=status    → JSON: is the stored Page token valid, which Page, expiry, permissions
 *  GET  ?action=connect   → redirects to the official Facebook Login dialog ("Reconnect Facebook")
 *  GET  ?action=callback  → Facebook returns here; the new token is exchanged and saved automatically
 *  POST action=save_system_token → saves a never-expiring System User token (Meta Business Settings)
 *
 * The token is stored by the existing helpers in announcement/backend/fb_config.php
 * (fb_token_store.json), so posting / analytics keep using fb_get_page_token() unchanged.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../activity_log_helper.php';

if (!defined('SOE_LIB_INCLUDE')) {
    define('SOE_LIB_INCLUDE', true);
}
require_once __DIR__ . '/../../announcement/backend/fb_helper.php'; // loads fb_config.php

const FB_REQUIRED_SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];
const FB_OPTIONAL_SCOPES = ['read_insights']; // views / reach on Announcement Analytics

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

function fbc_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function fbc_back(array $query): void
{
    header('Location: ../frontend/settings.php?' . http_build_query(['tab' => 'facebook'] + $query));
    exit;
}

/** Absolute URL of this file — must be listed in the app's "Valid OAuth Redirect URIs". */
function fbc_redirect_uri(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/CAPS/admin/settings/backend/fb_connection.php', '?');
    return $scheme . '://' . $host . $path . '?action=callback';
}

/** Inspect any token with the app token (no user login needed). */
function fbc_debug_token(string $token): array
{
    $res = fb_graph_request('GET', '/debug_token', [
        'input_token' => $token,
        'access_token' => FB_APP_ID . '|' . FB_APP_SECRET,
    ]);
    return $res['data']['data'] ?? ['is_valid' => false, 'error' => $res['data']['error'] ?? null];
}

if (!$isAdmin) {
    if ($action === 'status' || $_SERVER['REQUEST_METHOD'] === 'POST') {
        fbc_json(['success' => false, 'error' => 'Only administrators can manage the Facebook connection.'], 403);
    }
    fbc_back(['fb_error' => 'Only administrators can manage the Facebook connection.']);
}

switch ($action) {

    // ── Connection status ────────────────────────────────────────────────────
    case 'status':
        $store = _fb_load_store();
        $out = [
            'success' => true,
            'connected' => false,
            'redirect_uri' => fbc_redirect_uri(),
            'page_id' => FB_PAGE_ID,
            'token_type' => $store['token_type'] ?? (empty($store) ? null : 'login'),
            'obtained_at' => $store['page_token_obtained'] ?? null,
            'connected_by' => $store['connected_by'] ?? null,
        ];
        if (empty($store['page_token'])) {
            $out['error'] = 'No Facebook Page token saved yet.';
            fbc_json($out);
        }

        $dbg = fbc_debug_token($store['page_token']);
        $scopes = $dbg['scopes'] ?? [];
        $out['valid'] = !empty($dbg['is_valid']);
        $out['expires_at'] = isset($dbg['expires_at']) ? (int) $dbg['expires_at'] : null; // 0 = never
        $out['data_access_expires_at'] = isset($dbg['data_access_expires_at']) ? (int) $dbg['data_access_expires_at'] : null;
        $out['scopes'] = $scopes;
        $out['missing_scopes'] = array_values(array_diff(FB_REQUIRED_SCOPES, $scopes));
        $out['missing_optional'] = array_values(array_diff(FB_OPTIONAL_SCOPES, $scopes));

        if ($out['valid']) {
            $me = fb_graph_request('GET', '/me', ['fields' => 'id,name', 'access_token' => $store['page_token']]);
            $out['page_name'] = $me['data']['name'] ?? null;
            $out['page_id'] = $me['data']['id'] ?? FB_PAGE_ID;
            $out['connected'] = $me['success'] && (string) $out['page_id'] === (string) FB_PAGE_ID;
            if (!$out['connected']) {
                $out['error'] = $me['success']
                    ? 'The saved token belongs to a different Page (' . $out['page_name'] . ').'
                    : ($me['data']['error']['message'] ?? 'Could not read the Page with the saved token.');
            }
        } else {
            $out['error'] = $dbg['error']['message'] ?? 'The saved Facebook token has expired or was revoked. Please reconnect.';
        }
        fbc_json($out);

    // ── "Reconnect Facebook": send the admin to the Facebook Login dialog ───
    case 'connect':
        $_SESSION['fb_oauth_state'] = bin2hex(random_bytes(16));
        header('Location: https://www.facebook.com/' . FB_GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id' => FB_APP_ID,
            'redirect_uri' => fbc_redirect_uri(),
            'state' => $_SESSION['fb_oauth_state'],
            'scope' => implode(',', array_merge(FB_REQUIRED_SCOPES, FB_OPTIONAL_SCOPES)),
            'auth_type' => 'rerequest',
            'response_type' => 'code',
        ]));
        exit;

    // ── Facebook sends the admin back here ──────────────────────────────────
    case 'callback':
        $expected = $_SESSION['fb_oauth_state'] ?? '';
        unset($_SESSION['fb_oauth_state']);

        if (!empty($_GET['error'])) {
            fbc_back(['fb_error' => 'Facebook login was cancelled: ' . ($_GET['error_description'] ?? $_GET['error'])]);
        }
        if ($expected === '' || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
            fbc_back(['fb_error' => 'The Facebook login session expired. Please click Reconnect Facebook again.']);
        }

        try {
            // code → short-lived user token
            $res = _fb_curl_get(FB_GRAPH_BASE . '/oauth/access_token?' . http_build_query([
                'client_id' => FB_APP_ID,
                'client_secret' => FB_APP_SECRET,
                'redirect_uri' => fbc_redirect_uri(),
                'code' => (string) ($_GET['code'] ?? ''),
            ]));
            if (empty($res['access_token'])) {
                throw new RuntimeException($res['error']['message'] ?? 'Facebook did not return an access token.');
            }

            // short-lived → long-lived user token → Page token (saved to fb_token_store.json)
            fb_bootstrap_token($res['access_token']);

            $store = _fb_load_store();
            $store['token_type'] = 'login';
            $store['connected_by'] = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Administrator';
            _fb_save_store($store);

            log_activity('Settings', 'Reconnect Facebook', 'Facebook Page connection renewed via Facebook Login.');
            fbc_back(['fb' => 'connected']);
        } catch (Throwable $e) {
            error_log('[fb_connection] ' . $e->getMessage());
            $msg = $e->getMessage();
            if (stripos($msg, 'Page Access Token') !== false) {
                $msg = 'Logged in, but this Facebook account cannot manage the barangay Page. Log in with an account that is an admin of the Page and allow all requested permissions.';
            }
            fbc_back(['fb_error' => $msg]);
        }

    // ── Option 1: never-expiring System User token ──────────────────────────
    case 'save_system_token':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            fbc_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            fbc_json(['success' => false, 'error' => 'Your session has expired. Please refresh the page and try again.'], 403);
        }

        $token = trim((string) ($_POST['token'] ?? ''));
        if ($token === '' || strlen($token) < 50 || preg_match('/\s/', $token)) {
            fbc_json(['success' => false, 'error' => 'Please paste the full access token.']);
        }

        $dbg = fbc_debug_token($token);
        if (empty($dbg['is_valid'])) {
            fbc_json(['success' => false, 'error' => 'Facebook says this token is not valid: ' . ($dbg['error']['message'] ?? 'unknown reason')]);
        }
        if ((string) ($dbg['app_id'] ?? '') !== (string) FB_APP_ID) {
            fbc_json(['success' => false, 'error' => 'This token was generated for a different Facebook app. Generate it for app ID ' . FB_APP_ID . '.']);
        }
        $missing = array_values(array_diff(FB_REQUIRED_SCOPES, $dbg['scopes'] ?? []));
        if ($missing) {
            fbc_json(['success' => false, 'error' => 'The token is missing these permissions: ' . implode(', ', $missing)]);
        }

        // A System User token is a USER-type token; get the Page token from it.
        // A PAGE-type token (already for our Page) can be stored as is.
        if (($dbg['type'] ?? '') === 'PAGE') {
            if ((string) ($dbg['profile_id'] ?? '') !== (string) FB_PAGE_ID) {
                fbc_json(['success' => false, 'error' => 'This Page token is for a different Page.']);
            }
            $pageToken = $token;
        } else {
            $pageToken = _fb_fetch_page_token($token);
            if (!$pageToken) {
                fbc_json(['success' => false, 'error' => 'The System User has no access to the barangay Page (ID ' . FB_PAGE_ID . '). Assign the Page to the System User first.']);
            }
        }

        // No user_token/user_token_expires saved → fb_get_page_token() never tries to refresh it.
        _fb_save_store([
            'page_token' => $pageToken,
            'page_token_obtained' => date('Y-m-d H:i:s'),
            'page_id' => FB_PAGE_ID,
            'token_type' => 'system',
            'system_token_expires' => (int) ($dbg['expires_at'] ?? 0),
            'connected_by' => $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Administrator',
            'bootstrapped_at' => date('Y-m-d H:i:s'),
        ]);

        log_activity('Settings', 'Facebook System Token', 'Facebook Page connected with a System User token.');
        fbc_json([
            'success' => true,
            'message' => ((int) ($dbg['expires_at'] ?? 0)) === 0
                ? 'Facebook connected with a never-expiring System User token.'
                : 'Facebook connected. Note: this token has an expiry date — generate it with "Never" expiration to avoid reconnecting.',
        ]);

    default:
        fbc_json(['success' => false, 'error' => 'Unknown action.'], 400);
}
