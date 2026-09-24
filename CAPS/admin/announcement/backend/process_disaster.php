<?php
/**
 * process_disaster.php  —  Enhanced with:
 *   - Geo-radius SMS targeting for Fire & Flood
 *   - Hazard record auto-create/update on Fire & Flood alerts
 *   - Geo Maps link in SMS body
 *   - Linked hazard soft-delete on alert deactivation
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/csrf_helper.php';
require_once __DIR__ . '/../../activity_log_helper.php';
require_once __DIR__ . '/sms_recipient_log.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST')
    csrf_verify();

// ─── Load the active SMS configuration ──────────────────────────────────────
function getActiveSMSConfig($pdo)
{
    try {
        $stmt = $pdo->prepare(
            "SELECT api_key, from_number, device_id, api_url, configuration_name
             FROM sms_configurations
             WHERE status = 'Active'
             LIMIT 1"
        );
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) {
            error_log("Disaster SMS: No active SMS configuration found.");
            return null;
        }
        return $config;
    } catch (PDOException $e) {
        error_log("Disaster SMS: Failed to load SMS config — " . $e->getMessage());
        return null;
    }
}

// ─── SMS sender ──────────────────────────────────────────────────────────────
// Returns ['sent' => int, 'failed' => int] so callers can log real delivery counts.
function sendDisasterSMS($pdo, $residents, $sms_message)
{
    $sent = 0;
    $failed = 0;
    $results = []; // ResidentID => ['status' => sent|failed|invalid, 'detail' => …] for sms_recipient_logs

    $config = getActiveSMSConfig($pdo);
    if (!$config) {
        error_log("Disaster SMS: Aborting — no active SMS configuration.");
        return ['sent' => 0, 'failed' => count($residents), 'results' => []];
    }

    $api_key = $config['api_key'];
    $from_number = $config['from_number'];
    $device_id = $config['device_id'];
    $api_url = $config['api_url'];

    error_log("Disaster SMS: Using configuration — " . ($config['configuration_name'] ?? 'unknown'));

    foreach ($residents as $resident) {
        if (empty($resident['ContactNumber']))
            continue;

        $phone = preg_replace('/[^0-9]/', '', $resident['ContactNumber']);
        if (strlen($phone) < 10) {
            error_log("Disaster SMS: Skipping invalid number for ResidentID " . ($resident['ResidentID'] ?? 'unknown'));
            $failed++;
            $results[(int) ($resident['ResidentID'] ?? 0)] = ['status' => 'invalid', 'detail' => 'Number too short: ' . $resident['ContactNumber']];
            continue;
        }

        if (substr($phone, 0, 2) === '63') {
            $formatted_to = '+' . $phone;
        } elseif (substr($phone, 0, 1) === '0') {
            $formatted_to = '+63' . substr($phone, 1);
        } else {
            $formatted_to = '+63' . $phone;
        }

        $payload = [
            "to" => $formatted_to,
            "message" => $sms_message,
            // InfiniReach requires the registered sender number in `from`.
            // `e164From` and `device_id` were legacy fields and cause current
            // gateway requests to be rejected.
            "from" => $from_number,
            "channel" => "sms",
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-Key: $api_key", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        $response = json_decode($result, true);
        curl_close($ch);

        error_log("Disaster SMS | TO: $formatted_to | HTTP: $http_code | cURL: $curl_err | Response: $result");

        $is_success = false;
        if ($http_code >= 200 && $http_code < 300) {
            // The current gateway acknowledges accepted messages with
            // `success: true` and `messageId` (not the legacy `id` field).
            if (!empty($response['success']) || !empty($response['messageId']) || !empty($response['id'])) {
                $is_success = true;
            } elseif (isset($response['status']) && !in_array(strtolower($response['status']), ['failed', 'error', 'rejected'])) {
                $is_success = true;
            } elseif (is_array($response) && !isset($response['error']) && !isset($response['message'])) {
                $is_success = true;
            }
        }

        if ($is_success) {
            $sent++;
            // "sent" = accepted by the gateway; the gateway phone sends it afterwards.
            // Keep the messageId so SMS Live can check if the phone really sent it (no load, no signal…).
            $results[(int) ($resident['ResidentID'] ?? 0)] = [
                'status' => 'sent',
                'detail' => 'Accepted by the SMS gateway',
                'message_id' => $response['messageId'] ?? $response['id'] ?? $response['data']['messageId'] ?? $response['data']['id'] ?? null,
            ];
        } else {
            $failed++;
            $results[(int) ($resident['ResidentID'] ?? 0)] = [
                'status' => 'failed',
                'detail' => $curl_err ?: ('HTTP ' . $http_code . (is_array($response) && !empty($response['message']) ? ': ' . $response['message'] : '')),
            ];
            error_log("FAILED Disaster SMS to $formatted_to | HTTP: $http_code | Body: $result");
        }

        usleep(250000); // 0.25s delay to prevent rate limiting
    }

    return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
}

// ─── Log a disaster SMS broadcast so the SMS Live / History panel can show it ─
// Writes one row per broadcast (create or update) into sms_logs, tagged with
// the disaster's AlertID so the admin can tell which alert each SMS batch
// belongs to. Kept even after the disaster is deactivated, for History.
function logDisasterSMS($pdo, $alertId, $totalRecipients, $sentCount)
{
    try {
        $status = ($sentCount >= $totalRecipients && $totalRecipients > 0) ? 'Completed'
            : ($sentCount > 0 ? 'Partial' : 'Failed');
        $pdo->prepare(
            "INSERT INTO sms_logs (AlertID, total_recipients, sent_count, status)
             VALUES (?, ?, ?, ?)"
        )->execute([$alertId, $totalRecipients, $sentCount, $status]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("logDisasterSMS error: " . $e->getMessage());
        return 0;
    }
}

// ─── Fetch SMS recipients ────────────────────────────────────────────────────
/**
 * Returns residents array.
 * If $audience === 'radius', uses $lat/$lng/$radius to find residents within
 * the Haversine great-circle distance (MySQL calculation, no PostGIS needed).
 */
function getSMSRecipients(
    $pdo,
    $audience,
    $selected_streets,
    $selected_areas,
    $selected_groups,
    $lat = null,
    $lng = null,
    $radius = null,
    $includeNoNumber = false
) {
    $residents = [];
    // With $includeNoNumber = true the SAME audience is returned including residents
    // who have no contact number, so the SMS report can count who could not be reached.
    $cc = $includeNoNumber ? '1=1' : "ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''";
    $cols = 'ResidentID, ContactNumber, FirstName, LastName, StreetName, AreaType, AreaName, Purok';

    try {
        if ($audience === 'all') {
            $stmt = $pdo->query(
                "SELECT {$cols} FROM residents
                 WHERE IsDeceased = 0 AND {$cc}"
            );
            $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($audience === 'radius' && $lat !== null && $lng !== null && $radius !== null) {
            // Haversine formula in SQL — radius in metres
            $stmt = $pdo->prepare(
                "SELECT {$cols}
                 FROM residents
                 WHERE IsDeceased = 0 AND {$cc}
                   AND Latitude IS NOT NULL AND Longitude IS NOT NULL
                   AND (
                       6371000 * 2 * ASIN(SQRT(
                           POWER(SIN(RADIANS(:lat2 - Latitude)  / 2), 2) +
                           COS(RADIANS(Latitude)) * COS(RADIANS(:lat1)) *
                           POWER(SIN(RADIANS(:lng2 - Longitude) / 2), 2)
                       ))
                   ) <= :radius"
            );
            $stmt->execute([
                ':lat1' => (float) $lat,
                ':lat2' => (float) $lat,
                ':lng2' => (float) $lng,
                ':radius' => (int) $radius,
            ]);
            $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($audience === 'area') {
            // Resident Management stores managed areas in residents.AreaName +
            // residents.AreaType. The Announcement UI sends values as
            // "AreaType|AreaName". Streets are also supported in the same
            // selector; when both are chosen, matching either condition gets SMS.
            $or = [];
            $params = [];

            foreach ((array) $selected_streets as $street) {
                $street = trim(strip_tags((string) $street));
                if ($street !== '') {
                    $or[] = 'StreetName = ?';
                    $params[] = $street;
                }
            }

            foreach ((array) $selected_areas as $area) {
                $area = trim(strip_tags((string) $area));
                if ($area === '')
                    continue;

                // New format: AreaType|AreaName
                if (strpos($area, '|') !== false) {
                    [$areaType, $areaName] = array_pad(explode('|', $area, 2), 2, '');
                    $areaType = trim($areaType);
                    $areaName = trim($areaName);
                    if ($areaType !== '' && $areaName !== '') {
                        $or[] = '(AreaType = ? AND AreaName = ?)';
                        $params[] = $areaType;
                        $params[] = $areaName;
                    }
                } else {
                    // Backward compatibility with old Purok-only selector.
                    $or[] = '(Purok = ? OR (AreaType = \'Purok\' AND AreaName = ?))';
                    $params[] = $area;
                    $params[] = $area;
                }
            }

            if (!empty($or)) {
                $sql = "SELECT {$cols}
                        FROM residents
                        WHERE IsDeceased = 0
                          AND {$cc}
                          AND (" . implode(' OR ', $or) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

        } elseif ($audience === 'purok' && !empty($selected_areas)) {
            // Legacy fallback for old forms that still post sms_audience=purok.
            $legacyPuroks = [];
            foreach ((array) $selected_areas as $value) {
                $value = trim(strip_tags((string) $value));
                if ($value !== '')
                    $legacyPuroks[] = $value;
            }
            if (!empty($legacyPuroks)) {
                $placeholders = implode(',', array_fill(0, count($legacyPuroks), '?'));
                $stmt = $pdo->prepare(
                    "SELECT {$cols} FROM residents
                     WHERE IsDeceased = 0 AND {$cc}
                       AND Purok IN ($placeholders)"
                );
                $stmt->execute($legacyPuroks);
                $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

        } elseif ($audience === 'vulnerable' && !empty($selected_groups)) {
            $conditions = [];
            foreach ($selected_groups as $group) {
                if ($group === 'senior')
                    $conditions[] = "IsSenior = 1";
                elseif ($group === 'pwd')
                    $conditions[] = "IsPWD = 1";
                elseif ($group === 'infant')
                    $conditions[] = "vulnerability_type = 'infant'";
            }
            if (!empty($conditions)) {
                $where = implode(' OR ', $conditions);
                $stmt = $pdo->query(
                    "SELECT {$cols} FROM residents
                     WHERE IsDeceased = 0 AND {$cc}
                     AND ($where)"
                );
                $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        error_log("Disaster SMS recipient fetch error: " . $e->getMessage());
    }

    return $residents;
}

// ─── Build SMS message (geo-aware) ───────────────────────────────────────────
/**
 * For Fire and Flood with a pinned location, appends a Google Maps link.
 */
function buildSMSMessage(
    $title,
    $severity,
    $message,
    $evacuation_center,
    $prefix = '',
    $type = '',
    $lat = null,
    $lng = null
) {
    $sms = "[Barangay Binang 2nd" . ($prefix ? " $prefix" : "") . " Alert]\n\n";

    if (!empty($type)) {
        $sms .= strtoupper($type) . " ALERT\n\n";
    }
    $sms .= "Title: " . $title . "\n";
    $sms .= "Severity: " . strtoupper($severity) . "\n\n";

    $sms .= "Message:\n" . $message . "\n\n";

    // Append Google Maps link for Fire/Flood with coordinates
    if (!empty($lat) && !empty($lng) && ($type === 'Fire' || $type === 'Flood')) {
        $sms .= "Location:\nhttps://maps.google.com/?q=" . $lat . "," . $lng . "\n\n";
    }

    if (!empty($evacuation_center) && $evacuation_center !== 'None') {
        $sms .= "Evacuation Center: " . $evacuation_center . "\n\n";
    }

    date_default_timezone_set('Asia/Manila');
    $sms .= "Date: " . date('M j, Y g:i A');

    return $sms;
}

// ─── Sync hazard record for Fire/Flood alert ─────────────────────────────────
/**
 * Creates a new hazard or updates an existing linked hazard so Risk Mapping
 * stays synchronised with the alert.
 *
 * Returns the hazard id that was created / updated (or null on failure).
 */
function syncHazard(
    $pdo,
    $linkedHazardId,
    $type,
    $title,
    $message,
    $severity,
    $lat,
    $lng,
    $radius,
    $alertStatus = 'active'
) {
    if (empty($lat) || empty($lng))
        return null;

    $hazardType = $type; // 'Fire' or 'Flood' — matches hazards.Type values
    $hazardRadius = (int) ($radius ?: 200);

    // Severity → numeric level for hazard record
    $severityMap = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4];
    $severityNum = $severityMap[$severity] ?? 2;

    try {
        if ($linkedHazardId && (int) $linkedHazardId > 0) {
            // Update existing hazard
            $pdo->prepare(
                "UPDATE hazards
                    SET Title       = :title,
                        Type        = :type,
                        Description = :desc,
                        Lat         = :lat,
                        Lng         = :lng,
                        Radius      = :radius,
                        Severity    = :severity
                  WHERE id = :id
                    AND (is_deleted = 0 OR is_deleted IS NULL)"
            )->execute([
                        ':title' => $title,
                        ':type' => $hazardType,
                        ':desc' => $message,
                        ':lat' => $lat,
                        ':lng' => $lng,
                        ':radius' => $hazardRadius,
                        ':severity' => $severityNum,
                        ':id' => (int) $linkedHazardId,
                    ]);
            return (int) $linkedHazardId;

        } else {
            // Create new hazard
            $pdo->prepare(
                "INSERT INTO hazards (Title, Type, Description, Lat, Lng, Radius, Severity)
                 VALUES (:title, :type, :desc, :lat, :lng, :radius, :severity)"
            )->execute([
                        ':title' => $title,
                        ':type' => $hazardType,
                        ':desc' => $message,
                        ':lat' => $lat,
                        ':lng' => $lng,
                        ':radius' => $hazardRadius,
                        ':severity' => $severityNum,
                    ]);
            return (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        error_log("syncHazard error: " . $e->getMessage());
        return null;
    }
}

// ─── Soft-delete linked hazard ────────────────────────────────────────────────
function softDeleteLinkedHazard($pdo, $alertId)
{
    try {
        // disaster_alerts stores linked_hazard_id — soft-delete it
        $row = $pdo->prepare("SELECT linked_hazard_id FROM disaster_alerts WHERE AlertID = ?");
        $row->execute([$alertId]);
        $data = $row->fetch(PDO::FETCH_ASSOC);

        if ($data && !empty($data['linked_hazard_id'])) {
            $pdo->prepare(
                "UPDATE hazards
                    SET is_deleted = 1, deleted_at = NOW()
                  WHERE id = ?
                    AND (is_deleted = 0 OR is_deleted IS NULL)"
            )->execute([$data['linked_hazard_id']]);
        }
    } catch (PDOException $e) {
        error_log("softDeleteLinkedHazard error: " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
// POST HANDLER
// ════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ─── ACTION: CREATE ALERT ─────────────────────────────────────────────────
    if ($_POST['action'] === 'create') {
        $type = $_POST['type'] ?? '';
        $severity = $_POST['severity'] ?? '';
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $evacuation_center = $_POST['evacuation_center'] ?? 'None';
        $notify_app = isset($_POST['notify_app']) ? 1 : 0;
        $notify_sms = isset($_POST['notify_sms']) ? 1 : 0;

        // Geo fields (only for Fire/Flood)
        $isGeoType = in_array($type, ['Fire', 'Flood']);
        $hazard_lat = ($isGeoType && !empty(trim($_POST['hazard_lat'] ?? ''))) ? trim($_POST['hazard_lat']) : null;
        $hazard_lng = ($isGeoType && !empty(trim($_POST['hazard_lng'] ?? ''))) ? trim($_POST['hazard_lng']) : null;
        $hazard_radius = ($isGeoType && !empty(trim($_POST['hazard_radius'] ?? ''))) ? (int) $_POST['hazard_radius'] : 200;

        // Legacy text incident_location (keep for backwards compatibility)
        $incident_location = ($isGeoType && !empty(trim($_POST['incident_location'] ?? '')))
            ? trim($_POST['incident_location'])
            : ($hazard_lat ? ($hazard_lat . ', ' . $hazard_lng) : null);

        try {
            $pdo->beginTransaction();

            // 1. Sync hazard record first (if geo data supplied) to get hazard id
            $linked_hazard_id = null;
            if ($isGeoType && $hazard_lat && $hazard_lng) {
                $linked_hazard_id = syncHazard(
                    $pdo,
                    null,
                    $type,
                    $title,
                    $message,
                    $severity,
                    $hazard_lat,
                    $hazard_lng,
                    $hazard_radius,
                    'active'
                );
            }

            // 2. Insert disaster alert (include linked_hazard_id, hazard coords)
            $sql = "INSERT INTO disaster_alerts
                        (Type, Severity, Title, Message, IncidentLocation, EvacuationCenter,
                         Status, notify_app, notify_sms,
                         HazardLat, HazardLng, HazardRadius, linked_hazard_id)
                    VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $type,
                $severity,
                $title,
                $message,
                $incident_location,
                $evacuation_center,
                $notify_app,
                $notify_sms,
                $hazard_lat,
                $hazard_lng,
                $hazard_radius,
                $linked_hazard_id,
            ]);
            $new_alert_id = $pdo->lastInsertId();

            $pdo->commit();

            log_activity('Disaster and Risk Map', 'Issue Alert', "Issued {$type} alert \"{$title}\" with {$severity} severity.");

            // ── PUSH NOTIFICATION (DO NOT TOUCH) ─────────────────────────────
            if ($notify_app) {
                // Push notification logic preserved as-is
            }

            // ── SMS NOTIFICATION ──────────────────────────────────────────────
            $sms_notice = ' and the SMS was accepted by the gateway — see SMS Live to confirm it was really sent.';
            if ($notify_sms) {
                $audience = $_POST['sms_audience'] ?? 'all';
                $selected_streets = $_POST['selected_streets'] ?? [];
                $selected_areas = $_POST['selected_areas'] ?? [];
                $selected_groups = $_POST['selected_groups'] ?? [];

                // Build geo-aware SMS body
                $sms_message = buildSMSMessage(
                    $title,
                    $severity,
                    $message,
                    $evacuation_center,
                    '',
                    $type,
                    $hazard_lat,
                    $hazard_lng
                );

                // For radius audience, use geo targeting; else use existing logic
                $residents = getSMSRecipients(
                    $pdo,
                    $audience,
                    $selected_streets,
                    $selected_areas,
                    $selected_groups,
                    $hazard_lat,
                    $hazard_lng,
                    $hazard_radius
                );

                // Everyone in the chosen audience, INCLUDING residents without a number,
                // so SMS Live can show e.g. "30 targeted · 20 sent · 10 no number".
                $targeted = getSMSRecipients(
                    $pdo,
                    $audience,
                    $selected_streets,
                    $selected_areas,
                    $selected_groups,
                    $hazard_lat,
                    $hazard_lng,
                    $hazard_radius,
                    true
                );
                $noNumber = max(0, count($targeted) - count($residents));
                $noNumberNote = $noNumber > 0
                    ? " {$noNumber} targeted resident(s) have no contact number — see SMS Live → View breakdown."
                    : '';

                if (!getActiveSMSConfig($pdo)) {
                    $sms_notice = ' The alert was saved, but no SMS was sent because there is no active SMS configuration.';
                } elseif (empty($residents)) {
                    $sms_notice = ' The alert was saved, but no SMS was sent because none of the ' . count($targeted)
                        . ' targeted resident(s) has a valid contact number.';
                    if ($targeted) {
                        $logId = logDisasterSMS($pdo, $new_alert_id, 0, 0);
                        sms_log_recipients($pdo, $new_alert_id, $logId, $targeted, []);
                    }
                } else {
                    $smsResult = sendDisasterSMS($pdo, $residents, $sms_message);
                    $logId = logDisasterSMS($pdo, $new_alert_id, count($residents), $smsResult['sent']);
                    sms_log_recipients($pdo, $new_alert_id, $logId, $targeted ?: $residents, $smsResult['results'] ?? []);
                    if ($smsResult['sent'] === 0) {
                        $sms_notice = ' The alert was saved, but the SMS gateway did not accept any messages. Check SMS Settings and the gateway device.' . $noNumberNote;
                    } elseif ($smsResult['failed'] > 0) {
                        $sms_notice = " The alert was saved and SMS was partially sent ({$smsResult['sent']} of " . count($targeted ?: $residents)
                            . ' targeted); check SMS Live → View breakdown for who was not reached.';
                    } elseif ($noNumber > 0) {
                        $sms_notice = " SMS accepted by the gateway for {$smsResult['sent']} of " . count($targeted) . ' targeted residents.' . $noNumberNote;
                    }
                }
            }

            $_SESSION['ann_success'] = "New Issue Alerted — \"{$title}\" ({$type}, {$severity} severity)"
                . ($notify_sms ? $sms_notice : ".");
            header("Location: ../frontend/ann.php");
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $_SESSION['ann_error'] = $e->getMessage();
            header("Location: ../frontend/ann.php");
            exit();
        }
    }

    // ─── ACTION: UPDATE ALERT ─────────────────────────────────────────────────
    if ($_POST['action'] === 'update') {
        $alert_id = $_POST['alert_id'] ?? null;
        $type = $_POST['type'] ?? '';
        $severity = $_POST['severity'] ?? '';
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        try {
            $pdo->beginTransaction();

            // Risk-map fields and update notifications are deliberately not
            // changed here: both controls were removed from the Update Alert UI.
            $sql = "UPDATE disaster_alerts
                    SET Type = ?, Severity = ?, Title = ?, Message = ?
                    WHERE AlertID = ?";
            $pdo->prepare($sql)->execute([
                $type,
                $severity,
                $title,
                $message,
                $alert_id,
            ]);

            $pdo->commit();

            log_activity('Disaster and Risk Map', 'Update Alert', "Updated {$type} alert \"{$title}\" (ID: {$alert_id}).");

            $_SESSION['ann_success'] = "Alert \"{$title}\" has been updated.";
            header("Location: ../frontend/ann.php");
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $_SESSION['ann_error'] = $e->getMessage();
            header("Location: ../frontend/ann.php");
            exit();
        }
    }

    // ─── ACTION: DEACTIVATE WITH REPORT ──────────────────────────────────────
    if ($_POST['action'] === 'deactivate_with_report') {
        $id = $_POST['alert_id'] ?? null;
        $status = $_POST['status'] ?? 'Resolved';
        $affected = $_POST['affected_residents'] ?? 0;
        $evacuees = $_POST['evacuees'] ?? 0;
        $injuries = $_POST['injuries'] ?? 0;
        $casualties = $_POST['casualties'] ?? 0;
        $damage = $_POST['property_damage'] ?? '';
        $actions = $_POST['response_actions'] ?? '';

        try {
            $pdo->beginTransaction();

            $stmtAlert = $pdo->prepare("SELECT Type, Title FROM disaster_alerts WHERE AlertID = ?");
            $stmtAlert->execute([$id]);
            $alertInfo = $stmtAlert->fetch(PDO::FETCH_ASSOC);

            if (!$alertInfo)
                throw new Exception("Alert not found.");

            // Duty Officer = the account that is logged in while deactivating the disaster
            $dutyOfficer = $_SESSION['admin_name']
                ?? $_SESSION['staff_name']
                ?? 'Unknown User';

            // Uniform reference number — DIS-YYYY-0001. Tied directly to the
            // alert's own AlertID (not a deactivation-order counter) so the
            // same ID the admin saw when the alert was posted is the same ID
            // that shows up on the report once it's deactivated.
            $year = date('Y');
            $reportNo = sprintf('DIS-%s-%04d', $year, (int) $id);

            $sql2 = "INSERT INTO disaster_reports (
                        AlertID, ReportNo, Type, Title, Status, DutyOfficer,
                        AffectedResidents, Evacuees, Injuries,
                        Casualties, PropertyDamage, ResponseActions
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql2)->execute([
                $id,
                $reportNo,
                $alertInfo['Type'],
                $alertInfo['Title'],
                $status,
                $dutyOfficer,
                $affected,
                $evacuees,
                $injuries,
                $casualties,
                $damage,
                $actions,
            ]);

            // Deactivate alert. notify_app is intentionally left as-is (NOT
            // reset to 0) — it records whether app notification was used
            // when the alert was posted, and the Disaster Report / analytics
            // view reads it afterward to show "Sent" vs "Not used". Zeroing
            // it here was why that field always showed "Not used" post-deactivation.
            $pdo->prepare("UPDATE disaster_alerts SET Status = 'inactive' WHERE AlertID = ?")
                ->execute([$id]);

            // Soft-delete linked hazard (removes marker from Risk Mapping)
            softDeleteLinkedHazard($pdo, $id);

            $pdo->commit();

            log_activity('Disaster and Risk Map', 'Resolve Alert', "Resolved {$alertInfo['Type']} alert \"{$alertInfo['Title']}\" — affected: {$affected}.");

            $_SESSION['ann_success'] = "Disaster has been deactivated — \"{$alertInfo['Title']}\" report saved.";
            header("Location: ../frontend/ann.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['ann_error'] = $e->getMessage();
            header("Location: ../frontend/ann.php");
            exit();
        }
    }
}

// ─── GET: deactivate ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'deactivate') {
    $id = $_GET['id'];
    try {
        $pdo->beginTransaction();
        // notify_app left untouched here too, for the same reason as the
        // deactivate_with_report handler above.
        $pdo->prepare("UPDATE disaster_alerts SET Status = 'inactive' WHERE AlertID = ?")
            ->execute([$id]);
        softDeleteLinkedHazard($pdo, $id);
        $pdo->commit();
        log_activity('Disaster and Risk Map', 'Deactivate Alert', "Deactivated alert ID: {$id}.");
        $_SESSION['ann_success'] = "Disaster has been deactivated.";
        header("Location: ../frontend/ann.php");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['ann_error'] = $e->getMessage();
        header("Location: ../frontend/ann.php");
        exit();
    }
}
