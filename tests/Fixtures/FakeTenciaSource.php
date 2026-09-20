<?php

namespace Tests\Fixtures;

use App\Services\Sync\TenciaSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tencia, standing in for the real thing so the syncs can be tested off the corporate network.
 *
 * Creates plainly named local tables matching the view and per-state shapes the syncs read, and
 * points the source at them. Only the columns the syncs actually select are modelled — the real views
 * are far wider, and a fixture that copied them would hide which columns matter.
 */
class FakeTenciaSource extends TenciaSource
{
    protected function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    protected function viewName(string $name): string
    {
        return $name;
    }

    public function stateTableName(string $entityCode, string $table): string
    {
        return $this->databaseFor($entityCode).'_'.$table;
    }

    public function monthParts(string $column): array
    {
        return ["CAST(strftime('%Y', $column) AS INTEGER)", "CAST(strftime('%m', $column) AS INTEGER)"];
    }

    /**
     * Register this fake in the container so every sync resolves it.
     */
    public static function install(): self
    {
        $fake = new self;

        app()->instance(TenciaSource::class, $fake);

        return $fake;
    }

    public function createTables(): self
    {
        Schema::create('vwSTK_MASTER', function (Blueprint $table) {
            $table->string('ENTITY');
            $table->string('STOCK_CODE');
            $table->string('STOCK_CATEGORY');
            $table->string('STOCK_DESCRIPTION_1')->nullable();
            $table->decimal('UNIT_WEIGHT', 14, 6)->default(0);
            $table->decimal('SALES_CONVERSION', 14, 6)->default(0);
            $table->decimal('STANDARD_COST', 14, 6)->default(0);
            $table->string('OBSOLETE_STOCK')->nullable();
            $table->string('DELETE_STOCK')->nullable();
        });

        Schema::create('vwBOM_LINES', function (Blueprint $table) {
            $table->string('ENTITY');
            $table->string('BILL_STOCK_CODE');
            $table->string('STOCK_CODE');
            $table->string('DESCRIPTION')->nullable();
            $table->string('UNIT_OF_MEASURE')->nullable();
            $table->string('STOCK_CATEGORY')->nullable();
            $table->decimal('BILL_ORDER_QTY', 18, 8)->default(0);
        });

        Schema::create('vwSALES', function (Blueprint $table) {
            $table->string('ENTITY');
            $table->string('STOCK_CODE');
            $table->string('TRANS_TYPE');
            $table->dateTime('DATE');
            $table->decimal('QUANTITY', 18, 6)->default(0);
            $table->decimal('Line_Weight', 18, 6)->default(0);
            $table->decimal('Net_Value_Ex', 18, 6)->default(0);
        });

        Schema::create('vwProduction', function (Blueprint $table) {
            $table->string('ENTITY');
            $table->string('PROD_ORD_NBR');
            $table->integer('PROD_ORD_DUPLICATE')->default(0);
            $table->string('BILL_STOCK_CODE');
            $table->string('STOCK_CODE')->nullable();
            $table->string('STATUS');
            $table->dateTime('ORDER_DATE');
            $table->decimal('GOOD_PIPES', 18, 6)->default(0);
            $table->decimal('REJECTED_PIPES', 18, 6)->default(0);
            $table->decimal('BILL_TONNES_PRODUCED', 18, 6)->default(0);
        });

        foreach (['RCPVIC', 'RCPNSW', 'RCPQLD', 'RCPSA'] as $entity) {
            Schema::create($this->stateTableName($entity, 'SOR_LINES'), function (Blueprint $table) {
                $table->integer('UNIQUE_ID');
                $table->string('TRANS_TYPE')->default('DRSOR');
                $table->string('REFERENCE_NBR');
                $table->integer('REFERENCE_DUPLICATE')->default(0);
                $table->integer('LINE_NBR')->default(1);
                $table->string('STOCK_CODE');
                $table->string('STOCK_LOCATION')->nullable();
                $table->string('DESCRIPTION')->nullable();
                $table->decimal('BACKORDER_QTY', 18, 6)->default(0);
                $table->decimal('ALLOCATED_QTY', 18, 6)->default(0);
                $table->dateTime('DELIVERY_DATE')->nullable();
                $table->string('CUSTOMER_CODE')->nullable();
                $table->string('LINE_STATUS')->nullable();
            });

            Schema::create($this->stateTableName($entity, 'SOR_MASTER'), function (Blueprint $table) {
                $table->string('TRANS_TYPE')->default('DRSOR');
                $table->string('REFERENCE_NBR');
                $table->integer('REFERENCE_DUPLICATE')->default(0);
                $table->dateTime('ORDER_DATE')->nullable();
                $table->string('STATUS_FLAG')->nullable();
                $table->string('DELIVERY_NAME')->nullable();
                $table->string('DELIVERY_NOTE_1')->nullable();
            });

            Schema::create($this->stateTableName($entity, 'STK_LOCN_MASTER'), function (Blueprint $table) {
                $table->string('STOCK_CODE');
                $table->string('STOCK_LOCATION');
                $table->decimal('ON_HAND_QTY', 18, 6)->default(0);
                $table->decimal('ALLOCATED_QTY', 18, 6)->default(0);
                $table->decimal('BACKORDER_QTY', 18, 6)->default(0);
            });
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addProduct(array $row): self
    {
        DB::table('vwSTK_MASTER')->insert(array_merge([
            'ENTITY' => 'RCPVIC',
            'STOCK_CATEGORY' => 'VA',
            'STOCK_DESCRIPTION_1' => 'Pipe',
            'UNIT_WEIGHT' => 1,
        ], $row));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addBomLine(array $row): self
    {
        DB::table('vwBOM_LINES')->insert(array_merge([
            'ENTITY' => 'RCPVIC',
            'UNIT_OF_MEASURE' => 'TONN',
        ], $row));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addSale(array $row): self
    {
        DB::table('vwSALES')->insert(array_merge([
            'ENTITY' => 'RCPVIC',
            'TRANS_TYPE' => 'DRINV',
        ], $row));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addProductionRow(array $row): self
    {
        DB::table('vwProduction')->insert(array_merge([
            'ENTITY' => 'RCPVIC',
            'STATUS' => 'Order Completed',
            'PROD_ORD_DUPLICATE' => 0,
        ], $row));

        return $this;
    }

    /**
     * An order line and, unless one already exists, the header it hangs from.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $header
     */
    public function addOrderLine(string $entity, array $line, array $header = []): self
    {
        $reference = (string) ($line['REFERENCE_NBR'] ?? '1');

        DB::table($this->stateTableName($entity, 'SOR_LINES'))->insert(array_merge([
            'UNIQUE_ID' => DB::table($this->stateTableName($entity, 'SOR_LINES'))->count() + 1,
            'TRANS_TYPE' => 'DRSOR',
            'REFERENCE_NBR' => $reference,
            'REFERENCE_DUPLICATE' => 0,
            'LINE_NBR' => 1,
            'LINE_STATUS' => 'O',
            'BACKORDER_QTY' => 10,
            'DELIVERY_DATE' => '2026-12-01 00:00:00',
        ], $line));

        $headers = DB::table($this->stateTableName($entity, 'SOR_MASTER'));

        if ($headers->where('REFERENCE_NBR', $reference)->doesntExist()) {
            $headers->insert(array_merge([
                'TRANS_TYPE' => 'DRSOR',
                'REFERENCE_NBR' => $reference,
                'REFERENCE_DUPLICATE' => 0,
                'ORDER_DATE' => '2026-09-01 00:00:00',
                'STATUS_FLAG' => 'O',
                'DELIVERY_NAME' => 'A customer',
                'DELIVERY_NOTE_1' => 'A site',
            ], $header));
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addStock(string $entity, array $row): self
    {
        DB::table($this->stateTableName($entity, 'STK_LOCN_MASTER'))->insert($row);

        return $this;
    }
}
