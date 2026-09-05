<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'is_header' => (bool) $this->is_header,
            'parent_id' => $this->parent_id,
            'currency' => $this->currency,
            'status' => $this->status,
            'depth' => $this->getAttribute('depth') ?? 0,
            'children_count' => $this->getAttribute('children_count') ?? ($this->relationLoaded('children') ? $this->children->count() : 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'parent' => $this->whenLoaded('parent', fn () => new self($this->parent)),
            'children' => self::collection($this->whenLoaded('children')),
        ];

        // Balance aggregates are only available when the list query
        // eagerly loads them via withSum.
        if (array_key_exists('total_debit', $this->getAttributes())) {
            $totalDebit = (float) ($this->total_debit ?? 0);
            $totalCredit = (float) ($this->total_credit ?? 0);

            // Normal balance side: debit for asset/expense, credit otherwise.
            $isDebitNormal = in_array($this->type, ['asset', 'expense'], true);
            $net = $isDebitNormal
                ? $totalDebit - $totalCredit
                : $totalCredit - $totalDebit;
            $balanceSide = $net >= 0
                ? ($isDebitNormal ? 'debit' : 'credit')
                : ($isDebitNormal ? 'credit' : 'debit');

            $data['total_debit'] = number_format($totalDebit, 2, '.', '');
            $data['total_credit'] = number_format($totalCredit, 2, '.', '');
            $data['net'] = number_format($net, 2, '.', '');
            $data['balance'] = number_format(abs($net), 2, '.', '');
            $data['balance_side'] = $balanceSide;
        }

        return $data;
    }
}
