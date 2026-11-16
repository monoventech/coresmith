<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Server;
use App\Services\Projects\AppTags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationTagsController extends Controller
{
    public const CHANGED = 'tags_changed';

    public function update(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $request->merge(['tags' => AppTags::normalize($request->input('tags', []))]);
        $tags = array_values($request->validate(AppTags::rules(), AppTags::messages())['tags'] ?? []);
        $from = $application->tags;

        if ($tags === $from) {
            return back();
        }

        $application->tags = $tags;
        $application->save();

        activity()->performedOn($application)->causedBy($request->user())->withProperties(['from' => $from, 'to' => $tags])->log(self::CHANGED);

        return back()->with('success', 'Saved.');
    }
}
