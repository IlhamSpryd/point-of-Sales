@props([
    'unreadCount' => 0,
])

<x-erp.notification-dropdown :unread-count="$unreadCount" {{ $attributes }} />
