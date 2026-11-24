<?php

use App\Broadcasting\DeploymentChannel;
use App\Broadcasting\TeamChannel;
use App\Broadcasting\TerminalChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('deployments.{deploymentId}', DeploymentChannel::class);

Broadcast::channel('teams.{team}', TeamChannel::class);

Broadcast::channel('terminals.{terminal}', TerminalChannel::class);
