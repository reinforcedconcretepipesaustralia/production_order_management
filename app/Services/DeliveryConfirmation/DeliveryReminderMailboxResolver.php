<?php

namespace App\Services\DeliveryConfirmation;

/**
 * Resolves the shared sales mailbox that must send and receive for an entity.
 */
class DeliveryReminderMailboxResolver
{
    /**
     * Canonical planning entities shown on the dashboard.
     *
     * @var list<string>
     */
    public const CANONICAL_ENTITIES = ['RCPVIC', 'RCPNSW', 'RCPQLD', 'RCPSA'];

    public function forEntity(string $entity): ?string
    {
        $mailboxes = config('delivery-reminders.mailboxes', []);

        if (! is_array($mailboxes)) {
            return null;
        }

        $address = $mailboxes[strtoupper(trim($entity))] ?? null;

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        return strtolower(trim($address));
    }

    /**
     * Distinct shared mailboxes to poll for replies.
     *
     * @return list<string>
     */
    public function uniqueMailboxes(): array
    {
        $addresses = [];

        foreach (self::CANONICAL_ENTITIES as $entity) {
            $address = $this->forEntity($entity);

            if ($address !== null) {
                $addresses[$address] = $address;
            }
        }

        return array_values($addresses);
    }

    /**
     * @return list<array{entity: string, mailbox: ?string}>
     */
    public function canonicalMailboxMap(): array
    {
        return array_map(
            fn (string $entity): array => [
                'entity' => $entity,
                'mailbox' => $this->forEntity($entity),
            ],
            self::CANONICAL_ENTITIES,
        );
    }
}
