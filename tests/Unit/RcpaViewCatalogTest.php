<?php

namespace Tests\Unit;

use App\Models\Rcpa\ReadOnlyView;
use App\Models\Rcpa\VwBOMLINESView;
use App\Models\Rcpa\VwOPENORDERSView;
use App\Models\Rcpa\VwProductionView;
use App\Models\Rcpa\VwSALESORDERSView;
use App\Models\Rcpa\VwSALESView;
use App\Models\Rcpa\VwSTKMASTERView;
use App\Models\Rcpa\VwSTOCKONHANDView;
use App\Support\Rcpa\RcpaViewCatalog;
use Tests\TestCase;

class RcpaViewCatalogTest extends TestCase
{
    public function test_catalog_maps_each_erp_view_to_a_read_only_eloquent_model(): void
    {
        $models = (new RcpaViewCatalog)->models();

        $this->assertSame([
            VwBOMLINESView::class,
            VwOPENORDERSView::class,
            VwProductionView::class,
            VwSALESORDERSView::class,
            VwSALESView::class,
            VwSTKMASTERView::class,
            VwSTOCKONHANDView::class,
        ], $models);

        foreach ($models as $model) {
            $this->assertTrue(is_subclass_of($model, ReadOnlyView::class));
            $this->assertSame('rcpa_sql', (new $model)->getConnectionName());
        }
    }
}
