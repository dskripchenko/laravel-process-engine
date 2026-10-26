<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Runtime\VirtualClock;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Support\JsonPatch;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

/**
 * The core's scenarios and crash harness, on the database store.
 */
function dbTest(): ProcessTest
{
    return new ProcessTest(store: app(Store::class), clock: new VirtualClock);
}

function dbCrash(string $point, int $skip, bool $expire): ProcessTest
{
    $t = dbTest()
        ->fake(Runtime\Charge::class, returns: 'tx-1', reconcile: Outcome::succeeded('tx-1'))
        ->crashAt($point, $skip)
        ->begin(Runtime\Order::class, ['n' => 1]);
    $t->send('submit', ['externalId' => 'EXT', 'files' => ['a', 'b']], key: 'submit-1');
    $t->travel('10 minutes', '1 minute');
    $t->send('submit', ['externalId' => 'EXT', 'files' => ['a', 'b']], key: 'submit-1');
    if ($expire) {
        $t->travel('3 days', '6 hours')->travel('10 minutes', '1 minute');
    } else {
        $t->publish('party.decision', 'EXT', ['approved' => true], key: 'decision-1')->travel('10 minutes', '1 minute');
    }

    return $t->recover();
}

function dbInvariants(ProcessTest $t): void
{
    $view = $t->view();
    expect($view->inbox(InboxRecord::PENDING))->toBe([]);
    expect(count($t->fakeOf(Runtime\Charge::class)->calls))->toBeLessThanOrEqual(1);
    $keys = array_map(fn (EffectRecord $e) => $e->key, $view->effects());
    expect(count($keys))->toBe(count(array_unique($keys)));
    expect(array_filter($view->journal(), fn ($j) => $j->type === 'step' && ($j->data['event']['name'] ?? null) === 'submit'))->toHaveCount(1);
    $context = [];
    foreach ($view->journal() as $entry) {
        if (isset($entry->data['context_patch'])) {
            $context = JsonPatch::apply($context, $entry->data['context_patch']);
        }
    }
    expect($context)->toEqual($view->contextArray());
}

it('runs the full order scenario on the database', function () {
    $t = dbTest()->begin(Runtime\Order::class, ['n' => 3])
        ->send('submit', ['externalId' => 'E', 'files' => ['a', 'b']])
        ->assertIn('waiting')
        ->assertContext('uploaded', ['u-a', 'u-b']);
    $t->publish('party.decision', 'E', ['approved' => true])->assertFinal('approved');
});

it('survives a crash at any point on the database', function (string $point, int $skip, bool $expire) {
    $t = dbCrash($point, $skip, $expire);
    $t->assertFinal($expire ? 'expired' : 'approved');
    if (in_array($point, ['runner.before_commit', 'effect.after_claim', 'effect.after_call'], true) && $skip === 0) {
        expect($t->crashes)->not->toBeEmpty();
    }
    dbInvariants($t);
})->with(function () {
    foreach (['runner.before_commit', 'runner.after_commit', 'effect.after_claim', 'effect.after_call', 'effect.after_record', 'ticker.after_fire'] as $point) {
        foreach ([0, 2, 5] as $skip) {
            yield "{$point} #{$skip} approve" => [$point, $skip, false];
            yield "{$point} #{$skip} expire" => [$point, $skip, true];
        }
    }
});

it('handles unknown outcomes, compensation, children and archive on the database', function () {
    $t = dbTest()
        ->fake(Runtime\Charge::class, throws: new RuntimeException('timeout'), reconcile: Outcome::stillUnknown())
        ->begin(Runtime\Order::class)
        ->send('submit', ['externalId' => 'E', 'files' => []])
        ->travel('3 days', '30 minutes')
        ->assertIncident(IncidentRecord::EFFECT_UNKNOWN);
    $t->resolveEffect(Runtime\Charge::class, true, 'tx-hand')->assertIn('waiting');

    $t->fake(Runtime\Charge::class)->fake(Runtime\Book::class)->send('cancel')->assertFinal('closed');

    $c = dbTest()->begin(Runtime\Claim::class, ['claimId' => 'C-1']);
    $child = $c->view()->children()[0];
    $c->engine->send($child->id, 'signed', ['files' => ['f']]);
    $c->drain()->assertIn('review')->travel('2 hours', '30 minutes')->assertFinal('paid');

    // Instances with open incidents are kept out of the archive until someone resolves them.
    $t->travel('31 days', '1 day');
    $t->engine->archive();
    expect($t->engine->get($t->id())->isArchived())->toBeFalse();
    foreach ($t->view()->incidents() as $incident) {
        $t->engine->resolveIncident($incident->id, $t->operator(), 'payment confirmed');
    }
    $t->drain();
    expect($t->engine->archive())->toBeGreaterThanOrEqual(1);
    expect($t->engine->get($t->id())->isArchived())->toBeTrue();
});
