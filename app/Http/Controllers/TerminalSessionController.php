<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TerminalEndReason;
use App\Enums\TerminalStatus;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Services\Terminal\TerminalInbox;
use App\Services\Terminal\TerminalScreen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TerminalSessionController extends Controller
{
    public const INPUT_BYTES = 8192;

    public function input(Request $request, Server $server, TerminalSession $terminal, TerminalInbox $inbox): JsonResponse
    {
        $this->own($request, $terminal);

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:40'],
            'n' => ['required', 'integer', 'min:1'],
            'data' => ['required', 'string', 'max:' . ((int) ceil(self::INPUT_BYTES / 3) * 4)],
        ]);

        $bytes = base64_decode($validated['data'], true);

        abort_if($bytes === false || strlen($bytes) > self::INPUT_BYTES, 422);

        if ($terminal->live()) {
            $inbox->push($terminal, ['type' => 'input', 'id' => $validated['id'], 'n' => (int) $validated['n'], 'data' => $validated['data']]);
        }

        return $this->state($terminal);
    }

    public function resize(Request $request, Server $server, TerminalSession $terminal, TerminalInbox $inbox): JsonResponse
    {
        $this->own($request, $terminal);

        $validated = $request->validate([
            'cols' => ['required', 'integer', 'between:20,500'],
            'rows' => ['required', 'integer', 'between:5,200'],
        ]);

        if ($terminal->live()) {
            $inbox->push($terminal, ['type' => 'resize', 'cols' => (int) $validated['cols'], 'rows' => (int) $validated['rows']]);
        }

        return $this->state($terminal);
    }

    public function checkIn(Request $request, Server $server, TerminalSession $terminal, TerminalInbox $inbox): JsonResponse
    {
        $this->own($request, $terminal);

        if ($terminal->live()) {
            $inbox->push($terminal, ['type' => $request->boolean('keep') ? 'keep' : 'seen', 'hidden' => $request->boolean('hidden')]);
        }

        return $this->state($terminal);
    }

    public function screen(Request $request, Server $server, TerminalSession $terminal, TerminalInbox $inbox, TerminalScreen $screens): JsonResponse
    {
        $this->own($request, $terminal);

        if ($terminal->live()) {
            $inbox->push($terminal, ['type' => 'attach']);
        }

        return response()->json([...$screens->read($terminal), ...$this->body($terminal)]);
    }

    public function destroy(Request $request, Server $server, TerminalSession $terminal, TerminalInbox $inbox): JsonResponse
    {
        $this->own($request, $terminal);

        $closed = TerminalSession::query()
            ->whereKey($terminal->getKey())
            ->where('status', TerminalStatus::Starting->value)
            ->update(['status' => TerminalStatus::Closed->value, 'ended_at' => now(), 'end_reason' => TerminalEndReason::Ended->value]);

        if ($closed === 0 && $terminal->live()) {
            $inbox->push($terminal, ['type' => 'close', 'reason' => TerminalEndReason::Ended->value]);
        }

        return $this->state($terminal->fresh() ?? $terminal);
    }

    private function own(Request $request, TerminalSession $terminal): void
    {
        abort_unless($terminal->user_id === $request->user()->id, 404);
    }

    private function state(TerminalSession $terminal): JsonResponse
    {
        return response()->json($this->body($terminal));
    }

    /**
     * @return array{status: string, ended: array<string, mixed>|null}
     */
    private function body(TerminalSession $terminal): array
    {
        $present = TerminalController::present($terminal);

        return ['status' => $present['status'], 'ended' => $present['ended']];
    }
}
