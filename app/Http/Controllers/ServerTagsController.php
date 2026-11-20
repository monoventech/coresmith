<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Projects\AppTags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ServerTagsController extends Controller
{
    public const CHANGED = 'server_tags_changed';

    public function update(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $request->merge(['tags' => AppTags::normalize($request->input('tags', []))]);
        $tags = array_values($request->validate(AppTags::rules(), AppTags::messages('A server'))['tags'] ?? []);
        $from = $server->tags;

        if ($tags === $from) {
            return back();
        }

        $server->tags = $tags;
        $server->save();

        activity()->performedOn($server)->causedBy($request->user())->withProperties(['from' => $from, 'to' => $tags])->log(self::CHANGED);

        return back()->with('success', 'Saved.');
    }
}
