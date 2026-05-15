<?php

namespace App\Http\Controllers;

use App\Models\Message;
use DataTables;
use Illuminate\Http\Request;

class MessagesController extends Controller
{
    public function index()
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(404);
        }

        $distinct = function (string $column) {
            return Message::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->values();
        };

        $distinctWithSource = function (string $column) {
            return Message::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select('source', $column)
                ->distinct()
                ->orderBy('source')
                ->orderBy($column)
                ->get()
                ->map(fn ($r) => ['source' => $r->source, 'value' => $r->{$column}])
                ->values();
        };

        $filters = [
            'source' => $distinct('source'),
            'container' => $distinctWithSource('container_name'),
            'channel' => $distinctWithSource('channel_name'),
            'author' => $distinct('author_username'),
            'visibility' => $distinct('visibility'),
        ];

        return view('messages.index', compact('filters'));
    }

    public function data(Request $request)
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Message::query()->select([
            'id',
            'source',
            'external_id',
            'container_external_id',
            'container_name',
            'channel_external_id',
            'channel_name',
            'visibility',
            'author_external_id',
            'author_username',
            'content',
            'sent_at',
            'deleted_at',
        ]);

        return DataTables::of($query)
            // RSCPlus rows share one sent_at per session (the log only carries
            // a session-start timestamp), so add id as a tiebreaker to keep
            // them in file order under ties.
            ->orderColumn('sent_at', 'sent_at $1, id $1')
            ->editColumn('sent_at', function ($m) {
                return $m->sent_at?->format('Y-m-d H:i:s');
            })
            ->escapeColumns([])
            ->make(true);
    }

    public function destroy(Message $message)
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message->delete();

        return response()->json(['message' => 'Message deleted successfully']);
    }
}
