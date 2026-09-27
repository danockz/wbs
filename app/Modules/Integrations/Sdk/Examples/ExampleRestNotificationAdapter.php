<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk\Examples;

use WBS\Integrations\Sdk\AbstractCustomAdapter;
use WBS\Integrations\Sdk\AdapterManifest;
use WBS\Integrations\Sdk\OperationRequest;
use WBS\Integrations\Sdk\OperationResult;

/**
 * Reference custom adapter (SRS FR-INT-013) — the worked example the SDK docs
 * point to and the contract-test suite exercises.
 *
 * It models a hypothetical REST notification provider whose payload shape does
 * not fit any certified connector profile, so it ships as a small reviewed
 * module instead. It demonstrates every SDK rule:
 *  - declares an honest manifest (only the ops it truly supports + healthCheck),
 *  - implements one `op<Name>()` method per declared capability,
 *  - resolves its API key ONLY through the scoped secret resolver,
 *  - returns NON-secret references (never echoes the key back), and
 *  - surfaces provider errors as OperationResult::fail() rather than throwing.
 *
 * This class performs NO real network I/O — a production adapter would call the
 * provider via the platform's egress-controlled HTTP client bound to its
 * manifest's approved hosts. It is intentionally deterministic so it is safe as
 * documentation and as a test fixture.
 */
final class ExampleRestNotificationAdapter extends AbstractCustomAdapter
{
    protected function buildManifest(): AdapterManifest
    {
        return new AdapterManifest(
            code: 'example_rest_notify_v1',
            version: 1,
            category: 'notification',
            family: 'rest_json_notification',
            displayName: 'Example REST Notification Provider',
            capabilities: ['sendNotification', 'verifyWebhook', 'healthCheck'],
            credentialFields: ['api_key', 'webhook_secret'],
            approvedHosts: ['api.example-notify.com'],
            configSchema: ['default_sender' => 'string'],
            docsRef: 'docs/custom-adapter-sdk.md',
        );
    }

    protected function opSendNotification(OperationRequest $request): OperationResult
    {
        $to      = (string) $request->param('to', '');
        $message = (string) $request->param('message', '');
        if ($to === '' || $message === '') {
            return OperationResult::fail('INVALID_INPUT', 'both "to" and "message" are required');
        }

        // Resolve the API key for the duration of the call only.
        $apiKey = $request->secret('api_key');
        if ($apiKey === null || $apiKey === '') {
            return OperationResult::fail('NO_CREDENTIAL', 'api_key credential slot is not configured');
        }

        // A real adapter would POST to https://api.example-notify.com/... here.
        // We return a NON-secret provider reference; the key is never echoed.
        return OperationResult::ok([
            'provider_message_id' => 'exmsg_' . substr(hash('sha256', $to . '|' . $message), 0, 20),
            'accepted'            => true,
            'channel'             => 'push',
        ]);
    }

    protected function opVerifyWebhook(OperationRequest $request): OperationResult
    {
        $rawBody   = (string) $request->param('raw_body', '');
        $signature = (string) $request->param('signature', '');
        $secret    = $request->secret('webhook_secret');

        if ($secret === null || $secret === '' || $signature === '') {
            return OperationResult::fail('UNVERIFIED', 'missing webhook secret or signature');
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        $valid    = hash_equals($expected, $signature);

        return $valid
            ? OperationResult::ok(['verified' => true])
            : OperationResult::fail('BAD_SIGNATURE', 'webhook signature did not match');
    }
}
