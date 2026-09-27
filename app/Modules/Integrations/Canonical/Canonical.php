<?php

declare(strict_types=1);

namespace WBS\Integrations\Canonical;

/**
 * The finite, reviewed UPAF vocabulary (SRS FR-INT-002/003).
 *
 * Connector profiles may ONLY reference these canonical operations, protocol
 * families, HTTP methods and signature algorithms. Anything outside these sets
 * is rejected by the ProfileValidator — this is what makes profiles safe
 * configuration data rather than executable code.
 */
final class Canonical
{
    /** Canonical platform operations (8). */
    public const OPERATIONS = [
        'sendNotification',
        'createCheckout',
        'queryPayment',
        'verifyWebhook',
        'createMeetingLink',
        'getStreamMetrics',
        'refundPayment',
        'healthCheck',
    ];

    /** Approved protocol/capability families (9). */
    public const FAMILIES = [
        'smtp_email',
        'oauth2_oidc',
        'rest_json_notification',
        'signed_outbound_callback',
        'hosted_payment_checkout',
        'payment_status_query',
        'signed_inbound_webhook',
        'meeting_stream_link',
        'provider_metric_polling',
    ];

    /** Allowlisted HTTP methods. */
    public const HTTP_METHODS = ['GET', 'POST'];

    /** Finite reviewed signature algorithms. */
    public const SIGNATURE_ALGOS = ['hmac_sha256', 'hmac_sha512', 'jws_es256', 'jws_rs256', 'none'];

    /** Integration categories. */
    public const CATEGORIES = [
        'payment', 'notification', 'social_oidc', 'stream', 'meeting', 'learning', 'geocoding',
    ];

    public static function isOperation(string $op): bool
    {
        return in_array($op, self::OPERATIONS, true);
    }

    public static function isFamily(string $family): bool
    {
        return in_array($family, self::FAMILIES, true);
    }

    public static function isHttpMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::HTTP_METHODS, true);
    }

    public static function isSignatureAlgo(string $algo): bool
    {
        return in_array($algo, self::SIGNATURE_ALGOS, true);
    }
}
