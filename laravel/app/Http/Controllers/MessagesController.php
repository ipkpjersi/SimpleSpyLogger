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

        // Source and visibility are low-cardinality enums, so we ship their
        // full option lists with the page. Container, channel and author can
        // grow without bound, so those dropdowns load on demand via
        // filterOptions() instead of being serialized here.
        $distinct = function (string $column) {
            return Message::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->values();
        };

        $filters = [
            'source' => $distinct('source'),
            'visibility' => $distinct('visibility'),
        ];

        return view('messages.index', compact('filters'));
    }

    // Server-side option source for the Select2 ajax filter dropdowns. Returns
    // distinct values for a single whitelisted column, filtered by the typed
    // term and paginated, in the JSON shape Select2 expects.
    public function filterOptions(Request $request)
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $withSource = ['container_name', 'channel_name'];
        $plain = ['author_username'];
        $allowed = array_merge($withSource, $plain);

        $field = (string) $request->query('field', '');
        if (! in_array($field, $allowed, true)) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }

        $term = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 250;

        $query = Message::query()
            ->whereNotNull($field)
            ->where($field, '!=', '');

        if ($term !== '') {
            $query->where($field, 'like', '%'.$term.'%');
        }

        // Cascade: when one or more sources are selected, only offer values
        // belonging to those sources so the dependent dropdowns stay relevant.
        $sources = array_values(array_filter(
            (array) $request->query('source', []),
            fn ($s) => is_string($s) && $s !== ''
        ));
        if (! empty($sources)) {
            $query->whereIn('source', $sources);
        }

        if (in_array($field, $withSource, true)) {
            $query->select('source', $field)
                ->distinct()
                ->orderBy('source')
                ->orderBy($field);
        } else {
            $query->select($field)
                ->distinct()
                ->orderBy($field);
        }

        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;

        $results = $rows->take($perPage)->map(function ($r) use ($field, $withSource) {
            $value = (string) $r->{$field};

            return [
                'id' => $value,
                'text' => in_array($field, $withSource, true) ? $r->source.': '.$value : $value,
            ];
        })->values();

        return response()->json([
            'results' => $results,
            'pagination' => ['more' => $hasMore],
        ]);
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
