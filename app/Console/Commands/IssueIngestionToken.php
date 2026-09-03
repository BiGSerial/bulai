<?php

namespace App\Console\Commands;

use App\Models\Knowledge\IngestionToken;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class IssueIngestionToken extends Command
{
    protected $signature = 'knowledge:issue-token
        {name : Human readable token name}
        {--scope=knowledge:ingest : Token scope}
        {--expires=tomorrow : Expiration date parseable by Carbon}';

    protected $description = 'Issue a temporary ingestion Bearer Token. The plain token is shown only once.';

    public function handle(): int
    {
        [$token, $plainToken] = IngestionToken::issue(
            $this->argument('name'),
            $this->option('scope'),
            CarbonImmutable::parse($this->option('expires')),
        );

        $this->line('Token ID: '.$token->id);
        $this->line('Scope: '.$token->scope);
        $this->line('Expires at: '.$token->expires_at->toIso8601String());
        $this->newLine();
        $this->warn('Plain token, shown only once:');
        $this->line($plainToken);

        return self::SUCCESS;
    }
}
