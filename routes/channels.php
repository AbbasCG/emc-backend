<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Ticket 8 completion pass — private per-user notification channel. Strict
// ownership check identical to the default user channel above; kept as its
// own named channel so the frontend subscribes to something self-describing
// ("notifications.42") rather than the framework's default model-channel
// name, and so this authorization rule can evolve independently of it.
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
