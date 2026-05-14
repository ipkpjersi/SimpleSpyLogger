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

        return view('messages.index');
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
            ->editColumn('sent_at', function ($m) {
                return $m->sent_at?->format('Y-m-d H:i:s');
            })
            ->editColumn('content', function ($m) {
                if ($m->content === null) {
                    return null;
                }
                return mb_strimwidth($m->content, 0, 200, '...');
            })
            ->escapeColumns([])
            ->make(true);
    }
}
