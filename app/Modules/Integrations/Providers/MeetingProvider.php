<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Contract for meeting-platform adapters used as a "stream" (Zoom, Google Meet,
 * GoToWebinar, MS Teams). Creates the real meeting and returns a NON-secret
 * join reference. Tokens are used only inside CredentialVault closures.
 */
interface MeetingProvider
{
    public function code(): string;

    /**
     * Create the meeting/webinar on the provider.
     *
     * @param array<string,mixed> $meeting Fields: title, mode, starts_at,
     *                                      ends_at, connection_id, capabilities.
     */
    public function createMeeting(array $meeting): MeetingResult;
}
