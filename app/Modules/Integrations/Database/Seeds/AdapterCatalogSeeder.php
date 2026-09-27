<?php

declare(strict_types=1);

namespace WBS\Integrations\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Seeds the release adapter catalogue (SRS FR-INT-010).
 *
 * These are the code-owned adapters/profiles the platform ships with. Each
 * declares ONLY the canonical operations it genuinely supports (FR-INT-011), so
 * the generated UI never offers a capability the provider can't fulfil. Idempotent:
 * re-running upserts on (code, version).
 */
class AdapterCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $adapters = [
            // (a) Social OIDC
            ['google_oidc_v1', 'social_oidc', 'oauth2_oidc', 'Google Sign-In (OIDC)', ['healthCheck'], 'accounts.google.com', null, null],
            ['microsoft_oidc_v1', 'social_oidc', 'oauth2_oidc', 'Microsoft Sign-In (OIDC)', ['healthCheck'], 'login.microsoftonline.com', null, null],

            // (b) Payments
            ['stripe_v1', 'payment', 'hosted_payment_checkout', 'Stripe', ['createCheckout', 'queryPayment', 'verifyWebhook', 'refundPayment', 'healthCheck'], 'api.stripe.com', ['USD', 'GHS', 'NGN', 'EUR', 'GBP'], ['card']],
            ['ghana_nigeria_payment_aggregator_v1', 'payment', 'hosted_payment_checkout', 'Ghana/Nigeria Mobile Money Aggregator', ['createCheckout', 'queryPayment', 'verifyWebhook', 'refundPayment', 'healthCheck'], 'api.paymentaggregator.example', ['GHS', 'NGN'], ['momo', 'bank']],

            // (c) Notifications
            ['smtp_email_v1', 'notification', 'smtp_email', 'SMTP Email', ['sendNotification', 'healthCheck'], 'smtp.example.com', null, ['email']],
            ['rest_email_api_v1', 'notification', 'rest_json_notification', 'Transactional Email API', ['sendNotification', 'verifyWebhook', 'healthCheck'], 'api.emailprovider.example', null, ['email']],
            ['rest_sms_api_v1', 'notification', 'rest_json_notification', 'REST SMS Gateway', ['sendNotification', 'verifyWebhook', 'healthCheck'], 'api.smsprovider.example', ['GH', 'NG'], ['sms']],
            // mNotify/BMS — the SMS provider actually wired into the platform
            // (Notifications\Transport\MNotifySmsTransport). No inbound webhook:
            // delivery state is POLLED from /api/status/{campaignId}, so it
            // declares no verifyWebhook capability (FR-INT-011: never advertise an
            // op the provider can't fulfil). Key auth rides in the query string.
            ['mnotify_sms_v1', 'notification', 'rest_json_notification', 'mNotify SMS (Ghana)', ['sendNotification', 'healthCheck'], 'api.mnotify.com', ['GHS'], ['sms']],

            // Nalo Solutions — the SECOND SMS provider in the chain
            // (Notifications\Transport\NaloSmsTransport, behind SmsProviderChain
            // with mNotify primary). Form-encoded reseller API, no inbound webhook
            // and no documented per-message status endpoint, so it declares no
            // verifyWebhook capability (FR-INT-011: never advertise an op the
            // provider can't fulfil). Being the fallback, it is the row the
            // FR-INT-012 matrix points at when mNotify faults.
            ['nalo_sms_v1', 'notification', 'rest_json_notification', 'Nalo Solutions SMS (Ghana)', ['sendNotification', 'healthCheck'], 'api.nalosolutions.com', ['GHS'], ['sms']],

            // (d) Streaming (link/embed + metrics/recording where the provider API allows).
            // Declared capabilities drive the FR-INT-012 fallback matrix: an op a
            // provider doesn't expose is served by the documented fallback instead.
            ['youtube_live_v1', 'stream', 'meeting_stream_link', 'YouTube Live', ['createMeetingLink', 'getStreamMetrics', 'getRecording', 'healthCheck'], 'www.googleapis.com', null, null],
            ['twitch_live_v1', 'stream', 'meeting_stream_link', 'Twitch', ['createMeetingLink', 'getStreamMetrics', 'healthCheck'], 'api.twitch.tv', null, null],
            ['facebook_live_v1', 'stream', 'meeting_stream_link', 'Facebook Live', ['createMeetingLink', 'getStreamMetrics', 'healthCheck'], 'graph.facebook.com', null, null],
            ['custom_rtmp_v1', 'stream', 'meeting_stream_link', 'Custom RTMP Destination', ['createMeetingLink', 'healthCheck'], 'rtmp.example.com', null, null],

            // (e) Meetings
            ['zoom_v1', 'meeting', 'meeting_stream_link', 'Zoom', ['createMeetingLink', 'getStreamMetrics', 'getParticipants', 'getRecording', 'healthCheck'], 'api.zoom.us', null, null],
            ['google_meet_v1', 'meeting', 'meeting_stream_link', 'Google Meet', ['createMeetingLink', 'getParticipants', 'healthCheck'], 'www.googleapis.com', null, null],
            ['ms_teams_v1', 'meeting', 'meeting_stream_link', 'Microsoft Teams', ['createMeetingLink', 'healthCheck'], 'graph.microsoft.com', null, null],

            // (g) Geocoding / reference data
            ['geocoding_source_v1', 'geocoding', 'provider_metric_polling', 'Approved Geocoding Source', ['healthCheck'], 'api.geocoder.example', null, null],
        ];

        foreach ($adapters as [$code, $category, $family, $name, $caps, $host, $currencies, $channels]) {
            $row = [
                'code'              => $code,
                'version'           => 1,
                'category'          => $category,
                'family'            => $family,
                'display_name'      => $name,
                'capabilities'      => json_encode($caps, JSON_UNESCAPED_UNICODE),
                'credential_fields' => json_encode($this->credentialFields($category), JSON_UNESCAPED_UNICODE),
                'config_schema'     => json_encode(['approved_host' => $host], JSON_UNESCAPED_UNICODE),
                'countries'         => null,
                'currencies'        => $currencies !== null ? json_encode($currencies, JSON_UNESCAPED_UNICODE) : null,
                'channels'          => $channels !== null ? json_encode($channels, JSON_UNESCAPED_UNICODE) : null,
                'webhook_verify'    => in_array($category, ['payment', 'notification'], true) ? 'hmac_sha256' : 'none',
                'type'              => 'adapter',
                'status'            => 'active',
                'docs_ref'          => null,
                'created_at'        => $now,
            ];

            $existing = $this->db->table('provider_adapter_catalog')
                ->where('code', $code)->where('version', 1)->get()->getRowArray();
            if ($existing !== null) {
                $this->db->table('provider_adapter_catalog')->where('id', $existing['id'])->update($row);
            } else {
                $row['id'] = Uuid::v7();
                $this->db->table('provider_adapter_catalog')->insert($row);
            }
        }
    }

    /** @return list<string> */
    private function credentialFields(string $category): array
    {
        return match ($category) {
            'payment'      => ['secret_key', 'merchant_id', 'webhook_secret'],
            'notification' => ['api_key'],
            'social_oidc'  => ['client_id', 'client_secret'],
            default         => ['api_key'],
        };
    }
}
