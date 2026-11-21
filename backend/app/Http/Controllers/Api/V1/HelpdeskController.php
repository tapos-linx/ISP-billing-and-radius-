<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\Helpdesk\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0-13: Helpdesk Ticketing & SLA API Controller
 */
class HelpdeskController extends Controller
{
    public function __construct(
        protected TicketService $ticketService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Ticket::withoutGlobalScopes()->with('comments')->latest();

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->query('priority'));
        }
        if ($request->has('subscriber_id')) {
            $query->where('subscriber_id', $request->query('subscriber_id'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|string|max:64',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'subscriber_id' => 'nullable|uuid',
            'initial_comment' => 'nullable|string',
        ]);

        $ticket = $this->ticketService->createTicket(
            tenantId: 1,
            subject: $validated['subject'],
            category: $validated['category'],
            priority: $validated['priority'] ?? 'medium',
            subscriberId: $validated['subscriber_id'] ?? null,
            initialComment: $validated['initial_comment'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'data' => $ticket->load('comments'),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $ticket = Ticket::withoutGlobalScopes()->where('ticket_id', $id)->with(['comments', 'subscriber'])->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $ticket,
        ]);
    }

    public function addComment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'comment' => 'required|string',
            'is_internal' => 'nullable|boolean',
        ]);

        $ticket = Ticket::withoutGlobalScopes()->where('ticket_id', $id)->firstOrFail();
        $comment = $this->ticketService->addComment(
            ticket: $ticket,
            comment: $validated['comment'],
            isInternal: (bool) ($validated['is_internal'] ?? false)
        );

        return response()->json([
            'status' => 'success',
            'data' => $comment,
        ], 201);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'required|string',
        ]);

        $ticket = Ticket::withoutGlobalScopes()->where('ticket_id', $id)->firstOrFail();
        $resolved = $this->ticketService->resolveTicket($ticket, $validated['notes']);

        return response()->json([
            'status' => 'success',
            'data' => $resolved,
        ]);
    }

    public function cannedReplies(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->ticketService->getCannedReplies(1),
        ]);
    }
}
