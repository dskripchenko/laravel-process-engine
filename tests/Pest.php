<?php

declare(strict_types=1);

use Dskripchenko\LaravelProcessEngine\Tests\TestCase;

require_once __DIR__.'/Fixtures/CoreRuntime.php';
require_once __DIR__.'/Fixtures/Claims.php';
require_once __DIR__.'/Fixtures/Concurrency.php';

uses(TestCase::class)->in('Feature');
