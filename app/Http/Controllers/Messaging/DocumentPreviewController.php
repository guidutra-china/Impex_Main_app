<?php

namespace App\Http\Controllers\Messaging;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Messaging\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a message attachment inline so the browser renders it natively
 * (PDFs via the built-in viewer, images via <img>, etc.).
 *
 * Two layers of authorization:
 *
 *   1. Laravel's `signed` middleware validates the HMAC on the URL —
 *      without a valid signature the request is rejected before this
 *      controller runs. The signature expires in 5 minutes.
 *
 *   2. Inside the controller we additionally call Gate::allows('view', $message)
 *      for the *currently authenticated* user. This prevents a signed URL
 *      from being reused by another logged-in user who shouldn't have
 *      access to the conversation. A signed URL alone is not enough —
 *      it must be opened by a participant.
 */
class DocumentPreviewController
{
    public function stream(Request $request, Document $document): BinaryFileResponse
    {
        // Only message attachments go through this endpoint.
        abort_unless(
            $document->documentable_type === Message::class
                && $document->type === Message::ATTACHMENT_TYPE,
            404,
        );

        /** @var Message|null $message */
        $message = Message::with('conversation')->find($document->documentable_id);
        abort_unless($message, 404);

        // The signed URL guarantees the link was minted by us, but the current
        // user must still be a participant of the owning conversation.
        abort_unless(Gate::allows('view', $message), 403);

        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);

        $absolutePath = $disk->path($document->path);

        return response()->file($absolutePath, [
            'Content-Type'        => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($document->name) . '"',
            // Prevent intermediate caches from storing authorized content.
            'Cache-Control'       => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
