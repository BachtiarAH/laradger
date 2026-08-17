<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Support\Arrayable;

class JournalDraft implements Arrayable
{
    /**
     * @param  array<int, array{account_name: string, account_type: string, debit: ?string, credit: ?string, description: ?string}>  $lines
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public ?string $transaction_date,
        public ?string $description,
        public array $lines,
        public array $tags = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            transaction_date: isset($data['transaction_date']) ? (string) $data['transaction_date'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            lines: array_map(
                static fn (mixed $line): array => [
                    'account_name' => (string) ($line['account_name'] ?? ''),
                    'account_type' => (string) ($line['account_type'] ?? ''),
                    'debit' => isset($line['debit']) ? (string) $line['debit'] : null,
                    'credit' => isset($line['credit']) ? (string) $line['credit'] : null,
                    'description' => isset($line['description']) ? (string) $line['description'] : null,
                ],
                $data['lines'] ?? [],
            ),
            tags: array_map(
                static fn (mixed $tag): string => (string) $tag,
                $data['tags'] ?? [],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_date' => $this->transaction_date,
            'description' => $this->description,
            'lines' => $this->lines,
            'tags' => $this->tags,
        ];
    }
}
