<?php

namespace App\Services\Bot\Effects;

use App\Models\BotSession;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\BusinessHours;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * O bot de verdade: grava, envia e muda o ticket. Cada assunto fica num colaborador que recebe o mesmo `Ticket`, para que o
 * `refresh()` do `exclusive` valha para todos.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") a interface tem um método por tipo de efeito, de propósito
 */
final class LiveEffects implements BotEffects
{
    private readonly LiveMessages $messages;

    private readonly LiveTyping $typing;

    private readonly LiveQueue $queue;

    private readonly LiveTags $tags;

    private readonly LiveRecords $records;

    public function __construct(private readonly Ticket $ticket)
    {
        $this->messages = new LiveMessages($ticket);
        $this->typing = new LiveTyping($ticket);
        $this->queue = new LiveQueue($ticket);
        $this->tags = new LiveTags($ticket);
        $this->records = new LiveRecords($ticket);
    }

    public function say(string $text): void
    {
        $this->messages->say($text);
    }

    public function media(string $path, string $caption): void
    {
        $this->messages->media($path, $caption);
    }

    public function wait(int $seconds): void
    {
        $this->typing->wait($seconds);
    }

    public function saveContactField(string $field, string $value): void
    {
        $this->records->saveContactField($field, $value);
    }

    public function tag(Tag $tag): void
    {
        $this->tags->tag($tag);
    }

    public function hasTag(Tag $tag): bool
    {
        return $this->tags->hasTag($tag);
    }

    public function note(string $body): void
    {
        $this->records->note($body);
    }

    public function close(): void
    {
        $this->records->close();
    }

    public function enterQueue(?Sector $sector, ?User $assignTo): ?int
    {
        return $this->queue->enter($sector, $assignTo);
    }

    public function logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void
    {
        $this->records->logHandoff($reason, $sector, $assignedUserId);
    }

    public function exclusive(BotSession $session, Closure $work): void
    {
        DB::transaction(function () use ($session, $work): void {
            $locked = BotSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if ($locked === null || $locked->state !== BotSession::ACTIVE) {
                return;
            }

            $this->ticket->refresh();
            $work();
        });

        $session->refresh();
    }

    public function save(BotSession $session): void
    {
        $session->save();
    }

    public function entered(string $nodeId): void {}

    public function isOpen(): bool
    {
        return app(BusinessHours::class)->isOpen(now());
    }

    public function contactName(): string
    {
        return trim((string) $this->ticket->contact()->first()?->name);
    }

    public function returning(): bool
    {
        return Ticket::query()->where('contact_id', $this->ticket->contact_id)->whereKeyNot($this->ticket->id)->exists();
    }

    public function protocol(): string
    {
        return (string) $this->ticket->protocol;
    }
}
