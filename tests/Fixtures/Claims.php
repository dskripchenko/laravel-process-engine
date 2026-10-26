<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Tests\Fixtures;

use Dskripchenko\LaravelProcessEngine\Concerns\HasProcesses;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;
use Illuminate\Database\Eloquent\Model;

use function Dskripchenko\ProcessEngine\project;

final class Claim extends Model
{
    use HasProcesses;

    public $timestamps = false;

    protected $guarded = [];
}

final class Mailer
{
    /** @var list<string> */
    public array $sent = [];
}

#[Idempotent]
final class SendMail
{
    public function __construct(private readonly Mailer $mailer) {}

    public function __invoke(array $context): string
    {
        $this->mailer->sent[] = (string) ($context['to'] ?? '');

        return 'mail-'.count($this->mailer->sent);
    }
}

final class ClaimFlow extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('draft', fn (Builder $s) => $s->on('submit', to: 'review'));
        $p->state('review', fn (Builder $s) => $s
            ->invoke(SendMail::class, into: 'mail')
            ->on('approve', to: 'approved')
            ->after('2 days', to: 'expired'));
        $p->final('approved');
        $p->final('expired');
    }

    public function projections(): array
    {
        return [project(Claim::class, 'status', ['draft' => 'Draft', 'review' => 'In review', 'approved' => 'Approved', 'expired' => 'Expired'])];
    }
}
