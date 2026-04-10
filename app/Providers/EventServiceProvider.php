<?php

namespace App\Providers;

use App\Events\TicketCreated;
use App\Listeners\TriggerWorkflowsOnTicketCreated;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        TicketCreated::class => [
            TriggerWorkflowsOnTicketCreated::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
