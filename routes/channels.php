<?php

use App\Broadcasting\DeploymentChannel;
use App\Broadcasting\TeamChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('deployments.{deployment}', DeploymentChannel::class);

Broadcast::channel('teams.{team}', TeamChannel::class);
