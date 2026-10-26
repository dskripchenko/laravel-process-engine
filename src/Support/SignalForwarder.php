<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Dskripchenko\LaravelProcessEngine\Events\ProcessSignal;
use Dskripchenko\ProcessEngine\Runtime\EventListener;
use Dskripchenko\ProcessEngine\Runtime\Signal;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Engine signals become Laravel events (ProcessSignal) for listeners, metrics and alerts.
 */
final class SignalForwarder implements EventListener
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(Signal $signal): void
    {
        $this->events->dispatch(new ProcessSignal($signal->type, $signal->instanceId, $signal->data));
    }
}
