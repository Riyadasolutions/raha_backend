<?php

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Catalog\Services\RequirementsEngine;
use Modules\Catalog\Services\ServiceDelivery;

/** Static catalog option lists (front mocks/catalog_data.ts + service_delivery labels). */
class CatalogOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'serviceCategories' => raha('catalog.serviceCategories', []),
            'providerTypes' => raha('catalog.providerTypes', []),
            'deliveryTypes' => array_keys(ServiceDelivery::LABELS),
            'deliveryLabels' => ServiceDelivery::LABELS,
            'noRequirements' => RequirementsEngine::noRequirements(),
        ]]);
    }
}
