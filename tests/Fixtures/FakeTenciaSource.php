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

        Schema::create('vwSALES_ORDERS', function (Blueprint $table) {
            $table->string('ENTITY');
            $table->string('TRANS_TYPE')->default('DRSOR');
            $table->string('STATUS_FLAG')->nullable();
            $table->integer('UNIQUE_ID');
            $table->string('REFERENCE_NBR');
            $table->integer('LINE_NBR')->default(1);
            $table->string('STOCK_CODE');
            $table->string('STOCK_LOCATION')->nullable();
            $table->string('DESCRIPTION')->nullable();
            $table->decimal('BACKORDER_QTY', 18, 6)->default(0);
            $table->decimal('ALLOCATED_QTY', 18, 6)->default(0);
            $table->dateTime('LINE_DELIVERY_DATE')->nullable();
            $table->dateTime('ORDER_DATE')->nullable();
            $table->string('CUSTOMER_CODE')->nullable();
            $table->string('LINE_STATUS')->nullable();
            $table->string('DELIVERY_NAME')->nullable();
            $table->string('DELIVERY_NOTE_1')->nullable();
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
     * An open order line, written to both order-book sources so a scenario reads the same
     * whether it is answered by dbo.vwSALES_ORDERS or by the per-state SOR tables.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $header
     */
    public function addOrderLine(string $entity, array $line, array $header = []): self
    {
        $attributes = array_merge([
            'ENTITY' => $entity,
            'TRANS_TYPE' => 'DRSOR',
            'UNIQUE_ID' => DB::table('vwSALES_ORDERS')->count() + 1,
            'REFERENCE_NBR' => (string) ($line['REFERENCE_NBR'] ?? '1'),
            'LINE_NBR' => 1,
            'LINE_STATUS' => 'O',
            'STATUS_FLAG' => 'O',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => '2026-12-01 00:00:00',
            'ORDER_DATE' => '2026-09-01 00:00:00',
            'DELIVERY_NAME' => 'A customer',
            'DELIVERY_NOTE_1' => 'A site',
        ], $line, $header);

        $attributes['ENTITY'] = $entity;
        $attributes['LINE_DELIVERY_DATE'] = $line['LINE_DELIVERY_DATE']
            ?? $header['LINE_DELIVERY_DATE']
            ?? $line['DELIVERY_DATE']
            ?? $header['DELIVERY_DATE']
            ?? $attributes['LINE_DELIVERY_DATE'];
        unset($attributes['DELIVERY_DATE']);

        if (Schema::hasTable('vwSALES_ORDERS')) {
            DB::table('vwSALES_ORDERS')->insert($attributes);
        }

        $this->addStateOrderLine($entity, $attributes);

        return $this;
    }

    /**
     * A line only the per-state SOR tables know about, so a test can tell which source answered.
     *
     * @param  array<string, mixed>  $line
     */
    public function addOrderLineToStateTablesOnly(string $entity, array $line): self
    {
        $this->addStateOrderLine($entity, array_merge([
            'TRANS_TYPE' => 'DRSOR',
            'UNIQUE_ID' => 9000 + DB::table($this->stateTableName($entity, 'SOR_LINES'))->count(),
            'REFERENCE_NBR' => 'STATE-ONLY',
            'LINE_NBR' => 1,
            'LINE_STATUS' => 'O',
            'STATUS_FLAG' => 'O',
            'BACKORDER_QTY' => 10,
            'LINE_DELIVERY_DATE' => '2026-12-01 00:00:00',
            'ORDER_DATE' => '2026-09-01 00:00:00',
            'DELIVERY_NAME' => 'A customer',
            'DELIVERY_NOTE_1' => 'A site',
        ], $line));

        return $this;
    }

    /**
     * Take dbo.vwSALES_ORDERS away, the way the server does when the read-only login is denied
     * SELECT on the state tables behind it.
     */
    public function denyConsolidatedOrderBook(): self
    {
        Schema::drop('vwSALES_ORDERS');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes  A line already shaped like the consolidated view.
     */
    private function addStateOrderLine(string $entity, array $attributes): void
    {
        $lines = DB::table($this->stateTableName($entity, 'SOR_LINES'));
        $headers = DB::table($this->stateTableName($entity, 'SOR_MASTER'));

        $lines->insert([
            'UNIQUE_ID' => $attributes['UNIQUE_ID'],
            'TRANS_TYPE' => $attributes['TRANS_TYPE'],
            'REFERENCE_NBR' => $attributes['REFERENCE_NBR'],
            'REFERENCE_DUPLICATE' => 0,
            'LINE_NBR' => $attributes['LINE_NBR'],
            'STOCK_CODE' => $attributes['STOCK_CODE'],
            'STOCK_LOCATION' => $attributes['STOCK_LOCATION'] ?? null,
            'DESCRIPTION' => $attributes['DESCRIPTION'] ?? null,
            'BACKORDER_QTY' => $attributes['BACKORDER_QTY'],
            'ALLOCATED_QTY' => $attributes['ALLOCATED_QTY'] ?? 0,
            'DELIVERY_DATE' => $attributes['LINE_DELIVERY_DATE'],
            'CUSTOMER_CODE' => $attributes['CUSTOMER_CODE'] ?? null,
            'LINE_STATUS' => $attributes['LINE_STATUS'],
        ]);

        if ($headers->where('REFERENCE_NBR', $attributes['REFERENCE_NBR'])->exists()) {
            return;
        }

        $headers->insert([
            'TRANS_TYPE' => $attributes['TRANS_TYPE'],
            'REFERENCE_NBR' => $attributes['REFERENCE_NBR'],
            'REFERENCE_DUPLICATE' => 0,
            'ORDER_DATE' => $attributes['ORDER_DATE'],
            'STATUS_FLAG' => $attributes['STATUS_FLAG'],
            'DELIVERY_NAME' => $attributes['DELIVERY_NAME'],
            'DELIVERY_NOTE_1' => $attributes['DELIVERY_NOTE_1'],
        ]);
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
