<?php

namespace App\Enums;

enum Capability: string
{
    case ViewSchedules = 'schedules.view';
    case ManageSchedules = 'schedules.manage';
    case ViewAssets = 'assets.view';
    case ManageAssets = 'assets.manage';
    case ViewMasterData = 'master-data.view';
    case ManageMasterData = 'master-data.manage';
    case ViewDepotOperations = 'depot-operations.view';
    case ViewProductionActuals = 'production-actuals.view';
    case EnterProductionActuals = 'production-actuals.enter';
    case ViewQualityInspections = 'quality-inspections.view';
    case SignOffQualityRelease = 'quality.release.sign-off';
    case SignOffQualityRework = 'quality.rework.sign-off';
    case ViewDispatchLoads = 'dispatch-loads.view';
    case ManageDispatchLoads = 'dispatch-loads.manage';
    case ManageDispatchDates = 'dispatch-dates.manage';
    case SendDispatchNotifications = 'dispatch-notifications.send';
    case ViewMaterials = 'materials.view';
    case ManageSteelPurchasing = 'steel-purchasing.manage';
    case ManageInventoryTransfers = 'inventory-transfers.manage';
    case ViewDashboards = 'dashboards.view';

    public function requiresDepotAssignment(): bool
    {
        return match ($this) {
            self::ViewDepotOperations,
            self::ViewProductionActuals,
            self::EnterProductionActuals,
            self::ViewQualityInspections,
            self::SignOffQualityRelease,
            self::SignOffQualityRework,
            self::ViewDispatchLoads,
            self::ManageDispatchLoads,
            self::ManageDispatchDates,
            self::SendDispatchNotifications,
            self::ViewMaterials,
            self::ManageSteelPurchasing,
            self::ManageInventoryTransfers => true,
            default => false,
        };
    }
}
