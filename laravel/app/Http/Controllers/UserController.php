<?php

namespace App\Http\Controllers;

use App\Models\StaffActionLog;
use App\Models\User;
use DataTables;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function getUserData()
    {
        if (! request()->has(['start', 'length']) || request()->input('length') > 1000) {
            return response()->json(['error' => 'Invalid request'], 400);
        }
        $query = User::select('id', 'username', 'is_admin', 'is_banned', 'created_at');
        if (Auth::user() === null || Auth::user()->is_admin !== 1) {
            $query->where('is_banned', '0');
        }

        return DataTables::of($query)
            ->editColumn('created_at', function ($user) {
                return Carbon::parse($user->created_at)->format('M d, Y');
            })
            ->make(true);
    }

    public function list()
    {
        return view('userlist');
    }

    public function banUser(Request $request, $userId)
    {
        if (auth()->user() == null || ! auth()->user()->isAdmin()) {
            return response()->json([], 404);
        }
        $user = User::findOrFail($userId);
        $user->is_banned = true;
        $user->save();

        StaffActionLog::create([
            'user_id' => auth()->id(),
            'target_id' => $user->id,
            'action' => 'ban',
        ]);

        return response()->json(['message' => 'User banned successfully']);
    }

    public function unbanUser(Request $request, $userId)
    {
        if (auth()->user() == null || ! auth()->user()->isAdmin()) {
            return response()->json([], 404);
        }
        $user = User::findOrFail($userId);
        $user->is_banned = false;
        $user->save();

        StaffActionLog::create([
            'user_id' => auth()->id(),
            'target_id' => $user->id,
            'action' => 'unban',
        ]);

        return response()->json(['message' => 'User unbanned successfully']);
    }
}
