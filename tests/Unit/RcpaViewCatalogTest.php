<?php

namespace Tests\Unit;

use App\Models\Rcpa\ReadOnlyView;
use App\Models\Rcpa\VwProductionView;
use App\Support\Rcpa\RcpaViewCatalog;
use Tests\TestCase;

class RcpaViewCatalogTest extends TestCase
{
    public function test_catalog_maps_each_erp_view_to_a_read_only_eloquent_model(): void
    {
        $models = (new RcpaViewCatalog)->models();

        $this->assertNotEmpty($models);
        $this->assertContains(VwProductionView::class, $models);

        foreach ($models as $model) {
            $this->assertTrue(is_subclass_of($model, ReadOnlyView::class));
            $this->assertSame('rcpa_sql', (new $model)->getConnectionName());
        }
    }
}
