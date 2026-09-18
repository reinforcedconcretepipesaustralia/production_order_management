<?php

namespace App\Enums;

enum AppRole: string
{
    case PlannerAdmin = 'Planner.Admin';
    case DepotOperator = 'Depot.Operator';
    case QualityInspector = 'Quality.Inspector';
    case LogisticsDispatcher = 'Logistics.Dispatcher';
    case MaterialsBuyer = 'Materials.Buyer';
    case ViewerExecutive = 'Viewer.Executive';

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::PlannerAdmin => [
                Capability::ViewSchedules,
                Capability::ManageSchedules,
                Capability::ViewAssets,
                Capability::ManageAssets,
                Capability::ViewMasterData,
                Capability::ManageMasterData,
                Capability::ViewDashboards,
            ],
            self::DepotOperator => [
                Capability::ViewDepotOperations,
                Capability::ViewProductionActuals,
                Capability::EnterProductionActuals,
            ],
            self::QualityInspector => [
                Capability::ViewQualityInspections,
                Capability::SignOffQualityRelease,
                Capability::SignOffQualityRework,
            ],
            self::LogisticsDispatcher => [
                Capability::ViewDispatchLoads,
                Capability::ManageDispatchLoads,
                Capability::ManageDispatchDates,
                Capability::SendDispatchNotifications,
            ],
            self::MaterialsBuyer => [
                Capability::ViewMaterials,
                Capability::ManageSteelPurchasing,
                Capability::ManageInventoryTransfers,
            ],
            self::ViewerExecutive => [
                Capability::ViewDashboards,
            ],
        };
    }
}
