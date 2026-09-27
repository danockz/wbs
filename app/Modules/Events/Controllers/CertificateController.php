<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Event certificates (SRS FR-EVT-013). Public verification endpoint aside, all
 * routes are permission-gated in Routes.php.
 *
 * Two browser consoles turn the JSON-only write actions into no-JS admin pages:
 *   - GET certificates/templates    — org certificate templates + create form
 *   - GET events/{id}/certificates  — the event's certificates, a batch-request
 *     form, and per-certificate issue / revoke forms
 * Both mint the `_csrf` token via renderForm; API clients keep JSON.
 */
final class CertificateController extends BaseController
{
    /**
     * GET certificates/templates — org-level TEMPLATES CONSOLE: list the
     * organization's certificate templates plus a no-JS PRG form to create one.
     */
    public function templatesConsole()
    {
        $templates = EventServices::certificates()->listTemplates($this->orgId());

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['templates' => $templates, 'count' => count($templates)]));
        }

        return $this->renderForm('WBS\Events\Views\certificate_templates', [
            'templates' => $templates,
        ]);
    }

    /**
     * GET events/{id}/certificates — the event CERTIFICATES CONSOLE: every
     * certificate requested/issued for the event, a batch-request form, and a
     * per-certificate issue / revoke form (issue moves pending→issued; revoke
     * needs a reason and confirms client-side).
     */
    public function eventConsole(string $eventId = '')
    {
        $certs     = EventServices::certificates()->listForEvent($eventId);
        $templates = EventServices::certificates()->listTemplates($this->orgId());

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok([
                'event_id'     => $eventId,
                'certificates' => $certs,
                'templates'    => $templates,
            ]));
        }

        return $this->renderForm('WBS\Events\Views\certificate_event', [
            'eventId'      => $eventId,
            'certificates' => $certs,
            'templates'    => $templates,
        ]);
    }

    public function createTemplate()
    {
        $result = EventServices::certificates()->createTemplate($this->orgId(), $this->input());

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            return redirect()->to('/certificates/templates')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/certificates/templates')->with('success', lang('Events.certificate.templateCreatedFlash'));
    }

    public function editTemplate(string $templateId = '')
    {
        $row = EventServices::certificates()->findTemplate($this->orgId(), $templateId);
        if ($row === null) {
            return $this->respondWith(Result::notFound('certificate.template_not_found', 'TEMPLATE_NOT_FOUND'));
        }

        return $this->renderForm('WBS\\Events\\Views\\certificate_template_edit', [
            'template' => $row,
        ]);
    }

    public function reviseTemplate(string $templateId = '')
    {
        $result = EventServices::certificates()->reviseTemplate($this->orgId(), $templateId, $this->input());
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to('/certificates/templates/' . rawurlencode($templateId) . '/edit')
                ->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/certificates/templates')->with('success', lang('Events.certificate.templateRevisedFlash'));
    }

    public function retireTemplate(string $templateId = '')
    {
        $result = EventServices::certificates()->retireTemplate($this->orgId(), $templateId);
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to('/certificates/templates')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/certificates/templates')->with('success', lang('Events.certificate.templateRetiredFlash'));
    }

    public function previewTemplate(string $templateId = '')
    {
        $result = EventServices::certificates()->previewHtml($this->orgId(), $templateId);
        if (! $result->ok) {
            return $this->respondWith($result);
        }
        $html = (string) (($result->data['html'] ?? '') ?: '');

        return $this->response->setHeader('Content-Type', 'text/html; charset=utf-8')->setBody($html);
    }

    /** Batch-request certificates for all eligible attendees of an event. */
    public function requestForEvent(string $eventId = '')
    {
        $result = EventServices::certificates()->requestForEvent(
            $this->orgId(),
            $eventId,
            $this->field('template_id') ?: null,
        );

        return $this->respondCertEvent($result, $eventId, 'requestedFlash');
    }

    public function issue(string $certificateId = '')
    {
        $in     = $this->input();
        $result = EventServices::certificates()->issue(
            $certificateId,
            $this->actorId('signed_by'),
            $in['signature_ref'] ?? null,
        );

        return $this->respondCertEvent($result, (string) ($result->data['event_id'] ?? ''), 'issuedFlash');
    }

    public function revoke(string $certificateId = '')
    {
        $result = EventServices::certificates()->revoke(
            $certificateId,
            (string) $this->field('reason', ''),
        );

        return $this->respondCertEvent($result, (string) ($result->data['event_id'] ?? ''), 'revokedFlash');
    }

    /** Public: verify a certificate by its opaque verification id (from the QR). */
    public function verify(string $verificationId = '')
    {
        return $this->respondPage(
            EventServices::certificates()->verify($verificationId),
            'certificate_verify',
            static fn (array $d): array => ['record' => $d],
        );
    }

    /**
     * Self-service download of the member's OWN issued certificate PDF.
     *
     * Authorization is self-scoped inside the service (must belong to the
     * authenticated user); a non-owner gets a 404. On success we stream the
     * actual PDF rather than a negotiated Result.
     */
    public function download(string $certificateId = '')
    {
        $storageDir = defined('WRITEPATH') ? rtrim(WRITEPATH, '/\\') : rtrim(sys_get_temp_dir(), '/\\');

        $result = EventServices::certificates()->downloadOwn(
            $certificateId,
            $this->currentUserId(),
            $storageDir,
        );

        if (! $result->ok) {
            return $this->respondWith($result);
        }

        $data = $result->data;

        return $this->response
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->download($data['path'], null)
            ->setFileName($data['filename']);
    }

    /**
     * PRG for a browser certificate write scoped to an event: API clients keep the
     * raw Result (JSON); browsers redirect back to the event certificates console
     * with a localized success flash, or the failing Result's message as an error
     * flash. Falls back to the events index when the event id is unknown.
     */
    private function respondCertEvent(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/certificates' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.certificate.' . $okKey));
    }
}
