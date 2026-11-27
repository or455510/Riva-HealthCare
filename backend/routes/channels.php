<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('call.{channelId}', function ($user, string $channelId) {
    $ids = explode('-', $channelId);

    return in_array((string) $user->id, $ids);
});
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
