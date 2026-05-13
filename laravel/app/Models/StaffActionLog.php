<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Table('staff_action_log')]
#[Fillable(['user_id', 'target_id', 'action', 'message'])]
class StaffActionLog extends Model
{
    use HasFactory;
}
