# Tencia ERP contract

What each importer reads, and why. Recorded live from `RCP-AUS-SQL01` on 21 September 2026 against
SQL Server 14.00.2110, as login `tenciarcpa` (read-only). Every importer in `app/Console/Commands/Sync`
is written against this document rather than against the Excel workbooks it replaces.

Re-run `php artisan rcpa:sql-status --views --columns` after any change to the view layer.

## Entities

`RCPVIC`, `RCPNSW`, `RCPQLD`, `RCPSA`. There is no `RCPWA` row in the consolidated view — Western
Australia is closing and is out of the model. `RCPSA` is a holding yard: it appears in stock and
sales but has **no BOM and no production**, which confirms it makes nothing.

## Two databases, not one

The consolidated `TenciaRCP_View` is preferred, but it does not cover everything. Two gaps force a
per-state fallback to `TenciaRCP_VIC` / `_NSW` / `_QLD` / `_SA`, which the same login can read:

| Gap | Why the view fails | Fallback |
|---|---|---|
| Order book | `vwSALES_ORDERS` exists but **SELECT is denied** | `SOR_LINES` + `SOR_MASTER` |
| Pipe stock on hand | `vwSTOCK_ON_HAND` holds only categories `?G` (rings, sandbands, caps) and `?H` (seconds) — 142 rows, **no pipes** | `STK_LOCN_MASTER` |

Both are worth raising with whoever owns the view layer: a grant on `vwSALES_ORDERS` and a
`vwSTOCK_ON_HAND` widened to pipe categories would let the app read one database again. Until then
the per-state fallback is the contract, and `sync:orders` / `sync:stock` are written against it.

`vwQUOTES` is also denied. Nothing needs it yet.

## Per import

### `sync:products` → `sku_master`

`dbo.vwSTK_MASTER` (10,263 rows). Keyed `(ENTITY, STOCK_CODE)`.

`UNIT_WEIGHT` is tonnes per pipe. `OBSOLETE_STOCK` and `DELETE_STOCK` mark products to skip.
Pipe categories are per state: VIC `VA`/`VD`, NSW `NA`/`ND`/`NK`, QLD `YA`/`YB`. Take them from
`depots.erp_stock_categories`, never from a literal — copying a category list between states is
exactly the bug that is live in the VIC workbook today.

### `sync:bom` → `sku_wire_requirements`, `sku_master.concrete_volume_m3`

`dbo.vwBOM_LINES` (8,207 rows). Keyed `(ENTITY, BILL_STOCK_CODE, STOCK_CODE)`.

`BILL_ORDER_QTY` is **per pipe**, and its unit depends on the component:

| Component | `UNIT_OF_MEASURE` | Meaning |
|---|---|---|
| `WIRE…` | `TONN` | tonnes of wire per pipe — **×1000 for kg** |
| mix code, e.g. `YAT-BIDI`, `YAT-EKO` | `M3` | concrete volume per pipe |
| `LAB - …`, `OH - …` | `TONN` | labour and overhead recovery, not materials |

Wire gauge is the stock code's suffix. Live gauges, which differ by state — do not assume a
national list:

| State | Gauges present |
|---|---|
| VIC | `WIREPL3.97`, `WIREPL4.95`, `WIREPL6.23`, `WIREDE6.00`, `WIREDE7.50`, `WIREDE9.00` |
| NSW | `WIREPL4.95`, `WIREPL5.55`, `WIREPL6.23`, `WIREDE7.50`, `WIREDE9.00` |
| QLD | `WIREPL3.97`, `WIREPL4.95`, `WIREPL5.55`, `WIREPL6.23`, `WIREDE7.50`, `WIREDE9.00` |

The 6.00 mm gauge is `WIREDE6.00`, not `WIREPL6.00` as the plan assumed, and only VIC carries it.

### `sync:orders` → `sales_orders`

`SOR_LINES` joined `SOR_MASTER` on `(REFERENCE_NBR, REFERENCE_DUPLICATE, TRANS_TYPE)`, per state
database. Filter `m.TRANS_TYPE = 'DRSOR'`, `m.STATUS_FLAG NOT IN ('C','X')`, `l.LINE_STATUS <> 'C'` —
the same filter the workbook's Power Query used.

`l.UNIQUE_ID` is the natural key. `BACKORDER_QTY` is the quantity still to make; `STOCK_QTY` is the
ordered quantity. `l.DELIVERY_DATE` is the line's due date and is what the priority rule ranks on —
prefer it over the header's. `SHIPPED_QTY` is nullable.

Live at discovery: VIC 2,742 open lines, NSW 1,991, QLD 2,508, SA 169 — 7,410 nationally, against
155,835 + 98,738 + 140,805 + 7,876 backordered pipes.

Delivery dates run from 2016 to 2027, so overdue lines are real and must not be treated as bad data.

### `sync:stock` → `inventory_sohs`

`STK_LOCN_MASTER` per state database, keyed `(STOCK_CODE, STOCK_LOCATION)`. Use `ON_HAND_QTY`,
`ALLOCATED_QTY`, `BACKORDER_QTY`, `AVAILABLE_QTY`. Quantities go negative and that is not an error.

Locations, confirmed live. Ownership and kind come from `depots`, which `Depot::stockKindFor()` reads:

| State | Location | Holds |
|---|---|---|
| VIC | `31` Melton pipes, `32` Kilmore pipes | prime |
| VIC | `33` Melton repairs, `34` Kilmore repairs, `37` | rework |
| VIC | `35`, `36`, `38` Precast; `51`, `71`, `81` empty | not pipe plant stock |
| NSW | `MAIN` Somersby, `22` Thornton, `TS` | prime |
| NSW | `QS`, `QT` | quarantine |
| QLD | `40` Yatala | prime |
| QLD | `42` | rework |
| QLD | `43` | quarantine |
| SA | `50`, `51` | holding yard, no production |

VIC `33` alone holds 5,151 pipes of rework — large enough that netting it against demand matters.

### `sync:sales-history` → `sales_history_monthly`

`dbo.vwSALES` (116,586 rows). `TRANS_TYPE` is `DRINV` (113,642) or `DRCDT` (2,944, credits —
subtract them). Covers 22 September 2023 to today, so R12M is available for every state and R3M/R6M
are safe. Note `DATE` is a reserved word; bracket it.

### `sync:production-actuals` → `production_actuals`

`dbo.vwProduction` (328,417 rows).

**The view is BOM-exploded**: one row per production order *per component*, up to 24 rows for a
single order. `GOOD_PIPES`, `REJECTED_PIPES` and `BILL_TONNES_PRODUCED` are header values repeated
on every component row, while `QTY_ORDERED` is the component's own quantity. Summing `GOOD_PIPES`
over the view multiplies production by the number of components.

The grain is `(ENTITY, PROD_ORD_NBR, PROD_ORD_DUPLICATE)`, and `PROD_ORD_NBR` alone is **not**
unique — the same number recurs with a different duplicate and a different good-pipe count. Take one
row per key.

`GOOD_PIPES` and `REJECTED_PIPES` are supplied directly, so the `USER_FIELD_1`/`USER_FIELD_2`
parsing the plan expected is not needed. Import `STATUS = 'Order Completed'` only; the other statuses
are `Allocated Stock`, `Backordered Stock`, `Partly Completed` and `Cancelled`. `TRANS_TYPE` is
`BMORD`, or `BMRET` (758 rows) for returns.

Dates run back to 1946 in VIC — clamp the window rather than trusting the minimum.
