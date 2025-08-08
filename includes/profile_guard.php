<?php
/**
 * profile_guard.php
 * Reusable helper to enforce that a customer's profile is VERIFIED before accessing
 * booking-related pages or other restricted actions.
 *
 * HOW TO USE:
 * --------------------------------------------------------------------------------
 * 1. Include this file after you start the session and include your DB connection:
 *      session_start();
 *      require '../connect.php';
 *      require '../includes/profile_guard.php';
 *
 * 2. Call:
 *      requireVerifiedProfile($conn, (int)$_SESSION['cust_id']);
 *
 * 3. (Optional) Override the redirect target or provide a custom callback:
 *      requireVerifiedProfile($conn, (int)$_SESSION['cust_id'], 'dashboard.php');
 *
 * 4. (Optional) If you only want to CHECK without redirecting (e.g., to show a banner),
 *    use getProfileStatus() instead.
 *
 * SECURITY NOTES:
 * - Apply this guard to EVERY step that leads to a booking (book_car.php,
 *   booking_driver.php, booking_submit.php, etc.).
 * - Layered checks protect against direct URL access, stale open tabs, status changes mid-flow.
 *
 * RETURN / BEHAVIOR:
 * - On failure, it issues a header() redirect with an explanatory ?notice= message and exits.
 * - On success, function simply returns (allowing script to continue).
 */

/**
 * Fetch a customer's current profile status and rejection reason.
 *
 * @param mysqli $conn
 * @param int    $cust_id
 * @return array{status:string|null, reason:string|null}
 */
function getProfileStatus(mysqli $conn, int $cust_id): array
{
    $stmt = $conn->prepare("SELECT profile_status, profile_rejection_reason FROM customer WHERE cust_id=? LIMIT 1");
    if (!$stmt) {
        // On prepare failure, return a safe default (treat as not verified)
        return ['status' => null, 'reason' => null];
    }
    $stmt->bind_param("i", $cust_id);
    $stmt->execute();
    $stmt->bind_result($status, $reason);
    $stmt->fetch();
    $stmt->close();
    return ['status' => $status, 'reason' => $reason];
}

/**
 * Require verified profile; otherwise redirect with message and exit.
 *
 * @param mysqli $conn
 * @param int    $cust_id
 * @param string $redirectTo   Page to redirect on failure (default: dashboard.php)
 * @param bool   $allowUnsubmittedGrace  If true, allow 'unsubmitted' (temporary grace mode)
 * @return void
 */
function requireVerifiedProfile(
    mysqli $conn,
    int $cust_id,
    string $redirectTo = 'dashboard.php',
    bool $allowUnsubmittedGrace = false
): void {
    $info = getProfileStatus($conn, $cust_id);
    $status = $info['status'];
    $reason = $info['reason'];

    // If record missing or status is null, treat as not verified
    if ($status === null) {
        redirectWithNotice($redirectTo, "Unable to determine profile status. Please re-login.");
    }

    // Grace option (if you want a transition period)
    if ($allowUnsubmittedGrace && $status === 'unsubmitted') {
        return; // Permit access in grace mode
    }

    if ($status !== 'verified') {
        $msg = buildProfileBlockMessage($status, $reason);
        redirectWithNotice($redirectTo, $msg);
    }
    // else: verified, continue
}

/**
 * Construct user-facing message for blocked statuses.
 *
 * @param string|null $status
 * @param string|null $reason
 * @return string
 */
function buildProfileBlockMessage(?string $status, ?string $reason): string
{
    switch ($status) {
        case 'unsubmitted':
            return 'Complete your profile and submit it for verification before booking.';
        case 'pending':
            return 'Your profile is awaiting admin verification. You can book after approval.';
        case 'pending_reverification':
            return 'Recent profile changes require re-verification. Please wait for admin approval.';
        case 'rejected':
            return 'Profile rejected' . ($reason ? ': ' . $reason : '') . '. Please update and resubmit.';
        case 'verified':
            return ''; // not used
        default:
            return 'Your profile status is unknown. Please contact support.';
    }
}

/**
 * Redirect helper with notice message.
 *
 * @param string $target
 * @param string $notice
 * @return never
 */
function redirectWithNotice(string $target, string $notice)
{
    header("Location: {$target}?notice=" . urlencode($notice));
    exit;
}