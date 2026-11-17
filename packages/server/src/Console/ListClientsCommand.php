<?php

declare(strict_types=1);

namespace LaravelSso\Server\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use LaravelSso\Server\Contracts\ManagesClients;

final class ListClientsCommand extends Command
{
    protected $signature = 'sso:clients {--status=active : active, revoked or all} {--per-page=20} {--page=1} {--json}';

    protected $description = 'List registered SSO applications without exposing secrets';

    public function handle(ManagesClients $clients): int
    {
        try {
            $input = validator(['page' => $this->option('page'), 'per_page' => $this->option('per-page')],
                ['page' => 'required|integer|min:1|max:1000000', 'per_page' => 'required|integer|min:1|max:100'])->validate();
            $page = $clients->listing($this->option('status'), (int) $input['per_page'], (int) $input['page']);
            if ($this->option('json')) {
                $this->line($page->toJson(JSON_THROW_ON_ERROR));
            } else {
                $this->table(['ID', 'Name', 'Public', 'Restricted users', 'Revoked'],
                    array_map(fn ($client) => [$client->id, $client->name, (int) $client->public,
                        (int) $client->restrictUsers, (int) $client->revoked], $page->items()));
                $this->line('Page '.$page->currentPage().'/'.$page->lastPage().'; total '.$page->total());
            }
        } catch (ValidationException $error) {
            $this->error(implode(' ', $error->validator->errors()->all()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
