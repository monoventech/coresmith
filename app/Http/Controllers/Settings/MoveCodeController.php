<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Edition;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\MoveCode;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use App\Services\Moves\MoveSide;
use App\Services\Moves\TeamImporter;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class MoveCodeController extends Controller
{
    public function store(Request $request, MoveCodes $codes): RedirectResponse
    {
        $this->ensureAllowed($request);

        $team = Edition::current()->isCloud() ? $this->currentTeam() : null;

        MoveCode::query()->where('user_id', $request->user()->getKey())->when($team !== null, fn($query) => $query->where('team_id', $team?->getKey()))->whereNull('imported_at')->delete();
        $codes->create($request->user(), $team);

        return back()->with('success', 'Saved. Paste the move code into ' . MoveSide::origin() . '.');
    }

    public function upload(Request $request, MoveCode $moveCode, MoveFile $files): RedirectResponse
    {
        $this->ensureOwn($request, $moveCode);

        $choose = 'Choose the .coresmith-move file you downloaded from ' . MoveSide::origin() . '.';
        $validated = $request->validate(['file' => ['required', 'file', 'max:20480']], ['file.required' => $choose, 'file.file' => $choose, 'file.uploaded' => $choose, 'file.max' => $choose]);
        $upload = $validated['file'];
        $contents = $upload instanceof UploadedFile ? (string) file_get_contents((string) $upload->getRealPath()) : '';

        $files->open($contents, $moveCode);
        $moveCode->forceFill(['file' => $contents])->save();

        return back();
    }

    public function import(Request $request, MoveCode $moveCode, MoveFile $files, TeamImporter $importer, TeamUrls $urls): RedirectResponse
    {
        $this->ensureOwn($request, $moveCode);

        if ($moveCode->file === null) {
            throw ValidationException::withMessages(['file' => 'Upload the move file first.']);
        }

        $team = $importer->import($files->open((string) $moveCode->file, $moveCode), $moveCode, $request->user());
        $servers = $team->servers()->where('status', ServerStatus::Moving->value)->count();
        $moved = Edition::current()->isCloud()
            ? 'Moved. Coresmith Cloud is taking over ' . $servers . ' ' . ($servers === 1 ? 'server' : 'servers') . '.'
            : "Moved. {$team->name} is here, and its servers are being taken over.";

        return redirect($urls->home($team, $request))->with('success', $moved);
    }

    public function destroy(Request $request, MoveCode $moveCode): RedirectResponse
    {
        $this->ensureOwn($request, $moveCode);

        abort_if($moveCode->imported_at !== null, 404);
        $moveCode->delete();

        return back()->with('success', 'Removed.');
    }

    private function ensureAllowed(Request $request): void
    {
        if (Edition::current()->isSelfHosted()) {
            abort_unless($request->user()->managesInstance(), 403);

            return;
        }

        $team = $this->currentTeam();

        abort_unless($team !== null && $request->user()->can('manageBilling', $team) && !$team->servers()->exists(), 403);
    }

    private function ensureOwn(Request $request, MoveCode $moveCode): void
    {
        if (Edition::current()->isSelfHosted()) {
            $this->ensureAllowed($request);
        } else {
            abort_unless($moveCode->team !== null && $request->user()->can('manageBilling', $moveCode->team), 403);
        }

        abort_unless($moveCode->user_id === $request->user()->getKey(), 404);
    }
}
