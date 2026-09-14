<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Teams\TeamContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    protected function currentTeam(): ?Team
    {
        return app(TeamContext::class)->team();
    }
}
