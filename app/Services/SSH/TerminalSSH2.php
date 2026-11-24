<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Net\SSH2;

final class TerminalSSH2 extends SSH2
{
    public function resize(int $columns, int $rows): void
    {
        $this->setWindowSize($columns, $rows);

        if (!$this->interactive()) {
            return;
        }

        $this->send_binary_packet(Strings::packSSH2(
            'CNsbNNNN',
            NET_SSH2_MSG_CHANNEL_REQUEST,
            $this->server_channels[$this->isPTYOpen() ? self::CHANNEL_EXEC : self::CHANNEL_SHELL],
            'window-change',
            false,
            $columns,
            $rows,
            0,
            0,
        ));
    }

    public function interactive(): bool
    {
        return $this->isPTYOpen() || $this->isShellOpen();
    }
}
