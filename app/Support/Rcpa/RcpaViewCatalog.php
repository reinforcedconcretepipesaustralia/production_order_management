<?php

namespace App\Support\Rcpa;

use App\Models\Rcpa\ReadOnlyView;
use App\Models\Rcpa\VwBOMLINESView;
use App\Models\Rcpa\VwProductionView;
use App\Models\Rcpa\VwSALESORDERSView;
use App\Models\Rcpa\VwSALESView;
use App\Models\Rcpa\VwSTKMASTERView;
use App\Models\Rcpa\VwSTOCKONHANDView;

final class RcpaViewCatalog
{
    /**
     * @return list<class-string<ReadOnlyView>>
     */
    public function models(): array
    {
        return [
            VwBOMLINESView::class,
            VwProductionView::class,
            VwSALESORDERSView::class,
            VwSALESView::class,
            VwSTKMASTERView::class,
            VwSTOCKONHANDView::class,
        ];
    }
}
