<?php

use App\Broadcasting\DeploymentChannel;
use App\Broadcasting\TeamChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('deployments.{deployment}', DeploymentChannel::class);

Broadcast::channel('teams.{team}', TeamChannel::class);
