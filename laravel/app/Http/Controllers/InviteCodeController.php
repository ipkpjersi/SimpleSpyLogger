<?php

namespace App\Http\Controllers;

use App\Models\InviteCode;
use DataTables;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class InviteCodeController extends Controller
{
    public function generateInviteCodes(Request $request)
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $count = Artisan::call('app:generate-invite-codes');

        return response()->json(['message' => "$count invite codes generated successfully"]);
    }

    public function revokeUnusedInviteCodes(Request $request)
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $count = Artisan::call('app:revoke-unused-invite-codes');

        return response()->json(['message' => "$count unused invite codes revoked successfully"]);
    }

    public function index()
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(404);
        }

        return view('invitecodeslist');
    }

    public function data(Request $request)
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $query = InviteCode::query();

        return DataTables::of($query)
            ->editColumn('created_at', function ($user) {
                return Carbon::parse($user->created_at)->format('M d, Y');
            })
            ->make(true);
    }
}
