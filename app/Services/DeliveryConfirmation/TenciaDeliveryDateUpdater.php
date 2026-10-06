<?php

namespace App\Services\DeliveryConfirmation;

use App\Exceptions\DeliveryConfirmationException;
use App\Services\Sync\TenciaSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes a single open order line's delivery date into the state Tencia database.
 *
 * SOR_MASTER.DELIVERY_DATE is set to the earliest remaining open line date after
 * the line update so the header stays aligned when dates diverge.
 */
class TenciaDeliveryDateUpdater
{
    public function __construct(private TenciaSource $tencia) {}

    /**
     * @return array{lines_updated: int, header_updated: bool, header_date: string}
     */
    public function updateLine(
        string $entity,
        string $referenceNbr,
        int $referenceDuplicate,
        int $lineUniqueId,
        CarbonImmutable $newDate,
    ): array {
        $connection = $this->tencia->writeConnection();
        $username = (string) config('database.connections.rcpa_sql_write.username', '');

        if (
            $connection->getName() === 'rcpa_sql_write'
            && ($username === '' || blank(config('database.connections.rcpa_sql_write.database')))
        ) {
            throw DeliveryConfirmationException::writeConfiguration();
        }

        if (! $this->tencia->hasStateDatabase($entity)) {
            throw DeliveryConfirmationException::writeFailed();
        }

        $linesTable = $this->tencia->stateTableName($entity, 'SOR_LINES');
        $masterTable = $this->tencia->stateTableName($entity, 'SOR_MASTER');
        $dateValue = $newDate->startOfDay()->toDateTimeString();

        try {
            return $connection->transaction(function () use (
                $connection,
                $linesTable,
                $masterTable,
                $referenceNbr,
                $referenceDuplicate,
                $lineUniqueId,
                $dateValue,
            ): array {
                $linesUpdated = $connection->table(DB::raw($linesTable))
                    ->where('UNIQUE_ID', $lineUniqueId)
                    ->where('REFERENCE_NBR', $referenceNbr)
                    ->where('REFERENCE_DUPLICATE', $referenceDuplicate)
                    ->where('TRANS_TYPE', 'DRSOR')
                    ->update(['DELIVERY_DATE' => $dateValue]);

                if ($linesUpdated < 1) {
                    throw DeliveryConfirmationException::writeFailed();
                }

                $earliest = $connection->table(DB::raw($linesTable))
                    ->where('REFERENCE_NBR', $referenceNbr)
                    ->where('REFERENCE_DUPLICATE', $referenceDuplicate)
                    ->where('TRANS_TYPE', 'DRSOR')
                    ->where('BACKORDER_QTY', '>', 0)
                    ->min('DELIVERY_DATE');

                $headerDate = $earliest
                    ? CarbonImmutable::parse($earliest)->startOfDay()->toDateTimeString()
                    : $dateValue;

                $headerUpdated = $connection->table(DB::raw($masterTable))
                    ->where('REFERENCE_NBR', $referenceNbr)
                    ->where('REFERENCE_DUPLICATE', $referenceDuplicate)
                    ->where('TRANS_TYPE', 'DRSOR')
                    ->update(['DELIVERY_DATE' => $headerDate]) > 0;

                return [
                    'lines_updated' => $linesUpdated,
                    'header_updated' => $headerUpdated,
                    'header_date' => CarbonImmutable::parse($headerDate)->toDateString(),
                ];
            });
        } catch (DeliveryConfirmationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw DeliveryConfirmationException::writeFailed();
        }
    }
}
