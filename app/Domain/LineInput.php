<?php

namespace App\Domain;

/** One product line submitted with an action. */
final class LineInput
{
    public function __construct(
        public readonly int $productId,
        public readonly int $qty,
        public readonly ?string $unitPrice = null,
        public readonly ?string $unitCost = null,
    ) {}

    /**
     * Validate and normalise form rows: quantities > 0, duplicate products merged, sorted by
     * product id so row locks are always taken in the same order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<self>
     */
    public static function fromRows(array $rows): array
    {
        $byProduct = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);

            if ($productId <= 0) {
                throw new InventoryException('Every line needs a product.');
            }
            if ($qty <= 0) {
                throw new InventoryException('Quantities must be at least 1.');
            }
            foreach (['unit_price', 'unit_cost'] as $field) {
                if (isset($row[$field]) && $row[$field] !== '' && (float) $row[$field] < 0) {
                    throw new InventoryException('Prices cannot be negative.');
                }
            }

            $price = isset($row['unit_price']) && $row['unit_price'] !== '' ? Money::fromSen(Money::toSen($row['unit_price'])) : null;
            $cost = isset($row['unit_cost']) && $row['unit_cost'] !== '' ? Money::fromSen(Money::toSen($row['unit_cost'])) : null;

            if (isset($byProduct[$productId])) {
                $existing = $byProduct[$productId];
                if ($existing->unitPrice !== $price || $existing->unitCost !== $cost) {
                    throw new InventoryException('The same product appears twice with different prices.');
                }
                $byProduct[$productId] = new self($productId, $existing->qty + $qty, $price, $cost);
            } else {
                $byProduct[$productId] = new self($productId, $qty, $price, $cost);
            }
        }

        if ($byProduct === []) {
            throw new InventoryException('Add at least one product.');
        }

        ksort($byProduct);

        return array_values($byProduct);
    }
}
