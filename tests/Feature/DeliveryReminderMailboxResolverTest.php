<?php

namespace Tests\Feature;

use App\Services\DeliveryConfirmation\DeliveryReminderMailboxResolver;
use Tests\TestCase;

class DeliveryReminderMailboxResolverTest extends TestCase
{
    public function test_maps_plant_entities_and_src_aliases_to_shared_sales_mailboxes(): void
    {
        $resolver = app(DeliveryReminderMailboxResolver::class);

        $this->assertSame('salesqld@rcpa.com.au', $resolver->forEntity('RCPQLD'));
        $this->assertSame('salesqld@rcpa.com.au', $resolver->forEntity('SRCQLD'));
        $this->assertSame('salesnsw@rcpa.com.au', $resolver->forEntity('RCPNSW'));
        $this->assertSame('salesnsw@rcpa.com.au', $resolver->forEntity('SRCNSW'));
        $this->assertSame('vicsales@rcpa.com.au', $resolver->forEntity('RCPVIC'));
        $this->assertSame('vicsales@rcpa.com.au', $resolver->forEntity('SRCVIC'));
        $this->assertSame('vicsales@rcpa.com.au', $resolver->forEntity('SRCVIV'));
        $this->assertSame('salessa@rcpa.com.au', $resolver->forEntity('RCPSA'));
        $this->assertSame('salessa@rcpa.com.au', $resolver->forEntity('SRCSA'));
    }

    public function test_unmapped_entities_return_null_and_are_not_polled(): void
    {
        config()->set('delivery-reminders.mailboxes.RCPWA', null);

        $resolver = app(DeliveryReminderMailboxResolver::class);

        $this->assertNull($resolver->forEntity('RCPWA'));
        $this->assertNull($resolver->forEntity('UNKNOWN'));
        $this->assertSame([
            'vicsales@rcpa.com.au',
            'salesnsw@rcpa.com.au',
            'salesqld@rcpa.com.au',
            'salessa@rcpa.com.au',
        ], $resolver->uniqueMailboxes());
    }

    public function test_env_override_changes_the_mapped_address(): void
    {
        config()->set('delivery-reminders.mailboxes.RCPVIC', 'vic-override@example.com');
        config()->set('delivery-reminders.mailboxes.SRCVIC', 'vic-override@example.com');

        $resolver = app(DeliveryReminderMailboxResolver::class);

        $this->assertSame('vic-override@example.com', $resolver->forEntity('RCPVIC'));
    }
}
