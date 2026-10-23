<?php

declare(strict_types=1);

// Exercise Collision when installed, otherwise model its contract decorator.
// Resolve before provider boot, as a development provider may do.
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
$handler = $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
if (class_exists(NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler::class)) {
    $decoratedHandler = new NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler($app, $handler);
} else {
    $decoratedHandler = new class($handler) implements Illuminate\Contracts\Debug\ExceptionHandler
    {
        public function __construct(private readonly Illuminate\Contracts\Debug\ExceptionHandler $handler) {}
        public function report(Throwable $e) { return $this->handler->report($e); }
        public function shouldReport(Throwable $e) { return $this->handler->shouldReport($e); }
        public function render($request, Throwable $e) { return $this->handler->render($request, $e); }
        public function renderForConsole($output, Throwable $e) { return $this->handler->renderForConsole($output, $e); }
    };
}
$app->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, $decoratedHandler);
